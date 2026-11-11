<?php

namespace Tests\Feature;

use App\Models\PrototypeWorkspace;
use App\Services\AuthenticatedWorkspace;
use App\Services\ProjectHeadCorrection;
use App\Services\ProjectHeadReopen;
use App\Services\PrototypeDomain;
use App\Services\RelationalWorkflowStore;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\AuthenticatesArea3;
use Tests\TestCase;

class ProjectHeadCorrectionTest extends TestCase
{
    use AuthenticatesArea3, RefreshDatabase;

    private function fixture(): array
    {
        Storage::fake('local');
        $this->seedWorkflow('D');
        $this->asRole('Admin Konstruksi');
        $w = PrototypeWorkspace::firstOrFail();
        $store = app(RelationalWorkflowStore::class);
        $state = app(AuthenticatedWorkspace::class)->bind($store->read($w));
        $p = &$state['projects'][0];
        $p['workflowVersion'] = 3;
        $p['approvalScheme'] = 'project-support-first';
        $p['approvals'] = [];
        foreach (PrototypeDomain::APPROVERS_V3 as $i => $role) {
            $p['approverUserIds'][$role] = 'db-user-'.$this->accounts[$role]->getKey();
            $p['approvals'][] = ['role' => $role, 'userId' => $p['approverUserIds'][$role], 'actorName' => $this->accounts[$role]->name,
                'actorNik' => '', 'decision' => 'Setujui', 'note' => 'Actual fixture approval', 'at' => '2026-11-10T12:0'.$i.':00.000000Z'];
        }
        $p['approvalHistory'] = $p['approvals'];
        unset($p);
        $output = app(PrototypeDomain::class)->execute($state, 'createTerm', [$state['spks'][0]['id']], 'term-head-correction');
        $state = $output['state'];
        $state['terms'][0]['type'] = 'Final';
        $state['terms'][0]['status'] = 'Ditolak';
        $state['terms'][0]['history'] = [['role' => 'PM Konstruksi', 'userId' => $state['projects'][0]['approverUserIds']['PM Konstruksi'],
            'actorName' => 'PM Konstruksi Test', 'decision' => 'Tolak', 'note' => 'Correction needed', 'at' => '2026-11-11T01:00:00.000000Z']];
        DB::transaction(fn () => $store->write($w, $state));
        $p = DB::table('project_plans')->first();
        $run = DB::table('approval_runs')->where('pp_id', $p->id_pp)->first();
        $step = DB::table('approval_steps')->where('run_id', $run->id_approval_run)->where('step_no', 5)->first();
        $plan = ['workspaceId' => $w->id, 'ppId' => $p->id_pp, 'sourceId' => $p->source_id, 'ppNo' => $p->pp_no, 'ppRevision' => $p->revision,
            'runId' => $run->id_approval_run, 'headStepId' => $step->id_approval_step,
            'headDecisionId' => DB::table('approval_decisions')->where('step_id', $step->id_approval_step)->value('id_approval_decision'),
            'contractId' => DB::table('contracts')->first()->id_contract, 'spkId' => DB::table('work_orders')->first()->id_spk,
            'termId' => DB::table('payment_terms')->first()->id_payment_term, 'requestId' => (string) Str::uuid(), 'reason' => 'Head approval was accidental; preserve actual earlier decisions.'];
        $operator = $this->asRole('Superadmin');

        return [$w, $state, $plan, $operator];
    }

    private function api(string $command, array $args)
    {
        return $this->postJson('/api/prototype/commands', ['command' => $command, 'args' => $args, 'revision' => PrototypeWorkspace::firstOrFail()->revision, 'request_id' => (string) Str::uuid()]);
    }

