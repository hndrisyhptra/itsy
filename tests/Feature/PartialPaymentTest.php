<?php

namespace Tests\Feature;

use App\Models\PrototypeWorkspace;
use App\Services\AccountAccess;
use App\Services\PrototypeDomain;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\AuthenticatesArea3;
use Tests\TestCase;

class PartialPaymentTest extends TestCase
{
    use AuthenticatesArea3, RefreshDatabase;

    private function api(string $command, array $args)
    {
        $revision = $this->getJson('/api/prototype/state')->assertOk()->json('revision');

        return $this->postJson('/api/prototype/commands', ['command' => $command, 'args' => $args, 'revision' => $revision, 'request_id' => (string) Str::uuid()]);
    }

    private function approved(bool $final = false, int $decisions = 5): array
    {
        $this->withoutVite();
        Storage::fake('local');
        $this->seedWorkflow('D');
        $this->asRole('Admin Konstruksi');
        $s = $this->getJson('/api/prototype/state')->assertOk()->json('state');
        $response = $this->api('createTerm', [$s['spks'][0]['id']])->assertOk();
        $t = $response->json('state.terms.0');
        $t['type'] = $final ? 'Final' : 'Parsial';
        $t['actuals'] = [$s['deals'][0]['rows'][0]['rowId'] => ['m' => 1, 's' => 1]];
        $t['barp']['place'] = 'Tempat QA';
        $t['barp']['date'] = $s['spks'][0]['start'];
        $t['paymentFlow'] = 'partial-payment-v1';
        $t['paymentRequest'] = ['status' => 'Dibayar', 'amount' => 1, 'decision' => ['decision' => 'Setujui']];
        $saved = $this->api('saveTerm', [$t, true])->assertOk()->json('state.terms.0');
        $this->assertArrayNotHasKey('paymentFlow', $saved);
        $this->assertArrayNotHasKey('paymentRequest', $saved);
        foreach (array_slice(PrototypeDomain::APPROVERS_V3, 0, $decisions) as $role) {
            $this->asRole($role);
            $this->api('decide', [$t['id'], 'Setujui', 'QA approval'])->assertOk();
        }
        $this->asRole('Admin Konstruksi');

        return $this->term($t['id']);
    }

    private function term(string $id): array
    {
        return collect($this->getJson('/api/prototype/state')->assertOk()->json('state.terms'))->firstWhere('id', $id);
    }

    private function verify(string $id): void
    {
        $this->api('requestPartialPayment', [$id])->assertOk();
        $this->asRole('Finance');
        $this->api('verifyPartialPayment', [$id, 'Setujui', 'Berkas disusulkan'])->assertOk();
    }

    private function assertPaymentQueries(array $queries): void
    {
        $this->assertLessThanOrEqual(75, count($queries), 'One PP / one term HTTP fixture including revision read.');
        $this->assertCount(2, array_filter($queries, fn ($q) => str_contains($q['query'], 'from "designator_package_prices"')));
        foreach ($queries as $q) {
            $this->assertDoesNotMatchRegularExpression('/^\s*(insert into|update|delete from) ["`]*(project_plans|boq_lines|boq_components|term_actuals|approval_runs|approval_steps|approval_decisions|document_files|term_scans|term_work_evidence|volume_balances|volume_movements)["` ]/i', $q['query']);
        }
    }

