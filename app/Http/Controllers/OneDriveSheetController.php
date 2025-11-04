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
        $microsoftBaseApi = env('MICROSOFT_BASE_API');

        try {
            $user = User::fromSession();
            if (!$user) {
                return response()->json(['success' => false, 'error' => 'User not authenticated'], 401);
            }

            $accessToken = decrypt($user->microsoft_access_token);
            $refreshToken = decrypt($user->microsoft_refresh_token);
            $expiresAt = Carbon::parse($user->microsoft_token_expires_at);

            if (Carbon::now()->greaterThan($expiresAt)) {
                $accessToken = $this->graphService->getValidAccessToken($user->id, $accessToken, $refreshToken);
                if (!$accessToken) {
                    return response()->json(['success' => false, 'error' => 'Token expired, reauth required'], 401);
                }
            }

            $filePath = env('MICROSOFT_EXCEL_SHEET_PATH');
            $sheetName = env('MICROSOFT_EXCEL_SHEET_NAME');

            // Step 1: Get sheet values first (1 API call)
            $rangeUrl = "{$microsoftBaseApi}/drive/root:{$filePath}:/workbook/worksheets('{$sheetName}')/usedRange";
            $rangeResponse = Http::withToken($accessToken)->get($rangeUrl);

            if (!$rangeResponse->successful()) {
                return response()->json([
                    'success' => false,
                    'error' => 'Failed to fetch sheet data',
                    'details' => $rangeResponse->json(),
                ], 500);
            }

            $rangeData = $rangeResponse->json();
            $values = $rangeData['values'] ?? [];

            // Step 2: Prepare batch request for colors
            $requests = [];
            $batchId = 1;   
            $colToLetter = function ($colNum) {
                $letters = '';
                while ($colNum > 0) {
                    $mod = ($colNum - 1) % 26;
                    $letters = chr(65 + $mod) . $letters;
                    $colNum = (int)(($colNum - $mod) / 26);
                }
                return $letters;
            };

            foreach ($values as $rowIndex => $row) {
                foreach ($row as $colIndex => $cellValue) {
                    $colLetter = $colToLetter($colIndex + 1);
                    $cellAddress = "{$colLetter}" . ($rowIndex + 1);

                    $requests[] = [
                        'id' => (string)$batchId++,
                        'method' => 'GET',
                        'url' => "/me/drive/root:{$filePath}:/workbook/worksheets('{$sheetName}')/range(address='{$cellAddress}')/format/fill"
                    ];
                }
            }

            // Chunk into groups of 20 (Graph API limit)
            $chunks = array_chunk($requests, 20);
            $colorResults = [];
            foreach ($chunks as $chunk) {
                $batchResponse = Http::withToken($accessToken)->post("https://graph.microsoft.com/v1.0/\$batch", [
                    'requests' => $chunk
                ]);

                if ($batchResponse->successful()) {
                    $body = $batchResponse->json();
                    foreach ($body['responses'] as $res) {
                        $colorResults[$res['id']] = $res['body']['color'] ?? null;
                    }
                }
            }

            // Step 3: Merge values and colors
            $coloredData = [];
            $i = 1;
            foreach ($values as $row) {
                $rowData = [];
                foreach ($row as $cellValue) {
                    $color = $colorResults[(string)$i] ?? null;
                    $rowData[] = [
                        'value' => $cellValue,
                        'color' => $color,
                    ];
                    $i++;
                }
                $coloredData[] = $rowData;
            }

            return response()->json([
                'success' => true,
                'data' => $coloredData,
            ]);
        } catch (\Exception $e) {
            Log::error('Sheet fetch error', ['message' => $e->getMessage()]);
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }
}
