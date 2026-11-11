<?php

namespace Tests\Feature;

use App\Models\PrototypeWorkspace;
use App\Models\User;
use App\Services\AccountAccess;
use App\Services\ContractNumber;
use App\Services\ProjectPlanTemplate;
use App\Services\PrototypeDomain;
use App\Services\PrototypeWorkspaceService;
use App\Services\RelationalWorkflowStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\AuthenticatesArea3;
use Tests\TestCase;

class UsernameApprovalV3Test extends TestCase
{
    use AuthenticatesArea3, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function api(string $command, array $args)
    {
        $revision = $this->getJson('/api/prototype/state')->assertOk()->json('revision');

        return $this->postJson('/api/prototype/commands', ['command' => $command, 'args' => $args, 'revision' => $revision, 'request_id' => (string) Str::uuid()]);
    }

    private function assertAttestationQueries(array $queries, bool $financial = false): void
    {
        $this->assertLessThanOrEqual($financial ? 68 : 60, count($queries), 'Bounded small HTTP attestation fixture (up to two PP), including the revision read.');
        foreach ($queries as $query) {
            $this->assertDoesNotMatchRegularExpression('/^\s*(?:insert into|update|delete from) ["`]*(?:project_plans|boq_lines|boq_components|approval_runs|approval_steps|approval_decisions|document_files|term_scans|term_work_evidence)["` ]/i', $query['query']);
        }
    }

    private function chain(): array
    {
        $chain = [];
        foreach (PrototypeDomain::APPROVERS_V3 as $role) {
            $chain[$role] = 'db-user-'.$this->accounts[$role]->getKey();
        }

        return $chain;
    }

    private function newProject(): array
    {
        $this->seedWorkflow('B');
        $this->asRole('Admin Konstruksi');
        $s = $this->getJson('/api/prototype/state')->json('state');
        $p = app(PrototypeWorkspaceService::class)->scenario('B')['projects'][0];
        $p['id'] = $p['rootId'] = 'pp-five-approvers';
        $p['workflowVersion'] = 2; // Client cannot downgrade the new server workflow.
        $p['unit'] = 'Konstruksi';
        $p['branch'] = 'PEKALONGAN';
        $p['riskLevel'] = 'SEDANG';
        $p['costs']['permit'] = 0;
        $p['approverUserIds'] = $this->chain();
        $k = collect($s['khs'])->firstWhere('active', true);
        $p['boq'] = [['id' => 'boq-five-approvers', 'khsId' => $k['id'], 'qty' => 10.125, 'm' => 1, 's' => 1]];

        return $p;
    }

    private function projectRecord(string $id): array
    {
        return collect($this->getJson('/api/prototype/state')->json('state.projects'))->firstWhere('id', $id);
    }

    private function budgetProject(): array
    {
        $p = $this->newProject();
        $s = $this->getJson('/api/prototype/state')->json('state');
        $k = collect($s['khs'])->first(fn ($row) => $row['active'] && ($row['s'] ?? 0) >= 100);
        $p['boq'][0]['khsId'] = $k['id'];
        $p['boq'][0]['qty'] = 10;
        $b = json_decode(file_get_contents(base_path('tests/Fixtures/pp-budget-vector.json')), true)['project']['planBudget'];
        $b['mandor'] = [['rowId' => $p['boq'][0]['id'], 'qtyM' => 0, 'qtyS' => 5, 'priceM' => 0, 'priceS' => 100]];
        $b['usage'][0]['amount'] = $b['usage'][1]['amount'] = 250;
        $p['planBudget'] = $b;

        return $p;
    }

    public function test_complete_header_can_save_a_real_draft_without_boq_schedule_budget_or_approvers(): void
    {
        $p = $this->newProject();
        $p['boq'] = $p['schedule'] = $p['approverUserIds'] = [];
        $p['budget'] = 0;
        unset($p['planBudget'], $p['riskLevel'], $p['signatureRows']);
        $this->api('saveProject', [$p, false])->assertOk();
        $draft = $this->projectRecord($p['id']);
        $this->assertSame('Draft', $draft['status']);
        $this->assertSame('Belum diimpor', $draft['boqStatus']);
        $this->assertEquals(0, $draft['budget']);
        $this->assertSame('khs-auto-v1', $draft['boqPricingPolicy']);
        $this->assertSame([], $draft['boq']);
        $this->assertSame([], $draft['approverUserIds']);
        $this->assertSame([], $draft['approvals']);
        $this->assertSame(0, $draft['durationDays']);
        $this->assertCount(5, $draft['schedule']);
        $this->assertEquals(['mandor' => 0, 'material' => 0, 'bop' => 0, 'permit' => 0], $draft['costs']);
        $sql = DB::table('project_plans')->where('source_id', $p['id'])->first();
        $this->assertSame('DRAFT', $sql->status);
        $this->assertSame($draft['no'], $sql->pp_no);
        $this->assertSame(0, DB::table('boq_lines')->where('root_pp_id', $sql->id_pp)->count());
        $this->assertSame(0, DB::table('project_plan_budget_rows')->where('pp_id', $sql->id_pp)->count());
        $this->assertEquals($draft['boq'], json_decode($sql->document_data, true)['boq']);
        $this->get('/project-plan')->assertOk();
        $again = $draft;
        $again['name'] = 'Header dilanjutkan';
        $this->api('saveProject', [$again, false])->assertOk();
        $this->assertSame($draft['no'], $this->projectRecord($p['id'])['no']);
        $this->assertSame(1, DB::table('project_plans')->where('source_id', $p['id'])->count());
        $before = $this->getJson('/api/prototype/state')->json();
        $again['approverUserIds'] = $this->chain();
        $again['schedule'] = array_fill(0, 5, ['days' => 1]);
        $this->api('saveProject', [$again, true])->assertUnprocessable();
        $after = $this->getJson('/api/prototype/state')->json();
        $this->assertSame($before['revision'], $after['revision']);
        $this->assertSame($before['state']['projects'], $after['state']['projects']);
    }

    public function test_incomplete_header_cannot_create_draft_or_consume_number_revision_and_audit(): void
    {
        $p = $this->newProject();
        $p['boq'] = $p['schedule'] = $p['approverUserIds'] = [];
        $before = $this->getJson('/api/prototype/state')->json();
        foreach (['name', 'projectCode', 'date', 'branch'] as $field) {
            $bad = $p;
            $bad[$field] = '';
            $this->api('saveProject', [$bad, false])->assertStatus($field === 'branch' ? 403 : 422);
            $after = $this->getJson('/api/prototype/state')->json();
            $this->assertSame($before['revision'], $after['revision']);
            $this->assertSame($before['state']['settings'], $after['state']['settings']);
            $this->assertDatabaseMissing('project_plans', ['source_id' => $p['id']]);
            $this->assertDatabaseCount('prototype_audits', 0);
        }
    }

