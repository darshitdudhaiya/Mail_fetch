<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\MicrosoftGraphService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;


class SheetController extends Controller
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

            // ✅ Fetch used range (dynamic data)
            $graphUrl = "{$microsoftBaseApi}/drive/root:{$filePath}:/workbook/worksheets('{$sheetName}')/usedRange";

            $response = Http::withToken($accessToken)->get($graphUrl);

            if (!$response->successful()) {
                return response()->json([
                    'success' => false,
                    'error' => 'Failed to fetch sheet data',
                    'details' => $response->json(),
                ], 500);
            }

            return response()->json([
                'success' => true,
                'values' => $response->json()['values'] ?? [],
            ]);
        } catch (\Exception $e) {
            Log::error('Sheet fetch error', ['message' => $e->getMessage()]);
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }
}
