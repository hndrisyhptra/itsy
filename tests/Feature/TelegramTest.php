<?php

namespace Tests\Feature;

use App\Jobs\SendTelegramReminder;
use App\Models\PrototypeWorkspace;
use App\Models\User;
use App\Services\AccountAccess;
use App\Services\RelationalWorkflowStore;
use App\Services\TelegramAccounts;
use App\Services\TelegramPendingApprovals;
use App\Services\TelegramReminders;
use App\Services\WorkUnitAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Concerns\AuthenticatesArea3;
use Tests\TestCase;

class TelegramTest extends TestCase
{
    use AuthenticatesArea3, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['telegram.token' => '123:fake-test-token', 'telegram.username' => 'area3_qa_bot', 'telegram.webhook_secret' => str_repeat('x', 40), 'telegram.app_url' => 'https://area3.example.test/sub']);
    }

    private function connected(int $id, string $chat = '10001'): string
    {
        $binding = (string) Str::uuid();
        DB::table('user_telegram_accounts')->insert(['id_user_telegram' => (string) Str::uuid(), 'user_id' => $id, 'chat_hash' => hash_hmac('sha256', $chat, config('app.key')), 'chat_cipher' => Crypt::encryptString($chat), 'telegram_name' => 'QA Telegram', 'binding_version' => $binding, 'confirmed_at' => now(), 'blocked' => false, 'created_at' => now(), 'updated_at' => now()]);

        return $binding;
    }

    private function targets(?int $user = null): array
    {
        $out = [];
        app(TelegramPendingApprovals::class)->each(function ($t) use (&$out) {
            $out[] = $t;
        }, $user);

        return $out;
    }

    public function test_profile_link_is_private_single_use_two_phase_and_secrets_never_leave_server(): void
    {
        $u = $this->asRole('PM Konstruksi');
        $this->getJson('/api/telegram/admin')->assertForbidden();
        $link = $this->postJson('/api/telegram/profile', ['action' => 'link', 'request_id' => (string) Str::uuid(), 'user_id' => 999])->assertOk()->json('url');
        parse_str(parse_url($link, PHP_URL_QUERY), $query);
        $this->assertSame(48, strlen($query['start']));
        $saved = DB::table('telegram_link_tokens')->first();
        $this->assertNotSame($query['start'], $saved->token_hash);
        $update = ['message' => ['chat' => ['id' => 10001, 'type' => 'private'], 'from' => ['id' => 10001, 'is_bot' => false, 'first_name' => '<QA>', 'username' => 'qa_user'], 'text' => '/start '.$query['start']]];
        $this->postJson('/api/telegram/webhook', $update)->assertForbidden();
        $headers = ['X-Telegram-Bot-Api-Secret-Token' => config('telegram.webhook_secret')];
        $bad = $update;
        $bad['message']['chat']['type'] = 'group';
        $this->postJson('/api/telegram/webhook', $bad, $headers)->assertOk();
        $this->assertDatabaseCount('user_telegram_accounts', 0);
        $this->postJson('/api/telegram/webhook', $update, $headers)->assertOk();
        $a = DB::table('user_telegram_accounts')->first();
        $this->assertNull($a->confirmed_at);
        $this->assertNotSame('10001', $a->chat_cipher);
        $this->postJson('/api/telegram/webhook', $update, $headers)->assertOk();
        $this->assertSame($a->binding_version, DB::table('user_telegram_accounts')->value('binding_version'));
        $public = $this->getJson('/api/telegram/profile')->assertOk()->getContent();
        foreach (['chat_cipher', 'chat_hash', '10001', 'fake-test-token', str_repeat('x', 40), 'token_hash'] as $secret) {
            $this->assertStringNotContainsString($secret, $public);
        }
        $this->postJson('/api/telegram/profile', ['action' => 'confirm', 'binding_version' => (string) Str::uuid(), 'request_id' => (string) Str::uuid()])->assertConflict();
        $this->postJson('/api/telegram/profile', ['action' => 'confirm', 'binding_version' => $a->binding_version, 'request_id' => (string) Str::uuid()])->assertOk();
        $this->assertNotNull(DB::table('user_telegram_accounts')->value('confirmed_at'));
        $this->postJson('/api/telegram/profile', ['action' => 'disconnect', 'binding_version' => $a->binding_version, 'request_id' => (string) Str::uuid()])->assertOk();
        $this->assertDatabaseCount('user_telegram_accounts', 0);
        Http::assertNothingSent();
    }

    public function test_expiry_new_link_invalidates_pending_binding_and_chat_cannot_belong_to_two_users(): void
    {
        $u = $this->asRole();
        $service = app(TelegramAccounts::class);
        $link = $service->link($u->id_user);
        parse_str(parse_url($link['url'], PHP_URL_QUERY), $q);
        $update = ['message' => ['chat' => ['id' => 10001, 'type' => 'private'], 'from' => ['id' => 10001, 'is_bot' => false], 'text' => '/start '.$q['start']]];
        $service->webhook($update);
        $old = DB::table('user_telegram_accounts')->value('binding_version');
        $service->link($u->id_user);
        $this->assertDatabaseCount('user_telegram_accounts', 0);
        $service->webhook($update);
        $this->assertDatabaseCount('user_telegram_accounts', 0);
        $this->connected($u->id_user);
        $second = $this->asRole('Finance');
        $link = $service->link($second->id_user);
        parse_str(parse_url($link['url'], PHP_URL_QUERY), $q);
        $update['message']['text'] = '/start '.$q['start'];
        $service->webhook($update);
        $this->assertDatabaseCount('user_telegram_accounts', 1);
        $update['message']['chat']['id'] = $update['message']['from']['id'] = 20002;
        $this->travel(11)->minutes();
        $service->webhook($update);
        $this->assertDatabaseCount('user_telegram_accounts', 1);
    }

    public function test_admin_config_pic_scopes_paging_version_and_bot_probe_are_separate_from_business_state(): void
    {
        $this->asRole();
        $response = $this->getJson('/api/telegram/admin?section=settings')->assertOk();
        $this->assertFalse((bool) $response->json('settings.enabled'));
        $this->assertStringNotContainsString('fake-test-token', $response->getContent());
        $save = ['action' => 'save', 'version' => 1, 'enabled' => true, 'all_day' => false, 'start_time' => '08:00', 'end_time' => '17:00', 'message_template' => 'Halo {name}: {count} PP {pp_count} pembayaran {payment_count}'];
        $this->postJson('/api/telegram/admin', $save)->assertOk();
        $this->postJson('/api/telegram/admin', $save)->assertConflict();
        $this->postJson('/api/telegram/admin', array_replace($save, ['version' => 2, 'message_template' => '{token}']))->assertUnprocessable();
        $finance = $this->asRole('Finance');
        $this->getJson('/api/telegram/admin')->assertForbidden();
        $this->asRole();
        $branch = DB::table('branches')->value('id_branch');
        $unit = DB::table('work_units')->where('code', 'KONSTRUKSI')->value('id_work_unit');
        $this->postJson('/api/telegram/admin', ['action' => 'pic', 'version' => 2, 'branch_id' => $branch, 'work_unit_id' => $unit, 'user_id' => $finance->id_user])->assertOk();
        $this->getJson('/api/telegram/admin?section=pics')->assertOk()->assertJsonCount(1, 'rows');
        $this->getJson('/api/telegram/admin?section=candidates&branch_id='.$branch.'&work_unit_id='.$unit)->assertOk()->assertJsonPath('rows.0.id_user', $finance->id_user);
        Http::fake(['api.telegram.org/*/getMe' => Http::response(['ok' => true, 'result' => ['username' => 'area3_qa_bot']]), 'api.telegram.org/*/getWebhookInfo' => Http::response(['ok' => true, 'result' => ['url' => 'https://area3.example.test/sub/api/telegram/webhook', 'pending_update_count' => 0]])]);
        $this->postJson('/api/telegram/admin', ['action' => 'probe'])->assertOk()->assertJsonPath('webhook_ok', true);
        $this->assertDatabaseCount('project_plans', 0);
        $this->assertDatabaseCount('approval_decisions', 0);
        $this->assertDatabaseCount('prototype_workspaces', 0);
    }

    public function test_worker_test_is_binding_bound_idempotent_and_http_failure_does_not_leak_credentials(): void
    {
        Queue::fake();
        $u = $this->asRole();
        $binding = $this->connected($u->id_user);
        $uuid = (string) Str::uuid();
        $input = ['action' => 'test', 'binding_version' => $binding, 'request_id' => $uuid];
        $this->postJson('/api/telegram/profile', $input)->assertOk();
        $this->postJson('/api/telegram/profile', $input)->assertOk();
        Queue::assertPushed(SendTelegramReminder::class, 1);
        Http::fake(['api.telegram.org/*' => Http::sequence()->push(['ok' => true, 'result' => ['message_id' => 42]])->push(['ok' => false, 'error_code' => 403, 'description' => 'secret fake-test-token'], 403)]);
        $sender = app(TelegramReminders::class);
        $sender->send($u->id_user, $uuid, $binding);
        $sender->send($u->id_user, $uuid, $binding);
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => $r['chat_id'] === '10001' && $r['reply_markup']['inline_keyboard'][0][0]['url'] === 'https://area3.example.test/sub/inbox');
        $this->assertDatabaseHas('telegram_notification_logs', ['id_telegram_notification_log' => $uuid, 'status' => 'sent']);
        $new = app(TelegramAccounts::class)->log($u->id_user, 'test', 'queued', 'QA');
        $sender->send($u->id_user, $new, (string) Str::uuid());
        $this->assertDatabaseHas('telegram_notification_logs', ['id_telegram_notification_log' => $new, 'status' => 'cancelled']);
        $new = app(TelegramAccounts::class)->log($u->id_user, 'test', 'queued', 'QA');
        $sender->send($u->id_user, $new, $binding);
        $this->assertTrue((bool) DB::table('user_telegram_accounts')->value('blocked'));
        $this->assertStringNotContainsString('fake-test-token', DB::table('telegram_notification_logs')->get()->toJson());
    }

    public function test_current_run_receiver_hourly_digest_and_changed_stage_cancel_stale_work_without_financial_writes(): void
    {
        Queue::fake();
        $state = $this->seedWorkflow('B');
        $w = PrototypeWorkspace::first();
        $state['projects'][0]['status'] = 'Diajukan';
        $state['projects'][0]['unit'] = 'Konstruksi';
        $state['projects'][0]['workflowVersion'] = 3;
        $state['projects'][0]['approvalScheme'] = 'project-support-first';
        $state['projects'][0]['approverUserIds'] = $state['projects'][0]['paymentApproverUserIds'];
        $state['projects'][0]['approvals'] = [];
        $state['projects'][0]['approvalHistory'] = [];
        app(RelationalWorkflowStore::class)->write($w, $state);
        $rows = $this->targets();
        $this->assertNotEmpty($rows);
        $id = $rows[0]['user_id'];
        $this->connected($id);
        $before = DB::table('project_plans')->get()->toJson().DB::table('payment_terms')->get()->toJson().DB::table('approval_decisions')->get()->toJson();
        $sender = app(TelegramReminders::class);
        $this->assertSame(0, $sender->collect());
        DB::table('telegram_settings')->update(['enabled' => true]);
        $this->assertSame(1, $sender->collect());
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);
        $sender->send($id);
        Http::assertSentCount(1);
        $sender->send($id);
        Http::assertSentCount(1);
        $this->travel(61)->minutes();
        $sender->send($id);
        Http::assertSentCount(2);
        $this->assertSame($before, DB::table('project_plans')->get()->toJson().DB::table('payment_terms')->get()->toJson().DB::table('approval_decisions')->get()->toJson());
        User::whereKey($id)->update(['active' => false]);
        $this->assertSame([], $this->targets($id));
        $this->travel(61)->minutes();
        $sender->send($id);
        Http::assertSentCount(2);
        User::whereKey($id)->update(['active' => true]);
        DB::table('approval_runs')->update(['status' => 'REJECTED']);
        $sender->send($id);
        Http::assertSentCount(2);
        $this->assertSame(0, DB::table('approval_notification_tasks')->where('user_id', $id)->whereNull('closed_at')->count());
    }

    public function test_seven_stage_payment_uses_exact_kasbon_actor_and_finance_pic_with_region_unit_scope(): void
    {
        $s = $this->seedWorkflow('E');
        $w = PrototypeWorkspace::first();
        $p = &$s['projects'][0];
        $p['unit'] = 'Operation';
        $p['approvalScheme'] = 'operation-v1';
        $p['status'] = 'Sah';
        $t = &$s['terms'][0];
        $t['workflowVersion'] = 3;
        $t['approvalScheme'] = 'operation-v1';
        $t['paymentFlow'] = 'kasbon-settlement-v2';
        $t['status'] = 'Diajukan';
        $t['paid'] = 0;
        $t['apm'] = '';
        $t['apmStatus'] = 'Belum Terbit';
        $t['paymentHistory'] = [];
        $t['financePayment'] = ['status' => 'Menunggu Approval'];
        $t['kasbon'] = ['id' => (string) Str::uuid()];
        $t['history'] = $t['approvals'] = [];
        $t['approverUserIds'] = [];
        foreach ([...WorkUnitAccess::OPS_APPROVERS, 'Mgr Finance & Settlement'] as $role) {
            $t['approverUserIds'][$role] = 'db-user-'.$this->accounts[$role]->id_user;
        }
        $roles = [...WorkUnitAccess::OPS_APPROVERS, 'Mgr Finance & Settlement', 'Finance'];
        $store = app(RelationalWorkflowStore::class);
        // Region-mandatory officers use an actual active Region assignment.
        $branch = DB::table('branches')->where('name', $p['branch'])->first();
        foreach (['Manager SS', 'General Manager Operation'] as $role) {
            User::whereKey($this->accounts[$role]->id_user)->update(['access_scope' => 'REGION', 'id_regions' => $branch->region_id]);
        }
        foreach ($roles as $i => $role) {
            $store->write($w, $s);
            if ($role === 'Finance') {
                $this->assertSame([], $this->targets());
                DB::table('telegram_finance_pics')->insert(['id_telegram_finance_pic' => (string) Str::uuid(), 'branch_id' => $branch->id_branch, 'work_unit_id' => DB::table('work_units')->where('code', 'OPERATION')->value('id_work_unit'), 'user_id' => $this->accounts['Finance']->id_user]);
            }
            $rows = $this->targets();
            $this->assertCount(1, $rows, $role);
            $this->assertSame($this->accounts[$role]->id_user, $rows[0]['user_id']);
            $this->assertSame($t['id'], $rows[0]['target_id']);
            $decision = ['role' => $role, 'userId' => 'db-user-'.$this->accounts[$role]->id_user, 'actorName' => 'QA '.$role, 'decision' => 'Setujui', 'at' => now()->toISOString(), 'note' => '', 'kasbonId' => $t['kasbon']['id']];
            $t['approvals'][] = $decision;
            $t['history'][] = $decision;
        }
        $t['status'] = 'Disetujui';
        $store->write($w, $s);
        $this->assertSame([], $this->targets());
        // No reminder recipient is granted any new application permission.
        $this->assertSame('FINANCE', $this->accounts['Finance']->fresh()->role);
    }

    public function test_catalog_batches_account_context_and_uses_current_order_and_current_branch(): void
    {
        $s = $this->seedWorkflow('B');
        $w = PrototypeWorkspace::first();
        $p = &$s['projects'][0];
        $p['workflowVersion'] = 3;
        $p['status'] = 'Diajukan';
        $p['unit'] = 'Konstruksi';
        $p['approverUserIds'] = $p['paymentApproverUserIds'];
        // Historical SQL steps keep Deployment before Support, but actual current application flow selects Support.
        unset($p['approvalScheme']);
        $p['approvals'] = $p['approvalHistory'] = [];
        foreach (['PM Konstruksi', 'Admin Area'] as $role) {
            $p['approvals'][] = ['role' => $role, 'userId' => $p['approverUserIds'][$role], 'actorName' => 'QA', 'decision' => 'Setujui', 'at' => now()->toISOString(), 'note' => ''];
        }
        $p['approvalHistory'] = $p['approvals'];
        app(RelationalWorkflowStore::class)->write($w, $s);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $rows = $this->targets();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertCount(1, $rows);
        $this->assertSame($this->accounts['Mgr Project Support']->id_user, $rows[0]['user_id']);
        $this->assertLessThanOrEqual(9, count($queries));
        file_put_contents('/private/tmp/area3-telegram-query.json', json_encode(['catalog_fixture_queries' => count($queries), 'catalog_budget' => 9]));
        $u = $this->accounts['Mgr Project Support'];
        $other = DB::table('branches')->where('name', '<>', $p['branch'])->value('id_branch');
        app(AccountAccess::class)->assign($u, 'MGR_PROJECT_SUPPORT', [$other]);
        $this->assertSame([], $this->targets());
    }

    public function test_retry_after_and_ambiguous_timeout_never_blindly_resend_same_hourly_slot(): void
    {
        $u = $this->asRole();
        $binding = $this->connected($u->id_user);
        $id = app(TelegramAccounts::class)->log($u->id_user, 'test', 'queued', 'QA');
        Http::fake(['api.telegram.org/*' => Http::failedConnection()]);
        app(TelegramReminders::class)->send($u->id_user, $id, $binding);
        app(TelegramReminders::class)->send($u->id_user, $id, $binding);
        $this->assertDatabaseHas('telegram_notification_logs', ['id_telegram_notification_log' => $id, 'status' => 'unknown']);
        $this->assertDatabaseCount('approval_decisions', 0);
    }

    public function test_rate_limit_waits_for_retry_after_and_closed_or_removed_targets_do_not_send(): void
    {
        $u = $this->asRole();
        $this->connected($u->id_user);
        $key = hash('sha256', 'rate-limit-fixture');
        $this->mock(TelegramPendingApprovals::class, function ($mock) use ($u, $key) {
            $mock->shouldReceive('each')->andReturnUsing(fn ($callback, $id) => $callback(['key' => $key, 'user_id' => $u->id_user, 'kind' => 'project', 'target_id' => 'fixture-not-live']));
        });
        DB::table('telegram_settings')->update(['enabled' => true]);
        DB::table('approval_notification_tasks')->insert(['id_approval_notification' => (string) Str::uuid(), 'target_key' => $key, 'user_id' => $u->id_user, 'kind' => 'project', 'target_id' => 'fixture-not-live', 'next_remind_at' => now()]);
        Http::fake(['api.telegram.org/*' => Http::sequence()->push(['ok' => false, 'error_code' => 429, 'parameters' => ['retry_after' => 120]], 429)->push(['ok' => true, 'result' => ['message_id' => 77]])]);
        $service = app(TelegramReminders::class);
        $service->send($u->id_user);
        $this->assertSame(now()->addSeconds(120)->format('Y-m-d H:i:s'), DB::table('approval_notification_tasks')->value('next_remind_at'));
        $service->send($u->id_user);
        Http::assertSentCount(1);
        $this->travel(121)->seconds();
        $service->send($u->id_user);
        Http::assertSentCount(2);
        $this->assertDatabaseHas('telegram_notification_logs', ['status' => 'sent']);
    }

    public function test_real_database_notification_worker_processes_only_its_queue_in_isolated_memory(): void
    {
        // Explicit push avoids the enclosing test transaction's after-commit dispatch boundary.
        $u = $this->asRole();
        $binding = $this->connected($u->id_user);
        $id = app(TelegramAccounts::class)->log($u->id_user, 'test', 'queued', 'QA database queue');
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 78]])]);
        config(['queue.connections.notifications.after_commit' => false]);
        Queue::connection('notifications')->push(new SendTelegramReminder($u->id_user, $id, $binding), '', 'area3-notifications');
        $this->artisan('area3:work-notifications', ['--once' => true])->assertSuccessful();
        $this->assertDatabaseHas('telegram_notification_logs', ['id_telegram_notification_log' => $id, 'status' => 'sent']);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('failed_jobs', 0);
        Http::assertSentCount(1);
    }

    public function test_guest_csrf_and_inactive_users_cannot_link_and_only_webhook_is_csrf_exempt(): void
    {
        $this->getJson('/api/telegram/profile')->assertUnauthorized();
        $this->postJson('/api/telegram/profile', ['action' => 'link', 'request_id' => (string) Str::uuid()])->assertUnauthorized();
        $u = $this->asRole();
        $this->app->instance('env', 'local');
        $this->postJson('/api/telegram/profile', ['action' => 'link', 'request_id' => (string) Str::uuid()])->assertStatus(419);
        $this->postJson('/api/telegram/admin', ['action' => 'probe'])->assertStatus(419);
        $this->postJson('/api/telegram/webhook', [], ['X-Telegram-Bot-Api-Secret-Token' => config('telegram.webhook_secret')])->assertOk();
        $this->app->instance('env', 'testing');
        User::whereKey($u->id_user)->update(['active' => false]);
        $this->getJson('/api/telegram/profile')->assertForbidden();
        $this->assertDatabaseCount('telegram_link_tokens', 0);
        Http::assertNothingSent();
    }

    public function test_legacy_supplemental_manager_partial_request_and_scan_keep_original_flow_and_uuid(): void
    {
        $s = $this->seedWorkflow('E');
        $p = DB::table('project_plans')->first();
        $t = DB::table('payment_terms')->first();
        $finance = $this->accounts['Finance']->id_user;
        $manager = $this->accounts['Mgr Finance & Settlement']->id_user;
        DB::table('telegram_finance_pics')->insert(['id_telegram_finance_pic' => (string) Str::uuid(), 'branch_id' => $p->branch_id, 'work_unit_id' => $p->work_unit_id, 'user_id' => $finance]);
        $doc = json_decode($t->document_data, true);
        $doc['paid'] = 0;
        $doc['paymentFlow'] = 'kasbon-v1';
        $doc['workflowVersion'] = 3;
        $doc['kasbon'] = ['id' => 'legacy-current-kasbon'];
        $doc['financePayment'] = ['status' => 'Kasbon Terbit'];
        $doc['legacySettlementReview'] = ['kasbonId' => $doc['kasbon']['id'], 'userId' => 'db-user-'.$manager];
        $save = function () use (&$doc, $t) {
            DB::table('payment_terms')->where('id_payment_term', $t->id_payment_term)->update(['status' => 'APPROVED', 'document_data' => json_encode($doc)]);
        };
        $save();
        $this->assertSame($manager, $this->targets()[0]['user_id']);
        $doc['legacySettlementReview']['decision'] = ['role' => 'Mgr Finance & Settlement', 'decision' => 'Setujui', 'userId' => 'db-user-'.$manager, 'kasbonId' => $doc['kasbon']['id']];
        $save();
        $this->assertSame($finance, $this->targets()[0]['user_id']);
        $doc['legacySettlementReview']['kasbonId'] = 'old-version';
        $save();
        $this->assertSame([], $this->targets());
        unset($doc['legacySettlementReview']);
        $doc['paymentFlow'] = 'partial-payment-v1';
        $doc['paymentRequest'] = ['status' => 'Diajukan'];
        $save();
        $this->assertSame($finance, $this->targets()[0]['user_id']);
        unset($doc['paymentFlow']);
        $doc['scan'] = ['status' => 'Menunggu Verifikasi'];
        $save();
        $this->assertSame($finance, $this->targets()[0]['user_id']);
        $doc['scan']['status'] = 'Perlu Koreksi';
        $save();
        $this->assertSame([], $this->targets());
        Http::assertNothingSent();
    }

    public function test_time_window_and_permission_removal_suppress_delivery_and_migration_rollback_preserves_audit(): void
    {
        $u = $this->asRole();
        $binding = $this->connected($u->id_user);
        $service = app(TelegramReminders::class);
        $this->travelTo(now()->setTimezone('Asia/Jakarta')->setTime(23, 0));
        $this->assertTrue($service->inWindow((object) ['all_day' => false, 'start_time' => '22:00', 'end_time' => '06:00']));
        $this->assertFalse($service->inWindow((object) ['all_day' => false, 'start_time' => '08:00', 'end_time' => '17:00']));
        $log = app(TelegramAccounts::class)->log($u->id_user, 'test', 'queued', 'QA');
        DB::table('user_assignments')->where('user_id', $u->id_user)->update(['active' => false]);
        $service->send($u->id_user, $log, $binding);
        Http::assertNothingSent();
        $this->assertDatabaseHas('telegram_notification_logs', ['id_telegram_notification_log' => $log, 'status' => 'cancelled']);
        $migration = require database_path('migrations/2026_11_18_000003_create_telegram_notifications.php');
        $this->expectException(\RuntimeException::class);
        $migration->down();
    }
}