    public function test_bop_submission_does_not_require_or_trust_removed_limit_and_reference_fields(): void
    {
        $p = $this->budgetProject();
        $p['planBudget']['bopLimit'] = 1;
        $p['planBudget']['bopReference'] = ['stale' => 'client field no longer used'];
        $this->api('saveProject', [$p, true])->assertOk();
        $saved = $this->projectRecord($p['id']);
        $this->assertSame('Diajukan', $saved['status']);
        $this->assertEquals(30.02, $saved['costs']['bop']);
        $this->assertEquals(30.02, $saved['planBudgetTotals']['usage']['bop']);
        $this->assertEquals(555.05, $saved['planBudgetTotals']['total']);
        $this->assertArrayNotHasKey('bopLimit', $saved['planBudget']);
        $this->assertArrayNotHasKey('bopReference', $saved['planBudget']);
        $sql = DB::table('project_plans')->where('source_id', $p['id'])->first();
        $this->assertEquals($saved['planBudget'], json_decode($sql->document_data, true)['planBudget']);
    }

    public function test_contract_numbers_follow_the_saved_pp_in_sql_snapshots_and_reject_duplicates_atomically(): void
    {
        $p = $this->budgetProject();
        $this->api('saveProject', [$p, true])->assertOk();
        foreach (PrototypeDomain::APPROVERS_V3 as $role) {
            $this->asRole($role);
            $this->api('decideProject', [$p['id'], 'Setujui', 'QA'])->assertOk();
        }
        $this->asRole('Admin Konstruksi');
        $p = $this->projectRecord($p['id']);
        $before = $this->getJson('/api/prototype/state')->json('state');
        $deal = app(PrototypeWorkspaceService::class)->scenario('C')['deals'][0];
        $deal['id'] = 'bakh-auto-number-qa';
        unset($deal['evidence']);
        $deal['projectId'] = $p['id'];
        $deal['no'] = 'FORGED/CLIENT';
        $deal['rows'] = [['rowId' => $p['boq'][0]['id'], 'm' => false, 's' => true, 'qm' => 0, 'qs' => 1,
            'pm' => 0, 'ps' => 100, 'sphM' => 0, 'sphS' => 100, 'offerM' => 0, 'offerS' => 100,
            'capM' => $p['boq'][0]['m'], 'capS' => $p['boq'][0]['s']]];
        $this->api('saveDeal', [$deal, 'save'])->assertOk();
        $sql = DB::table('contracts')->where('source_id', $deal['id'])->first();
        $number = ContractNumber::fromProject($p['no'], '01');
        $this->assertSame($number, $sql->bakh_no);
        $this->assertSame($number, json_decode($sql->document_data, true)['no']);
        $baseline = $this->getJson('/api/prototype/state')->json();
        $duplicate = $deal;
        $duplicate['id'] = 'bakh-duplicate-qa';
        $duplicate['no'] = 'BYPASS/CLIENT';
        $this->api('saveDeal', [$duplicate, 'save'])->assertUnprocessable();
        $this->assertSame($baseline['revision'], $this->getJson('/api/prototype/state')->json('revision'));
        $this->assertDatabaseMissing('contracts', ['source_id' => $duplicate['id']]);
        $this->api('saveDeal', [$deal, 'generate'])->assertOk();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->api('saveDeal', [$deal, 'attest'])->assertOk();
        $bakhQueries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertAttestationQueries($bakhQueries);
        $this->assertSame('', json_decode(DB::table('contracts')->where('source_id', $deal['id'])->value('document_data'), true)['evidence']);
        $saved = collect($this->getJson('/api/prototype/state')->json('state.deals'))->firstWhere('id', $deal['id']);
        $spk = app(PrototypeWorkspaceService::class)->scenario('D')['spks'][0];
        $spk['id'] = 'spk-auto-number-qa';
        unset($spk['evidence']);
        $spk['dealId'] = $deal['id'];
        $spk['no'] = 'FORGED/CLIENT';
        $spk['start'] = $saved['start'];
        $spk['end'] = $saved['end'];
        $expectedSpk = ContractNumber::fromProject($p['no'], '02');
        foreach (['save', 'generate', 'attest'] as $action) {
            if ($action === 'attest') {
                $this->completeMandorPayee($spk['id']);
            }
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->api('saveSpk', [$spk, $action])->assertOk();
            $spkQueries = DB::getQueryLog();
            DB::disableQueryLog();
            $this->assertAttestationQueries($spkQueries, true);
            $row = DB::table('work_orders')->where('source_id', $spk['id'])->first();
            $this->assertSame('', json_decode($row->document_data, true)['evidence']);
            $this->assertSame($expectedSpk, $row->spk_no);
            $this->assertSame($expectedSpk, json_decode($row->document_data, true)['no']);
            $this->assertSame($expectedSpk, DB::table('contracts')->where('source_id', $deal['id'])->value('spk_no'));
        }
        $after = $this->getJson('/api/prototype/state')->json('state');
        $this->assertSame($before['settings'], $after['settings']);
        $this->assertSame($before['projects'], $after['projects']);
    }

    public function test_sph_threshold_uses_saved_pp_budget_and_rejects_over_limit_requests_atomically(): void
    {
        $p = $this->budgetProject();
        $this->api('saveProject', [$p, true])->assertOk();
        foreach (PrototypeDomain::APPROVERS_V3 as $role) {
            $this->asRole($role);
            $this->api('decideProject', [$p['id'], 'Setujui', 'QA'])->assertOk();
        }
        $this->asRole('Admin Konstruksi');
        $saved = $this->projectRecord($p['id']);
        $deal = app(PrototypeWorkspaceService::class)->scenario('C')['deals'][0];
        $deal['id'] = 'bakh-sph-http-qa';
        $deal['no'] = 'SPH/HTTP/QA';
        $deal['projectId'] = $p['id'];
        $deal['date'] = $deal['start'] = $deal['end'] = $saved['schedule'][0]['start'];
        $deal['evidence'] = 'Bukti sintetis QA';
        $deal['rows'] = [['rowId' => $saved['boq'][0]['id'], 'm' => false, 's' => true, 'qm' => 0, 'qs' => 1, 'pm' => 0, 'ps' => 100, 'sphM' => 0, 'sphS' => 100, 'offerM' => 0, 'offerS' => 100, 'capM' => $saved['boq'][0]['m'], 'capS' => $saved['boq'][0]['s']]];
        $before = $this->getJson('/api/prototype/state')->json();
        $bad = $deal;
        $bad['rows'][0]['sphS'] = 100.01;
        $bad['status'] = 'Sah';
        $bad['sphThresholdS'] = 999999;
        $bad['planBudget'] = ['mandor' => [['priceS' => 999999]]];
        $badAgreement = $deal;
        $badAgreement['rows'][0]['ps'] = 100.01;
        $badAgreement['status'] = 'Sah';
        $badAgreement['agreementThresholdS'] = 999999;
        $badAgreement['planBudget'] = ['mandor' => [['priceS' => 999999]]];
        foreach (['SPH Jasa' => $bad, 'Harga kesepakatan Jasa' => $badAgreement] as $message => $invalid) {
            foreach (['save', 'generate', 'attest'] as $action) {
                DB::flushQueryLog();
                DB::enableQueryLog();
                $response = $this->api('saveDeal', [$invalid, $action])->assertUnprocessable()->assertJsonValidationErrors('prototype');
                $queries = DB::getQueryLog();
                DB::disableQueryLog();
                $this->assertStringContainsString($message, implode(' ', $response->json('errors.prototype')));
                $this->assertEmpty(array_filter($queries, fn ($q) => preg_match('/^\s*(insert|update|delete|replace)\b/i', $q['query'])));
                $after = $this->getJson('/api/prototype/state')->json();
                $this->assertSame($before['revision'], $after['revision']);
                $this->assertSame($before['state']['deals'], $after['state']['deals']);
                $this->assertSame($before['state']['projects'], $after['state']['projects']);
                $this->assertDatabaseCount('prototype_audits', 6);
            }
        }
        $this->api('saveDeal', [$deal, 'save'])->assertOk();
        $this->api('saveDeal', [$deal, 'generate'])->assertOk();
        $bad['rows'][0]['ps'] = 999999;
        $this->api('saveDeal', [$bad, 'attest'])->assertOk();
        $record = collect($this->getJson('/api/prototype/state')->json('state.deals'))->firstWhere('id', $deal['id']);
        $this->assertEquals(100, $record['rows'][0]['sphS']);
        $this->assertEquals(100, $record['rows'][0]['ps']);
        $this->assertSame('Sah', $record['status']);
    }

