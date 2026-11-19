<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Tokens/URLs/Telegram response bodies are never logged or returned to browsers. */
class TelegramApi
{
    public function call(string $method, array $payload): array
    {
        if (! in_array($method, ['sendMessage', 'getMe', 'getWebhookInfo', 'setWebhook'], true) || ! config('telegram.token')) {
            throw new RuntimeException('Telegram belum dikonfigurasi.');
        }
        try {
            $response = Http::connectTimeout(5)->timeout(15)->asJson()->post('https://api.telegram.org/bot'.config('telegram.token').'/'.$method, $payload);
        } catch (\Throwable) {
            // Network timeouts are ambiguous: do not automatically resend the same message.
            return ['ok' => false, 'code' => 0, 'retry_after' => null];
        }
        $data = $response->json();
        if (! $response->successful() || ! is_array($data) || ! ($data['ok'] ?? false)) {
            return ['ok' => false, 'code' => (int) ($data['error_code'] ?? $response->status()), 'retry_after' => isset($data['parameters']['retry_after']) ? min(86400, max(1, (int) $data['parameters']['retry_after'])) : null];
        }

        return ['ok' => true, 'result' => $data['result'] ?? []];
    }

    public function send(string $chat, string $text): array
    {
        $url = rtrim((string) config('telegram.app_url'), '/').'/inbox';
        if (! filter_var($url, FILTER_VALIDATE_URL) || parse_url($url, PHP_URL_SCHEME) !== 'https') {
            throw new RuntimeException('TELEGRAM_APP_URL harus alamat HTTPS aplikasi.');
        }

        return $this->call('sendMessage', ['chat_id' => $chat, 'text' => $text, 'protect_content' => true, 'link_preview_options' => ['is_disabled' => true],
            'reply_markup' => ['inline_keyboard' => [[['text' => 'Buka Inbox Approval', 'url' => $url]]]]]);
    }
}
