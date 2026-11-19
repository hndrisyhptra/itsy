<?php

namespace App\Services;

use App\Jobs\SendTelegramReminder;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TelegramReminders
{
    public function inWindow(object $settings): bool
    {
        if ($settings->all_day) {
            return true;
        }
        $time = now('Asia/Jakarta')->format('H:i');

        return $settings->start_time <= $settings->end_time
            ? $time >= $settings->start_time && $time < $settings->end_time
            : $time >= $settings->start_time || $time < $settings->end_time;
    }

    public function collect(): int
    {
        app(TelegramAccounts::class)->ready();
        DB::table('telegram_settings')->where('id_telegram_setting', 1)->update(['scheduler_at' => now()]);
        $settings = DB::table('telegram_settings')->first();
        if (! $settings->enabled || ! config('telegram.token') || ! $this->inWindow($settings)) {
            return 0;
        }
        $buffer = [];
        $flush = function () use (&$buffer) {
            if ($buffer) {
                DB::table('approval_notification_tasks')->insertOrIgnore($buffer);
                $buffer = [];
            }
        };
        app(TelegramPendingApprovals::class)->each(function ($target) use (&$buffer, $flush) {
            $buffer[] = ['id_approval_notification' => (string) Str::uuid(), 'target_key' => $target['key'], 'user_id' => $target['user_id'], 'kind' => $target['kind'], 'target_id' => $target['target_id'], 'next_remind_at' => now()];
            if (count($buffer) >= 300) {
                $flush();
            }
        });
        $flush();
        $count = 0;
        DB::table('approval_notification_tasks as t')->join('user_telegram_accounts as a', 'a.user_id', '=', 't.user_id')->join('users as u', 'u.id_user', '=', 't.user_id')
            ->where('u.active', true)->whereNull('t.closed_at')->where('t.next_remind_at', '<=', now())->whereNotNull('a.confirmed_at')->where('a.blocked', false)
            ->select('t.user_id')->distinct()->orderBy('t.user_id')->chunkById(100, function ($rows) use (&$count) {
                foreach ($rows as $r) {
                    SendTelegramReminder::dispatch((int) $r->user_id);
                    $count++;
                }
            }, 't.user_id', 'user_id');

        return $count;
    }

    public function send(int $id, ?string $testLog = null, ?string $binding = null): void
    {
        DB::table('telegram_settings')->where('id_telegram_setting', 1)->update(['worker_at' => now()]);
        $account = app(AccountAccess::class)->context(User::find($id));
        $connection = DB::table('user_telegram_accounts')->where('user_id', $id)->whereNotNull('confirmed_at')->where('blocked', false)->first();
        if (! $account || ! $connection || ($binding && $connection->binding_version !== $binding) || ! config('telegram.token')) {
            if ($testLog) {
                DB::table('telegram_notification_logs')->where('id_telegram_notification_log', $testLog)->where('status', 'queued')->update(['status' => 'cancelled', 'detail' => 'Koneksi atau akses pengguna berubah.']);
            }

            return;
        }
        $settings = DB::table('telegram_settings')->first();
        $tasks = collect();
        $current = [];
        if (! $testLog) {
            if (! $settings->enabled || ! $this->inWindow($settings)) {
                return;
            }
            app(TelegramPendingApprovals::class)->each(function ($target) use (&$current) {
                $current[$target['key']] = $target;
            }, $id);
            $tasks = DB::table('approval_notification_tasks')->where('user_id', $id)->whereNull('closed_at')->where('next_remind_at', '<=', now())->get();
            $stale = $tasks->filter(fn ($t) => ! isset($current[$t->target_key]));
            foreach ($stale->chunk(200) as $batch) {
                DB::table('approval_notification_tasks')->whereIn('id_approval_notification', $batch->pluck('id_approval_notification'))->update(['closed_at' => now()]);
            }
            $tasks = $tasks->filter(fn ($t) => isset($current[$t->target_key]));
            if ($tasks->isEmpty()) {
                return;
            }
        }
        $log = $testLog ?? app(TelegramAccounts::class)->log($id, 'reminder', 'queued', $tasks->count().' pengajuan jatuh tempo.');
        if (! DB::table('telegram_notification_logs')->where('id_telegram_notification_log', $log)->where('user_id', $id)->where('status', 'queued')->update(['status' => 'sending'])) {
            return;
        }
        // Reserve this hourly slot BEFORE HTTP; a crashed/ambiguous attempt is never blindly replayed.
        foreach ($tasks->chunk(200) as $batch) {
            DB::table('approval_notification_tasks')->whereIn('id_approval_notification', $batch->pluck('id_approval_notification'))->update(['next_remind_at' => now()->addHour()]);
        }
        $pp = $tasks->where('kind', 'project')->count();
        $payment = $tasks->where('kind', 'payment')->count();
        $text = $testLog ? 'IT’S AREA 3 — Uji koneksi Telegram berhasil. Pesan ini tidak mengubah approval Anda.' : strtr($settings->message_template, ['{name}' => $account['name'], '{count}' => (string) $tasks->count(), '{pp_count}' => (string) $pp, '{payment_count}' => (string) $payment]);
        try {
            $result = app(TelegramApi::class)->send(Crypt::decryptString($connection->chat_cipher), $text);
        } catch (\Throwable) {
            $result = ['ok' => false, 'code' => 0];
        }
        $ok = $result['ok'];
        $code = $result['code'] ?? 0;
        DB::table('telegram_notification_logs')->where('id_telegram_notification_log', $log)->update(['status' => $ok ? 'sent' : ($code === 0 ? 'unknown' : 'failed'), 'telegram_message_id' => $ok ? (string) ($result['result']['message_id'] ?? '') : null, 'detail' => $ok ? ($testLog ? 'Pesan uji diterima Telegram.' : $tasks->count().' pengajuan; pesan diterima Telegram.') : ($code === 0 ? 'Hasil kirim tidak pasti; tidak dicoba ulang otomatis pada slot ini.' : 'Telegram menolak pengiriman (kode '.$code.').')]);
        if ($ok) {
            foreach ($tasks->chunk(200) as $batch) {
                DB::table('approval_notification_tasks')->whereIn('id_approval_notification', $batch->pluck('id_approval_notification'))->update(['sent_at' => now()]);
            }
        }
        if (in_array($code, [400, 403], true)) {
            DB::table('user_telegram_accounts')->where('user_id', $id)->where('binding_version', $connection->binding_version)->update(['blocked' => true]);
        }
        if ($code === 429 && ! $testLog) {
            foreach ($tasks->chunk(200) as $batch) {
                DB::table('approval_notification_tasks')->whereIn('id_approval_notification', $batch->pluck('id_approval_notification'))->update(['next_remind_at' => now()->addSeconds($result['retry_after'] ?? 60)]);
            }
        }
    }
}