    public function test_bakh_dates_are_server_derived_from_pp_and_persist_in_sql_for_old_draft_and_attestation(): void
    {
        $p = $this->budgetProject();
        $p['date'] = '2026-11-30';
        $this->accounts['Head of PMO']->forceFill(['name' => 'Selected Head QA', 'nik' => 'QA-PMO-17'])->save();
        foreach ([1, 4, 7, 1, 1] as $i => $days) {
            $p['schedule'][$i]['days'] = $days;
        }
        $this->api('saveProject', [$p, true])->assertOk();
        foreach (PrototypeDomain::APPROVERS_V3 as $role) {
            $this->asRole($role);
            $this->api('decideProject', [$p['id'], 'Setujui', 'QA'])->assertOk();
        }
        $this->asRole('Admin Konstruksi');
        $saved = $this->projectRecord($p['id']);
        $deal = app(PrototypeWorkspaceService::class)->scenario('C')['deals'][0];
        $deal['id'] = 'bakh-date-http-qa';
        $deal['no'] = 'BAKH/DATES/QA';
        $deal['projectId'] = $p['id'];
        $deal['start'] = '2000-01-01';
        $deal['end'] = '2100-12-31';
        $deal['evidence'] = 'Bukti sintetis QA';
        $deal['signer'] = 'FORGED SIGNER';
        $deal['signerNik'] = 'FORGED NIK';
        $deal['signerUserId'] = 'db-user-'.$this->accounts['PM Konstruksi']->getKey();
        $deal['rows'] = [['rowId' => $saved['boq'][0]['id'], 'm' => false, 's' => true, 'qm' => 0, 'qs' => 1, 'pm' => 0, 'ps' => 100, 'sphM' => 0, 'sphS' => 100, 'offerM' => 0, 'offerS' => 100, 'capM' => $saved['boq'][0]['m'], 'capS' => $saved['boq'][0]['s']]];
        foreach (['save', 'generate', 'attest'] as $action) {
            $response = $this->api('saveDeal', [$deal, $action])->assertOk();
            $record = collect($response->json('state.deals'))->firstWhere('id', $deal['id']);
            $this->assertSame('Selected Head QA', $record['signer']);
            $this->assertSame('QA-PMO-17', $record['signerNik']);
            $this->assertSame('db-user-'.$this->accounts['Head of PMO']->getKey(), $record['signerUserId']);
            $this->assertSame('2026-11-30', $record['start']);
            $this->assertSame('2026-12-13', $record['end']);
            $sql = DB::table('contracts')->where('source_id', $deal['id'])->first();
            $this->assertSame('Selected Head QA', $sql->signer_name);
            $this->assertSame('Head of PMO', $sql->signer_position);
            $this->assertSame('QA-PMO-17', json_decode($sql->document_data, true)['signerNik']);
            $this->assertSame('2026-11-30', $sql->start_date);
            $this->assertSame('2026-12-13', $sql->end_date);
            $this->assertSame('2026-12-13', json_decode($sql->document_data, true)['end']);
        }
        $this->assertSame($saved, $this->projectRecord($p['id']));
    }

    public function test_spk_head_identity_is_server_owned_and_stored_in_sql_snapshots_with_bounded_queries(): void
    {
        $p = $this->budgetProject();
        $this->accounts['Head of PMO']->forceFill(['name' => 'SPK Head QA', 'nik' => '000017'])->save();
        $this->api('saveProject', [$p, true])->assertOk();
        foreach (PrototypeDomain::APPROVERS_V3 as $role) {
            $this->asRole($role);
            $this->api('decideProject', [$p['id'], 'Setujui', 'QA'])->assertOk();
        }
        $this->asRole('Admin Konstruksi');
        $pp = $this->projectRecord($p['id']);
        $deal = app(PrototypeWorkspaceService::class)->scenario('C')['deals'][0];
        $deal['id'] = 'bakh-spk-head-qa';
        $deal['projectId'] = $p['id'];
        $deal['rows'] = [['rowId' => $pp['boq'][0]['id'], 'm' => false, 's' => true, 'qm' => 0, 'qs' => 1, 'pm' => 0, 'ps' => 100, 'sphM' => 0, 'sphS' => 100, 'offerM' => 0, 'offerS' => 100, 'capM' => null, 'capS' => $pp['boq'][0]['s']]];
        $this->api('saveDeal', [$deal, 'attest'])->assertOk();
        $savedDeal = collect($this->getJson('/api/prototype/state')->json('state.deals'))->firstWhere('id', $deal['id']);
        $spk = app(PrototypeWorkspaceService::class)->scenario('D')['spks'][0];
        $spk['id'] = 'spk-head-qa';
        $spk['dealId'] = $deal['id'];
        $spk['start'] = $savedDeal['start'];
        $spk['end'] = $savedDeal['end'];
        $spk['signer'] = $spk['position'] = $spk['signerNik'] = 'FORGED';
        $spk['signerUserId'] = 'some-other-user';
        $archive = DB::table('prototype_workspaces')->value('state');
        foreach (['save', 'generate', 'attest'] as $action) {
            if ($action === 'attest') {
                $this->completeMandorPayee($spk['id']);
            }
            DB::flushQueryLog();
            DB::enableQueryLog();
            $response = $this->api('saveSpk', [$spk, $action])->assertOk();
            $queries = DB::getQueryLog();
            DB::disableQueryLog();
            $this->assertAttestationQueries($queries, true);
            $actual = collect($response->json('state.spks'))->firstWhere('id', $spk['id']);
            $this->assertSame('SPK Head QA', $actual['signer']);
            $this->assertSame('000017', $actual['signerNik']);
            $this->assertSame($pp['approverUserIds']['Head of PMO'], $actual['signerUserId']);
            $sql = DB::table('work_orders')->where('source_id', $spk['id'])->first();
            $this->assertSame('000017', json_decode($sql->document_data, true)['signerNik']);
            $this->assertDatabaseHas('contracts', ['source_id' => $deal['id'], 'signer_name' => 'SPK Head QA', 'signer_position' => 'HEAD OF PMO AREA JAWA BALI']);
            $this->accounts['Head of PMO']->forceFill(['name' => 'Account renamed QA', 'nik' => 'OTHER'])->save();
        }
        $this->assertSame($pp, $this->projectRecord($p['id']));
        $this->assertSame($archive, DB::table('prototype_workspaces')->value('state'));
    }

