<?php

namespace Tests\Feature;

use App\Models\PrototypeWorkspace;
use App\Models\User;
use App\Services\AccountAccess;
use App\Services\ApprovalReset;
use App\Services\PrototypeDomain;
use App\Services\RelationalWorkflowStore;
use App\Services\TelegramPendingApprovals;
use App\Services\WorkUnitAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\AuthenticatesArea3;
use Tests\TestCase;

class ApprovalResetTest extends TestCase
{
    use AuthenticatesArea3, RefreshDatabase;

    private function state(): array
    {
        return $this->getJson('/api/prototype/state')->assertOk()->json('state');
    }

    private function api(string $command, array $args, ?string $request = null, ?int $revision = null)
    {
        return $this->postJson('/api/prototype/commands', ['command' => $command, 'args' => $args, 'revision' => $revision ?? PrototypeWorkspace::first()->revision, 'request_id' => $request ?? (string) Str::uuid()]);
    }

    private function patchFixture(callable $change): void
    {
        $workspace = PrototypeWorkspace::first();
        $store = app(RelationalWorkflowStore::class);
        $before = $store->read($workspace);
        $state = $before;
        $change($state);
        DB::transaction(fn () => $store->write($workspace, $state, $before));
    }

    private function snapshot(): string
    {
        $tables = ['project_plans', 'contracts', 'contract_components', 'work_orders', 'payment_terms', 'term_actuals', 'approval_runs', 'approval_steps', 'approval_decisions', 'volume_balances', 'volume_movements', 'prototype_audits', 'command_receipts'];

        return json_encode(array_map(fn ($t) => DB::table($t)->get()->toArray(), $tables));
    }

