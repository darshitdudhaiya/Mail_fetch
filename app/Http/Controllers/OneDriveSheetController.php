<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\MicrosoftGraphService;
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

    /**
     * Downloads Excel file from OneDrive and returns it to the frontend.
     */
    public function downloadExcelFile(Request $request)
    {
        try {
            $user = User::fromSession();
            if (!$user) {
                return response()->json(['success' => false, 'error' => 'User not authenticated'], 401);
            }

            $accessToken = decrypt($user->microsoft_access_token);
            $refreshToken = decrypt($user->microsoft_refresh_token);

            // Refresh token if expired
            $accessToken = $this->graphService->getValidAccessToken(
                $user->id,
                $accessToken,
                $refreshToken
            );

            if (!$accessToken) {
                return response()->json(['success' => false, 'error' => 'Token expired, reauth required'], 401);
            }

            $microsoftBaseApi = env('MICROSOFT_BASE_API');
            $filePath = env('MICROSOFT_EXCEL_SHEET_PATH');

            // Fetch Excel file binary from OneDrive
            $fileUrl = "{$microsoftBaseApi}/drive/root:{$filePath}:/content";
            $response = Http::withToken($accessToken)->get($fileUrl);

            if (!$response->successful()) {
                Log::error('Failed to fetch Excel file', ['body' => $response->body()]);
                return response()->json(['success' => false, 'error' => 'Failed to fetch Excel file'], 500);
            }

            return response($response->body(), 200)
                ->header('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
                ->header('Content-Disposition', 'inline; filename="data.xlsx"');
        } catch (\Exception $e) {
            Log::error('Excel fetch error', ['message' => $e->getMessage()]);
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }
}