    public function test_adopting_budget_template_archives_existing_v3_draft_notes_and_costs_without_changing_them_on_read(): void
    {
        $p = $this->newProject();
        $this->api('saveProject', [$p, false])->assertOk();
        $workspace = PrototypeWorkspace::firstOrFail();
        $state = app(RelationalWorkflowStore::class)->read($workspace);
        $previous = ['costs' => ['mandor' => 123, 'material' => 45, 'bop' => 67, 'permit' => 0], 'risk' => 'Catatan risiko lama', 'communication' => 'Catatan komunikasi lama', 'quality' => 'Catatan kualitas lama'];
        foreach ($state['projects'] as &$row) {
            if ($row['id'] === $p['id']) {
                unset($row['planBudget'], $row['planBudgetTotals']);
                $row = array_replace($row, $previous);
            }
        }
        unset($row);
        DB::transaction(fn () => app(RelationalWorkflowStore::class)->write($workspace, $state));
        $before = $this->projectRecord($p['id']);
        foreach ($previous as $key => $value) {
            $this->assertSame($value, $before[$key]);
        }
        $this->assertArrayNotHasKey('planBudget', $before);
        $this->api('saveProject', [$p, false])->assertOk();
        $saved = $this->projectRecord($p['id']);
        $this->assertSame($previous, $saved['previousPlanning']);
        $this->assertSame('Catatan risiko lama', $saved['previousPlanning']['risk']);
        $this->assertSame($before['budget'], $saved['budget']);
        $this->assertSame($before['boq'], $saved['boq']);
    }

    public function test_budget_rows_fixed_text_real_signer_metadata_and_totals_are_server_owned(): void
    {
        $p = $this->budgetProject();
        $this->accounts['PM Konstruksi']->forceFill(['nik' => '00112233'])->save();
        $p['planBudgetTotals'] = ['total' => -1, 'revenue' => 999999];
        $p['costs'] = ['mandor' => 999, 'material' => 999, 'bop' => 999, 'permit' => 999];
        $p['communication'] = $p['quality'] = $p['closing'] = 'CLIENT';
        $p['preparedBy'] = ['name' => 'FAKE CREATOR', 'nik' => 'FAKE'];
        $p['signatureUsers'] = ['PM Konstruksi' => ['name' => 'FAKE PM', 'nik' => 'FAKE']];
        $p['signatureRows'] = [['id' => 'signature-qa', 'label' => 'Disetujui Oleh', 'role' => 'Head of PMO', 'job' => 'Jabatan pada cetakan', 'name' => 'FAKE', 'date' => '2000-01-01']];
        $this->api('saveProject', [$p, true])->assertOk();
        $saved = $this->projectRecord($p['id']);
        $this->assertEquals(555.05, $saved['planBudgetTotals']['total']);
        $this->assertEquals(555.05, $saved['planBudgetTotals']['cogs']);
        $this->assertEquals(7.03, $saved['planBudgetTotals']['permit']);
        $this->assertEquals(500, $saved['costs']['mandor']);
        $this->assertEquals(25.03, $saved['costs']['material']);
        $this->assertEquals($saved['boq'][0]['s'] * 10, $saved['planBudgetTotals']['revenue']);
        $this->assertStringContainsString('Lensa Pro/Group Chat', $saved['communication']);
        $this->assertStringContainsString('Commissioning Test dan Uji Terima', $saved['quality']);
        $this->assertStringStartsWith('Project Plan ini disusun dengan sebenar-benarnya', $saved['closing']);
        $this->assertSame($this->accounts['Admin Konstruksi']->name, $saved['preparedBy']['name']);
        $this->assertSame($this->accounts['PM Konstruksi']->name, $saved['signatureUsers']['PM Konstruksi']['name']);
        $this->assertSame('00112233', $saved['signatureUsers']['PM Konstruksi']['nik']);
        $this->assertArrayNotHasKey('name', $saved['signatureRows'][0]);
        $this->assertArrayNotHasKey('date', $saved['signatureRows'][0]);
        foreach (PrototypeDomain::APPROVERS_V3 as $i => $role) {
            $this->asRole($role);
            $this->api('decideProject', [$p['id'], 'Setujui', 'QA'])->assertOk();
            $this->assertSame($i === 4 ? 'Sah' : 'Diajukan', $this->projectRecord($p['id'])['status']);
        }
        $this->assertCount(5, $this->projectRecord($p['id'])['approvals']);
        $this->assertSame('00112233', $this->projectRecord($p['id'])['approvals'][0]['actorNik']);
        $this->asRole('Admin Konstruksi');
        $this->api('saveProject', [$saved, false])->assertUnprocessable();
    }

    public function test_invalid_budget_does_not_write_audit_revision_or_saved_rows_and_print_rows_can_be_deleted(): void
    {
        $p = $this->budgetProject();
        $p['signatureRows'] = [];
        $this->api('saveProject', [$p, false])->assertOk();
        $before = $this->getJson('/api/prototype/state')->json();
        foreach (['sourceAmount', 'bopAmount', 'ids', 'component', 'risk'] as $case) {
            $bad = $p;
            if ($case === 'sourceAmount') {
                $bad['planBudget']['materials'][0]['price'] = -1;
            }
            if ($case === 'bopAmount') {
                $bad['planBudget']['bop'][0]['total'] = -1;
            }
            if ($case === 'ids') {
                $bad['planBudget']['materials'][] = $bad['planBudget']['materials'][0];
            }
            if ($case === 'component') {
                $bad['planBudget']['mandor'][0]['priceM'] = 1;
            }
            if ($case === 'risk') {
                $bad['riskLevel'] = '';
            }
            $this->api('saveProject', [$bad, true])->assertUnprocessable();
            $after = $this->getJson('/api/prototype/state')->json();
            $this->assertSame($before['revision'], $after['revision']);
            $this->assertSame($before['state']['projects'], $after['state']['projects']);
            $this->assertDatabaseCount('prototype_audits', 1);
        }
        $this->api('saveProject', [$p, true])->assertOk();
        $saved = $this->projectRecord($p['id']);
        $this->assertSame([], $saved['signatureRows']);
        $this->assertCount(5, $saved['approverUserIds']);
        $this->assertSame('Diajukan', $saved['status']);
    }