    public function test_partial_can_be_requested_verified_and_paid_without_scan_then_next_term_and_late_scan_preserve_payment(): void
    {
        $t = $this->approved();
        $id = $t['id'];
        $approvals = $t['approvals'];
        $archive = PrototypeWorkspace::first()->getRawOriginal('state');
        $this->assertArrayNotHasKey('scan', $t);
        foreach ([['requestPartialPayment', [$id]], ['verifyPartialPayment', [$id, 'Setujui', 'Berkas disusulkan']], ['payPartialPayment', [$id, 'APM/PARTIAL/001']]] as [$command,$args]) {
            $this->asRole($command === 'requestPartialPayment' ? 'Admin Konstruksi' : 'Finance');
            if ($command === 'requestPartialPayment') {
                $this->api('createTerm', [$t['spkId']])->assertUnprocessable();
            }
            DB::enableQueryLog();
            DB::flushQueryLog();
            $this->api($command, $args)->assertOk();
            $queries = DB::getQueryLog();
            DB::disableQueryLog();
            $this->assertPaymentQueries($queries);
            fwrite(STDERR, "\nPartial Payment {$command}: ".count($queries)." queries including revision.\n");
        }
        $paid = $this->term($id);
        $this->assertSame('Dibayar', $paid['paymentRequest']['status']);
        $this->assertSame('NOK', $paid['paymentRequest']['documentsAtPayment']);
        $this->assertSame($approvals, $paid['approvals']);
        $this->assertArrayNotHasKey('kasbon', $paid['paymentRequest']);
        $this->assertArrayNotHasKey('scan', $paid);
        $this->assertEquals(app(PrototypeDomain::class)->load($this->getJson('/api/prototype/state')->json('state'))->termTotal($paid), $paid['paid']);
        $settlement = (array) DB::table('apm_settlements')->first();
        $this->assertNull($settlement['verified_scan_id']);
        $this->assertNotNull($settlement['payment_request_id']);
        $this->assertDatabaseHas('partial_payment_requests', ['status' => 'PAID', 'documents_at_request' => 'NOK', 'documents_at_payment' => 'NOK']);
        $this->api('payPartialPayment', [$id, 'APM/PARTIAL/002'])->assertUnprocessable();
        $this->assertDatabaseCount('apm_settlements', 1);
        $this->asRole('Admin Konstruksi');
        $this->api('createTerm', [$t['spkId']])->assertOk();
        $revision = $this->getJson('/api/prototype/state')->json('revision');
        $this->post('/api/prototype/payments/'.$id.'/scan', ['revision' => (string) $revision, 'request_id' => (string) Str::uuid(), 'file' => UploadedFile::fake()->createWithContent('scan-late.pdf', "%PDF-1.4\nlate signed scan\n%%EOF")], ['Accept' => 'application/json'])->assertOk();
        $scan = $this->term($id)['scan'];
        Storage::disk('local')->assertExists('prototype-scans/'.PrototypeWorkspace::first()->id.'/'.$id.'/'.$scan['id'].'.pdf');
        $this->asRole('Finance');
        $this->api('verifyTermScan', [$id, 'Valid', 'Berkas lengkap'])->assertOk();
        $after = $this->term($id);
        $this->assertSame('Valid', $after['scan']['status']);
        $this->assertSame($paid['paymentRequest'], $after['paymentRequest']);
        $this->assertEquals($paid['paid'], $after['paid']);
        $this->assertEquals($settlement, (array) DB::table('apm_settlements')->first());
        $this->assertCount(1, $after['paymentHistory']);
        $this->assertSame($archive, PrototypeWorkspace::first()->getRawOriginal('state'));
    }

    public function test_verification_and_payment_are_gated_and_apm_failure_does_not_change_request(): void
    {
        $t = $this->approved();
        $id = $t['id'];
        $this->asRole('Finance');
        $this->api('verifyPartialPayment', [$id, 'Setujui', ''])->assertUnprocessable();
        $this->api('payPartialPayment', [$id, 'APM/001'])->assertUnprocessable();
        $this->asRole('Admin Konstruksi');
        $this->api('requestPartialPayment', [$id])->assertOk();
        $this->api('requestPartialPayment', [$id])->assertUnprocessable();
        $this->api('verifyPartialPayment', [$id, 'Setujui', ''])->assertForbidden();
        $this->asRole('Finance');
        $this->api('payPartialPayment', [$id, 'APM/001'])->assertUnprocessable();
        $this->api('verifyPartialPayment', [$id, 'Setujui', ''])->assertOk();
        $before = $this->term($id);
        $revision = PrototypeWorkspace::first()->revision;
        $this->api('payPartialPayment', [$id, 'bad apm!'])->assertUnprocessable();
        $this->assertSame($before, $this->term($id));
        $this->assertSame($revision, PrototypeWorkspace::first()->revision);
        $this->assertDatabaseCount('apm_settlements', 0);
        $this->asRole('Admin Konstruksi');
        $this->api('createTerm', [$t['spkId']])->assertUnprocessable();
    }

