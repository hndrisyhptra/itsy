<?php

namespace Tests\Feature;

use App\Models\PrototypeWorkspace;
use App\Models\User;
use App\Services\AccountAccess;
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
        foreach (['save', 'generate', 'attest'] as $action) {
            $this->api('saveDeal', [$bad, $action])->assertUnprocessable()->assertJsonValidationErrors('prototype');
            $after = $this->getJson('/api/prototype/state')->json();
            $this->assertSame($before['revision'], $after['revision']);
            $this->assertSame($before['state']['deals'], $after['state']['deals']);
            $this->assertSame($before['state']['projects'], $after['state']['projects']);
            $this->assertDatabaseCount('prototype_audits', 6);
        }
        $this->api('saveDeal', [$deal, 'save'])->assertOk();
        $this->api('saveDeal', [$deal, 'generate'])->assertOk();
        $this->api('saveDeal', [$bad, 'attest'])->assertOk();
        $record = collect($this->getJson('/api/prototype/state')->json('state.deals'))->firstWhere('id', $deal['id']);
        $this->assertEquals(100, $record['rows'][0]['sphS']);
        $this->assertSame('Sah', $record['status']);
    }

    public function test_bakh_dates_are_server_derived_from_pp_and_persist_in_sql_for_old_draft_and_attestation(): void
    {
        $p = $this->budgetProject();
        $p['date'] = '2026-11-30';
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
        $deal['rows'] = [['rowId' => $saved['boq'][0]['id'], 'm' => false, 's' => true, 'qm' => 0, 'qs' => 1, 'pm' => 0, 'ps' => 100, 'sphM' => 0, 'sphS' => 100, 'offerM' => 0, 'offerS' => 100, 'capM' => $saved['boq'][0]['m'], 'capS' => $saved['boq'][0]['s']]];
        foreach (['save', 'generate', 'attest'] as $action) {
            $response = $this->api('saveDeal', [$deal, $action])->assertOk();
            $record = collect($response->json('state.deals'))->firstWhere('id', $deal['id']);
            $this->assertSame('2026-11-30', $record['start']);
            $this->assertSame('2026-12-13', $record['end']);
            $sql = DB::table('contracts')->where('source_id', $deal['id'])->first();
            $this->assertSame('2026-11-30', $sql->start_date);
            $this->assertSame('2026-12-13', $sql->end_date);
            $this->assertSame('2026-12-13', json_decode($sql->document_data, true)['end']);
        }
        $this->assertSame($saved, $this->projectRecord($p['id']));
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
        foreach (['usage', 'bopLimit', 'ids', 'component', 'risk'] as $case) {
            $bad = $p;
            if ($case === 'usage') {
                $bad['planBudget']['usage'][0]['amount'] = 1;
            }
            if ($case === 'bopLimit') {
                $bad['planBudget']['bopLimit'] = 1;
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
        $this->api('saveProject', [$p, false])->assertUnprocessable();
        $this->assertSame($original, $this->getJson('/api/prototype/state')->json('state.projects'));
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
        $this->api('attestBapp', [$b['id'], 'BAPP/QA/V3', 'Referensi dokumen sintetis QA'])->assertOk();
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