    public function test_pp_submission_rebuilds_automatic_budget_recap_and_persists_it_without_terms_or_dates(): void
    {
        $p = $this->budgetProject();
        $p['planBudget']['usage'] = [['id' => 'stale-usage', 'category' => 'mandor', 'amount' => 1, 'term' => '', 'date' => '2026-02-30']];
        $this->api('saveProject', [$p, false])->assertOk();
        $draft = $this->projectRecord($p['id']);
        $this->assertSame(2, $draft['planBudget']['version']);
        $this->assertEquals(500, $draft['planBudgetTotals']['usage']['mandor']);
        $p = $draft;
        $p['planBudget']['usage'] = 'do not trust client recap';
        $this->api('saveProject', [$p, true])->assertOk();
        $saved = $this->projectRecord($p['id']);
        $this->assertSame('Diajukan', $saved['status']);
        $this->assertCount(0, $saved['approvals']);
        $this->assertEquals(555.05, $saved['planBudgetTotals']['total']);
        $this->assertEquals(7.03, $saved['planBudgetTotals']['usage']['permit']);
        $sql = DB::table('project_plans')->where('source_id', $p['id'])->first();
        $rows = DB::table('project_plan_budget_rows')->where('pp_id', $sql->id_pp)->where('category', 'usage')->orderBy('position')->pluck('row_data')->map(fn ($row) => json_decode($row, true))->all();
        $this->assertEquals($saved['planBudget']['usage'], $rows);
        $this->assertEquals($saved['planBudget'], json_decode($sql->document_data, true)['planBudget']);
        foreach ($rows as $row) {
            $this->assertGreaterThan(0, $row['amount']);
            $this->assertArrayNotHasKey('term', $row);
            $this->assertArrayNotHasKey('date', $row);
        }
        $before = $saved;
        $this->api('saveProject', [$saved, false])->assertUnprocessable();
        $this->assertSame($before, $this->projectRecord($p['id']));
    }

    public function test_reading_and_approving_an_existing_manual_budget_keeps_its_historical_schedule(): void
    {
        $p = $this->budgetProject();
        $this->api('saveProject', [$p, true])->assertOk();
        $sql = DB::table('project_plans')->where('source_id', $p['id'])->first();
        $historical = json_decode($sql->document_data, true);
        // Emulate an already submitted pre-change snapshot in isolated memory storage only.
        $historical['planBudget'] = $p['planBudget'];
        DB::table('project_plans')->where('id_pp', $sql->id_pp)->update(['document_data' => json_encode($historical)]);
        $before = DB::table('project_plans')->where('id_pp', $sql->id_pp)->value('document_data');
        $read = $this->projectRecord($p['id']);
        $this->assertEquals($p['planBudget'], $read['planBudget']);
        $this->assertSame($before, DB::table('project_plans')->where('id_pp', $sql->id_pp)->value('document_data'));
        $this->asRole('PM Konstruksi');
        $this->api('decideProject', [$p['id'], 'Setujui', 'QA'])->assertOk();
        $approved = $this->projectRecord($p['id']);
        $this->assertSame(1, $approved['planBudget']['version']);
        $this->assertEquals($p['planBudget']['usage'], $approved['planBudget']['usage']);
        $this->assertEquals($historical['planBudgetTotals'], $approved['planBudgetTotals']);
    }

    public function test_fixed_pp_template_ignores_client_redaction_and_dates_and_submits_without_invented_team(): void
    {
        $p = $this->newProject();
        $p['date'] = '2028-02-28';
        $p['intro'] = 'Redaksi client';
        $p['teamText'] = 'Tim client';
        $p['team'] = [['name' => 'Nama rekaan', 'job' => 'Peran rekaan']];
        $p['planTemplateVersion'] = 999;
        $p['durationDays'] = 999;
        foreach ([2, 0, 3, 1, 1] as $i => $hk) {
            $p['schedule'][$i] = ['name' => 'Nama client', 'days' => $hk, 'start' => '2000-01-01', 'end' => '2000-01-02'];
        }
        $this->api('saveProject', [$p, true])->assertOk();
        $saved = $this->projectRecord($p['id']);
        $this->assertSame(1, $saved['planTemplateVersion']);
        $this->assertSame(7, $saved['durationDays']);
        $this->assertStringContainsString('Mekanisme swakelola berpotensi meningkatkan margin', $saved['intro']);
        $this->assertStringContainsString('Teknisi/Jointer, dan Mandor.', $saved['teamText']);
        $this->assertSame([], $saved['team']);
        $this->assertSame(['name' => 'Persiapan', 'days' => 2, 'start' => '2028-02-28', 'end' => '2028-02-29'], $saved['schedule'][0]);
        $this->assertSame(['name' => 'Material Delivery', 'days' => 0, 'start' => '', 'end' => ''], $saved['schedule'][1]);
        $this->assertSame('2028-03-01', $saved['schedule'][2]['start']);
        $this->assertSame('2028-03-05', $saved['schedule'][4]['end']);
        $this->assertSame('Diajukan', $saved['status']);
        $this->api('saveProject', [$saved, false])->assertUnprocessable();
        $this->assertSame($saved, $this->projectRecord($p['id']));
    }

    public function test_invalid_hk_is_rejected_atomically_and_reading_does_not_rewrite_existing_pp(): void
    {
        $p = $this->newProject();
        $boundary = $p;
        $boundary['date'] = '9999-12-31';
        $boundary['schedule'] = array_map(fn ($days) => ['days' => $days], [1, 0, 0, 0, 0]);
        $this->assertSame('9999-12-31', ProjectPlanTemplate::apply($boundary)['schedule'][0]['end']);
        $original = $this->getJson('/api/prototype/state')->json('state.projects');
        foreach ([-1, 1.5, 1000001] as $hk) {
            $p['schedule'][0]['days'] = $hk;
            $this->api('saveProject', [$p, false])->assertUnprocessable();
            $this->assertSame($original, $this->getJson('/api/prototype/state')->json('state.projects'));
        }
        foreach ($p['schedule'] as &$stage) {
            $stage['days'] = 0;
        }
        unset($stage);
        $this->api('saveProject', [$p, false])->assertOk();
        $draft = $this->projectRecord($p['id']);
        $this->assertSame('Draft', $draft['status']);
        $this->assertSame(0, $draft['durationDays']);
        $beforeSubmit = $this->getJson('/api/prototype/state')->json();
        $this->api('saveProject', [$p, true])->assertUnprocessable();
        $afterSubmit = $this->getJson('/api/prototype/state')->json();
        $this->assertSame($beforeSubmit['revision'], $afterSubmit['revision']);
        $this->assertSame($beforeSubmit['state']['projects'], $afterSubmit['state']['projects']);
    }

