<?php

namespace App\Services;

use App\Models\MailAccount;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Http;

class MailProvider
{
    public function exchange(string $provider, array $fields): array
    {
        $config = config("expenses.$provider");

        return Http::asForm()->timeout(30)->post($config['token'], array_merge([
            'client_id' => $config['client_id'], 'client_secret' => $config['client_secret'],
            'redirect_uri' => $config['redirect_uri'],
        ], $fields))->throw()->json();
    }

    public function profile(string $provider, string $token): string
    {
        $url = $provider === 'gmail' ? 'https://gmail.googleapis.com/gmail/v1/users/me/profile' : 'https://graph.microsoft.com/v1.0/me';
        $data = Http::withToken($token)->timeout(30)->get($url)->throw()->json();

        return $data['emailAddress'] ?? $data['mail'] ?? $data['userPrincipalName'];
    }

    public function page(MailAccount $account): array
    {
        $this->ensureToken($account);
        $http = $this->authenticated($account);
        $since = CarbonImmutable::parse(config('expenses.import_since'), 'America/Costa_Rica')->startOfDay();
        $window = $account->last_synced_at ? $account->last_synced_at->copy()->subDays(2)->toImmutable()->max($since) : $since;
        if ($account->provider === 'gmail') {
            $params = ['maxResults' => 10, 'q' => 'after:'.($window->timestamp - 1)];
            if ($account->sync_cursor) {
                $params['pageToken'] = $account->sync_cursor;
            }
            $page = $http->get('https://gmail.googleapis.com/gmail/v1/users/me/messages', $params)->throw()->json();
            $messages = [];
            foreach ($page['messages'] ?? [] as $item) {
                $response = $http->get('https://gmail.googleapis.com/gmail/v1/users/me/messages/'.rawurlencode($item['id']), ['format' => 'full']);
                if ($response->status() === 404) {
                    continue;
                }
                $message = $response->throw()->json();
                if (CarbonImmutable::createFromTimestampMs($message['internalDate'])->lt($since)) {
                    continue;
                }
                $headers = collect($message['payload']['headers'] ?? [])->mapWithKeys(fn ($h) => [strtolower($h['name']) => $h['value']]);
                $subject = $headers->get('subject', '');
                $body = $this->body($message['payload']);
                $attachments = [];
                if (preg_match('/estado\s+(?:de\s+)?cuenta|account statement/iu', $subject.' '.$body)) {
                    $attachments = $this->gmailAttachments($message['payload'], $message['id'], $http);
                }
                $messages[] = ['id' => $message['id'], 'subject' => $subject, 'received_at' => CarbonImmutable::createFromTimestampMs($message['internalDate'])->toIso8601String(), 'body' => $body, 'attachments' => $attachments];
            }

            return ['messages' => $messages, 'cursor' => $page['nextPageToken'] ?? null];
        }
        $url = $account->sync_cursor ?: 'https://graph.microsoft.com/v1.0/me/messages';
        // Never send a bearer token to a host supplied by a pagination response.
        abort_unless(parse_url($url, PHP_URL_HOST) === 'graph.microsoft.com' && parse_url($url, PHP_URL_SCHEME) === 'https', 422);
        $params = $account->sync_cursor ? [] : ['$top' => 50, '$select' => 'id,subject,receivedDateTime,body', '$orderby' => 'receivedDateTime desc'];
        if (! $account->sync_cursor) {
            $params['$filter'] = 'receivedDateTime ge '.$window->utc()->toIso8601String();
        }
        $page = $http->withHeaders(['Prefer' => 'IdType="ImmutableId"'])->get($url, $params)->throw()->json();

        return ['messages' => array_map(fn ($m) => ['id' => $m['id'], 'subject' => $m['subject'], 'received_at' => $m['receivedDateTime'], 'body' => $m['body']['content']], $page['value'] ?? []), 'cursor' => $page['@odata.nextLink'] ?? null];
    }

    private function ensureToken(MailAccount $account): void
    {
        if (! $account->expires_at || $account->expires_at->lte(now()->addMinute())) {
            $this->refresh($account);
        }
    }

