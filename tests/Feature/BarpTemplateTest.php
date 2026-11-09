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
        $t['barp'] = array_merge($t['barp'], ['location' => 'Lokasi BARP', 'sto' => 'STO BARP', 'homepass' => 0, 'headName' => 'FORGED', 'headNik' => 'FORGED', 'headUserId' => 'FORGED', 'waspang' => 'FORGED', 'mandor' => 'FORGED', 'job' => 'FORGED', 'deployment' => ['name' => 'FORGED'], 'support' => ['userId' => 'FORGED']]);
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
        $this->assertSame(2, $saved['barp']['version']);
        $this->assertSame($s['projects'][0]['name'], $saved['barp']['job']);
        $this->assertSame(['userId' => 'db-user-'.$this->accounts['Mgr Deployment']->getKey(), 'name' => 'Mgr Deployment Test', 'nik' => ''], $saved['barp']['deployment']);
        $this->assertSame('Mgr Project Support Test', $saved['barp']['support']['name']);
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
        foreach ([['date', '2026-02-30'], ['start', '1900-01-01'], ['end', '2999-01-01'], ['homepass', -1], ['homepass', 1.2], ['homepass', '10'], ['homepass', 1000000001], ['sto', str_repeat('x', 151)]] as [$field, $value]) {
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
        $t['barp']['sto'] = 'STO koreksi';
        $again = $this->api('saveTerm', [$t, true])->assertOk()->json('state.terms.0');
        $this->assertCount(2, $again['barpHistory']);
        $this->assertSame($submitted['barp'], $again['barpHistory'][0]['document']);
        $this->assertSame('STO koreksi', $again['barpHistory'][1]['document']['sto']);
        $this->assertSame([], $again['approvals']);
        $this->assertSame('PM Konstruksi', PrototypeDomain::nextApprovalRole($again));
        $this->asRole('PM Konstruksi');
        $this->api('saveTerm', [$t, false])->assertForbidden();
    }

    public function test_version_one_barp_is_preserved_on_reads_and_adopted_only_on_explicit_admin_correction(): void
    {
        [$s, $t] = $this->draft();
        $submitted = $this->api('saveTerm', [$t, true])->assertOk()->json('state.terms.0');
        // Change an isolated fixture to a document submitted before this feature, never live data.
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $row = DB::table('payment_terms')->where('source_id', $t['id'])->first();
        $document = json_decode($row->document_data, true);
        $old = $submitted['barp'];
        $old['version'] = 1;
        $old['location'] = 'Lokasi historis';
        unset($old['job'], $old['deployment'], $old['support']);
        $document['barp'] = $document['barpHistory'][0]['document'] = $old;
        $json = json_encode($document);
        DB::table('payment_terms')->where('source_id', $t['id'])->update(['document_data' => $json]);
        $read = $this->getJson('/api/prototype/state')->assertOk()->json('state.terms.0');
        $this->assertSame($old, $read['barp']);
        $this->assertSame($json, DB::table('payment_terms')->where('source_id', $t['id'])->value('document_data'));
        $this->api('saveTerm', [$read, false])->assertUnprocessable();
        $this->asRole('PM Konstruksi');
        $this->api('decide', [$t['id'], 'Tolak', 'Koreksi metadata'])->assertOk();
        $this->asRole('Admin Konstruksi');
        $read = $this->getJson('/api/prototype/state')->json('state.terms.0');
        $read['barp']['job'] = 'FORGED';
        $read['barp']['deployment'] = ['name' => 'FORGED', 'userId' => 'FORGED'];
        $saved = $this->api('saveTerm', [$read, false])->assertOk()->json('state.terms.0');
        $this->assertSame(2, $saved['barp']['version']);
        $this->assertSame($s['projects'][0]['name'], $saved['barp']['job']);
        $this->assertSame('Mgr Deployment Test', $saved['barp']['deployment']['name']);
        $this->assertSame('Mgr Project Support Test', $saved['barp']['support']['name']);
        $this->assertSame($old, $saved['barpHistory'][0]['document']);
        $this->accounts['Mgr Deployment']->forceFill(['name' => 'Nama akun sesudah snapshot'])->save();
        $resubmitted = $this->api('saveTerm', [$saved, true])->assertOk()->json('state.terms.0');
        $this->assertSame($old, $resubmitted['barpHistory'][0]['document']);
        $this->assertSame($saved['barp'], $resubmitted['barpHistory'][1]['document']);
        $this->assertSame('Mgr Deployment Test', $resubmitted['barp']['deployment']['name']);
        $this->assertSame([], $resubmitted['approvals']);
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
