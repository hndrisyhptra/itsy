<?php

namespace Tests\Feature;

use App\Services\UploadWorkerLauncher;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Tests\TestCase;

class UploadWorkerLauncherTest extends TestCase
{
    private string $workerStorage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workerStorage = sys_get_temp_dir().'/area3-worker-test-'.Str::uuid();
        $this->app->useStoragePath($this->workerStorage);
        config(['uploads.autostart' => true, 'uploads.php' => PHP_BINARY]);
        Process::fake();
        Process::preventStrayProcesses();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->workerStorage);
        parent::tearDown();
    }

    public function test_local_apache_launcher_ignores_hup_and_redirects_streams_without_requiring_a_console(): void
    {
        // The process facade is faked before changing env; no worker is spawned.
        $this->app->instance('env', 'local');
        app(UploadWorkerLauncher::class)->start();
        Process::assertRan(function (PendingProcess $process) {
            $command = $process->command;

            return $command[0] === '/bin/sh' && $command[1] === '-c'
                && str_starts_with($command[2], "trap '' HUP; ")
                && ! str_contains($command[2], 'nohup ')
                && str_contains($command[2], escapeshellarg(PHP_BINARY).' '.escapeshellarg(base_path('artisan')).' area3:work-uploads')
                && str_ends_with($command[2], ' 2>&1 < /dev/null &')
                && $process->path === base_path() && $process->timeout === 3;
        });
    }

    public function test_testing_disabled_autostart_and_recent_heartbeat_never_spawn_another_worker(): void
    {
        app(UploadWorkerLauncher::class)->start();
        $this->app->instance('env', 'local');
        config(['uploads.autostart' => false]);
        app(UploadWorkerLauncher::class)->start();
        config(['uploads.autostart' => true]);
        File::ensureDirectoryExists(storage_path('app/private'));
        File::put(storage_path('app/private/upload-worker.heartbeat'), (string) time());
        app(UploadWorkerLauncher::class)->start();
        Process::assertNothingRan();
    }

    public function test_launcher_failure_is_logged_without_discarding_the_queued_file_or_exposing_server_output(): void
    {
        $this->app->instance('env', 'local');
        Process::fake(fn () => Process::result(errorOutput: 'private server error', exitCode: 1));
        Log::shouldReceive('warning')->once()->with('Worker upload gagal dimulai; antrean tetap tersimpan.', \Mockery::on(fn ($data) => isset($data['exception']) && ! str_contains(json_encode($data), 'private server error')));
        app(UploadWorkerLauncher::class)->start();
        Process::assertRanTimes(fn () => true, 1);
    }
}
