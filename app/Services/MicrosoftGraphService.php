<?php

namespace App\Services;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class MicrosoftGraphService
{
    private $clientId;
    private $clientSecret;
    private $redirectUri;
    private $microsoftBaseApi;

    public function __construct()
    {
        $this->clientId = env('MICROSOFT_CLIENT_ID');
        $this->clientSecret = env('MICROSOFT_CLIENT_SECRET');
        $this->redirectUri = env('MICROSOFT_REDIRECT_URI');
        $this->microsoftBaseApi = env('MICROSOFT_BASE_API');

    }

    /**
     * Refresh the access token using refresh token
     */
    public function refreshAccessToken($refreshToken)
    {
        try {
            $response = Http::asForm()->post(
                'https://login.microsoftonline.com/consumers/oauth2/v2.0/token',
                [
                    'client_id' => $this->clientId,
                    'client_secret' => $this->clientSecret,
                    'grant_type' => 'refresh_token',
                    'refresh_token' => $refreshToken,
                    'scope' => 'https://graph.microsoft.com/user.read https://graph.microsoft.com/mail.read offline_access',
                ]
            );

            if ($response->successful()) {
                return $response->json();
            }

            Log::error('Token refresh failed', ['response' => $response->json()]);
            return null;
        } catch (\Exception $e) {
            Log::error('Token refresh exception', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Get valid access token (auto-refresh if expired)
     */
    public function getValidAccessToken($userId, $currentToken, $refreshToken)
    {
        // Check if token is cached and valid
        $cachedToken = Cache::get("user_{$userId}_access_token");
        if ($cachedToken) {
            return $cachedToken;
        }

        // Try to refresh the token
        $tokenData = $this->refreshAccessToken($refreshToken);

        if ($tokenData && isset($tokenData['access_token'])) {
            // Cache the new token (expires_in is in seconds, typically 3600)
            $expiresIn = $tokenData['expires_in'] ?? 3600;
            Cache::put("user_{$userId}_access_token", $tokenData['access_token'], $expiresIn - 300); // 5 min buffer

            // Update refresh token in session if new one provided
            if (isset($tokenData['refresh_token'])) {
                $this->updateRefreshTokenInSession($userId, $tokenData);
            }

            return $tokenData['access_token'];
        }

        return null;
    }

    public function getUnrepliedEmails($accessToken, $maxResults = 50, $startDate = null, $endDate = null)
    {
        try {
            $filterParts = ["isDraft eq false"];

            // Apply date filters if provided
            if ($startDate) {
                $filterParts[] = "receivedDateTime ge " . $this->formatGraphDate($startDate);
            }
            if ($endDate) {
                $filterParts[] = "receivedDateTime le " . $this->formatGraphDate($endDate);
            }

            $filter = implode(' and ', $filterParts);

            $response = Http::withToken($accessToken)
                ->get("{$this->microsoftBaseApi}/mailFolders/inbox/messages", [
                    '$top' => $maxResults,
                    '$select' => 'id,subject,from,receivedDateTime,bodyPreview,isRead,conversationId,hasAttachments,webLink',
                    '$orderby' => 'receivedDateTime DESC',
                    '$filter' => $filter,
                ]);

            if ($response->successful()) {
                $messages = $response->json()['value'] ?? [];

                // Filter unreplied emails
                $unrepliedEmails = $this->filterUnrepliedEmails($messages, $accessToken);

                // Add Outlook web links
                $unrepliedEmails = array_map(function ($email) {
                    if (!isset($email['webLink'])) {
                        $email['webLink'] = "https://outlook.live.com/mail/0/inbox/id/" . $email['id'];
                    }
                    return $email;
                }, $unrepliedEmails);

                return [
                    'success' => true,
                    'emails' => $unrepliedEmails,
                    'count' => count($unrepliedEmails),
                ];
            }

            return [
                'success' => false,
                'error' => $response->json(),
                'status' => $response->status(),
            ];
        } catch (\Exception $e) {
            Log::error('Email fetch exception', ['error' => $e->getMessage()]);
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Format date for Microsoft Graph filter
     */
    private function formatGraphDate($date)
    {
        // Convert to ISO 8601 UTC format (e.g., 2025-11-01T00:00:00Z)
        return Carbon::parse($date)->toIso8601ZuluString();
    }



    /**
     * Filter emails that haven't been replied to
     */
    private function filterUnrepliedEmails($messages, $accessToken)
    {
        $unreplied = [];

        foreach ($messages as $message) {
            $conversationId = $message['conversationId'];

            // Check if there's a sent message in this conversation
            $hasReply = $this->checkIfReplied($conversationId, $accessToken);

            if (!$hasReply) {
                $unreplied[] = [
                    'id' => $message['id'],
                    'subject' => $message['subject'],
                    'from' => $message['from']['emailAddress']['address'] ?? 'Unknown',
                    'fromName' => $message['from']['emailAddress']['name'] ?? 'Unknown',
                    'receivedDateTime' => $message['receivedDateTime'],
                    'bodyPreview' => $message['bodyPreview'],
                    'isRead' => $message['isRead'],
                    'hasAttachments' => $message['hasAttachments'] ?? false,
                ];
            }
        }

        return $unreplied;
    }

    /**
     * Check if a conversation has been replied to
     */
    private function checkIfReplied($conversationId, $accessToken)
    {
        try {
            // Check sent items for replies in this conversation
            $response = Http::withToken($accessToken)
                ->get("{$this->microsoftBaseApi}/mailFolders/SentItems/messages", [
                    '$top' => 1,
                    '$filter' => "conversationId eq '{$conversationId}'",
                    '$select' => 'id'
                ]);

            if ($response->successful()) {
                $sentMessages = $response->json()['value'] ?? [];
                return count($sentMessages) > 0;
            }

            return false;
        } catch (\Exception $e) {
            Log::error('Reply check exception', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Get email details by ID
     */
    public function getEmailById($accessToken, $messageId)
    {
        try {
            $response = Http::withToken($accessToken)
                ->get("{$this->microsoftBaseApi}/messages/{$messageId}", [
                    '$select' => 'id,subject,from,receivedDateTime,body,bodyPreview,isRead,hasAttachments,toRecipients,ccRecipients'
                ]);

            if ($response->successful()) {
                return [
                    'success' => true,
                    'email' => $response->json()
                ];
            }

            return [
                'success' => false,
                'error' => $response->json()
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Update refresh token in session
     */
    private function updateRefreshTokenInSession($userId, $tokenData)
    {
        $user = User::fromSession();

        if ($user && $user->id === $userId) {
            $expiresAt = Carbon::now()->addSeconds($tokenData['expires_in'] ?? 3600);

            $user->microsoft_access_token = encrypt($tokenData['access_token']);
            $user->microsoft_refresh_token = encrypt($tokenData['refresh_token']);
            $user->microsoft_token_expires_at = $expiresAt->toDateTimeString();

            // Update session
            $user->updateSession();
        }
    }
}