    public function test_same_business_project_id_has_multiple_independent_pp_numbers_roots_and_approvals(): void
    {
        $p = $this->newProject();
        $p['projectCode'] = 'QA-MULTIPLE-PP';
        $this->api('saveProject', [$p, true])->assertOk();
        $second = $p;
        $second['id'] = $second['rootId'] = 'pp-second-same-project';
        $second['boq'][0]['id'] = 'boq-second-same-project';
        $second['boq'][0]['qty'] = 25;
        $this->api('saveProject', [$second, true])->assertOk();
        $first = $this->projectRecord($p['id']);
        $other = $this->projectRecord($second['id']);
        $this->assertSame($first['projectCode'], $other['projectCode']);
        $this->assertNotSame($first['no'], $other['no']);
        $this->assertNotSame($first['rootId'], $other['rootId']);
        $this->assertEquals(10.125, $first['boq'][0]['qty']);
        $this->assertEquals(25, $other['boq'][0]['qty']);
        $this->asRole('PM Konstruksi');
        $this->api('decideProject', [$p['id'], 'Setujui', 'QA PP pertama'])->assertOk();
        $this->assertCount(1, $this->projectRecord($p['id'])['approvals']);
        $this->assertCount(0, $this->projectRecord($second['id'])['approvals']);
    }

    public function test_timeline_deep_link_preserves_branch_filter_even_for_matching_business_project_ids(): void
    {
        $p = $this->newProject();
        $p['projectCode'] = 'QA-SAME-ID-ACROSS-BRANCHES';
        $this->api('saveProject', [$p, false])->assertOk();
        $other = $p;
        $other['id'] = $other['rootId'] = 'pp-hidden-same-id';
        $other['branch'] = 'SEMARANG';
        $this->api('saveProject', [$other, false])->assertOk();
        $pm = $this->asRole('PM Konstruksi');
        app(AccountAccess::class)->assign($pm, 'PM_KONSTRUKSI', [DB::table('branches')->where('name', 'PEKALONGAN')->value('id_branch')]);
        foreach ([$p['id'], $other['id']] as $id) {
            $this->get('/project-plan/'.$id.'/timeline')->assertOk()->assertViewHas('boot', function ($boot) use ($p, $other) {
                $this->assertContains($p['id'], array_column($boot['state']['projects'], 'id'));
                $this->assertNotContains($other['id'], array_column($boot['state']['projects'], 'id'));
                $this->assertNotContains('/project-plan/'.$other['id'], array_column($boot['state']['events'], 'route'));

                return true;
            });
        }
    }

    private function payment(bool $final = false): array
    {
        $s = $this->seedWorkflow('D');
        $this->asRole('Admin Konstruksi');
        $p = $s['projects'][0];
        $history = $p;
        $this->api('assignPaymentApprovers', [$p['id'], $this->chain()])->assertOk();
        $after = $this->projectRecord($p['id']);
        unset($after['paymentApproverUserIds'], $history['paymentApproverUserIds']);
        $this->assertSame($history, $after);
        $state = $this->api('createTerm', [$s['spks'][0]['id']])->assertOk()->json('state');
        $state['terms'][0] = $this->historicalPaymentFixture($state['terms'][0]['id']);
        $t = $state['terms'][0];
        $this->assertSame(3, $t['workflowVersion']);
        $this->assertSame($this->chain(), $t['approverUserIds']);
        $t['type'] = $final ? 'Final' : 'Parsial';
        $t['actuals'] = [$s['deals'][0]['rows'][0]['rowId'] => ['m' => 100, 's' => 80]];
        $this->api('saveTerm', [$t, true])->assertOk();

        return $t;
    }

    private function approvePayment(string $id): void
    {
        foreach (PrototypeDomain::APPROVERS_V3 as $i => $role) {
            $this->asRole($role);
            $this->api('decide', [$id, 'Setujui', 'QA '.$role])->assertOk()->assertJsonPath('state.terms.0.status', $i === 4 ? 'Disetujui' : 'Diajukan')->assertJsonCount($i + 1, 'state.terms.0.approvals');
        }
    }

    private function upload(string $id)
    {
        $revision = $this->getJson('/api/prototype/state')->json('revision');

        return $this->post('/api/prototype/payments/'.$id.'/scan', ['revision' => $revision, 'request_id' => (string) Str::uuid(),
            'file' => UploadedFile::fake()->createWithContent('qa-scan-tanpa-ttd.pdf', "%PDF-1.4\nDokumen sintetis QA, bukan tanda tangan asli\n%%EOF")], ['Accept' => 'application/json']);
    }

    public function test_account_identity_preserves_nik_zeroes_and_validates_geographic_hierarchy_and_unique_username(): void
    {
        $this->asRole();
        $service = DB::table('service_areas as s')->join('branches as b', 'b.id_branch', '=', 's.branch_id')->whereNotNull('b.region_id')->first(['s.id_service_area', 's.branch_id', 'b.region_id']);
        $input = ['name' => 'QA manager', 'username' => 'QA.Manager', 'nik' => '00001234', 'email' => 'identity@example.test', 'password' => 'ChosenPassword123', 'password_confirmation' => 'ChosenPassword123',
            'role' => 'MGR_BUSSINESS_SUPPORT', 'id_regions' => $service->region_id, 'id_branch' => $service->branch_id, 'id_service_areas' => $service->id_service_area, 'branches' => [$service->branch_id], 'active' => 1];
        $this->post('/akun', $input)->assertRedirect('/akun');
        $user = User::where('username', 'qa.manager')->firstOrFail();
        $this->assertSame('00001234', $user->nik);
        $this->assertEquals($service->id_service_area, $user->id_service_areas);
        $this->assertSame('MGR_BUSSINESS_SUPPORT', $user->role);
        $this->assertSame('Mgr Bussiness Support', app(AccountAccess::class)->context($user)['role']);
        $this->get('/akun/'.$user->getKey().'/edit')->assertOk()->assertSee('qa.manager')->assertSee('00001234');
        $bad = $input;
        $bad['email'] = 'duplicate@example.test';
        $this->post('/akun', $bad)->assertSessionHasErrors(['username', 'nik']);
        $bad['username'] = 'another';
        $bad['nik'] = null;
        $bad['id_regions'] = DB::table('regions')->where('id_region', '!=', $service->region_id)->value('id_region');
        $this->post('/akun', $bad)->assertSessionHasErrors('id_branch');
        $bad = $input;
        $bad['id_service_areas'] = DB::table('service_areas')->where('branch_id', '!=', $service->branch_id)->value('id_service_area');
        $this->put('/akun/'.$user->getKey(), $bad)->assertSessionHasErrors('id_service_areas');
        $this->assertSame('00001234', $user->fresh()->nik);
        Auth::logout();
        $this->post('/login', ['username' => 'QA.MANAGER', 'password' => 'ChosenPassword123'])->assertRedirect('/dashboard');
        $this->getJson('/api/prototype/state')->assertJsonPath('state.role', 'Mgr Bussiness Support');
    }

