<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class MicrosoftAuthConfigController extends Controller
{
    public function getMicrosoftConfig()
    {
        return response()->json([
            'client_id' => env('MICROSOFT_CLIENT_ID'),
            'redirect_uri' => env('MICROSOFT_REDIRECT_URI'),
            'scopes' => env('MICROSOFT_PERMISSIONS'),
        ]);
    }
}
