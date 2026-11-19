<?php

namespace App\Services;

use App\Support\Area3MigrationTarget;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/** Operator-only, read-only aggregate output. No chat decryption, job payloads or document bodies. */
class TelegramDiagnostics
{
    public function report(): array
    {
        Area3MigrationTarget::assertAllowed();
        $now = now();
        $out = ['read_only' => true, 'php' => PHP_VERSION, 'database_driver' => DB::getDriverName(),
            'configuration_cached' => app()->configurationIsCached(), 'time_wib' => $now->copy()->timezone('Asia/Jakarta')->format('Y-m-d H:i:s'),
            'token_configured_in_cli' => (bool) config('telegram.token'), 'findings' => []];
        if (! Schema::hasTable('telegram_settings')) {
            $out['findings'][] = 'TELEGRAM_MIGRATION_MISSING';

            return $out;
        }
        $settings = DB::table('telegram_settings')->where('id_telegram_setting', 1)->first(['enabled', 'all_day', 'start_time', 'end_time', 'scheduler_at', 'worker_at']);
        if (! $settings) {
            $out['findings'][] = 'TELEGRAM_SETTINGS_MISSING';

            return $out;
        }
        $window = app(TelegramReminders::class)->inWindow($settings);
        $out['settings'] = ['enabled' => (bool) $settings->enabled, 'all_day' => (bool) $settings->all_day,
            'start_time' => $settings->start_time, 'end_time' => $settings->end_time, 'inside_send_window' => $window];
        foreach (['scheduler', 'worker'] as $kind) {
            $value = $settings->{$kind.'_at'};
            $out[$kind] = ['last_activity_utc' => $value, 'seconds_since_activity' => $value ? (int) Carbon::parse($value, 'UTC')->diffInSeconds($now, false) : null];
        }
        if (! $settings->enabled) {
            $out['findings'][] = 'REMINDER_DISABLED';
        }
        if (! $out['token_configured_in_cli']) {
            $out['findings'][] = 'CLI_TOKEN_NOT_CONFIGURED';
        }
        if (! $window) {
            $out['findings'][] = 'OUTSIDE_SEND_WINDOW';
        }
        if ($out['scheduler']['seconds_since_activity'] === null || $out['scheduler']['seconds_since_activity'] > 600) {
            $out['findings'][] = 'SCHEDULER_ACTIVITY_NOT_RECENT';
        }
        $out['sql_candidates'] = [
            'submitted_pp' => DB::table('project_plans')->where('status', 'SUBMITTED')->count(),
            'submitted_payments' => DB::table('payment_terms')->where('status', 'SUBMITTED')->count(),
            'pending_approval_runs' => DB::table('approval_runs')->where('status', 'PENDING')->count(),
            'finance_pics' => DB::table('telegram_finance_pics')->count(),
        ];
        $targets = ['project' => 0, 'payment' => 0];
        $receivers = [];
        try {
            app(TelegramPendingApprovals::class)->each(function ($target) use (&$targets, &$receivers) {
                $targets[$target['kind']]++;
                $receivers[$target['user_id']] = true;
            });
            $out['resolver'] = ['ok' => true, 'targets' => $targets, 'recipients' => count($receivers)];
        } catch (Throwable $e) {
            $receivers = []; // Do not draw recipient conclusions from a partially resolved catalogue.
            // Raw exceptions may contain SQL bindings/PII or configuration. Report safe identifiers only.
            $out['resolver'] = ['ok' => false, 'exception_class' => class_basename($e),
                'sqlstate' => $e instanceof QueryException && preg_match('/^[A-Z0-9]{5}$/D', (string) ($e->errorInfo[0] ?? '')) ? $e->errorInfo[0] : null];
            $out['findings'][] = 'APPROVAL_TARGET_RESOLVER_FAILED';
        }
        $connected = 0;
        foreach (array_chunk(array_keys($receivers), 300) as $ids) {
            $connected += DB::table('user_telegram_accounts')->whereIn('user_id', $ids)->whereNotNull('confirmed_at')->where('blocked', false)->count();
        }
        $out['connected_current_recipients'] = $connected;
        if (($out['resolver']['ok'] ?? false) && ! array_sum($targets)) {
            $out['findings'][] = 'NO_CURRENT_APPROVAL_TARGETS';
        } elseif (($out['resolver']['ok'] ?? false) && ! $connected) {
            $out['findings'][] = 'NO_CONNECTED_CURRENT_RECIPIENTS';
        }
        $tasks = DB::table('approval_notification_tasks')->selectRaw('COUNT(*) as total, SUM(CASE WHEN closed_at IS NULL THEN 1 ELSE 0 END) as open_count, SUM(CASE WHEN closed_at IS NULL AND next_remind_at <= ? THEN 1 ELSE 0 END) as due_count, MIN(CASE WHEN closed_at IS NULL THEN next_remind_at END) as next_due', [$now])->first();
        $out['tasks'] = ['total' => (int) $tasks->total, 'open' => (int) $tasks->open_count, 'due' => (int) $tasks->due_count, 'next_due_utc' => $tasks->next_due];
        $out['queue'] = ['pending_jobs' => DB::table('jobs')->where('queue', 'area3-notifications')->count(),
            'failed_jobs_last_24h' => DB::table('failed_jobs')->where('queue', 'area3-notifications')->where('failed_at', '>=', $now->copy()->subDay())->count()];
        $out['reminder_logs_last_24h'] = DB::table('telegram_notification_logs')->where('event', 'reminder')->where('created_at', '>=', $now->copy()->subDay())
            ->select('status')->selectRaw('COUNT(*) as count')->groupBy('status')->orderBy('status')->get()->map(fn ($r) => ['status' => $r->status, 'count' => (int) $r->count])->all();
        if ($connected && ! $out['tasks']['open']) {
            $out['findings'][] = 'CURRENT_TARGETS_NOT_COLLECTED';
        }
        if ($out['queue']['pending_jobs']) {
            $out['findings'][] = 'NOTIFICATION_JOBS_PENDING';
        }
        if ($out['queue']['failed_jobs_last_24h']) {
            $out['findings'][] = 'NOTIFICATION_JOBS_FAILED';
        }
        $out['note'] = 'Aktivitas scheduler bukan bukti koleksi selesai; hasil ini tidak mengirim pesan, memproses antrean atau mengubah status.';

        return $out;
    }
}
