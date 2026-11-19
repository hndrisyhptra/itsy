<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class TelegramAccounts
{
    public function ready(): void
    {
        abort_unless(Schema::hasTable('telegram_settings'), 503, 'Jalankan migration Telegram terlebih dahulu.');
    }

    public function actor(int $id, bool $admin = false): array
    {
        $user = User::findOrFail($id);
        $context = app(AccountAccess::class)->context($user);
        abort_unless($context && (! $admin || $context['code'] === 'SUPERADMIN'), 403, 'Akses Telegram tidak diizinkan.');

        return $context;
    }

    public function log(?int $user, string $event, string $status, string $detail, ?string $id = null): string
    {
        $id ??= (string) Str::uuid();
        DB::table('telegram_notification_logs')->insert(['id_telegram_notification_log' => $id, 'user_id' => $user, 'event' => $event, 'status' => $status, 'detail' => $detail, 'created_at' => now()]);

        return $id;
    }

    public function profile(int $id): array
    {
        $this->ready();
        $this->actor($id);
        $row = DB::table('user_telegram_accounts')->where('user_id', $id)->first(['telegram_name', 'telegram_username', 'binding_version', 'confirmed_at', 'blocked']);

        return ['configured' => (bool) (config('telegram.token') && preg_match('/^[a-zA-Z0-9_]{5,32}$/D', (string) config('telegram.username'))), 'account' => $row ? (array) $row : null];
    }

    public function link(int $id): array
    {
        return DB::transaction(function () use ($id) {
            User::whereKey($id)->lockForUpdate()->firstOrFail();
            $this->actor($id);
            abort_unless(config('telegram.token') && preg_match('/^[a-zA-Z0-9_]{5,32}$/D', (string) config('telegram.username')), 422, 'Bot belum dikonfigurasi oleh pengelola server.');
            $existing = DB::table('user_telegram_accounts')->where('user_id', $id)->first();
            abort_if($existing && $existing->confirmed_at, 409, 'Putuskan koneksi lama sebelum menghubungkan akun baru.');
            DB::table('user_telegram_accounts')->where('user_id', $id)->whereNull('confirmed_at')->delete();
            $token = Str::random(48);
            DB::table('telegram_link_tokens')->updateOrInsert(['user_id' => $id], ['id_telegram_link_token' => (string) Str::uuid(), 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addMinutes(10), 'consumed_at' => null]);
            $this->log($id, 'link', 'created', 'Tautan pengaitan dibuat, berlaku 10 menit.');

            return ['url' => 'https://t.me/'.config('telegram.username').'?start='.$token, 'message' => 'Buka Telegram, tekan Start, lalu muat status dan konfirmasikan akun di sini.'];
        });
    }

    public function webhook(array $data): void
    {
        $m = $data['message'] ?? [];
        $chat = $m['chat'] ?? [];
        $from = $m['from'] ?? [];
        if (($chat['type'] ?? '') !== 'private' || ($from['is_bot'] ?? true) || ! preg_match('/^[1-9][0-9]{0,18}$/D', (string) ($chat['id'] ?? '')) || (string) $chat['id'] !== (string) ($from['id'] ?? '') || ! preg_match('/^\/start ([A-Za-z0-9]{48})$/D', $m['text'] ?? '', $match)) {
            return;
        }
        DB::transaction(function () use ($match, $chat, $from) {
            $token = DB::table('telegram_link_tokens')->where('token_hash', hash('sha256', $match[1]))->first();
            if (! $token || $token->consumed_at || now()->greaterThan($token->expires_at)) {
                return;
            }
            // The same user lock serializes confirmation/disconnection and link callbacks.
            User::whereKey($token->user_id)->lockForUpdate()->firstOrFail();
            // Match lock order used by link/confirm: user first, then the token.
            $token = DB::table('telegram_link_tokens')->where('token_hash', hash('sha256', $match[1]))->lockForUpdate()->first();
            if (! $token || $token->consumed_at || now()->greaterThan($token->expires_at) || ! User::whereKey($token->user_id)->where('active', true)->exists()) {
                return;
            }
            $this->actor((int) $token->user_id);
            $hash = hash_hmac('sha256', (string) $chat['id'], (string) config('app.key'));
            if (DB::table('user_telegram_accounts')->where('chat_hash', $hash)->where('user_id', '<>', $token->user_id)->exists() || DB::table('user_telegram_accounts')->where('user_id', $token->user_id)->whereNotNull('confirmed_at')->exists()) {
                return;
            }
            $name = Str::limit(preg_replace('/[\p{C}]/u', '', (string) ($from['first_name'] ?? 'Telegram')), 140, '');
            DB::table('user_telegram_accounts')->updateOrInsert(['user_id' => $token->user_id], ['id_user_telegram' => (string) Str::uuid(), 'chat_hash' => $hash, 'chat_cipher' => Crypt::encryptString((string) $chat['id']), 'telegram_name' => $name ?: 'Telegram', 'telegram_username' => Str::limit((string) ($from['username'] ?? ''), 90, ''), 'binding_version' => (string) Str::uuid(), 'confirmed_at' => null, 'blocked' => false, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('telegram_link_tokens')->where('user_id', $token->user_id)->update(['consumed_at' => now()]);
            $this->log((int) $token->user_id, 'link', 'pending', 'Telegram diterima; menunggu konfirmasi pengguna di aplikasi.');
        });
    }

    public function change(int $id, string $action, ?string $binding): void
    {
        DB::transaction(function () use ($id, $action, $binding) {
            User::whereKey($id)->lockForUpdate()->firstOrFail();
            $this->actor($id);
            $q = DB::table('user_telegram_accounts')->where('user_id', $id);
            $row = $q->first();
            abort_unless($row && hash_equals($row->binding_version, (string) $binding), 409, 'Koneksi berubah. Muat ulang status Telegram.');
            if ($action === 'confirm') {
                $token = DB::table('telegram_link_tokens')->where('user_id', $id)->first();
                abort_unless($token && $token->consumed_at && now()->lessThanOrEqualTo($token->expires_at), 422, 'Pengaitan kedaluwarsa. Buat tautan baru.');
                $q->update(['confirmed_at' => now(), 'updated_at' => now()]);
            } else {
                $q->delete();
                DB::table('telegram_link_tokens')->where('user_id', $id)->delete();
            }
            $this->log($id, $action, 'success', $action === 'confirm' ? 'Pengguna mengonfirmasi akun Telegram.' : 'Pengguna memutuskan koneksi Telegram.');
        });
    }
}
