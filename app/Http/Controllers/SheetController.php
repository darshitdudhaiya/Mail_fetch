<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class SheetController extends Controller
{
    /**
     * Fetch Excel data from OneDrive securely (app-based access)
     */
    public function getSheetData(Request $request)
    {
        try {
            $tenantId = env('MICROSOFT_TENANT_ID');
            $clientId = env('MICROSOFT_CLIENT_ID');
            $clientSecret = env('MICROSOFT_CLIENT_SECRET');
            $fileId = env('MICROSOFT_EXCEL_FILE_ID');
            $sheetName = env('MICROSOFT_EXCEL_SHEET_NAME', 'Sheet1');
            $range = env('MICROSOFT_EXCEL_RANGE', 'A1:D10');

            $tokenResponse = Http::asForm()->post("https://login.microsoftonline.com/{$tenantId}/oauth2/v2.0/token", [
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'grant_type' => 'client_credentials',
                'scope' => 'https://graph.microsoft.com/.default',
            ]);

            if (!$tokenResponse->successful()) {
                return response()->json([
                    'success' => false,
                    'error' => 'Failed to get access token',
                    'details' => $tokenResponse->json(),
                ], 500);
            }

            $accessToken = $tokenResponse->json()['access_token'];

            $graphUrl = "https://graph.microsoft.com/v1.0/me/drive/items/{$fileId}/workbook/worksheets('{$sheetName}')/range(address='{$range}')";

            $sheetResponse = Http::withToken($accessToken)->get($graphUrl);

            if (!$sheetResponse->successful()) {
                return response()->json([
                    'success' => false,
                    'error' => 'Failed to fetch sheet data',
                    'details' => $sheetResponse->json(),
                ], 500);
            }

            $data = $sheetResponse->json();

            return response()->json([
                'success' => true,
                'values' => $data['values'] ?? [],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
