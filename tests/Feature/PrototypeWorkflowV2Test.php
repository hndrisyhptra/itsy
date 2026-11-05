<?php

namespace Tests\Feature;

use App\Models\PrototypeWorkspace;
use App\Services\AccountAccess;
use App\Services\AuthenticatedWorkspace;
use App\Services\PrototypeDomain;
use App\Services\PrototypeWorkspaceService;
use App\Services\RelationalWorkflowStore;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class PrototypeWorkflowV2Test extends TestCase
{
    use RefreshDatabase, \Tests\Concerns\AuthenticatesArea3;

    private function apply(array &$state, string $command, array $args = [], ?string $id = null): mixed
    {
        $result = app(PrototypeDomain::class)->execute($state, $command, $args, $id);
        $state = $result['state'];

        return $result['result'];
    }

    private function rejected(array &$state, string $command, array $args): void
    {
        $before = $state;
        try {
            $this->apply($state, $command, $args);
            $this->fail('Command must be rejected');
        } catch (ValidationException|HttpException $error) {
            $this->assertSame($before, $state);
        }
    }

    private function project(array $state): array
    {
        $p = $state['projects'][0];
        $p['id'] = $p['rootId'] = 'pp-v2-new';
        $p['no'] = 'CLIENT-CANNOT-CHOOSE-THIS';
        $p['unit'] = 'Konstruksi';
        $p['pmUserId'] = 'user-1';
        $p['managerUserId'] = 'user-2';
        $p['boq'] = array_slice($p['boq'], 0, 1);
        $p['boq'][0]['id'] = 'row-v2-new';
        $p['boq'][0]['qty'] = 10.125;
        $p['boq'][0]['m'] = 1;
        $p['approvals'] = [['role' => 'Finance', 'decision' => 'Setujui']];

        return $p;
    }

    public function test_historical_v2_draft_keeps_its_narrative_team_and_schedule_in_v3_workspace(): void
    {
        $s = app(PrototypeWorkspaceService::class)->scenario('B');
        $s['schema'] = 3;
        $s['projects'][0]['workflowVersion'] = 2;
        $s['projects'][0]['status'] = 'Draft';
        $s['projects'][0]['unit'] = 'Konstruksi';
        $s['projects'][0]['pmUserId'] = 'user-1';
        $s['projects'][0]['managerUserId'] = 'user-2';
        $s['projects'][0]['intro'] = 'Redaksi historis tersimpan';
        $p = $s['projects'][0];
        $this->apply($s, 'saveProject', [$p, false]);
        $saved = collect($s['projects'])->firstWhere('id', $p['id']);
        foreach (['intro', 'team', 'schedule'] as $key) {
            $this->assertSame($p[$key], $saved[$key]);
        }
        $this->assertArrayNotHasKey('planTemplateVersion', $saved);
    }

    public function test_numbering_default_matches_requested_example_uses_server_month_and_preserves_history(): void
    {
        $this->travelTo(Carbon::parse('2026-10-31 18:15:00', 'UTC'));
        $s = app(PrototypeWorkspaceService::class)->scenario('B');
        $history = $s['projects'];
        $s['branches'][0]['code'] = 'R424';
        $s['settings'] = ['ppNumberFormat' => 'PP/{BRANCH}/{YYYY}/{SEQ}', 'nextPpSequence' => 1];
        $s = PrototypeDomain::upgradeState($s);
        $this->assertSame($history, $s['projects']);
        $this->assertSame(PrototypeDomain::DEFAULT_PP_NUMBER_FORMAT, $s['settings']['ppNumberFormat']);
        $p = $this->project($s);
        $this->apply($s, 'saveProject', [$p, false]);
        $saved = collect($s['projects'])->firstWhere('id', $p['id']);
        $this->assertSame('0001/LG.000/TA-03R424/11-2026', $saved['no']);
        $this->assertSame(2, $s['settings']['nextPpSequence']);
        $this->travelTo(Carbon::parse('2027-01-01 00:00:00', 'Asia/Jakarta'));
        $p['no'] = 'CLIENT-EDIT';
        $this->apply($s, 'saveProject', [$p, false]);
        $this->assertSame($saved['no'], collect($s['projects'])->firstWhere('id', $p['id'])['no']);
        $p['id'] = $p['rootId'] = 'pp-next-year';
        $this->apply($s, 'saveProject', [$p, false]);
        $this->assertSame('0002/LG.000/TA-03R424/01-2027', collect($s['projects'])->firstWhere('id', $p['id'])['no']);
        $this->apply($s, 'setNumberFormat', ['PP/{BRANCH}/{YYYY}/{SEQ}']);
        $this->assertSame('PP/{BRANCH}/{YYYY}/{SEQ}', PrototypeDomain::upgradeState($s)['settings']['ppNumberFormat']);
        $s['settings'] = ['ppNumberFormat' => 'CUSTOM/{SEQ}', 'nextPpSequence' => 12000];
        $s = PrototypeDomain::upgradeState($s);
        $this->assertSame('CUSTOM/{SEQ}', $s['settings']['ppNumberFormat']);
        $p['id'] = $p['rootId'] = 'pp-custom';
        $this->apply($s, 'saveProject', [$p, false]);
        $this->assertSame('CUSTOM/12000', collect($s['projects'])->firstWhere('id', $p['id'])['no']);
        $this->travelBack();
    }

    public function test_pp_number_boq_and_assigned_approvals_are_server_owned(): void
    {
        $s = app(PrototypeWorkspaceService::class)->scenario('B');
        $p = $this->project($s);
        $this->apply($s, 'setNumberFormat', ['PLAN/{YYYY}/{BRANCH}/{SEQ}']);
        $this->apply($s, 'saveProject', [$p, true]);
        $saved = collect($s['projects'])->firstWhere('id', $p['id']);
        $this->assertMatchesRegularExpression('/^PLAN\/\d{4}\/BR01\/0001$/', $saved['no']);
        $this->assertSame(18500, $saved['boq'][0]['m']);
        $this->assertSame('Diajukan', $saved['status']);
        $this->assertSame([], $saved['approvals']);
        $this->assertEquals(313875, app(PrototypeDomain::class)->boqTotal($saved));
        $deal = $s['deals'][0] ?? ['id' => 'new-deal', 'projectId' => $p['id']];
        $deal['projectId'] = $p['id'];
        $this->rejected($s, 'saveDeal', [$deal, 'save']);
        $this->apply($s, 'role', ['Manager Konstruksi']);
        $this->rejected($s, 'decideProject', [$p['id'], 'Setujui', '']);
        $s['users'][] = ['id' => 'other-pm', 'name' => 'PM Lain Demo', 'email' => 'other@example.com', 'role' => 'PM Konstruksi', 'active' => true];
        $this->apply($s, 'role', ['PM Konstruksi']);
        $this->apply($s, 'selectActor', ['other-pm']);
        $this->rejected($s, 'decideProject', [$p['id'], 'Setujui', '']);
        $this->apply($s, 'selectActor', ['user-1']);
        $this->apply($s, 'decideProject', [$p['id'], 'Setujui', 'PM meninjau']);
        $this->apply($s, 'role', ['Manager Konstruksi']);
        $this->apply($s, 'decideProject', [$p['id'], 'Setujui', 'Manager meninjau']);
        $saved = collect($s['projects'])->firstWhere('id', $p['id']);
        $this->assertSame('Sah', $saved['status']);
        $this->assertCount(2, $saved['approvalHistory']);
        $this->assertSame('user-2', $saved['approvals'][1]['userId']);
        $this->apply($s, 'role', ['Admin Konstruksi']);
        $this->rejected($s, 'saveProject', [$saved, false]);
        $this->rejected($s, 'setNumberFormat', ['PP/{UNKNOWN}']);
    }

    public function test_pp_return_resubmission_preserves_history_and_number_but_resets_approvals(): void
    {
        $s = app(PrototypeWorkspaceService::class)->scenario('B');
        $p = $this->project($s);
        $this->apply($s, 'saveProject', [$p, true]);
        $this->apply($s, 'role', ['PM Konstruksi']);
        $this->rejected($s, 'decideProject', [$p['id'], 'Kembalikan', '']);
        $this->apply($s, 'decideProject', [$p['id'], 'Kembalikan', 'Lengkapi rencana']);
        $p = collect($s['projects'])->firstWhere('id', $p['id']);
        $no = $p['no'];
        $this->apply($s, 'role', ['Admin Konstruksi']);
        $p['no'] = 'RENUMBER-ATTEMPT';
        $this->apply($s, 'saveProject', [$p, true]);
        $p = collect($s['projects'])->firstWhere('id', $p['id']);
        $this->assertSame($no, $p['no']);
        $this->assertCount(1, $p['approvalHistory']);
        $this->assertCount(0, $p['approvals']);
        $this->assertSame(2, $s['settings']['nextPpSequence']);
    }

    public function test_boq_rejects_missing_duplicate_negative_or_too_precise_rows_and_wrong_assignees(): void
    {
        $s = app(PrototypeWorkspaceService::class)->scenario('B');
        $p = $this->project($s);
        foreach ([[], [$p['boq'][0], $p['boq'][0]]] as $boq) {
            $bad = $p;
            $bad['boq'] = $boq;
            $this->rejected($s, 'saveProject', [$bad, false]);
        }
        foreach ([-1, 0, 0.0001] as $qty) {
            $bad = $p;
            $bad['boq'][0]['qty'] = $qty;
            $this->rejected($s, 'saveProject', [$bad, false]);
        }
        $p['pmUserId'] = 'user-3';
        $this->rejected($s, 'saveProject', [$p, true]);
        $this->assertSame(1, $s['settings']['nextPpSequence']);
    }

    private function api(string $command, array $args = [], ?string $requestId = null, ?int $revision = null)
    {
        $current = $this->getJson('/api/prototype/state')->assertOk()->json();

        return $this->postJson('/api/prototype/commands', ['command' => $command, 'args' => $args, 'revision' => $revision ?? $current['revision'], 'request_id' => $requestId ?? (string) Str::uuid()]);
    }

    private function approvedPayment(): string
    {
        $state = $this->seedWorkflow('D');
        $state = app(AuthenticatedWorkspace::class)->bind($state);
        $state['schema'] = 2;
        $state = app(PrototypeDomain::class)->execute($state, 'createTerm', [$state['spks'][0]['id']])['state'];
        DB::transaction(fn () => app(RelationalWorkflowStore::class)->write(PrototypeWorkspace::first(), $state));
        $t = $state['terms'][0];
        $row = $state['deals'][0]['rows'][0]['rowId'];
        $t['actuals'] = [$row => ['m' => 100, 's' => 80]];
        $this->api('saveTerm', [$t, true])->assertOk();
        $this->asRole('Finance');
        $this->api('decide', [$t['id'], 'Setujui', ''])->assertForbidden();
        foreach (['PM Konstruksi', 'Manager Konstruksi'] as $role) {
            $this->asRole($role);
            $this->api('decide', [$t['id'], 'Setujui', ''])->assertOk();
        }
        $this->asRole('Finance');
        $this->api('recordApm', [$t['id'], 'APM/TEST/001', true])->assertUnprocessable();

        return $t['id'];
    }

    private function upload(string $id, ?int $revision = null, ?string $requestId = null, ?UploadedFile $file = null)
    {
        $current = $this->getJson('/api/prototype/state')->json();

        return $this->post('/api/prototype/payments/'.$id.'/scan', ['revision' => $revision ?? $current['revision'], 'request_id' => $requestId ?? (string) Str::uuid(), 'file' => $file ?? UploadedFile::fake()->createWithContent('scan-demo.pdf', "%PDF-1.4\nDokumen uji sintetis tanpa tanda tangan\n%%EOF")], ['Accept' => 'application/json']);
    }

    public function test_real_private_upload_finance_verification_and_apm_settle_once(): void
    {
        Storage::fake('local');
        $id = $this->approvedPayment();
        $this->upload($id)->assertForbidden();
        $this->asRole('Admin Konstruksi');
        $this->api('_attachTermScan', [$id, ['id' => 'forged']])->assertUnprocessable();
        $this->upload($id, file: UploadedFile::fake()->createWithContent('fake.pdf', '<html>not a PDF</html>'))->assertUnprocessable();
        $this->upload($id, file: UploadedFile::fake()->create('oversized.pdf', 10241, 'application/pdf'))->assertUnprocessable();
        $this->upload($id, revision: 0)->assertConflict();
        $this->assertCount(0, Storage::disk('local')->allFiles());
        $requestId = (string) Str::uuid();
        $first = $this->upload($id, requestId: $requestId)->assertOk()->json('state.terms.0.scan');
        $this->assertSame('Menunggu Verifikasi', $first['status']);
        $this->upload($id, requestId: $requestId)->assertOk()->assertJsonPath('replayed', true)->assertJsonPath('state.terms.0.scan.id', $first['id']);
        $this->assertCount(1, Storage::disk('local')->allFiles());
        $this->get('/api/prototype/payments/'.$id.'/scan')->assertOk()->assertDownload('scan-demo.pdf');
        $this->api('verifyTermScan', [$id, 'Valid', ''])->assertForbidden();
        $this->asRole('Finance');
        $this->api('recordApm', [$id, 'APM/TEST/001', true])->assertUnprocessable();
        $this->api('verifyTermScan', [$id, 'Perlu Koreksi', ''])->assertUnprocessable();
        $this->api('verifyTermScan', [$id, 'Valid', 'Dokumen diperiksa'])->assertOk();
        $apmRequest = (string) Str::uuid();
        $result = $this->api('recordApm', [$id, 'APM/TEST/001', true], $apmRequest)->assertOk();
        $this->assertEquals(2422500, $result->json('state.terms.0.paid'));
        $this->assertCount(2, $result->json('state.terms.0.approvals'));
        $this->assertCount(1, $result->json('state.terms.0.paymentHistory'));
        $this->api('recordApm', [$id, 'APM/TEST/001', true], $apmRequest)->assertOk()->assertJsonPath('replayed', true);
        $this->api('recordApm', [$id, 'APM/TEST/002', true])->assertUnprocessable();
        $this->api('recordPayment', [$id, 1, 'double-payment'])->assertUnprocessable();
        $this->asRole('Admin Konstruksi');
        $this->upload($id)->assertUnprocessable();
        $state = $this->getJson('/api/prototype/state')->json('state');
        $this->api('createTerm', [$state['terms'][0]['spkId']])->assertOk()->assertJsonPath('state.terms.1.number', 2);
        app(AccountAccess::class)->assign($this->accounts['Admin Konstruksi'], 'ADMIN_KONSTRUKSI', [DB::table('branches')->where('name', 'SEMARANG')->value('id_branch')]);
        $this->get('/api/prototype/payments/'.$id.'/scan')->assertNotFound();
        $this->upload($id)->assertNotFound();
    }

    public function test_scan_correction_keeps_file_history_and_quantities_locked(): void
    {
        Storage::fake('local');
        $id = $this->approvedPayment();
        $this->asRole('Admin Konstruksi');
        $this->upload($id)->assertOk();
        $before = $this->getJson('/api/prototype/state')->json('state.terms.0');
        $this->asRole('Finance');
        $this->api('verifyTermScan', [$id, 'Perlu Koreksi', 'Halaman kurang'])->assertOk();
        $this->asRole('Admin Konstruksi');
        $this->upload($id)->assertOk();
        $after = $this->getJson('/api/prototype/state')->json('state.terms.0');
        $this->assertCount(1, $after['scanHistory']);
        $this->assertSame($before['scan']['id'], $after['scanHistory'][0]['id']);
        $this->assertSame($before['actuals'], $after['actuals']);
        $this->assertSame($before['approvals'], $after['approvals']);
        $this->assertCount(2, Storage::disk('local')->allFiles());
        $this->api('saveTerm', [$after, false])->assertUnprocessable();
    }

    public function test_existing_state_upgrade_preserves_all_historical_data(): void
    {
        $old = json_decode(file_get_contents(database_path('demo/overview.json')), true, 512, JSON_THROW_ON_ERROR);
        $new = PrototypeDomain::upgradeState($old);
        foreach (['projects', 'terms'] as $collection) {
            foreach ($new[$collection] as &$row) {
                unset($row['workflowVersion']);
            }
            unset($row);
            $this->assertSame($old[$collection], $new[$collection]);
        }
        $this->assertSame($old['bapps'], $new['bapps']);
        $this->assertSame($old['deals'], $new['deals']);
    }

    public function test_v2_final_release_and_continuation_preserve_root_stock_and_budget(): void
    {
        $s = app(PrototypeWorkspaceService::class)->scenario('D');
        $rootId = $s['projects'][0]['id'];
        $budget = $s['projects'][0]['budget'];
        $id = $this->apply($s, 'createTerm', [$s['spks'][0]['id']]);
        $t = $s['terms'][0];
        $rowId = $s['deals'][0]['rows'][0]['rowId'];
        $t['type'] = 'Final';
        $t['actuals'] = [$rowId => ['m' => 100, 's' => 80]];
        $this->apply($s, 'saveTerm', [$t, true]);
        foreach (['PM Konstruksi', 'Manager Konstruksi'] as $role) {
            $this->apply($s, 'role', [$role]);
            $this->apply($s, 'decide', [$id, 'Setujui', '']);
        }
        $this->apply($s, 'role', ['Admin Konstruksi']);
        $b = $s['bapps'][0];
        $this->rejected($s, 'releaseVolume', [$b['id'], [$rowId => ['m' => 100, 's' => 20]]]);
        $this->apply($s, 'attestBapp', [$b['id'], 'BAPP/V2/001', 'Referensi dokumen uji']);
        $this->apply($s, 'releaseVolume', [$b['id'], [$rowId => ['m' => 100, 's' => 20]]]);
        $newId = $this->apply($s, 'createContinuation', [$b['id'], '', [$rowId => ['m' => 60, 's' => 10]], 'Sisa pekerjaan']);
        $p = collect($s['projects'])->firstWhere('id', $newId);
        $this->assertEquals(1235000, $p['budget']);
        $this->assertSame(2, $p['workflowVersion']);
        $this->assertMatchesRegularExpression('/^0001\/LG\.000\/TA-03BR01\/\d{2}-\d{4}$/', $p['no']);
        $this->assertSame($budget, $s['projects'][0]['budget']);
        $balance = app(PrototypeDomain::class)->load($s)->ledger($rootId, $rowId, 'm');
        $this->assertEquals(['total' => 1200, 'available' => 40, 'reserved' => 60, 'committed' => 1000, 'accepted' => 100], $balance);
        $this->rejected($s, 'createTerm', [$t['spkId']]);
        $this->rejected($s, 'createContinuation', [$b['id'], '', [$rowId => ['m' => 41, 's' => 0]], '']);
    }
}