    public function test_finance_return_requires_note_and_admin_resubmits_payment_without_repeating_document_approvals(): void
    {
        $t = $this->approved();
        $id = $t['id'];
        $this->api('requestPartialPayment', [$id])->assertOk();
        $this->asRole('Finance');
        $this->api('verifyPartialPayment', [$id, 'Kembalikan', ''])->assertUnprocessable();
        $this->api('verifyPartialPayment', [$id, 'Kembalikan', 'Periksa data Payment'])->assertOk();
        $old = (array) DB::table('partial_payment_requests')->first();
        $this->asRole('Admin Konstruksi');
        $this->api('requestPartialPayment', [$id])->assertOk();
        $new = $this->term($id);
        $this->assertSame(2, $new['paymentRequest']['cycle']);
        $this->assertSame($t['approvals'], $new['approvals']);
        $this->assertSame('Disetujui', $new['status']);
        $this->assertDatabaseCount('partial_payment_requests', 2);
        $this->assertEquals($old, (array) DB::table('partial_payment_requests')->where('cycle_no', 1)->first());
        $this->assertSame('Periksa data Payment', $new['paymentRequestHistory'][0]['decision']['note']);
    }

    public function test_final_and_historical_versions_do_not_obtain_the_partial_exception(): void
    {
        $t = $this->approved(true);
        $this->api('requestPartialPayment', [$t['id']])->assertUnprocessable();
        $this->asRole('Finance');
        $this->api('payPartialPayment', [$t['id'], 'APM/FINAL'])->assertUnprocessable();
        $this->api('recordApm', [$t['id'], 'APM/FINAL', true])->assertUnprocessable();
        $this->assertDatabaseCount('partial_payment_requests', 0);
    }

    public function test_sql_failure_rolls_back_payment_request_settlement_receipt_and_revision(): void
    {
        $t = $this->approved();
        $this->verify($t['id']);
        $before = $this->term($t['id']);
        $revision = PrototypeWorkspace::first()->revision;
        $audits = DB::table('prototype_audits')->count();
        DB::statement("CREATE TRIGGER reject_partial_apm BEFORE INSERT ON apm_settlements BEGIN SELECT RAISE(ABORT, 'forced failure'); END");
        $this->api('payPartialPayment', [$t['id'], 'APM/ROLLBACK'])->assertStatus(500);
        $this->assertSame($before, $this->term($t['id']));
        $this->assertSame($revision, PrototypeWorkspace::first()->revision);
        $this->assertSame($audits, DB::table('prototype_audits')->count());
        $this->assertDatabaseHas('partial_payment_requests', ['status' => 'VERIFIED']);
        $this->assertDatabaseCount('apm_settlements', 0);
        DB::statement('DROP TRIGGER reject_partial_apm');
        $this->api('payPartialPayment', [$t['id'], 'APM/ROLLBACK'])->assertOk();
    }

