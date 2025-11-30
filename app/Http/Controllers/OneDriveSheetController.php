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
            if (! $user) {
                Log::warning('Sheet data access attempt without authenticated user.');
                return response()->json(['success' => false, 'error' => 'User not authenticated'], 401);
            }

            try {
                $accessToken = decrypt($user->microsoft_access_token);
            } catch (\Illuminate\Contracts\Encryption\DecryptException $e) {
                Log::error('Failed to decrypt access token for user: ' . $user->id, ['error' => $e->getMessage()]);
                return response()->json(['success' => false, 'error' => 'Invalid authentication token. Please re-authenticate.'], 401);
            }

            if (empty($accessToken)) {
                Log::error("Access token empty for user {$user->id}");
                return response()->json(['success' => false, 'error' => 'Authentication token is missing or invalid.'], 401);
            }

            // Refresh expired token
            $refreshToken = decrypt($user->microsoft_refresh_token);
            $expiresAt    = Carbon::parse($user->microsoft_token_expires_at);

            Log::info("Token expiry check for user {$user->id}", [
                'access_token'  => $accessToken,
                'refresh_token' => $refreshToken,
                'expires_at'    => $expiresAt->toDateTimeString(),
            ]);

            if (Carbon::now()->greaterThan($expiresAt)) {
                Log::info("Refreshing expired token for user {$user->id}");
                $newAccessToken = $this->graphService->getValidAccessToken($user->id, $accessToken, $refreshToken);

                if (! $newAccessToken) {
                    return response()->json(['success' => false, 'error' => 'Token expired. Re-authenticate.'], 401);
                }

                $accessToken = $newAccessToken;
            }

                                                                   
            $filePath  = env("DOCSYNC_MISCROSOFT_SHEET_FILE_PATH"); 
            $sheetName = env("DOCSYNC_MISCROSOFT_SHEET_NAME");
            $tableName = env("DOCSYNC_MICROSOFT_TABLE_NAME");

            
            $rootChildren = Http::withToken($accessToken)
                ->get("https://graph.microsoft.com/v1.0/me/drive/root/children");

            if ($rootChildren->failed()) {
                Log::error("Unable to fetch root children", ['body' => $rootChildren->body()]);
                return response()->json(['success' => false, 'error' => 'Unable to access OneDrive root'], 500);
            }

            $items      = $rootChildren->json()['value'] ?? [];
            $fileId     = null;
            $searchName = basename($filePath);

            foreach ($items as $item) {
                if (($item['name'] ?? '') === $searchName) {
                    $fileId = $item['id'];
                    break;
                }
            }

            if (! $fileId) {
                foreach ($items as $item) {
                    if (($item['name'] ?? '') === 'Documents') {
                        $documentsId = $item['id'];

                        $docsChildren = Http::withToken($accessToken)
                            ->get("https://graph.microsoft.com/v1.0/me/drive/items/{$documentsId}/children");

                        if ($docsChildren->failed()) {
                            Log::error("Unable to fetch Documents folder");
                            return response()->json(['success' => false, 'error' => 'Unable to access Documents folder'], 500);
                        }

                        foreach ($docsChildren->json()['value'] as $docItem) {
                            if ($docItem['name'] === $searchName) {
                                $fileId = $docItem['id'];
                                break 2;
                            }
                        }
                    }
                }
            }

            if (! $fileId) {
                return response()->json(['success' => false, 'error' => "File {$searchName} not found on OneDrive"], 404);
            }

            Log::info("File located", ['fileId' => $fileId]);


            $graphUserId = $user->microsoft_graph_user_id ?? ($user->microsoft_email ?? null);
            if (! $graphUserId) {
                Log::error("Graph user id missing in session for user {$user->id}");
                return response()->json(['success' => false, 'error' => 'Server misconfiguration: graph user id missing'], 500);
            }

            // Use the users/{id} path which is more reliable for workbook APIs
            $baseUrl = "https://graph.microsoft.com/v1.0/users/{$graphUserId}/drive/items/{$fileId}/workbook/tables/{$tableName}";

            Log::info("Requesting table data using fileId", ['base_url' => $baseUrl]);

            // Build a client with explicit headers (avoid relying only on withToken during pooling)
            $client = Http::withHeaders([
                'Authorization' => "Bearer {$accessToken}",
                'Accept' => 'application/json',
            ]);

            // Try pooled requests first (faster)
            try {
                $responses = Http::pool(fn($pool) => [
                    $pool->as('headers')->withHeaders([
                        'Authorization' => "Bearer {$accessToken}",
                        'Accept' => 'application/json',
                    ])->get("{$baseUrl}/headerRowRange"),

                    $pool->as('data')->withHeaders([
                        'Authorization' => "Bearer {$accessToken}",
                        'Accept' => 'application/json',
                    ])->get("{$baseUrl}/dataBodyRange"),
                ]);

            } catch (\Throwable $ex) {
                // Pooling failed (network/Guzzle quirk) — log and fall back to sequential
                Log::warning('Pooling failed, falling back to sequential requests', ['error' => $ex->getMessage()]);
                $responses = null;
            }

            // If pool returned null or failed, do sequential requests with detailed logs
            if (! $responses) {
                $headersResp = $client->get("{$baseUrl}/headerRowRange");
                $dataResp    = $client->get("{$baseUrl}/dataBodyRange");

                Log::info('Sequential Excel requests headers', [
                    'headers_status' => $headersResp->status(),
                    'headers_body'   => substr($headersResp->body(), 0, 1000), // avoid massive logs
                ]);
                Log::info('Sequential Excel requests data', [
                    'data_status' => $dataResp->status(),
                    'data_body'   => substr($dataResp->body(), 0, 1000),
                ]);

                if ($headersResp->failed() || $dataResp->failed()) {
                    Log::error('Failed to fetch Excel (sequential)', [
                        'headers_status' => $headersResp->status(),
                        'headers_body'   => $headersResp->body(),
                        'data_status'    => $dataResp->status(),
                        'data_body'      => $dataResp->body(),
                    ]);
                    return response()->json(['success' => false, 'error' => 'Failed to fetch Excel data (sequential)'], 500);
                }

                $headers = $headersResp->json()['values'][0] ?? [];
                $rows    = $dataResp->json()['values'] ?? [];

            } else {
                // Pool returned a responses array keyed by 'headers' and 'data'
                if ($responses['headers']->failed() || $responses['data']->failed()) {
                    Log::error('Excel API failed (pool)', [
                        'headers_status' => $responses['headers']->status(),
                        'headers_body'   => $responses['headers']->body(),
                        'data_status'    => $responses['data']->status(),
                        'data_body'      => $responses['data']->body(),
                    ]);
                    return response()->json(['success' => false, 'error' => 'Failed to fetch Excel data (pool)'], 500);
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
                'msg'  => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json(['success' => false, 'error' => 'Unexpected server error.'], 500);
        }
    }
}
