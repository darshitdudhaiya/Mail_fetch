<?php
namespace App\Http\Controllers;

use App\Models\User;
use App\Services\MicrosoftGraphService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class OneDriveSheetController extends Controller
{
    protected $graphService;

    public function __construct(MicrosoftGraphService $graphService)
    {
        $this->graphService = $graphService;
    }

    public function getSheetData(Request $request)
    {
        try {
            $user = User::fromSession();
            if (!$user) {
                Log::warning('Sheet data access attempt without authenticated user.');
                return response()->json(['success' => false, 'error' => 'User not authenticated'], 401);
            }

            // Get and validate access token
            $accessToken = $this->getValidAccessToken($user);
            if (!$accessToken) {
                return response()->json(['success' => false, 'error' => 'Invalid or expired token'], 401);
            }

            // Get configuration
            $filePath = env("DOCSYNC_MISCROSOFT_SHEET_FILE_PATH");
            $tableName = env("DOCSYNC_MICROSOFT_TABLE_NAME");
            $fileName = basename($filePath);

            // Check if we have cached file ID
            $cacheKey = "onedrive_file_id_{$user->id}_{$fileName}";
            $fileInfo = Cache::get($cacheKey);

            if (!$fileInfo) {
                // Search for file and cache the result
                $fileInfo = $this->findFileInOneDrive($accessToken, $fileName);
                
                if (!$fileInfo) {
                    return response()->json([
                        'success' => false, 
                        'error' => "File '{$fileName}' not found in OneDrive or shared files"
                    ], 404);
                }

                // Cache for 24 hours
                Cache::put($cacheKey, $fileInfo, now()->addHours(24));
            }

            // Fetch Excel data using the file info
            $excelData = $this->fetchExcelData($accessToken, $fileInfo, $tableName);

            if (!$excelData) {
                // Clear cache if we can't access the file (might have been moved/deleted)
                Cache::forget($cacheKey);
                return response()->json(['success' => false, 'error' => 'Failed to fetch Excel data'], 500);
            }

            return response()->json([
                'success' => true,
                'fileName' => $fileName,
                'location' => $fileInfo['location'],
                'table' => $excelData,
            ]);

        } catch (\Exception $e) {
            Log::error('Unhandled exception in getSheetData', [
                'message' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => $e->getFile(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json(['success' => false, 'error' => 'Unexpected server error'], 500);
        }
    }

    /**
     * Get valid access token, refresh if needed
     */
    private function getValidAccessToken(User $user): ?string
    {
        try {
            $accessToken = decrypt($user->microsoft_access_token);
        } catch (\Illuminate\Contracts\Encryption\DecryptException $e) {
            Log::error("Failed to decrypt access token for user {$user->id}");
            return null;
        }

        if (empty($accessToken)) {
            return null;
        }

        // Check if token needs refresh
        $expiresAt = Carbon::parse($user->microsoft_token_expires_at);
        if (Carbon::now()->greaterThan($expiresAt)) {
            Log::info("Refreshing expired token for user {$user->id}");

            $refreshToken = decrypt($user->microsoft_refresh_token);
            $newAccessToken = $this->graphService->getValidAccessToken(
                $user->id,
                $accessToken,
                $refreshToken
            );

            return $newAccessToken ?: null;
        }

        return $accessToken;
    }

    /**
     * Search for file in OneDrive (user's drive and shared locations)
     */
    private function findFileInOneDrive(string $accessToken, string $fileName): ?array
    {
        // Strategy 1: Search using Microsoft Graph Search API (most efficient)
        $fileInfo = $this->searchFileByGraphAPI($accessToken, $fileName);
        if ($fileInfo) {
            return $fileInfo;
        }

        // Strategy 2: Check user's root drive
        $fileInfo = $this->searchInUserDrive($accessToken, $fileName);
        if ($fileInfo) {
            return $fileInfo;
        }

        // Strategy 3: Check shared with me
        $fileInfo = $this->searchInSharedFiles($accessToken, $fileName);
        if ($fileInfo) {
            return $fileInfo;
        }

        return null;
    }

    /**
     * Search using Microsoft Graph Search API (searches everywhere including nested folders)
     */
    private function searchFileByGraphAPI(string $accessToken, string $fileName): ?array
    {
        try {
            Log::info("Searching for file using Graph Search API", ['fileName' => $fileName]);

            $response = Http::withToken($accessToken)
                ->post("https://graph.microsoft.com/v1.0/search/query", [
                    'requests' => [
                        [
                            'entityTypes' => ['driveItem'],
                            'query' => [
                                'queryString' => "filename:\"{$fileName}\""
                            ],
                            'from' => 0,
                            'size' => 25
                        ]
                    ]
                ]);

            if ($response->successful()) {
                $hits = $response->json()['value'][0]['hitsContainers'][0]['hits'] ?? [];
                
                foreach ($hits as $hit) {
                    $resource = $hit['resource'] ?? [];
                    if (($resource['name'] ?? '') === $fileName) {
                        $fileId = $resource['id'];
                        $driveId = $resource['parentReference']['driveId'] ?? null;

                        Log::info("File found via Graph Search", [
                            'fileId' => $fileId,
                            'driveId' => $driveId,
                            'path' => $resource['parentReference']['path'] ?? 'unknown'
                        ]);

                        return [
                            'fileId' => $fileId,
                            'driveId' => $driveId,
                            'location' => 'search_result',
                            'path' => $resource['parentReference']['path'] ?? null
                        ];
                    }
                }
            }
        } catch (\Exception $e) {
            Log::warning("Graph Search API failed", ['error' => $e->getMessage()]);
        }

        return null;
    }

    /**
     * Recursively search in user's drive including all nested folders
     */
    private function searchInUserDrive(string $accessToken, string $fileName, string $folderId = 'root', int $depth = 0): ?array
    {
        // Limit recursion depth to prevent infinite loops
        if ($depth > 10) {
            return null;
        }

        try {
            $endpoint = $folderId === 'root' 
                ? "https://graph.microsoft.com/v1.0/me/drive/root/children"
                : "https://graph.microsoft.com/v1.0/me/drive/items/{$folderId}/children";

            $response = Http::withToken($accessToken)->get($endpoint);

            if ($response->failed()) {
                return null;
            }

            $items = $response->json()['value'] ?? [];

            foreach ($items as $item) {
                $itemName = $item['name'] ?? '';

                // Found the file
                if ($itemName === $fileName && isset($item['file'])) {
                    Log::info("File found in user's drive", [
                        'fileId' => $item['id'],
                        'location' => $folderId === 'root' ? 'root' : 'nested_folder',
                        'depth' => $depth
                    ]);

                    return [
                        'fileId' => $item['id'],
                        'driveId' => $item['parentReference']['driveId'] ?? null,
                        'location' => $folderId === 'root' ? 'user_drive_root' : 'user_drive_nested',
                        'path' => $item['parentReference']['path'] ?? null
                    ];
                }

                // If it's a folder, search recursively
                if (isset($item['folder']) && $item['id']) {
                    $result = $this->searchInUserDrive($accessToken, $fileName, $item['id'], $depth + 1);
                    if ($result) {
                        return $result;
                    }
                }
            }
        } catch (\Exception $e) {
            Log::warning("Error searching user drive at depth {$depth}", ['error' => $e->getMessage()]);
        }

        return null;
    }

    /**
     * Search in shared files (including nested shared folders)
     */
    private function searchInSharedFiles(string $accessToken, string $fileName): ?array
    {
        try {
            Log::info("Searching in shared files", ['fileName' => $fileName]);

            $response = Http::withToken($accessToken)
                ->get("https://graph.microsoft.com/v1.0/me/drive/sharedWithMe");

            if ($response->failed()) {
                return null;
            }

            $sharedItems = $response->json()['value'] ?? [];

            foreach ($sharedItems as $sharedItem) {
                $remoteItem = $sharedItem['remoteItem'] ?? [];
                
                // Check if this shared item is the file we're looking for
                if (($remoteItem['name'] ?? '') === $fileName && isset($remoteItem['file'])) {
                    Log::info("File found in shared items", [
                        'fileId' => $remoteItem['id'],
                        'driveId' => $remoteItem['parentReference']['driveId'] ?? null
                    ]);

                    return [
                        'fileId' => $remoteItem['id'],
                        'driveId' => $remoteItem['parentReference']['driveId'],
                        'location' => 'shared_with_me',
                        'path' => $remoteItem['parentReference']['path'] ?? null
                    ];
                }

                // If it's a shared folder, search inside it
                if (isset($remoteItem['folder'])) {
                    $result = $this->searchInSharedFolder(
                        $accessToken, 
                        $fileName, 
                        $remoteItem['id'],
                        $remoteItem['parentReference']['driveId']
                    );
                    if ($result) {
                        return $result;
                    }
                }
            }
        } catch (\Exception $e) {
            Log::warning("Error searching shared files", ['error' => $e->getMessage()]);
        }

        return null;
    }

    /**
     * Recursively search inside a shared folder
     */
    private function searchInSharedFolder(string $accessToken, string $fileName, string $folderId, string $driveId, int $depth = 0): ?array
    {
        // Limit recursion depth
        if ($depth > 10) {
            return null;
        }

        try {
            $response = Http::withToken($accessToken)
                ->get("https://graph.microsoft.com/v1.0/drives/{$driveId}/items/{$folderId}/children");

            if ($response->failed()) {
                return null;
            }

            $items = $response->json()['value'] ?? [];

            foreach ($items as $item) {
                // Found the file
                if (($item['name'] ?? '') === $fileName && isset($item['file'])) {
                    Log::info("File found in shared nested folder", [
                        'fileId' => $item['id'],
                        'driveId' => $driveId,
                        'depth' => $depth
                    ]);

                    return [
                        'fileId' => $item['id'],
                        'driveId' => $driveId,
                        'location' => 'shared_nested_folder',
                        'path' => $item['parentReference']['path'] ?? null
                    ];
                }

                // If it's a folder, search recursively
                if (isset($item['folder'])) {
                    $result = $this->searchInSharedFolder($accessToken, $fileName, $item['id'], $driveId, $depth + 1);
                    if ($result) {
                        return $result;
                    }
                }
            }
        } catch (\Exception $e) {
            Log::warning("Error searching shared folder at depth {$depth}", ['error' => $e->getMessage()]);
        }

        return null;
    }

    /**
     * Fetch Excel data using file ID and drive ID
     */
    private function fetchExcelData(string $accessToken, array $fileInfo, string $tableName): ?array
    {
        $fileId = $fileInfo['fileId'];
        $driveId = $fileInfo['driveId'];

        // Build the workbook URL based on whether we have a driveId
        if ($driveId) {
            $baseUrl = "https://graph.microsoft.com/v1.0/drives/{$driveId}/items/{$fileId}/workbook/tables/{$tableName}";
        } else {
            $baseUrl = "https://graph.microsoft.com/v1.0/me/drive/items/{$fileId}/workbook/tables/{$tableName}";
        }

        Log::info("Fetching Excel data", ['url' => $baseUrl]);

        try {
            // Use HTTP pool for parallel requests
            $responses = Http::pool(fn($pool) => [
                $pool->as('headers')->withToken($accessToken)->get("{$baseUrl}/headerRowRange"),
                $pool->as('data')->withToken($accessToken)->get("{$baseUrl}/dataBodyRange")
            ]);

            if ($responses['headers']->failed() || $responses['data']->failed()) {
                Log::error("Failed to fetch Excel data", [
                    'headers_status' => $responses['headers']->status(),
                    'data_status' => $responses['data']->status()
                ]);
                return null;
            }

            $headers = $responses['headers']->json()['values'][0] ?? [];
            $rows = $responses['data']->json()['values'] ?? [];

            return [
                'headers' => $headers,
                'rows' => $rows,
            ];

        } catch (\Exception $e) {
            Log::error("Exception fetching Excel data", [
                'error' => $e->getMessage(),
                'fileId' => $fileId,
                'driveId' => $driveId
            ]);
            return null;
        }
    }

    /**
     * Clear cached file info (useful if file is moved or deleted)
     */
    public function clearFileCache(Request $request)
    {
        $user = User::fromSession();
        if (!$user) {
            return response()->json(['success' => false, 'error' => 'User not authenticated'], 401);
        }

        $filePath = env("DOCSYNC_MISCROSOFT_SHEET_FILE_PATH");
        $fileName = basename($filePath);
        $cacheKey = "onedrive_file_id_{$user->id}_{$fileName}";

        Cache::forget($cacheKey);

        return response()->json(['success' => true, 'message' => 'File cache cleared']);
    }
}