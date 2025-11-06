<?php
namespace App\Services;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MicrosoftGraphService
{
    private $clientId;
    private $clientSecret;
    private $redirectUri;
    private $microsoftBaseApi;

    public function __construct()
    {
        $this->clientId         = env('MICROSOFT_CLIENT_ID');
        $this->clientSecret     = env('MICROSOFT_CLIENT_SECRET');
        $this->redirectUri      = env('MICROSOFT_REDIRECT_URI');
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
                    'client_id'     => $this->clientId,
                    'client_secret' => $this->clientSecret,
                    'grant_type'    => 'refresh_token',
                    'refresh_token' => $refreshToken,
                    'scope'         => 'https://graph.microsoft.com/user.read https://graph.microsoft.com/mail.read offline_access',
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

    public function getUnrepliedEmails($accessToken, $page = 1, $perPage = 20)
    {
        try {
            $page    = max((int) $page, 1);
            $perPage = max((int) $perPage, 10);
            $skip    = ($page - 1) * $perPage;

            // ✅ 1. Fetch all messages from INBOX (not drafts)
            $inboxResponse = Http::withToken($accessToken)->get("{$this->microsoftBaseApi}/me/mailFolders/inbox/messages", [
                '$top'     => $perPage,
                '$skip'    => $skip,
                '$select'  => 'id,subject,from,receivedDateTime,isRead,conversationId,hasAttachments,webLink',
                '$orderby' => 'receivedDateTime DESC',
                '$filter'  => "isDraft eq false",
            ]);

            if (! $inboxResponse->successful()) {
                return [
                    'success' => false,
                    'error'   => $inboxResponse->json(),
                    'status'  => $inboxResponse->status(),
                ];
            }

            $inboxMessages = $inboxResponse->json()['value'] ?? [];

            if (empty($inboxMessages)) {
                return [
                    'success' => true,
                    'emails'  => [],
                    'count'   => 0,
                ];
            }

            // ✅ 2. Collect all conversation IDs from Inbox
            $conversationIds = collect($inboxMessages)
                ->pluck('conversationId')
                ->filter()
                ->unique()
                ->values()
                ->toArray();

            // ✅ 3. Get “Sent Items” folder ID dynamically (works for any mailbox)
            $folderRes = Http::withToken($accessToken)
                ->get("{$this->microsoftBaseApi}/me/mailFolders?\$select=id,displayName");

            $folders      = $folderRes->json()['value'] ?? [];
            $sentFolderId = collect($folders)
                ->firstWhere('displayName', 'Sent Items')['id'] ?? collect($folders)->firstWhere('displayName', 'Sent Mail')['id'] ?? null;

            if (! $sentFolderId) {
                Log::error('No Sent Items folder found.');
                $sentFolderId = 'SentItems'; // fallback default
            }

            // ✅ 4. Fetch all recent sent messages to detect replied threads
            $sentRes = Http::withToken($accessToken)
                ->get("{$this->microsoftBaseApi}/me/mailFolders/{$sentFolderId}/messages?\$top=200&\$select=conversationId");

            $sentConversations = collect($sentRes->json()['value'] ?? [])
                ->pluck('conversationId')
                ->filter()
                ->unique()
                ->toArray();

            // ✅ 5. Compare Inbox vs SentItems → build unreplied/unread list
            $emails = [];
            foreach ($inboxMessages as $msg) {
                $isRead   = $msg['isRead'] ?? false;
                $convId   = $msg['conversationId'] ?? '';
                $hasReply = in_array($convId, $sentConversations, true);

                $status = [];
                if (! $hasReply) {
                    $status[] = 'unreplied';
                }

                if (! $isRead) {
                    $status[] = 'unread';
                }

                if (! empty($status)) {
                    $emails[] = [
                        'id'               => $msg['id'],
                        'subject'          => $msg['subject'] ?: '(No Subject)',
                        'from'             => $msg['from']['emailAddress']['address'] ?? 'Unknown',
                        'fromName'         => $msg['from']['emailAddress']['name'] ?? 'Unknown',
                        'receivedDateTime' => $msg['receivedDateTime'],
                        'isRead'           => $isRead,
                        'hasAttachments'   => $msg['hasAttachments'] ?? false,
                        'status'           => implode(', ', $status),
                        'webLink'          => $msg['webLink'] ?? "https://outlook.live.com/mail/0/inbox/id/{$msg['id']}",
                    ];
                }
            }

            return [
                'success'  => true,
                'page'     => $page,
                'per_page' => $perPage,
                'count'    => count($emails),
                'emails'   => $emails,
            ];

        } catch (\Exception $e) {
            Log::error('getUnrepliedEmails exception', ['error' => $e->getMessage()]);
            return [
                'success' => false,
                'error'   => $e->getMessage(),
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

            if (! $hasReply) {
                $unreplied[] = [
                    'id'               => $message['id'],
                    'subject'          => $message['subject'],
                    'from'             => $message['from']['emailAddress']['address'] ?? 'Unknown',
                    'fromName'         => $message['from']['emailAddress']['name'] ?? 'Unknown',
                    'receivedDateTime' => $message['receivedDateTime'],
                    'bodyPreview'      => $message['bodyPreview'],
                    'isRead'           => $message['isRead'],
                    'hasAttachments'   => $message['hasAttachments'] ?? false,
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
                    '$top'    => 1,
                    '$filter' => "conversationId eq '{$conversationId}'",
                    '$select' => 'id',
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
     * Normalize subject line for reliable comparison.
     */
    private function normalizeSubject($subject)
    {
        if (! $subject) {
            return '';
        }

        return strtolower(
            trim(
                preg_replace('/^(re|fw|fwd):\s*/i', '', $subject)
            )
        );
    }

    /**
     * Filter emails that haven't been replied to and/or are unread.
     * Supports Outlook.com + Microsoft 365 mailboxes.
     * Automatically detects the "Sent Items" folder ID if the default one fails.
     */
    private function filterUnrepliedEmailsWithStatus($messages, $accessToken)
    {
        try {
            if (empty($messages)) {
                return [];
            }

            // Collect conversationIds + normalized subjects from inbox messages
            $conversationIds = collect($messages)
                ->pluck('conversationId')
                ->filter()
                ->unique()
                ->values()
                ->toArray();

            $subjects = collect($messages)
                ->pluck('subject')
                ->filter()
                ->map(fn($s) => $this->normalizeSubject($s))
                ->unique()
                ->values()
                ->toArray();

            // 🔹 Step 1: Try fetching from the standard "SentItems" path
            $sentConversations = [];
            $sentSubjects      = [];
            $nextLink          = "{$this->microsoftBaseApi}/mailFolders/SentItems/messages?\$top=500&\$select=conversationId,subject";

            $res = Http::withToken($accessToken)->get($nextLink);

            // 🔹 Step 2: Fallback — if 404, find the actual Sent Items folder ID dynamically
            if ($res->status() === 404) {
                Log::warning('SentItems not found, fetching folder ID dynamically...');

                $folderRes = Http::withToken($accessToken)
                    ->get("{$this->microsoftBaseApi}/mailFolders?\$select=id,displayName");

                $folders = $folderRes->json()['value'] ?? [];

                $sentFolderId = collect($folders)
                    ->firstWhere('displayName', 'Sent Items')['id'] ?? collect($folders)->firstWhere('displayName', 'Sent Mail')['id'] ?? null;

                if ($sentFolderId) {
                    $nextLink = "{$this->microsoftBaseApi}/mailFolders/{$sentFolderId}/messages?\$top=500&\$select=conversationId,subject";
                    $res      = Http::withToken($accessToken)->get($nextLink);
                } else {
                    Log::error('No Sent Items folder found in mailbox');
                }
            }

            // 🔹 Step 3: Collect SentItems data (paginated)
            while ($res && $res->successful()) {
                $data      = $res->json();
                $pageItems = collect($data['value'] ?? []);

                $sentConversations = array_merge(
                    $sentConversations,
                    $pageItems->pluck('conversationId')->filter()->toArray()
                );

                $sentSubjects = array_merge(
                    $sentSubjects,
                    $pageItems->pluck('subject')
                        ->filter()
                        ->map(fn($s) => $this->normalizeSubject($s))
                        ->toArray()
                );

                $nextLink = $data['@odata.nextLink'] ?? null;
                if ($nextLink) {
                    $res = Http::withToken($accessToken)->get($nextLink);
                } else {
                    break;
                }
            }

            $sentConversations = array_unique($sentConversations);
            $sentSubjects      = array_unique($sentSubjects);

            // 🔹 Step 4: Classify inbox messages
            $filtered = [];

            foreach ($messages as $message) {
                $conversationId = $message['conversationId'] ?? null;
                $isRead         = $message['isRead'] ?? false;
                $subject        = $this->normalizeSubject($message['subject'] ?? '');

                $hasReply = in_array($conversationId, $sentConversations, true)
                || in_array($subject, $sentSubjects, true);

                $status = [];
                if (! $hasReply) {
                    $status[] = 'unreplied';
                }

                if (! $isRead) {
                    $status[] = 'unread';
                }

                if (empty($status)) {
                    continue;
                }

                $filtered[] = [
                    'id'               => $message['id'],
                    'subject'          => $message['subject'] ?? '(No Subject)',
                    'from'             => $message['from']['emailAddress']['address'] ?? 'Unknown',
                    'fromName'         => $message['from']['emailAddress']['name'] ?? 'Unknown',
                    'receivedDateTime' => $message['receivedDateTime'] ?? null,
                    'bodyPreview'      => $message['bodyPreview'] ?? '',
                    'isRead'           => $isRead,
                    'hasAttachments'   => $message['hasAttachments'] ?? false,
                    'status'           => implode(', ', $status),
                ];
            }

            return $filtered;
        } catch (\Exception $e) {
            Log::error('filterUnrepliedEmailsWithStatus exception', ['error' => $e->getMessage()]);
            return [];
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
                    '$select' => 'id,subject,from,receivedDateTime,body,bodyPreview,isRead,hasAttachments,toRecipients,ccRecipients',
                ]);

            if ($response->successful()) {
                return [
                    'success' => true,
                    'email'   => $response->json(),
                ];
            }

            return [
                'success' => false,
                'error'   => $response->json(),
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error'   => $e->getMessage(),
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

            $user->microsoft_access_token     = encrypt($tokenData['access_token']);
            $user->microsoft_refresh_token    = encrypt($tokenData['refresh_token']);
            $user->microsoft_token_expires_at = $expiresAt->toDateTimeString();

            // Update session
            $user->updateSession();
        }
    }
}