    public function test_request_replay_branch_scope_and_rollback_guard_preserve_history(): void
    {
        $t = $this->approved();
        $revision = PrototypeWorkspace::first()->revision;
        $request = ['command' => 'requestPartialPayment', 'args' => [$t['id']], 'revision' => $revision, 'request_id' => (string) Str::uuid()];
        $this->postJson('/api/prototype/commands', $request)->assertOk();
        $this->postJson('/api/prototype/commands', $request)->assertOk()->assertJsonPath('replayed', true);
        $this->assertDatabaseCount('partial_payment_requests', 1);
        $finance = $this->asRole('Finance');
        $branch = DB::table('branches')->where('name', '!=', 'DENPASAR')->value('id_branch');
        app(AccountAccess::class)->assign($finance, 'FINANCE', [$branch]);
        $this->api('verifyPartialPayment', [$t['id'], 'Setujui', ''])->assertForbidden();
        $migration = require database_path('migrations/2026_11_09_000002_enable_partial_payment_requests.php');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('rollback ditolak');
        $migration->down();
    }

    public function test_request_cannot_precede_head_approval_and_client_fields_cannot_create_payment_authority(): void
    {
        $t = $this->approved(false, 4);
        $this->api('requestPartialPayment', [$t['id']])->assertUnprocessable();
        $this->asRole('Finance');
        $this->api('verifyPartialPayment', [$t['id'], 'Setujui', ''])->assertUnprocessable();
        $this->assertDatabaseCount('partial_payment_requests', 0);
        $this->asRole('Head of PMO');
        $this->api('decide', [$t['id'], 'Setujui', ''])->assertOk();
        $this->asRole('Admin Konstruksi');
        $this->api('requestPartialPayment', [$t['id']])->assertOk();
        $this->assertTrue(PrototypeDomain::financeTaskPending($this->term($t['id'])));
    }

    public function test_settlement_keeps_exactly_one_authority_and_cannot_reference_another_terms_request(): void
    {
        $t = $this->approved();
        $this->verify($t['id']);
        $this->api('payPartialPayment', [$t['id'], 'APM/FK/001'])->assertOk();
        $saved = (array) DB::table('apm_settlements')->first();
        $this->asRole('Admin Konstruksi');
        $this->api('createTerm', [$t['spkId']])->assertOk();
        $other = DB::table('payment_terms')->where('term_no', 2)->value('id_payment_term');
        foreach ([['payment_request_id' => null], ['verified_scan_id' => (string) Str::uuid()], ['term_id' => $other]] as $change) {
            try {
                DB::table('apm_settlements')->where('id_apm_settlement', $saved['id_apm_settlement'])->update($change);
                $this->fail('A payment without authority, two authorities or cross-term reference must fail.');
            } catch (QueryException $e) {
                $this->assertMatchesRegularExpression('/settlement_authority|FOREIGN KEY/i', $e->getMessage());
            }
        }
        $this->assertEquals($saved, (array) DB::table('apm_settlements')->first());
    }

    public function test_migration_preserves_a_legacy_scan_based_settlement_through_down_and_up(): void
    {
        $t = $this->approved(true);
        $revision = $this->getJson('/api/prototype/state')->json('revision');
        $this->post('/api/prototype/payments/'.$t['id'].'/scan', [
            'revision' => (string) $revision, 'request_id' => (string) Str::uuid(),
            'file' => UploadedFile::fake()->createWithContent('signed-final.pdf', "%PDF-1.4\nlegacy scan\n%%EOF"),
        ], ['Accept' => 'application/json'])->assertOk();
        $this->asRole('Finance');
        $this->api('verifyTermScan', [$t['id'], 'Valid', ''])->assertOk();
        $this->api('recordApm', [$t['id'], 'APM/LEGACY/001', true])->assertOk();
        $before = (array) DB::table('apm_settlements')->first();
        $this->assertNotNull($before['verified_scan_id']);
        $this->assertNull($before['payment_request_id']);
        $migration = require database_path('migrations/2026_11_09_000002_enable_partial_payment_requests.php');
        $migration->down();
        $migration->up();
        $this->assertEquals($before, (array) DB::table('apm_settlements')->first());
        $this->assertDatabaseCount('partial_payment_requests', 0);
    }
}