    public function test_new_pp_requires_five_selected_people_and_return_restarts_full_chain_without_losing_history(): void
    {
        $p = $this->newProject();
        $missing = $p;
        unset($missing['approverUserIds']['Head of PMO']);
        $this->api('saveProject', [$missing, true])->assertUnprocessable();
        $this->assertDatabaseCount('prototype_audits', 0);
        $this->api('saveProject', [$p, true])->assertOk();
        $this->assertSame(3, $this->projectRecord($p['id'])['workflowVersion']);
        $this->asRole('Head of PMO');
        $this->api('decideProject', [$p['id'], 'Setujui', ''])->assertForbidden();
        $this->asRole('Finance');
        $this->api('decideProject', [$p['id'], 'Setujui', ''])->assertForbidden();
        $this->asRole('PM Konstruksi');
        $this->api('decideProject', [$p['id'], 'Setujui', 'QA PM'])->assertOk();
        $this->asRole('Admin Area');
        $this->api('decideProject', [$p['id'], 'Kembalikan', ''])->assertUnprocessable();
        $this->api('decideProject', [$p['id'], 'Kembalikan', 'Lengkapi QA'])->assertOk();
        $returned = $this->projectRecord($p['id']);
        $this->assertSame('Dikembalikan', $returned['status']);
        $this->assertCount(0, $returned['approvals']);
        $this->assertCount(2, $returned['approvalHistory']);
        $number = $returned['no'];
        $this->asRole('Admin Konstruksi');
        $this->api('saveProject', [$returned, true])->assertOk();
        $this->asRole('Mgr Deployment');
        $this->api('decideProject', [$p['id'], 'Setujui', ''])->assertForbidden();
        foreach (PrototypeDomain::APPROVERS_V3 as $i => $role) {
            $this->asRole($role);
            $this->api('decideProject', [$p['id'], 'Setujui', 'QA '.$role])->assertOk();
            $actual = $this->projectRecord($p['id']);
            $this->assertSame($i === 4 ? 'Sah' : 'Diajukan', $actual['status']);
        }
        $this->assertSame($number, $actual['no']);
        $this->assertCount(7, $actual['approvalHistory']);
        $this->assertSame('Head of PMO', $actual['approvals'][4]['role']);
        $this->api('decideProject', [$p['id'], 'Setujui', 'duplicate'])->assertUnprocessable();
    }

    public static function rejectionStages(): array
    {
        return array_combine(PrototypeDomain::APPROVERS_V3, array_map(fn ($i) => [$i], range(0, 4)));
    }

    #[DataProvider('rejectionStages')]
    public function test_pp_rejected_at_any_stage_requires_admin_resubmission_and_all_five_fresh_decisions(int $stage): void
    {
        $p = $this->newProject();
        $this->api('saveProject', [$p, true])->assertOk();
        foreach (array_slice(PrototypeDomain::APPROVERS_V3, 0, $stage) as $role) {
            $this->asRole($role);
            $this->api('decideProject', [$p['id'], 'Setujui', 'Siklus awal'])->assertOk();
        }
        $this->asRole(PrototypeDomain::APPROVERS_V3[$stage]);
        $before = $this->getJson('/api/prototype/state')->json();
        $this->api('decideProject', [$p['id'], 'Tolak', '   '])->assertUnprocessable();
        $this->assertSame($before, $this->getJson('/api/prototype/state')->json());
        $this->api('decideProject', [$p['id'], 'Tolak', 'Perbaiki rincian PP'])->assertOk();
        $rejected = $this->projectRecord($p['id']);
        $this->assertSame('Ditolak', $rejected['status']);
        $this->assertEmpty($rejected['approvals']);
        $this->assertSame('Perbaiki rincian PP', $rejected['approvalHistory'][$stage]['note']);
        $this->assertDatabaseHas('approval_runs', ['cycle_no' => 1, 'status' => 'REJECTED']);
        $decisions = DB::table('approval_decisions')->orderBy('id_approval_decision')->get()->all();
        $this->api('saveProject', [$rejected, true])->assertForbidden();
        $this->asRole('Admin Konstruksi');
        $forged = $rejected;
        $forged['approvalHistory'] = [];
        $forged['approvals'] = collect($before['state']['projects'])->firstWhere('id', $p['id'])['approvals'];
        $forged['no'] = 'FORGED';
        $this->api('saveProject', [$forged, false])->assertOk();
        $draft = $this->projectRecord($p['id']);
        $this->assertSame('Draft', $draft['status']);
        $this->assertSame($rejected['approvalHistory'], $draft['approvalHistory']);
        $this->assertSame($rejected['no'], $draft['no']);
        $this->assertDatabaseCount('approval_runs', 1);
        $this->asRole('PM Konstruksi');
        $this->api('decideProject', [$p['id'], 'Setujui', 'Belum diajukan ulang'])->assertUnprocessable();
        $this->asRole('Admin Konstruksi');
        $this->api('saveProject', [$draft, true])->assertOk();
        $fresh = $this->projectRecord($p['id']);
        $this->assertEmpty($fresh['approvals']);
        $this->assertDatabaseHas('approval_runs', ['cycle_no' => 2, 'status' => 'PENDING']);
        $this->assertEquals($decisions, DB::table('approval_decisions')->orderBy('id_approval_decision')->get()->all());
        $this->asRole('Admin Area');
        $this->api('decideProject', [$p['id'], 'Setujui', 'Tidak boleh lompat PM'])->assertForbidden();
        foreach (PrototypeDomain::APPROVERS_V3 as $i => $role) {
            $this->asRole($role);
            $this->api('decideProject', [$p['id'], 'Setujui', 'Siklus baru'])->assertOk();
            $this->assertSame($i === 4 ? 'Sah' : 'Diajukan', $this->projectRecord($p['id'])['status']);
        }
        $this->assertCount($stage + 6, $this->projectRecord($p['id'])['approvalHistory']);
        $this->assertDatabaseHas('approval_runs', ['cycle_no' => 1, 'status' => 'REJECTED']);
        $this->assertDatabaseHas('approval_runs', ['cycle_no' => 2, 'status' => 'APPROVED']);
        $this->assertDatabaseCount('approval_decisions', $stage + 6);
    }

