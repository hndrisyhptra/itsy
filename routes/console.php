<?php

use App\Services\TelegramAccounts;
use App\Services\TelegramApi;
use App\Services\TelegramDiagnostics;
use App\Services\TelegramReminders;
use App\Support\Area3MigrationTarget;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Schema;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('area3:work-uploads {--once} {--stop-when-empty}', function () {
    Area3MigrationTarget::assertAllowed();
    $heartbeat = storage_path('app/private/upload-worker.heartbeat');
    $lock = fopen(storage_path('app/private/upload-worker.lock'), 'c');
    if (! $lock || ! flock($lock, LOCK_EX | LOCK_NB)) {
        $this->comment('Worker upload sudah berjalan.');

        return 0;
    }
    Queue::looping(function () use ($heartbeat) {
        file_put_contents($heartbeat, (string) time(), LOCK_EX);
    });
    $this->info('Worker upload Area 3 aktif (database its; queue area3-uploads).');

    return $this->call('queue:work', ['connection' => 'uploads', '--queue' => 'area3-uploads', '--sleep' => 2, '--tries' => 3,
        '--timeout' => 90, '--memory' => 256, '--once' => $this->option('once'), '--stop-when-empty' => $this->option('stop-when-empty')]);
})->purpose('Proses antrean BOQ dan eviden privat Area 3');

Artisan::command('area3:work-notifications {--once} {--stop-when-empty}', function () {
    Area3MigrationTarget::assertAllowed();
    app(TelegramAccounts::class)->ready();
    $this->info('Worker notifikasi Area 3 aktif (queue area3-notifications).');

    return $this->call('queue:work', ['connection' => 'notifications', '--queue' => 'area3-notifications', '--sleep' => 3, '--tries' => 1,
        '--timeout' => 90, '--memory' => 256, '--once' => $this->option('once'), '--stop-when-empty' => $this->option('stop-when-empty')]);
})->purpose('Proses notifikasi Telegram terpisah dari upload');

Artisan::command('area3:telegram-status', function () {
    $this->line(json_encode(app(TelegramDiagnostics::class)->report(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
})->purpose('Diagnosis baca-saja reminder Telegram tanpa mengirim pesan atau membuka data privat');

Artisan::command('area3:telegram-reminders', function () {
    Area3MigrationTarget::assertAllowed();
    $count = Cache::lock('area3-telegram-collect', 120)->get(fn () => app(TelegramReminders::class)->collect());
    $this->info('Pemeriksaan Telegram selesai; penerima antrean: '.($count ?? 0));
})->purpose('Periksa approval aktif dan antrekan reminder Telegram satu jam');

Artisan::command('area3:telegram-webhook {--check}', function () {
    Area3MigrationTarget::assertAllowed();
    $url = rtrim((string) config('telegram.app_url'), '/').'/api/telegram/webhook';
    $secret = (string) config('telegram.webhook_secret');
    if (! filter_var($url, FILTER_VALIDATE_URL) || parse_url($url, PHP_URL_SCHEME) !== 'https' || strlen($secret) < 32 || ! preg_match('/^[A-Za-z0-9_-]{32,256}$/D', $secret)) {
        $this->error('Isi TELEGRAM_APP_URL HTTPS dan TELEGRAM_WEBHOOK_SECRET 32–256 karakter huruf/angka/_/- di konfigurasi server.');

        return 1;
    }
    $api = app(TelegramApi::class);
    $result = $api->call($this->option('check') ? 'getWebhookInfo' : 'setWebhook', $this->option('check') ? [] : ['url' => $url, 'secret_token' => $secret, 'allowed_updates' => ['message'], 'drop_pending_updates' => false]);
    $ok = $result['ok'] && (! $this->option('check') || ($result['result']['url'] ?? '') === $url);
    $this->line($ok ? 'Webhook Telegram terdaftar sesuai alamat aplikasi.' : 'Webhook belum sesuai/berhasil. Periksa konfigurasi dan akses HTTPS.');

    return $ok ? 0 : 1;
})->purpose('Daftarkan atau periksa webhook Telegram, tanpa mencetak token');

Schedule::command('area3:telegram-reminders')->everyMinute()->withoutOverlapping(2)
    ->when(fn () => Schema::hasTable('telegram_settings'));
