<?php
namespace App\Http\Controllers;

use App\Models\User;
use App\Services\MicrosoftGraphService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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

            try {
                $accessToken = decrypt($user->microsoft_access_token);
            } catch (\Illuminate\Contracts\Encryption\DecryptException $e) {
                Log::error("Failed to decrypt access token for user {$user->id}");
                return response()->json(['success' => false, 'error' => 'Invalid authentication token'], 401);
            }

            if (empty($accessToken)) {
                return response()->json(['success' => false, 'error' => 'Missing access token'], 401);
            }

            // Check token expiration
            $refreshToken = decrypt($user->microsoft_refresh_token);
            $expiresAt = Carbon::parse($user->microsoft_token_expires_at);

            if (Carbon::now()->greaterThan($expiresAt)) {
                Log::info("Refreshing expired token for user {$user->id}");

                $newAccessToken = $this->graphService->getValidAccessToken(
                    $user->id,
                    $accessToken,
                    $refreshToken
                );

                if (!$newAccessToken) {
                    return response()->json(['success' => false, 'error' => 'Token expired, re-authenticate'], 401);
                }

                $accessToken = $newAccessToken;
            }

            // ──────────────────────────────────────
            // ENV PATHS
            // ──────────────────────────────────────
            $filePath  = env("DOCSYNC_MISCROSOFT_SHEET_FILE_PATH");
            $sheetName = env("DOCSYNC_MISCROSOFT_SHEET_NAME");
            $tableName = env("DOCSYNC_MICROSOFT_TABLE_NAME");
            $searchName = basename($filePath);

            // ──────────────────────────────────────
            // 1. SEARCH IN USER ROOT
            // ──────────────────────────────────────
            $rootChildren = Http::withToken($accessToken)
                ->get("https://graph.microsoft.com/v1.0/me/drive/root/children");

            if ($rootChildren->failed()) {
                return response()->json(['success' => false, 'error' => 'Unable to access OneDrive root'], 500);
            }

            $items = $rootChildren->json()['value'] ?? [];
            $fileId = null;
            $driveId = null;

            foreach ($items as $item) {
                if (($item['name'] ?? '') === $searchName) {
                    $fileId = $item['id'];
                    $driveId = $item['parentReference']['driveId'] ?? null;
                    break;
                }
            }

            // ──────────────────────────────────────
            // 2. SEARCH IN DOCUMENTS FOLDER
            // ──────────────────────────────────────
            if (!$fileId) {
                foreach ($items as $item) {
                    if (($item['name'] ?? '') === 'Documents') {
                        $documentsId = $item['id'];

                        $docsChildren = Http::withToken($accessToken)
                            ->get("https://graph.microsoft.com/v1.0/me/drive/items/{$documentsId}/children");

                        if ($docsChildren->successful()) {
                            foreach ($docsChildren->json()['value'] as $docItem) {
                                if ($docItem['name'] === $searchName) {
                                    $fileId = $docItem['id'];
                                    $driveId = $docItem['parentReference']['driveId'] ?? null;
                                    break 2;
                                }
                            }
                        }
                    }
                }
            }

            // ──────────────────────────────────────
            // 3. SEARCH IN SHARED WITH ME
            // ──────────────────────────────────────
            if (!$fileId) {
                Log::info("Searching 'sharedWithMe' for {$searchName}");

                $sharedResp = Http::withToken($accessToken)
                    ->get("https://graph.microsoft.com/v1.0/me/drive/sharedWithMe");

                if ($sharedResp->successful()) {
                    foreach ($sharedResp->json()['value'] ?? [] as $sharedItem) {
                        if (($sharedItem['name'] ?? '') === $searchName) {

                            // THIS IS THE REAL FILE INFO
                            $remote = $sharedItem['remoteItem'];

                            $fileId = $remote['id'];
                            $driveId = $remote['parentReference']['driveId'];

                            Log::info("File found in sharedWithMe", [
                                'fileId' => $fileId,
                                'driveId' => $driveId
                            ]);

                            break;
                        }
                    }
                }
            }

            if (!$fileId) {
                return response()->json(['success' => false, 'error' => "File {$searchName} not found"], 404);
            }

            // ──────────────────────────────────────
            // SELECT APPROPRIATE DRIVE PATH
            // ──────────────────────────────────────
            $graphUserId = $user->microsoft_graph_user_id ?? $user->microsoft_email;

            if ($driveId) {
                // SHARED OR OTHER DRIVE
                $baseUrl = "https://graph.microsoft.com/v1.0/drives/{$driveId}/items/{$fileId}/workbook/tables/{$tableName}";
            } else {
                // USER'S PRIMARY DRIVE
                $baseUrl = "https://graph.microsoft.com/v1.0/users/{$graphUserId}/drive/items/{$fileId}/workbook/tables/{$tableName}";
            }

            Log::info("Workbook base URL", ['url' => $baseUrl]);

            // ──────────────────────────────────────
            // FETCH EXCEL DATA (headers + rows)
            // ──────────────────────────────────────
            $client = Http::withHeaders([
                'Authorization' => "Bearer {$accessToken}",
                'Accept'        => 'application/json',
            ]);

            try {
                $responses = Http::pool(fn($pool) => [
                    $pool->as('headers')->withToken($accessToken)->get("{$baseUrl}/headerRowRange"),
                    $pool->as('data')->withToken($accessToken)->get("{$baseUrl}/dataBodyRange")
                ]);
            } catch (\Throwable $ex) {
                $responses = null;
            }

            if (!$responses) {
                $headersResp = $client->get("{$baseUrl}/headerRowRange");
                $dataResp    = $client->get("{$baseUrl}/dataBodyRange");

                if ($headersResp->failed() || $dataResp->failed()) {
                    return response()->json(['success' => false, 'error' => 'Failed to fetch Excel data'], 500);
                }

                $headers = $headersResp->json()['values'][0] ?? [];
                $rows    = $dataResp->json()['values'] ?? [];
            } else {
                if ($responses['headers']->failed() || $responses['data']->failed()) {
                    return response()->json(['success' => false, 'error' => 'Failed to fetch Excel data'], 500);
                }

                $headers = $responses['headers']->json()['values'][0] ?? [];
                $rows    = $responses['data']->json()['values'] ?? [];
            }

            return response()->json([
                'success'  => true,
                'fileName' => basename($filePath),
                'table'    => [
                    'headers' => $headers,
                    'rows'    => $rows,
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Unhandled exception', [
                'msg' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => $e->getFile(),
            ]);

            return response()->json(['success' => false, 'error' => 'Unexpected server error'], 500);
        }
    }
}