    #[DataProvider('rejectionStages')]
    public function test_final_payment_rejected_at_any_stage_restarts_from_pm_without_scan_apm_or_early_ba_pp(int $stage): void
    {
        Storage::fake('local');
        $t = $this->payment(true);
        foreach (array_slice(PrototypeDomain::APPROVERS_V3, 0, $stage) as $role) {
            $this->asRole($role);
            $this->api('decide', [$t['id'], 'Setujui', 'Siklus awal'])->assertOk();
        }
        $this->asRole(PrototypeDomain::APPROVERS_V3[$stage]);
        $before = $this->getJson('/api/prototype/state')->json();
        $this->api('decide', [$t['id'], 'Tolak', ''])->assertUnprocessable();
        $this->assertSame($before, $this->getJson('/api/prototype/state')->json());
        $this->api('decide', [$t['id'], 'Tolak', 'Perbaiki volume dan eviden'])->assertOk();
        $rejected = $this->getJson('/api/prototype/state')->json('state.terms.0');
        $this->assertSame('Ditolak', $rejected['status']);
        $this->assertEmpty($rejected['approvals']);
        $this->assertCount($stage + 1, $rejected['history']);
        $runs = DB::table('approval_runs')->whereNotNull('term_id');
        $oldRun = (clone $runs)->first();
        $this->assertSame('REJECTED', $oldRun->status);
        $decisions = DB::table('approval_decisions')->orderBy('id_approval_decision')->get()->all();
        $this->api('saveTerm', [$rejected, true])->assertForbidden();
        $this->asRole('Admin Konstruksi');
        $this->upload($t['id'])->assertUnprocessable();
        $this->assertEmpty(Storage::disk('local')->allFiles());
        $this->api('createTerm', [$t['spkId']])->assertUnprocessable();
        $forged = $rejected;
        $forged['history'] = [];
        $forged['approvals'] = $before['state']['terms'][0]['approvals'];
        $forged['paid'] = 999999;
        $forged['apmStatus'] = 'Valid';
        $this->api('saveTerm', [$forged, false])->assertOk();
        $draft = $this->getJson('/api/prototype/state')->json('state.terms.0');
        $this->assertSame('Draft', $draft['status']);
        $this->assertSame($rejected['history'], $draft['history']);
        $this->assertSame($rejected['number'], $draft['number']);
        $this->assertSame($rejected['actuals'], $draft['actuals']);
        $this->asRole('PM Konstruksi');
        $this->api('decide', [$t['id'], 'Setujui', 'Belum diajukan ulang'])->assertForbidden();
        $this->asRole('Admin Konstruksi');
        $this->api('saveTerm', [$draft, true])->assertOk();
        $this->assertSame('PENDING', (clone $runs)->where('cycle_no', 2)->value('status'));
        $this->assertSame('REJECTED', DB::table('approval_runs')->where('id_approval_run', $oldRun->id_approval_run)->value('status'));
        $this->assertEquals($decisions, DB::table('approval_decisions')->orderBy('id_approval_decision')->get()->all());
        $this->assertDatabaseCount('ba_completions', 0);
        $this->assertDatabaseCount('apm_settlements', 0);
        $this->asRole('Head of PMO');
        $this->api('decide', [$t['id'], 'Setujui', 'Tidak boleh lompat PM'])->assertForbidden();
        $this->approvePayment($t['id']);
        $approved = $this->getJson('/api/prototype/state')->json('state.terms.0');
        $this->assertCount($stage + 6, $approved['history']);
        $this->assertSame('Belum Terbit', $approved['apmStatus']);
        $this->assertEquals(0, $approved['paid']);
        $this->assertDatabaseCount('ba_completions', 1);
        $this->assertDatabaseCount('apm_settlements', 0);
    }

    public function test_payment_requires_five_approvals_then_real_scan_and_separate_finance_approval_before_apm(): void
    {
        Storage::fake('local');
        $t = $this->payment();
        $this->upload($t['id'])->assertUnprocessable();
        $this->assertEmpty(Storage::disk('local')->allFiles());
        $this->asRole('Finance');
        $this->api('decide', [$t['id'], 'Setujui', ''])->assertForbidden();
        $this->api('verifyTermScan', [$t['id'], 'Valid', ''])->assertUnprocessable();
        $this->approvePayment($t['id']);
        $this->asRole('Admin Konstruksi');
        $this->api('saveTerm', [$t, false])->assertUnprocessable();
        $this->upload($t['id'])->assertOk()->assertJsonPath('state.terms.0.scan.status', 'Menunggu Verifikasi');
        $this->api('verifyTermScan', [$t['id'], 'Valid', ''])->assertForbidden();
        $this->asRole('Finance');
        $this->api('recordApm', [$t['id'], 'APM/QA/V3-01', true])->assertUnprocessable();
        $this->api('verifyTermScan', [$t['id'], 'Perlu Koreksi', 'QA halaman kurang'])->assertOk()->assertJsonCount(1, 'state.terms.0.financeHistory');
        $this->asRole('Admin Konstruksi');
        $this->upload($t['id'])->assertOk()->assertJsonCount(1, 'state.terms.0.scanHistory');
        $this->asRole('Finance');
        $verified = $this->api('verifyTermScan', [$t['id'], 'Valid', 'QA diperiksa'])->assertOk()->assertJsonCount(5, 'state.terms.0.approvals')->assertJsonCount(2, 'state.terms.0.financeHistory')->json('state.terms.0');
        $this->assertSame($verified['scan']['id'], $verified['financeApproval']['scanId']);
        $this->assertSame('db-user-'.$this->accounts['Finance']->getKey(), $verified['financeApproval']['userId']);
        $this->api('decide', [$t['id'], 'Setujui', ''])->assertForbidden();
        $paid = $this->api('recordApm', [$t['id'], 'APM/QA/V3-01', true])->assertOk()->json('state.terms.0');
        $this->assertEquals(2422500, $paid['paid']);
        $this->assertCount(1, $paid['paymentHistory']);
        $this->api('recordApm', [$t['id'], 'APM/QA/V3-02', true])->assertUnprocessable();
        $this->asRole('Admin Konstruksi');
        $this->upload($t['id'])->assertUnprocessable();
        $this->api('createTerm', [$t['spkId']])->assertOk()->assertJsonPath('state.terms.1.number', 2)->assertJsonPath('state.terms.1.workflowVersion', 3);
    }

    public function test_five_approver_final_preserves_release_ledger_and_continuation_assignment(): void
    {
        $t = $this->payment(true);
        $this->approvePayment($t['id']);
        $this->asRole('Admin Konstruksi');
        $s = $this->getJson('/api/prototype/state')->json('state');
        $b = $s['bapps'][0];
        $row = $s['deals'][0]['rows'][0]['rowId'];
        $this->api('releaseVolume', [$b['id'], [$row => ['m' => 100, 's' => 20]]])->assertUnprocessable();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->api('attestBapp', [$b['id'], 'BAPP/QA/V3'])->assertOk();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertAttestationQueries($queries);
        $completion = DB::table('ba_completions')->where('source_id', $b['id'])->first();
        $this->assertNull($completion->evidence_reference);
        $this->assertSame('', json_decode($completion->document_data, true)['evidence']);
        $this->assertSame('ATTESTED', $completion->status);
        $this->api('releaseVolume', [$b['id'], [$row => ['m' => 100, 's' => 20]]])->assertOk();
        $state = $this->api('createContinuation', [$b['id'], '', [$row => ['m' => 60, 's' => 10]], 'QA lanjutan'])->assertOk()->json('state');
        $p = collect($state['projects'])->first(fn ($p) => ! empty($p['parentId']));
        $this->assertSame(3, $p['workflowVersion']);
        $this->assertSame($this->chain(), $p['approverUserIds']);
        $this->assertEquals(1235000, $p['budget']);
        $balance = app(PrototypeDomain::class)->load($state)->ledger($p['rootId'], $row, 'm');
        $this->assertEquals(['total' => 1200, 'available' => 40, 'reserved' => 60, 'committed' => 1000, 'accepted' => 100], $balance);
        $this->api('createTerm', [$t['spkId']])->assertUnprocessable();
    }
}