    private function refresh(MailAccount $account): void
    {
        if (! $account->refresh_token) {
            $this->reconnect();
        }
        try {
            $tokens = $this->exchange($account->provider, ['grant_type' => 'refresh_token', 'refresh_token' => $account->refresh_token]);
        } catch (RequestException $exception) {
            if (in_array($exception->response->status(), [400, 401, 403])) {
                $this->reconnect();
            }
            throw $exception;
        }
        $account->update(['access_token' => $tokens['access_token'], 'refresh_token' => $tokens['refresh_token'] ?? $account->refresh_token, 'expires_at' => now()->addSeconds($tokens['expires_in'])]);
    }

    private function reconnect(): never
    {
        throw new HttpResponseException(response()->json(['message' => 'El proveedor de correo requiere una nueva autorización. Vuelve a conectar tu correo.', 'reconnect' => true], 409));
    }

    private function authenticated(MailAccount $account): PendingRequest
    {
        return Http::withToken($account->access_token)->timeout(30)->retry(2, 0, function ($exception, $request) use ($account) {
            if (! $exception instanceof RequestException || $exception->response->status() !== 401) {
                return false;
            }
            $this->refresh($account);
            $request->withToken($account->access_token);

            return true;
        }, throw: false);
    }

    private function body(array $part): string
    {
        if (isset($part['body']['data']) && in_array($part['mimeType'] ?? '', ['text/plain', 'text/html'])) {
            return base64_decode(strtr($part['body']['data'], '-_', '+/')) ?: '';
        }
        $parts = $part['parts'] ?? [];
        // BN's plain-text alternative omits the merchant; its HTML contains the complete receipt.
        foreach ($parts as $child) {
            if (in_array($child['mimeType'] ?? '', ['text/html', 'multipart/related'])) {
                $html = $this->body($child);
                if (trim($html) !== '') {
                    return $html;
                }
            }
        }
        foreach ($parts as $child) {
            if (($child['mimeType'] ?? '') === 'text/plain') {
                $plain = $this->body($child);
                if (trim($plain) !== '') {
                    return $plain;
                }
            }
        }

        return implode("\n", array_map(fn ($child) => $this->body($child), $parts));
    }

    public function receipt(MailAccount $account, string $messageId): ?array
    {
        abort_unless($account->provider === 'gmail', 422);
        $this->ensureToken($account);
        $response = $this->authenticated($account)->get('https://gmail.googleapis.com/gmail/v1/users/me/messages/'.rawurlencode($messageId), ['format' => 'full']);
        if ($response->status() === 404) {
            return null;
        }
        $message = $response->throw()->json();
        $headers = collect($message['payload']['headers'] ?? [])->mapWithKeys(fn ($header) => [strtolower($header['name']) => $header['value']]);

        return ['subject' => $headers->get('subject', ''), 'body' => $this->body($message['payload']), 'received_at' => CarbonImmutable::createFromTimestampMs($message['internalDate'])->toIso8601String()];
    }

    private function gmailAttachments(array $part, string $messageId, $http): array
    {
        $attachments = [];
        if (str_ends_with(strtolower($part['filename'] ?? ''), '.pdf')) {
            $attachmentId = $part['body']['attachmentId'] ?? $part['partId'] ?? $part['filename'];
            $size = $part['body']['size'] ?? 0;
            $bytes = null;
            if ($size <= 10000000) {
                $data = $part['body']['data'] ?? null;
                if (! $data && isset($part['body']['attachmentId'])) {
                    $data = $http->get('https://gmail.googleapis.com/gmail/v1/users/me/messages/'.rawurlencode($messageId).'/attachments/'.rawurlencode($attachmentId))->throw()->json('data');
                }
                if ($data) {
                    $bytes = base64_decode(strtr($data, '-_', '+/'), true) ?: null;
                }
                if ($bytes !== null && strlen($bytes) > 10000000) {
                    $bytes = null;
                }
            }
            $attachments[] = ['key' => hash('sha256', $attachmentId), 'filename' => mb_substr(basename($part['filename']), 0, 255), 'bytes' => $bytes];
        }
        foreach ($part['parts'] ?? [] as $child) {
            $attachments = array_merge($attachments, $this->gmailAttachments($child, $messageId, $http));
        }

        return $attachments;
    }
}
