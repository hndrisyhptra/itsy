<?php

namespace Tests\Feature;

use App\Models\PrototypeWorkspace;
use App\Services\BarpTemplate;
use App\Services\PrototypeDomain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\AuthenticatesArea3;
use Tests\TestCase;

class BarpTemplateTest extends TestCase
{
    use AuthenticatesArea3, RefreshDatabase;

    private function api(string $command, array $args)
    {
        $revision = $this->getJson('/api/prototype/state')->assertOk()->json('revision');

        return $this->postJson('/api/prototype/commands', ['command' => $command, 'args' => $args, 'revision' => $revision, 'request_id' => (string) Str::uuid()]);
    }

    private function draft(): array
    {
        $s = $this->seedWorkflow('D');
        $this->asRole('Admin Konstruksi');
        $t = $this->api('createTerm', [$s['spks'][0]['id']])->assertOk()->json('state.terms.0');
        $t['actuals'] = [$s['deals'][0]['rows'][0]['rowId'] => ['m' => 1, 's' => 1]];

        return [$s, $t];
    }

    public function test_barp_fields_persist_in_relational_snapshot_with_no_extra_catalog_queries_or_unrelated_writes(): void
    {
        [$s, $t] = $this->draft();
        $archive = PrototypeWorkspace::first()->getRawOriginal('state');
        $identity = array_intersect_key($t['barp'], array_flip(['waspang', 'mandor', 'headName', 'headNik', 'headUserId']));
        $this->assertSame('Head of PMO Test', $t['barp']['headName']);
        $this->assertSame($s['spks'][0]['start'], $t['barp']['start']);
        $t['barp'] = array_merge($t['barp'], ['location' => 'Lokasi BARP', 'sto' => 'STO BARP', 'homepass' => 0, 'headName' => 'FORGED', 'headNik' => 'FORGED', 'headUserId' => 'FORGED', 'waspang' => 'FORGED', 'mandor' => 'FORGED']);
        $t['barpHistory'] = [['at' => 'FORGED']];
        DB::enableQueryLog();
        DB::flushQueryLog();
        $saved = $this->api('saveTerm', [$t, false])->assertOk()->json('state.terms.0');
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertLessThanOrEqual(60, count($queries), 'Bounded one-PP HTTP fixture, including revision read.');
        foreach ($queries as $query) {
            $this->assertDoesNotMatchRegularExpression('/^\s*(?:insert into|update|delete from) ["`]*(?:project_plans|boq_lines|contracts|contract_components|work_orders|document_files|approval_decisions|term_scans|apm_settlements)["` ]/i', $query['query']);
        }
        $catalogReads = array_filter($queries, fn ($q) => str_contains($q['query'], 'from "designator_package_prices"'));
        $this->assertCount(2, $catalogReads, 'Existing revision/command reference reads only; no per-BARP-row lookup.');
        $this->assertSame($identity, array_intersect_key($saved['barp'], $identity));
        $this->assertSame('STO BARP', $saved['barp']['sto']);
        $this->assertSame(0, $saved['barp']['homepass']);
        $this->assertArrayNotHasKey('barpHistory', $saved);
        $row = DB::table('payment_terms')->where('source_id', $t['id'])->first();
        $this->assertSame($saved['barp'], json_decode($row->document_data, true)['barp']);
        $this->assertSame($archive, PrototypeWorkspace::first()->getRawOriginal('state'));
        fwrite(STDERR, "\nBARP one-PP HTTP metadata save: ".count($queries)." queries.\n");
    }

    public function test_barp_validation_is_atomic_and_submission_snapshots_survive_rejection_and_resubmission(): void
    {
        [$s, $t] = $this->draft();
        foreach ([['date', '2026-02-30'], ['start', '1900-01-01'], ['end', '2999-01-01'], ['homepass', -1], ['homepass', 1.2], ['homepass', '10'], ['homepass', 1000000001], ['location', str_repeat('x', 501)]] as [$field, $value]) {
            $invalid = $t;
            $invalid['barp'][$field] = $value;
            $revision = PrototypeWorkspace::first()->revision;
            $this->api('saveTerm', [$invalid, false])->assertUnprocessable();
            $this->assertSame($revision, PrototypeWorkspace::first()->revision);
        }
        $invalid = $t;
        $invalid['barp'] = null;
        $this->api('saveTerm', [$invalid, false])->assertUnprocessable();
        $invalid = $t;
        $invalid['barp']['place'] = '';
        $this->api('saveTerm', [$invalid, false])->assertOk();
        $this->api('saveTerm', [$invalid, true])->assertUnprocessable();
        $submitted = $this->api('saveTerm', [$t, true])->assertOk()->json('state.terms.0');
        $this->assertSame($submitted['barp'], $submitted['barpHistory'][0]['document']);
        $this->api('saveTerm', [$t, false])->assertUnprocessable();
        $this->asRole('PM Konstruksi');
        $this->api('decide', [$t['id'], 'Tolak', 'Perbaiki lokasi'])->assertOk();
        $this->asRole('Admin Konstruksi');
        $t = $this->getJson('/api/prototype/state')->json('state.terms.0');
        $t['barp']['location'] = 'Lokasi koreksi';
        $again = $this->api('saveTerm', [$t, true])->assertOk()->json('state.terms.0');
        $this->assertCount(2, $again['barpHistory']);
        $this->assertSame($submitted['barp'], $again['barpHistory'][0]['document']);
        $this->assertSame('Lokasi koreksi', $again['barpHistory'][1]['document']['location']);
        $this->assertSame([], $again['approvals']);
        $this->assertSame('PM Konstruksi', PrototypeDomain::nextApprovalRole($again));
        $this->asRole('PM Konstruksi');
        $this->api('saveTerm', [$t, false])->assertForbidden();
    }

    public function test_historical_documents_without_metadata_are_not_rewritten(): void
    {
        $s = $this->seedWorkflow('E');
        DB::enableQueryLog();
        DB::flushQueryLog();
        BarpTemplate::defaults($s, ['spkId' => $s['spks'][0]['id']]);
        $this->assertSame([], DB::getQueryLog(), 'BARP resolver adds zero database queries.');
        DB::disableQueryLog();
        $before = $s['terms'][0];
        $this->assertSame($before, BarpTemplate::save($s, $before, $before, true));
        $this->asRole('Admin Konstruksi');
        $read = $this->getJson('/api/prototype/state')->assertOk()->json('state.terms.0');
        $this->assertArrayNotHasKey('barp', $read);
    }
}