    public function test_inspection_writes_nothing_and_apply_keeps_original_decisions_and_descendants_then_head_can_approve_again(): void
    {
        [$w, $before, $plan, $operator] = $this->fixture();
        $store = app(RelationalWorkflowStore::class);
        $service = app(ProjectHeadCorrection::class);
        $archive = $w->state;
        $oldDecisions = DB::table('approval_decisions')->get()->keyBy('id_approval_decision');
        $oldRuns = DB::table('approval_runs')->get()->keyBy('id_approval_run');
        $unchanged = [];
        foreach (['contracts', 'work_orders', 'payment_terms', 'term_actuals', 'boq_lines', 'project_plan_costs', 'volume_balances', 'workflow_metadata'] as $table) {
            $unchanged[$table] = DB::table($table)->get()->toJson();
        }
        $writes = [];
        $sqlLog = [];
        $count = 0;
        DB::listen(function (QueryExecuted $q) use (&$writes, &$count, &$sqlLog) {
            $count++;
            $sqlLog[] = $q->sql;
            if (preg_match('/^(insert|update|delete)/i', $q->sql)) {
                $writes[] = $q->sql;
            }
        });
        $inspection = $service->run($plan, $operator);
        $this->assertFalse($inspection['applied']);
        $this->assertSame([], $writes);
        $this->assertSame([], Storage::disk('local')->allFiles());
        $count = 0;
        $result = $service->run($plan, $operator, $inspection['workspaceRevision'], true);
        $queries = $count;
        $this->assertLessThanOrEqual(140, $queries, 'One inspected PP/five stages; no full catalog SQL.');
        $this->assertDoesNotMatchRegularExpression('/designator_package_prices|"designators"|"packages"/', implode("\n", $sqlLog));
        foreach ($writes as $sql) {
            $this->assertDoesNotMatchRegularExpression('/(?:insert into|update|delete from) "(?:contracts|work_orders|payment_terms|term_actuals|boq_lines|volume_balances|project_plan_costs)"/i', $sql);
        }
        $saved = $store->read($w->fresh());
        $p = $saved['projects'][0];
        $this->assertSame('Diajukan', $p['status']);
        $this->assertSame(array_slice($before['projects'][0]['approvals'], 0, 4), $p['approvals']);
        $this->assertSame($before['projects'][0]['approvalHistory'], $p['approvalHistory']);
        $this->assertSame('Head of PMO', PrototypeDomain::nextApprovalRole($p, true));
        $this->assertTrue($result['applied']);
        $this->assertSame('private', Storage::disk('local')->getVisibility($result['backup']));
        $this->assertSame($result['backupSha256'], hash('sha256', Storage::disk('local')->get($result['backup'])));
        foreach ($unchanged as $table => $bytes) {
            if ($table !== 'workflow_metadata') {
                $this->assertSame($bytes, DB::table($table)->get()->toJson(), $table);
            }
        }
        $this->assertSame($archive, $w->fresh()->state);
        $newRun = DB::table('approval_runs')->where('pp_id', $plan['ppId'])->where('cycle_no', 2)->first();
        $this->assertSame('PENDING', $newRun->status);
        $newHead = DB::table('approval_steps')->where('run_id', $newRun->id_approval_run)->where('step_no', 5)->first();
        $this->assertDatabaseMissing('approval_decisions', ['step_id' => $newHead->id_approval_step]);
        foreach ($oldDecisions as $id => $old) {
            $this->assertEquals($old, DB::table('approval_decisions')->where('id_approval_decision', $id)->first());
        }
        foreach ($oldRuns as $id => $old) {
            $this->assertEquals($old, DB::table('approval_runs')->where('id_approval_run', $id)->first());
        }
        $this->assertTrue($service->run($plan, $operator, 0, true)['replayed']);
        $this->assertSame($result['workspaceRevision'], $w->fresh()->revision);
        $this->asRole('Admin Konstruksi');
        $this->api('saveTerm', [$saved['terms'][0], true])->assertUnprocessable()->assertJsonFragment(['prototype' => [ProjectHeadReopen::MESSAGE]]);
        $this->asRole('PM Konstruksi');
        $this->api('decideProject', [$p['id'], 'Setujui', 'Not my stage'])->assertForbidden();
        $this->asRole('Head of PMO');
        $this->api('decideProject', [$p['id'], 'Setujui', 'Reviewed again'])->assertOk();
        $p = $store->read($w->fresh())['projects'][0];
        $this->assertSame('Sah', $p['status']);
        $this->assertCount(6, $p['approvalHistory']);
        $this->assertSame(array_slice($before['projects'][0]['approvals'], 0, 4), array_slice($p['approvals'], 0, 4));
        $this->assertDatabaseHas('approval_runs', ['id_approval_run' => $newRun->id_approval_run, 'status' => 'APPROVED']);
        foreach ($oldDecisions as $id => $old) {
            $this->assertEquals($old, DB::table('approval_decisions')->where('id_approval_decision', $id)->first());
        }
        $this->assertDatabaseCount('prototype_audits', 2);
        $this->assertDatabaseCount('command_receipts', 2);
        file_put_contents(sys_get_temp_dir().'/area3-head-query-budget.txt', (string) $queries);
    }

