<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;
use Throwable;

class UploadWorkerLauncher
{
    public function start(): void
    {
        if (app()->environment('testing') || ! config('uploads.autostart')) {
            return;
        }
        $heartbeat = storage_path('app/private/upload-worker.heartbeat');
        if (is_file($heartbeat) && time() - (int) file_get_contents($heartbeat) < 20) {
            return;
        }
        $php = config('uploads.php');
        if (! is_executable($php)) {
            Log::warning('Worker upload belum aktif: PHP CLI tidak tersedia.');

            return;
        }
        try {
            // Fixed server-owned arguments only. The worker takes an exclusive process lock.
            // Starting under Apache's identity preserves private-file permissions on XAMPP.
            $command = 'nohup '.escapeshellarg($php).' '.escapeshellarg(base_path('artisan')).' area3:work-uploads >> '.escapeshellarg(storage_path('logs/uploads-worker.log')).' 2>&1 < /dev/null &';
            $process = new Process(['/bin/sh', '-c', $command], base_path());
            $process->setTimeout(3)->mustRun();
        } catch (Throwable $error) {
            Log::warning('Worker upload gagal dimulai; antrean tetap tersimpan.', ['exception' => get_class($error)]);
        }
    }
}
