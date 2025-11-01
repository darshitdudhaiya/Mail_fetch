<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\MicrosoftGraphService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    protected $graphService;

    public function __construct(MicrosoftGraphService $graphService)
    {
        $this->graphService = $graphService;
    }

    public function handleToken(Request $request)
    {
        $code = $request->input('code');
        $codeVerifier = $request->input('code_verifier');

        $clientId = env('MICROSOFT_CLIENT_ID');
        $clientSecret = env('MICROSOFT_CLIENT_SECRET');
        $redirectUri = env('MICROSOFT_REDIRECT_URI');

        try {
            // Exchange authorization code for access token
            $response = Http::asForm()->post(
                "https://login.microsoftonline.com/consumers/oauth2/v2.0/token",
                [
                    'client_id' => $clientId,
                    'client_secret' => $clientSecret,
                    'scope' => 'https://graph.microsoft.com/user.read https://graph.microsoft.com/mail.read offline_access Files.Read Files.ReadWrite profile openid',
                    'code' => $code,
                    'redirect_uri' => $redirectUri,
                    'grant_type' => 'authorization_code',
                    'code_verifier' => $codeVerifier,
                ]
            );

            if ($response->successful()) {
                $data = $response->json();

                // Get user info from Microsoft Graph
                $userInfo = $this->getUserInfo($data['access_token']);

                if ($userInfo) {
                    // Create user object and store in session
                    $user = $this->createUserFromMicrosoft($userInfo, $data);

                    return response()->json([
                        'success' => true,
                        'access_token' => $data['access_token'],
                        'refresh_token' => $data['refresh_token'] ?? null,
                        'expires_in' => $data['expires_in'] ?? null,
                        'user' => [
                            'id' => $user->id,
                            'email' => $user->email,
                            'name' => $user->name,
                        ]
                    ]);
                }
            }

            return response()->json([
                'success' => false,
                'error' => $response->json(),
            ], 400);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get user info from Microsoft Graph
     */
    private function getUserInfo($accessToken)
    {
        try {
            $response = Http::withToken($accessToken)
                ->get('https://graph.microsoft.com/v1.0/me');

            if ($response->successful()) {
                return $response->json();
            }

            return null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Create user from Microsoft data and store in session
     */
    private function createUserFromMicrosoft($userInfo, $tokenData)
    {
        $expiresAt = Carbon::now()->addSeconds($tokenData['expires_in'] ?? 3600);

        // Generate unique session-based user ID
        $userId = Str::uuid()->toString();

        $userData = [
            'id' => $userId,
            'name' => $userInfo['displayName'],
            'email' => $userInfo['mail'] ?? $userInfo['userPrincipalName'],
            'microsoft_email' => $userInfo['mail'] ?? $userInfo['userPrincipalName'],
            'microsoft_access_token' => encrypt($tokenData['access_token']),
            'microsoft_refresh_token' => encrypt($tokenData['refresh_token']),
            'microsoft_token_expires_at' => $expiresAt->toDateTimeString(),
        ];

        // Store in session
        User::storeInSession($userData);

        return new User($userData);
    }

    /**
     * Fetch unreplied emails for authenticated user (with optional date filter)
     */
    public function getUnrepliedEmails(Request $request)
    {
        try {
            $user = User::fromSession();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'error' => 'User not authenticated',
                    'reauth_required' => true
                ], 401);
            }

            // Decrypt tokens
            $accessToken = decrypt($user->microsoft_access_token);
            $refreshToken = decrypt($user->microsoft_refresh_token);

            // Check if token is expired
            $expiresAt = Carbon::parse($user->microsoft_token_expires_at);
            if (Carbon::now()->greaterThan($expiresAt)) {
                $newToken = $this->graphService->getValidAccessToken(
                    $user->id,
                    $accessToken,
                    $refreshToken
                );

                if (!$newToken) {
                    return response()->json([
                        'success' => false,
                        'error' => 'Failed to refresh token. Please re-authenticate.',
                        'reauth_required' => true
                    ], 401);
                }

                $accessToken = $newToken;
            }

            $startDate = $request->query('startDate');
            $endDate   = $request->query('endDate');

            $result = $this->graphService->getUnrepliedEmails(
                $accessToken,
                50, // max results
                $startDate,
                $endDate
            );

            return response()->json($result);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get specific email details
     */
    public function getEmailDetails(Request $request, $messageId)
    {
        try {
            // Get user from session
            $user = User::fromSession();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'error' => 'User not authenticated',
                    'reauth_required' => true
                ], 401);
            }

            $accessToken = decrypt($user->microsoft_access_token);
            $refreshToken = decrypt($user->microsoft_refresh_token);

            // Check if token is expired and refresh if needed
            $expiresAt = Carbon::parse($user->microsoft_token_expires_at);
            if (Carbon::now()->greaterThan($expiresAt)) {
                $accessToken = $this->graphService->getValidAccessToken(
                    $user->id,
                    $accessToken,
                    $refreshToken
                );

                if (!$accessToken) {
                    return response()->json([
                        'success' => false,
                        'error' => 'Failed to refresh token',
                        'reauth_required' => true
                    ], 401);
                }
            }

            $result = $this->graphService->getEmailById($accessToken, $messageId);

            return response()->json($result);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Logout user
     */
    public function logout()
    {
        User::clearSession();

        return response()->json([
            'success' => true,
            'message' => 'Logged out successfully'
        ]);
    }

    /**
     * Get current user
     */
    public function getCurrentUser()
    {
        $user = User::fromSession();

        if (!$user) {
            return response()->json([
                'success' => false,
                'error' => 'Not authenticated'
            ], 401);
        }

        return response()->json([
            'success' => true,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ]
        ]);
    }
}
