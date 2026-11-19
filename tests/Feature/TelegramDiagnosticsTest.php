<?php

namespace Tests\Feature;

use App\Models\PrototypeWorkspace;
use App\Services\RelationalWorkflowStore;
use App\Services\TelegramDiagnostics;
use App\Services\TelegramPendingApprovals;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Concerns\AuthenticatesArea3;
use Tests\TestCase;

class TelegramDiagnosticsTest extends TestCase
{
    use AuthenticatesArea3, RefreshDatabase;

    public function test_cli_diagnostics_are_read_only_and_never_send_or_expose_private_values(): void
    {
        Http::preventStrayRequests();
        Queue::fake();
        $this->freezeTime();
        $u = $this->asRole('PM Konstruksi');
        config(['telegram.token' => 'PRIVATE-TOKEN-NOT-OUTPUT']);
        DB::table('telegram_settings')->update(['enabled' => true, 'scheduler_at' => now(), 'message_template' => 'PRIVATE-TEMPLATE-NOT-OUTPUT']);
        DB::table('user_telegram_accounts')->insert(['id_user_telegram' => (string) Str::uuid(), 'user_id' => $u->id_user, 'chat_hash' => str_repeat('a', 64), 'chat_cipher' => Crypt::encryptString('PRIVATE-CHAT-NOT-OUTPUT'), 'telegram_name' => 'PRIVATE-NAME-NOT-OUTPUT', 'binding_version' => (string) Str::uuid(), 'confirmed_at' => now(), 'blocked' => false]);
        DB::table('jobs')->insert(['queue' => 'area3-notifications', 'payload' => 'PRIVATE-PAYLOAD-NOT-OUTPUT', 'attempts' => 0, 'available_at' => time(), 'created_at' => time()]);
        $this->mock(TelegramPendingApprovals::class, fn ($mock) => $mock->shouldReceive('each')->andReturnUsing(fn ($callback) => $callback(['kind' => 'project', 'user_id' => $u->id_user])));
        $tables = ['telegram_settings', 'user_telegram_accounts', 'approval_notification_tasks', 'telegram_notification_logs', 'jobs', 'failed_jobs', 'project_plans', 'approval_decisions'];
        $snapshot = fn () => array_map(fn ($t) => DB::table($t)->get()->toJson(), $tables);
        $before = $snapshot();
        DB::enableQueryLog();
        DB::flushQueryLog();
        $result = app(TelegramDiagnostics::class)->report();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertSame($before, $snapshot());
        $this->assertSame(1, $result['resolver']['targets']['project']);
        $this->assertSame(1, $result['connected_current_recipients']);
        $this->assertSame(1, $result['queue']['pending_jobs']);
        $this->assertContains('CURRENT_TARGETS_NOT_COLLECTED', $result['findings']);
        $this->assertContains('NOTIFICATION_JOBS_PENDING', $result['findings']);
        $this->assertNotContains('SCHEDULER_ACTIVITY_NOT_RECENT', $result['findings']);
        $encoded = json_encode($result, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('PRIVATE-', $encoded);
        $this->assertLessThanOrEqual(15, count($queries));
        foreach ($queries as $query) {
            $this->assertMatchesRegularExpression('/^(select|pragma)\b/i', $query['query']);
            $this->assertDoesNotMatchRegularExpression('/chat_cipher|chat_hash|binding_version|message_template|payload|exception\b/i', $query['query']);
        }
        $this->artisan('area3:telegram-status')->expectsOutputToContain('"read_only": true')->assertSuccessful();
        $this->assertSame($before, $snapshot());
        Http::assertNothingSent();
        Queue::assertNothingPushed();
        fwrite(STDERR, "\nTelegram diagnostics fixture: ".count($queries)." read-only queries (mocked target resolver).\n");
    }

    public function test_missing_activation_window_and_scheduler_are_reported_without_dispatch(): void
    {
        $this->travelTo(now('UTC')->setTime(0, 0)); // 07:00 WIB, outside 08–17.
        config(['telegram.token' => null]);
        DB::table('telegram_settings')->update(['enabled' => false, 'all_day' => false]);
        $this->mock(TelegramPendingApprovals::class, fn ($mock) => $mock->shouldReceive('each')->andReturnNull());
        $result = app(TelegramDiagnostics::class)->report();
        foreach (['REMINDER_DISABLED', 'CLI_TOKEN_NOT_CONFIGURED', 'OUTSIDE_SEND_WINDOW', 'SCHEDULER_ACTIVITY_NOT_RECENT', 'NO_CURRENT_APPROVAL_TARGETS'] as $finding) {
            $this->assertContains($finding, $result['findings']);
        }
        $this->assertDatabaseCount('approval_notification_tasks', 0);
        $this->assertDatabaseCount('telegram_notification_logs', 0);
    }

    public function test_failed_target_resolution_is_visible_without_exposing_exception_sql_or_credentials(): void
    {
        config(['telegram.token' => 'SECRET-TOKEN']);
        $this->mock(TelegramPendingApprovals::class, fn ($mock) => $mock->shouldReceive('each')->andThrow(new RuntimeException('SECRET-TOKEN SQL VALUES private@example.test')));
        $result = app(TelegramDiagnostics::class)->report();
        $this->assertFalse($result['resolver']['ok']);
        $this->assertSame('RuntimeException', $result['resolver']['exception_class']);
        $this->assertContains('APPROVAL_TARGET_RESOLVER_FAILED', $result['findings']);
        $this->assertNotContains('NO_CURRENT_APPROVAL_TARGETS', $result['findings']);
        $this->assertStringNotContainsString('SECRET-TOKEN', json_encode($result));
        $this->assertStringNotContainsString('private@example.test', json_encode($result));
        $this->assertDatabaseCount('telegram_notification_logs', 0);
    }

    public function test_diagnostics_use_real_current_approval_resolver_without_collecting_tasks(): void
    {
        Http::preventStrayRequests();
        Queue::fake();
        $state = $this->seedWorkflow('B');
        $pp = &$state['projects'][0];
        $pp['status'] = 'Diajukan';
        $pp['unit'] = 'Konstruksi';
        $pp['workflowVersion'] = 3;
        $pp['approvalScheme'] = 'project-support-first';
        $pp['approverUserIds'] = $pp['paymentApproverUserIds'];
        $pp['approvals'] = $pp['approvalHistory'] = [];
        app(RelationalWorkflowStore::class)->write(PrototypeWorkspace::first(), $state);
        $before = DB::table('project_plans')->get()->toJson().DB::table('approval_runs')->get()->toJson().DB::table('approval_decisions')->get()->toJson();
        DB::enableQueryLog();
        DB::flushQueryLog();
        $result = app(TelegramDiagnostics::class)->report();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertTrue($result['resolver']['ok']);
        $this->assertSame(1, $result['resolver']['targets']['project']);
        $this->assertSame(1, $result['sql_candidates']['submitted_pp']);
        $this->assertSame(0, $result['connected_current_recipients']);
        $this->assertContains('NO_CONNECTED_CURRENT_RECIPIENTS', $result['findings']);
        $this->assertLessThanOrEqual(24, count($queries));
        foreach ($queries as $query) {
            $this->assertMatchesRegularExpression('/^(select|pragma)\b/i', $query['query']);
            $this->assertDoesNotMatchRegularExpression('/chat_cipher|chat_hash|message_template|payload|finance_cash_advances|prototype_workspaces/i', $query['query']);
        }
        $this->assertSame($before, DB::table('project_plans')->get()->toJson().DB::table('approval_runs')->get()->toJson().DB::table('approval_decisions')->get()->toJson());
        $this->assertDatabaseCount('approval_notification_tasks', 0);
        $this->assertDatabaseCount('telegram_notification_logs', 0);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
        fwrite(STDERR, "\nTelegram diagnostics real resolver fixture: ".count($queries)." read-only queries.\n");
    }
}
