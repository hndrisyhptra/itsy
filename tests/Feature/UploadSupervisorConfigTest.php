<?php

namespace Tests\Feature;

use App\Jobs\ProcessUpload;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class UploadSupervisorConfigTest extends TestCase
{
    public function test_generated_config_runs_existing_heartbeat_worker_with_matching_timing_and_private_os_identity_without_sql_or_processes(): void
    {
        Process::fake();
        Process::preventStrayProcesses();
        DB::enableQueryLog();
        DB::flushQueryLog();
        $exit = Artisan::call('area3:supervisor-config', ['--user' => 'www']);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $config = Artisan::output();
        $this->assertSame(0, $exit);
        $this->assertSame([], $queries);
        Process::assertNothingRan();
        $this->assertStringStartsWith('[program:area3-uploads]', $config);
        $this->assertStringContainsString('directory='.base_path(), $config);
        $this->assertStringContainsString('command='.PHP_BINARY.' '.base_path().'/artisan area3:work-uploads --no-interaction', $config);
        $this->assertStringNotContainsString('queue:work', $config);
        $this->assertStringNotContainsString('{{', $config);
        $this->assertStringContainsString('user=www', $config);
        $this->assertStringContainsString('umask=0077', $config);
        $this->assertStringContainsString('numprocs=1', $config);
        $this->assertStringContainsString('autorestart=true', $config);
        $this->assertStringContainsString('autostart=true', $config);
        $this->assertStringContainsString('stopasgroup=true', $config);
        $this->assertStringContainsString('killasgroup=true', $config);
        $this->assertStringContainsString('stdout_logfile='.storage_path('logs/uploads-worker.log'), $config);
        $this->assertStringContainsString('stdout_logfile_maxbytes=10MB', $config);
        $this->assertStringContainsString('stdout_logfile_backups=5', $config);
        preg_match('/^stopwaitsecs=(\d+)$/m', $config, $stop);
        $job = new ProcessUpload('not-dispatched');
        $this->assertGreaterThan($job->timeout, (int) $stop[1]);
        $this->assertLessThan(config('queue.connections.uploads.retry_after'), $job->timeout);
        $this->assertSame('uploads', $job->connection);
        $this->assertSame('area3-uploads', $job->queue);
    }

    public function test_missing_root_and_injected_os_users_fail_without_emitting_executable_configuration(): void
    {
        foreach ([null, 'root', 'ROOT', "www\ncommand=bad", 'www;bad', 'www%bad', 'www bad', '../www'] as $user) {
            $this->assertSame(1, Artisan::call('area3:supervisor-config', $user === null ? [] : ['--user' => $user]));
            $this->assertStringNotContainsString('[program:', Artisan::output());
        }
        $this->assertSame(0, Artisan::call('area3:supervisor-config', ['--user' => 'site-user_1']));
        $this->assertStringContainsString('user=site-user_1', Artisan::output());
    }

    public function test_unsafe_supervisor_paths_fail_instead_of_allowing_interpolation_or_comment_injection(): void
    {
        $original = storage_path();
        try {
            foreach (['/srv/area3 space/storage', '/srv/area3%secret/storage', "/srv/area3\ncommand=bad", '/srv/area3;bad', '/srv/../private'] as $path) {
                $this->app->useStoragePath($path);
                $this->assertSame(1, Artisan::call('area3:supervisor-config', ['--user' => 'www']));
                $this->assertStringNotContainsString('[program:', Artisan::output());
            }
        } finally {
            $this->app->useStoragePath($original);
        }
    }
}
