<?php

use App\Support\Area3MigrationTarget;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;

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