    public function test_stale_revision_wrong_head_wrong_operator_and_changed_term_abort_before_backup_or_writes(): void
    {
        [$w, $state, $plan, $operator] = $this->fixture();
        $service = app(ProjectHeadCorrection::class);
        foreach (['stale', 'head', 'operator', 'term', 'branch', 'inactive'] as $case) {
            $candidate = $plan;
            $who = $operator;
            $revision = $w->revision;
            if ($case === 'stale') {
                $revision++;
            } elseif ($case === 'head') {
                $candidate['headDecisionId'] = (string) Str::uuid();
            } elseif ($case === 'operator') {
                $who = $this->accounts['Admin Konstruksi'];
            } elseif ($case === 'term') {
                DB::table('payment_terms')->where('id_payment_term', $plan['termId'])->update(['status' => 'APPROVED']);
            } elseif ($case === 'branch') {
                DB::table('user_assignments')->where('user_id', $operator->getKey())->update(['active' => false]);
            } elseif ($case === 'inactive') {
                DB::table('users')->where('id_user', $this->accounts['Head of PMO']->getKey())->update(['active' => false]);
            }
            try {
                $service->run($candidate, $who, $revision, true);
                $this->fail('Expected refusal: '.$case);
            } catch (HttpException $e) {
                $this->assertContains($e->getStatusCode(), [403, 409, 422]);
            }
            DB::table('payment_terms')->where('id_payment_term', $plan['termId'])->update(['status' => 'REJECTED']);
            DB::table('user_assignments')->where('user_id', $operator->getKey())->update(['active' => true]);
            DB::table('users')->where('id_user', $this->accounts['Head of PMO']->getKey())->update(['active' => true]);
        }
        $this->assertDatabaseCount('prototype_audits', 0);
        $this->assertDatabaseCount('command_receipts', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertSame('APPROVED', DB::table('project_plans')->value('status'));
    }

    public function test_head_rejection_keeps_both_histories_and_restart_cycle_does_not_carry_old_approvals_again(): void
    {
        [$w, $state, $plan, $operator] = $this->fixture();
        app(ProjectHeadCorrection::class)->run($plan, $operator, $w->revision, true);
        $this->asRole('Head of PMO');
        $this->api('decideProject', [$plan['sourceId'], 'Tolak', ''])->assertUnprocessable();
        $this->api('decideProject', [$plan['sourceId'], 'Tolak', 'Please correct'])->assertOk();
        $p = app(RelationalWorkflowStore::class)->read($w->fresh())['projects'][0];
        $this->assertSame('Ditolak', $p['status']);
        $this->assertSame([], $p['approvals']);
        $this->assertCount(6, $p['approvalHistory']);
        $p['approvalHistory'][] = $state['projects'][0]['approvals'][0];
        $cycles = ProjectHeadReopen::cycles($p, true);
        $this->assertCount(3, $cycles);
        $this->assertCount(5, $cycles[0]);
        $this->assertCount(5, $cycles[1]);
        $this->assertCount(1, $cycles[2]);
        $this->assertDatabaseHas('approval_runs', ['pp_id' => $plan['ppId'], 'cycle_no' => 1, 'status' => 'APPROVED']);
        $this->assertDatabaseHas('approval_runs', ['pp_id' => $plan['ppId'], 'cycle_no' => 2, 'status' => 'REJECTED']);
    }

    public function test_sql_failure_rolls_back_correction_and_original_rows_remain_replayable(): void
    {
        [$w, $state, $plan, $operator] = $this->fixture();
        $before = DB::table('project_plans')->first();
        $count = DB::table('approval_decisions')->count();
        $armed = true;
        DB::listen(function (QueryExecuted $q) use (&$armed) {
            if ($armed && str_starts_with($q->sql, 'insert into "command_receipts"')) {
                $armed = false;
                throw new RuntimeException('Isolated late SQL failure');
            }
        });
        try {
            app(ProjectHeadCorrection::class)->run($plan, $operator, $w->revision, true);
            $this->fail('Expected rollback');
        } catch (RuntimeException $e) {
            $this->assertSame('Isolated late SQL failure', $e->getMessage());
        }
        $this->assertFalse($armed);
        $this->assertEquals($before, DB::table('project_plans')->first());
        $this->assertSame($count, DB::table('approval_decisions')->count());
        $this->assertSame($w->revision, $w->fresh()->revision);
        $this->assertDatabaseCount('prototype_audits', 0);
        $this->assertDatabaseCount('command_receipts', 0);
        $this->assertTrue(app(ProjectHeadCorrection::class)->run($plan, $operator, $w->revision, true)['applied']);
    }

    public function test_cli_inspects_by_default_requires_current_workspace_revision_and_replays_bound_request(): void
    {
        [$w, $state, $plan, $operator] = $this->fixture();
        Storage::disk('local')->put('qa-plan.json', json_encode($plan));
        $args = ['plan' => Storage::disk('local')->path('qa-plan.json'), '--operator' => $operator->username];
        $this->artisan('area3:reopen-pp-head', $args)->assertSuccessful();
        $this->assertDatabaseCount('prototype_audits', 0);
        $this->artisan('area3:reopen-pp-head', $args + ['--apply' => true])->assertFailed();
        $this->assertSame('APPROVED', DB::table('project_plans')->value('status'));
        $apply = $args + ['--apply' => true, '--workspace-revision' => (string) $w->revision];
        $this->artisan('area3:reopen-pp-head', $apply)->assertSuccessful();
        $this->artisan('area3:reopen-pp-head', $apply)->assertSuccessful();
        $this->assertDatabaseCount('prototype_audits', 1);
        $changed = $plan;
        $changed['reason'] = 'A different reason cannot reuse the same correction UUID.';
        try {
            app(ProjectHeadCorrection::class)->run($changed, $operator, $w->revision, true);
            $this->fail('Expected hash-bound conflict');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        $this->api('reopenProjectHead', [$plan])->assertUnprocessable();
    }

    public function test_native_mysql_json_key_order_is_not_treated_as_a_different_decision(): void
    {
        [$w, $state, $plan, $operator] = $this->fixture();
        foreach (DB::table('approval_decisions')->get() as $row) {
            $data = json_decode($row->decision_data, true);
            ksort($data);
            DB::table('approval_decisions')->where('id_approval_decision', $row->id_approval_decision)->update(['decision_data' => json_encode($data)]);
        }
        $before = DB::table('approval_decisions')->get()->keyBy('id_approval_decision');
        $this->assertTrue(app(ProjectHeadCorrection::class)->run($plan, $operator, $w->revision, true)['applied']);
        foreach ($before as $id => $row) {
            $this->assertEquals($row, DB::table('approval_decisions')->where('id_approval_decision', $id)->first());
        }
        $store = app(RelationalWorkflowStore::class);
        $saved = $store->read($w->fresh());
        foreach (['note' => 'Overwriting an actual decision is forbidden', 'actorNik' => 0] as $key => $value) {
            $forged = $saved;
            $forged['projects'][0]['approvalHistory'][0][$key] = $value;
            try {
                DB::transaction(fn () => $store->write($w->fresh(), $forged, $saved));
                $this->fail('Expected immutable decision rejection');
            } catch (RuntimeException $e) {
                $this->assertSame('Riwayat keputusan approval tidak boleh ditimpa.', $e->getMessage());
            }
        }
        $this->assertSame($saved, $store->read($w->fresh()));
    }

    public function test_all_descendant_commands_resolve_the_saved_parent_and_refuse_while_correction_is_pending(): void
    {
        [$w, $state, $plan, $operator] = $this->fixture();
        app(ProjectHeadCorrection::class)->run($plan, $operator, $w->revision, true);
        $state = app(RelationalWorkflowStore::class)->read($w->fresh());
        $term = $state['terms'][0];
        $state['bapps'][] = ['id' => 'bapp-qa', 'termId' => $term['id']];
        $commands = ['saveDeal' => [$state['deals'][0]], 'saveSpk' => [$state['spks'][0]], 'saveSpkFinance' => [$state['spks'][0]],
            'saveTerm' => [$term], 'submitFinanceTerm' => [$term], 'createTerm' => [$term['spkId']], '_recordPayeeFile' => [$term['spkId']], '_setPayeeVersion' => [$term['spkId']]];
        foreach (['decide', 'adoptFinancePayment', '_issueKasbon', '_attachTransferProof', 'decideFinancePayment', 'requestPartialPayment', 'verifyPartialPayment', 'payPartialPayment', 'recordApm', 'recordPayment', 'verifyTermScan', '_attachTermScan', '_attachTermWorkEvidence', 'removeTermWorkEvidence'] as $command) {
            $commands[$command] = [$term['id']];
        }
        foreach (['attestBapp', 'releaseVolume', 'createContinuation'] as $command) {
            $commands[$command] = ['bapp-qa'];
        }
        foreach ($commands as $command => $args) {
            try {
                app(PrototypeDomain::class)->execute($state, $command, $args);
                $this->fail('Expected pending parent denial: '.$command);
            } catch (ValidationException $e) {
                $this->assertSame([ProjectHeadReopen::MESSAGE], $e->errors()['prototype']);
            }
        }
        $state['projects'][0]['status'] = 'Sah';
        foreach ($commands as $command => $args) {
            ProjectHeadReopen::assertCommandAllowed($state, $command, $args);
        }
    }
}