    /** Trusted synthetic pre-update state in SQLite memory only; no production command is bypassed. */
    private function fixture(bool $project, bool $operation = false, ?string $legacy = null): array
    {
        $this->withoutVite();
        Storage::fake('local');
        $before = $this->seedWorkflow('D');
        $state = $before;
        $roles = $operation ? WorkUnitAccess::OPS_APPROVERS : PrototypeDomain::APPROVERS_V3;
        $p = &$state['projects'][0];
        $p['unit'] = $operation ? 'Operation' : 'Konstruksi';
        $p['workflowVersion'] = 3;
        $p['approvalScheme'] = $operation ? 'operation-v1' : 'project-support-first';
        $p['approverUserIds'] = [];
        if ($operation) {
            $unit = DB::table('work_units')->where('code', 'OPERATION')->value('id_work_unit');
            $region = DB::table('branches')->where('name', $p['branch'])->value('region_id');
            foreach ($roles as $role) {
                DB::table('users')->where('id_user', $this->accounts[$role]->id_user)->update(['work_unit_id' => $unit, 'access_scope' => in_array($role, ['Manager SS', 'General Manager Operation']) ? 'REGION' : 'BRANCH', 'id_regions' => $region]);
            }
        }
        $approvals = [];
        foreach ($roles as $i => $role) {
            $u = $this->accounts[$role];
            $p['approverUserIds'][$role] = 'db-user-'.$u->id_user;
            $approvals[] = ['role' => $role, 'userId' => 'db-user-'.$u->id_user, 'actorName' => $u->name, 'decision' => 'Setujui', 'note' => 'Original fixture decision', 'at' => now()->subMinutes(20 - $i)->toISOString()];
        }
        $p['approvals'] = $p['approvalHistory'] = $approvals;
        $p['status'] = 'Sah';
        $state['deals'][0]['status'] = $state['spks'][0]['status'] = 'Draft';
        $manifest = ['id' => (string) Str::uuid(), 'at' => now()->subHour()->toISOString(), 'documents' => []];
        foreach (['deals', 'spks'] as $kind) {
            $child = &$state[$kind][0];
            $manifest['documents'][$kind][$child['id']] = hash('sha256', json_encode(Arr::sortRecursive($child), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
            $child['status'] = 'Sah';
            $child['approvedWithProject'] = ['ppId' => $p['id'], 'submissionId' => $manifest['id']];
            unset($child);
        }
        $p['contractBundle'] = ['version' => 1, 'submissions' => [$manifest]];
        $state['terms'] = $state['bapps'] = [];
        $id = $p['id'];
        if (! $project) {
            $allRoles = $legacy ? $roles : [...$roles, 'Mgr Finance & Settlement', 'Finance'];
            $kasbon = (string) Str::uuid();
            $decisions = [];
            $selected = [];
            foreach ($allRoles as $i => $role) {
                $u = $this->accounts[$role];
                $selected[$role] = 'db-user-'.$u->id_user;
                $decisions[] = ['role' => $role, 'userId' => $selected[$role], 'actorName' => $u->name, 'decision' => 'Setujui', 'note' => 'Original fixture payment', 'at' => now()->subMinutes(10 - $i)->toISOString(), 'kasbonId' => $kasbon];
            }
            $id = 'term-reset-qa';
            $state['terms'][] = ['id' => $id, 'spkId' => $state['spks'][0]['id'], 'number' => 1, 'type' => 'Parsial', 'status' => 'Disetujui', 'workflowVersion' => 3,
                'approvalScheme' => $p['approvalScheme'], 'unit' => $p['unit'], 'paymentFlow' => $legacy ? 'kasbon-v1' : 'kasbon-settlement-v2', 'approverUserIds' => $selected,
                'actuals' => [$state['deals'][0]['rows'][0]['rowId'] => ['m' => 1, 's' => 1]], 'approvals' => $decisions, 'history' => $decisions,
                'apm' => '', 'apmStatus' => 'Belum Terbit', 'paid' => 0, 'paymentHistory' => [], 'kasbon' => ['id' => $kasbon, 'version' => 1, 'amount' => 26350, 'issuedBy' => 'db-user-'.$this->accounts['Admin Konstruksi']->id_user, 'issuedAt' => now()->subHour()->toISOString()],
                'financePayment' => $legacy ? ['status' => 'Kasbon Terbit'] : ['status' => 'Diverifikasi', 'decision' => end($decisions)], 'financePaymentHistory' => $legacy ? [] : [end($decisions)]];
            if ($legacy) {
                $t = &$state['terms'][0];
                if (in_array($legacy, ['manager', 'finance'], true)) {
                    $t['legacySettlementReview'] = ['version' => 1, 'kasbonId' => $kasbon, 'userId' => 'db-user-'.$this->accounts['Mgr Finance & Settlement']->id_user, 'name' => 'Manager QA', 'assignedAt' => now()->toISOString(), 'assignedBy' => 'db-user-'.$this->accounts['Superadmin']->id_user];
                    $t['legacySettlementReviewHistory'] = [$t['legacySettlementReview']];
                } else {
                    unset($t['kasbon'], $t['financePayment']);
                    $t['paymentFlow'] = $legacy === 'partial' ? 'partial-payment-v1' : null;
                    if ($legacy === 'partial') {
                        $t['paymentRequest'] = ['id' => (string) Str::uuid(), 'cycle' => 1, 'status' => 'Diajukan', 'amount' => 26350, 'requestedBy' => 'db-user-'.$this->accounts['Admin Konstruksi']->id_user, 'requestedByName' => 'Admin QA', 'requestedAt' => now()->toISOString(), 'documentsAtRequest' => 'NOK'];
                    } else {
                        $t['scan'] = ['id' => (string) Str::uuid(), 'name' => 'QA.pdf', 'extension' => 'pdf', 'mime' => 'application/pdf', 'size' => 1, 'sha256' => str_repeat('a', 64), 'status' => 'Menunggu Verifikasi', 'uploadedAt' => now()->toISOString()];
                    }
                }
                unset($t);
            }
            $branch = DB::table('branches')->where('name', $p['branch'])->value('id_branch');
            $unit = DB::table('work_units')->where('code', $operation ? 'OPERATION' : 'KONSTRUKSI')->value('id_work_unit');
            DB::table('telegram_finance_pics')->insert(['id_telegram_finance_pic' => (string) Str::uuid(), 'branch_id' => $branch, 'work_unit_id' => $unit, 'user_id' => $this->accounts['Finance']->id_user]);
        }
        unset($p);
        $workspace = PrototypeWorkspace::first();
        DB::transaction(fn () => app(RelationalWorkflowStore::class)->write($workspace, $state, $before));

        return [$id, $roles];
    }

    public static function stages(): array
    {
        $cases = [];
        foreach ([false, true] as $operation) {
            foreach ([true, false] as $project) {
                for ($stage = 0; $stage < ($project ? 5 : 7); $stage++) {
                    $cases[($operation ? 'Operation' : 'Construction').'-'.($project ? 'PP' : 'Payment').'-'.$stage] = [$project, $operation, $stage];
                }
            }
        }

        return $cases;
    }

    #[DataProvider('stages')]
    public function test_self_reset_cascades_to_own_stage_and_keeps_old_sql_decisions_before_reapproval(bool $project, bool $operation, int $stage): void
    {
        [$id, $workRoles] = $this->fixture($project, $operation);
        $roles = $project ? $workRoles : [...$workRoles, 'Mgr Finance & Settlement', 'Finance'];
        $this->asRole($roles[$stage]);
        $before = $this->state();
        $document = $before[$project ? 'projects' : 'terms'][0];
        $plan = ApprovalReset::plan($before, $document, $project);
        $this->assertTrue($plan['own']);
        $this->assertSame('', $plan['reason']);
        $sql = DB::table('approval_decisions')->get()->keyBy('id_approval_decision');
        $history = $document[$project ? 'approvalHistory' : 'history'];
        DB::enableQueryLog();
        DB::flushQueryLog();
        $after = $this->api($project ? 'resetProjectApproval' : 'resetPaymentApproval', [$id, $plan['expected'], 'Koreksi persetujuan saya'])->assertOk()->json('state');
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $current = $after[$project ? 'projects' : 'terms'][0];
        $this->assertSame('Diajukan', $current['status']);
        $this->assertSame($roles[$stage], PrototypeDomain::nextApprovalRole($current, $project));
        $this->assertSame(array_slice($roles, 0, $stage), array_column($current['approvals'], 'role'));
        $this->assertSame($history, $current[$project ? 'approvalHistory' : 'history']);
        $this->assertSame($history, $document[$project ? 'approvalHistory' : 'history']);
        $this->assertCount(count($roles) - $stage, $current['approvalResets'][0]['affectedApprovals']);
        foreach ($sql as $pk => $decision) {
            $this->assertEquals($decision, DB::table('approval_decisions')->where('id_approval_decision', $pk)->first());
        }
        if ($project) {
            $this->assertSame('Draft', $after['deals'][0]['status']);
            $this->assertSame('Draft', $after['spks'][0]['status']);
            $this->assertSame($before['projects'][0]['contractBundle'], $after['projects'][0]['contractBundle']);
            $this->assertSame($before['deals'][0]['rows'], $after['deals'][0]['rows']);
        } else {
            $this->assertSame($before['projects'], $after['projects']);
            $this->assertSame($document['actuals'], $current['actuals']);
            $this->assertSame($document['kasbon'], $current['kasbon']);
            $this->assertSame('Menunggu Approval', $current['financePayment']['status']);
            $this->assertEquals(0, $current['paid']);
        }
        $this->assertLessThanOrEqual($project ? 110 : 100, count($queries));
        foreach ($queries as $query) {
            $this->assertDoesNotMatchRegularExpression('/^(insert|update|delete).*contract_components/i', $query['query']);
        }
        $targets = [];
        app(TelegramPendingApprovals::class)->each(function ($row) use (&$targets) {
            $targets[] = $row;
        });
        $this->assertCount(1, $targets);
        $this->assertSame($this->accounts[$roles[$stage]]->id_user, $targets[0]['user_id']);
        foreach (array_slice($roles, $stage) as $role) {
            $this->asRole($role);
            $this->api($project ? 'decideProject' : 'decide', [$id, 'Setujui', 'Persetujuan ulang'])->assertOk();
        }
        $completed = $this->state()[$project ? 'projects' : 'terms'][0];
        $this->assertSame($project ? 'Sah' : 'Disetujui', $completed['status']);
        $this->assertCount(count($roles), $completed['approvals']);
        $this->assertCount(1, $completed['approvalResets']);
        if ($stage === count($roles) - 1) {
            fwrite(STDERR, "\nApproval reset ".($project ? 'PP' : 'payment').($operation ? ' Operation' : ' Construction').': '.count($queries)." HTTP queries.\n");
        }
    }

    public function test_replay_is_bound_to_actor_payload_and_current_version_and_never_resets_twice(): void
    {
        [$id] = $this->fixture(false);
        $this->asRole('Mgr Finance & Settlement');
        $before = $this->state();
        $plan = ApprovalReset::plan($before, $before['terms'][0], false);
        $request = (string) Str::uuid();
        $revision = PrototypeWorkspace::first()->revision;
        $args = [$id, $plan['expected'], 'Koreksi pengajuan'];
        $this->api('resetPaymentApproval', $args, $request, $revision)->assertOk();
        $snapshot = $this->snapshot();
        $this->api('resetPaymentApproval', $args, $request, $revision)->assertOk()->assertJsonPath('replayed', true);
        $this->assertSame($snapshot, $this->snapshot());
        $changed = $args;
        $changed[2] = 'Alasan lain';
        $this->api('resetPaymentApproval', $changed, $request)->assertConflict();
        $this->assertSame($snapshot, $this->snapshot());
        $this->api('resetPaymentApproval', $args)->assertForbidden();
        $this->asRole('Finance');
        $this->api('resetPaymentApproval', $args, $request)->assertConflict();
        $this->assertSame($snapshot, $this->snapshot());
    }

    public function test_multiple_resets_at_same_history_offset_create_distinct_cycles_without_rewriting_history(): void
    {
        [$id] = $this->fixture(true);
        foreach (['Mgr Project Support', 'PM Konstruksi'] as $role) {
            $this->asRole($role);
            $state = $this->state();
            $plan = ApprovalReset::plan($state, $state['projects'][0], true);
            $this->api('resetProjectApproval', [$id, $plan['expected'], 'Koreksi tahap '.$role])->assertOk();
        }
        $p = $this->state()['projects'][0];
        $this->assertCount(2, $p['approvalResets']);
        $this->assertSame($p['approvalResets'][0]['historyOffset'], $p['approvalResets'][1]['historyOffset']);
        $this->assertCount(5, $p['approvalHistory']);
        $this->assertSame([], $p['approvals']);
        $this->assertSame('PM Konstruksi', PrototypeDomain::nextApprovalRole($p, true));
        $this->assertSame(3, DB::table('approval_runs')->count());
        $this->assertEquals(3, DB::table('approval_runs')->max('cycle_no'));
        $this->assertSame('PENDING', DB::table('approval_runs')->orderByDesc('cycle_no')->value('status'));
    }

    public function test_stale_fingerprint_blank_note_wrong_actor_and_scope_do_not_change_revision_or_sql(): void
    {
        [$id] = $this->fixture(true);
        $this->asRole('Mgr Deployment');
        $state = $this->state();
        $plan = ApprovalReset::plan($state, $state['projects'][0], true);
        $before = $this->snapshot();
        $revision = PrototypeWorkspace::first()->revision;
        $this->api('resetProjectApproval', [$id, $plan['expected'], '  '])->assertUnprocessable();
        $bad = $plan['expected'];
        $bad['historyLength']++;
        $this->api('resetProjectApproval', [$id, $bad, 'Koreksi'])->assertConflict();
        $this->asRole('Superadmin');
        $this->api('resetProjectApproval', [$id, $plan['expected'], 'Koreksi'])->assertForbidden();
        $other = User::factory()->create();
        app(AccountAccess::class)->assign($other, 'MGR_DEPLOYMENT', DB::table('branches')->pluck('id_branch')->all());
        $other->refresh();
        $this->actingAs($other)->withSession(['password_hash_web' => $other->password]);
        $this->api('resetProjectApproval', [$id, $plan['expected'], 'Koreksi'])->assertForbidden();
        $this->asRole('Mgr Deployment');
        $branch = DB::table('branches')->where('name', '<>', $state['projects'][0]['branch'])->value('id_branch');
        app(AccountAccess::class)->assign($this->accounts['Mgr Deployment'], 'MGR_DEPLOYMENT', [$branch]);
        $this->api('resetProjectApproval', [$id, $plan['expected'], 'Koreksi'])->assertForbidden();
        $this->assertSame($revision, PrototypeWorkspace::first()->revision);
        $this->assertSame($before, $this->snapshot());
    }

    public function test_pending_upload_and_unattached_private_transfer_file_block_reset(): void
    {
        [$id] = $this->fixture(false);
        $this->asRole('Finance');
        $state = $this->state();
        $plan = ApprovalReset::plan($state, $state['terms'][0], false);
        $workspace = PrototypeWorkspace::first();
        $task = (string) Str::uuid();
        DB::table('upload_tasks')->insert(['id_upload_task' => $task, 'workspace_id' => $workspace->id, 'user_id' => auth()->id(), 'request_uuid' => (string) Str::uuid(), 'kind' => 'transfer-proof', 'document_id' => $id, 'status' => 'processing', 'original_name' => 'QA.pdf', 'storage_key' => 'qa-private/no-real-file', 'mime' => 'application/pdf', 'size' => 1, 'sha256' => str_repeat('a', 64)]);
        $before = $this->snapshot();
        $this->api('resetPaymentApproval', [$id, $plan['expected'], 'Koreksi'])->assertUnprocessable();
        $this->assertSame($before, $this->snapshot());
        DB::table('upload_tasks')->where('id_upload_task', $task)->update(['status' => 'failed']);
        $term = app(RelationalWorkflowStore::class)->id($workspace->id, 'terms', $id);
        DB::table('finance_private_files')->insert(['id_finance_file' => (string) Str::uuid(), 'workspace_id' => $workspace->id, 'term_id' => $term, 'kind' => 'transfer-proof', 'storage_key' => 'qa-private/no-real-file', 'mime' => 'application/pdf', 'extension' => 'pdf', 'size' => 1, 'sha256' => str_repeat('a', 64), 'uploaded_by' => auth()->id(), 'created_at' => now()]);
        $this->api('resetPaymentApproval', [$id, $plan['expected'], 'Koreksi'])->assertUnprocessable();
        $this->assertSame($before, $this->snapshot());
    }

    public function test_payment_side_effects_and_released_volumes_fail_closed_without_mutation(): void
    {
        [$id] = $this->fixture(false);
        $this->asRole('Finance');
        $original = $this->state();
        $patches = [
            fn (&$s) => $s['terms'][0]['paid'] = 1,
            fn (&$s) => $s['terms'][0]['apm'] = 'APM/QA/01',
            fn (&$s) => $s['terms'][0]['transferProof'] = ['id' => 'proof', 'kasbonId' => $s['terms'][0]['kasbon']['id']],
            fn (&$s) => $s['terms'][0]['financePayment']['settledAt'] = now()->toISOString(),
            fn (&$s) => $s['terms'][] = array_replace($s['terms'][0], ['id' => 'later-term', 'number' => 2]),
            fn (&$s) => $s['bapps'][] = ['id' => 'bapp-release', 'termId' => $id, 'released' => ['row' => ['m' => 1, 's' => 0]], 'releaseHistory' => [['at' => now()->toISOString()]]],
        ];
        foreach ($patches as $patch) {
            $state = $original;
            $patch($state);
            $plan = ApprovalReset::plan($state, $state['terms'][0], false);
            $this->assertTrue($plan['own']);
            $this->assertNotSame('', $plan['reason']);
            $before = $state;
            try {
                app(PrototypeDomain::class)->execute($state, 'resetPaymentApproval', [$id, $plan['expected'], 'Koreksi']);
                $this->fail('Financial side effect must block reset.');
            } catch (ValidationException $error) {
                $this->assertSame($before, $state);
            }
        }
    }

    public function test_reset_metadata_cannot_be_removed_or_forged_through_a_draft_form(): void
    {
        [$id] = $this->fixture(false);
        $this->asRole('PM Konstruksi');
        $state = $this->state();
        $plan = ApprovalReset::plan($state, $state['terms'][0], false);
        $this->api('resetPaymentApproval', [$id, $plan['expected'], 'Koreksi'])->assertOk();
        $this->api('decide', [$id, 'Kembalikan', 'Perbaiki berkas'])->assertOk();
        $state = $this->state();
        $markers = $state['terms'][0]['approvalResets'];
        $this->asRole('Admin Konstruksi');
        $input = $state['terms'][0];
        $input['approvalResets'] = [];
        $input['approvalResetVersion'] = 999;
        $this->api('saveTerm', [$input, false])->assertOk()->assertJsonPath('state.terms.0.approvalResetVersion', 1);
        $this->assertSame($markers, $this->state()['terms'][0]['approvalResets']);
    }

    public static function legacyModes(): array
    {
        return [['manager'], ['finance'], ['partial'], ['scan']];
    }

    #[DataProvider('legacyModes')]
    public function test_legacy_financial_self_reset_keeps_five_work_decisions_and_rotates_reminder_identity(string $mode): void
    {
        [$id] = $this->fixture(false, false, $mode);
        $targets = [];
        app(TelegramPendingApprovals::class)->each(function ($row) use (&$targets) {
            $targets[] = $row;
        });
        $this->assertCount(1, $targets);
        $initialTarget = $targets[0]['key'];
        $this->asRole(in_array($mode, ['manager', 'finance'], true) ? 'Mgr Finance & Settlement' : 'Finance');
        $t = $this->state()['terms'][0];
        if (in_array($mode, ['manager', 'finance'], true)) {
            $this->api('decideLegacySettlementReview', [$id, $t['kasbon']['id'], 'Setujui', 'Manager review'])->assertOk();
            if ($mode === 'finance') {
                $targets = [];
                app(TelegramPendingApprovals::class)->each(function ($row) use (&$targets) {
                    $targets[] = $row;
                });
                $initialTarget = $targets[0]['key'];
            }
            $this->asRole('Finance');
            $this->api('decideFinancePayment', [$id, 'Setujui', 'Finance review', $t['kasbon']['id']])->assertOk();
        } elseif ($mode === 'partial') {
            $this->api('verifyPartialPayment', [$id, 'Setujui', 'Finance review'])->assertOk();
        } else {
            $this->api('verifyTermScan', [$id, 'Valid', 'Finance review'])->assertOk();
        }
        $this->asRole($mode === 'manager' ? 'Mgr Finance & Settlement' : 'Finance');
        $before = $this->state();
        $t = $before['terms'][0];
        $plan = ApprovalReset::plan($before, $t, false);
        $this->assertSame('legacy-'.$mode, $plan['mode']);
        if ($mode === 'manager') {
            $stale = $t;
            $stale['legacySettlementReview']['decision']['kasbonId'] = 'archived-kasbon';
            $this->assertFalse(ApprovalReset::plan($before, $stale, false)['own']);
        }
        $this->assertTrue($plan['own']);
        $this->assertSame('', $plan['reason']);
        $decisions = DB::table('approval_decisions')->get()->toJson();
        $after = $this->api('resetPaymentApproval', [$id, $plan['expected'], 'Koreksi review saya'])->assertOk()->json('state.terms.0');
        $this->assertSame('Disetujui', $after['status']);
        $this->assertSame($t['approvals'], $after['approvals']);
        $this->assertSame($t['history'], $after['history']);
        $this->assertSame($decisions, DB::table('approval_decisions')->get()->toJson());
        $this->assertEquals(0, $after['paid']);
        $this->assertSame(1, $after['approvalResetVersion']);
        $targets = [];
        app(TelegramPendingApprovals::class)->each(function ($row) use (&$targets) {
            $targets[] = $row;
        });
        $this->assertCount(1, $targets);
        $this->assertSame(auth()->id(), $targets[0]['user_id']);
        $this->assertNotSame($initialTarget, $targets[0]['key']);
    }
}
