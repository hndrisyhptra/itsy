<?php

namespace Tests\Feature;

use App\Models\PrototypeWorkspace;
use App\Services\BarpTemplate;
use App\Services\DocumentSigningPlace;
use App\Services\PrototypeDomain;
use App\Services\RelationalWorkflowStore;
use App\Services\SpkIssuance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\AuthenticatesArea3;
use Tests\TestCase;

class DocumentSigningPlaceTest extends TestCase
{
    use AuthenticatesArea3, RefreshDatabase;

    public function test_all_branch_places_are_canonical_and_pure_with_valid_future_dates_preserved(): void
    {
        $branches = DB::table('branches')->pluck('name')->all();
        DB::enableQueryLog();
        DB::flushQueryLog();
        foreach ($branches as $branch) {
            $project = ['branch' => $branch];
            $spk = ['place' => 'FORGED', 'date' => '2100-12-31', 'start' => '2026-11-01'];
            $canonical = SpkIssuance::sync($spk, $project);
            $this->assertSame($branch, DocumentSigningPlace::fromProject($project));
            $this->assertSame($branch, $canonical['place']);
            $this->assertSame('2100-12-31', $canonical['date']);
            $this->assertSame('FORGED', $spk['place']);
        }
        foreach ([[], ['branch' => ' '], ['branch' => null], ['branch' => ['forged']]] as $missing) {
            $this->assertSame('', DocumentSigningPlace::fromProject($missing));
        }
        $this->assertSame([], DB::getQueryLog());
        DB::disableQueryLog();
    }

    public function test_bakh_and_spk_save_follow_exact_related_branch_ignore_forgery_and_preserve_other_pp(): void
    {
        foreach ([false, true] as $spk) {
            $s = json_decode(file_get_contents(database_path('demo/'.($spk ? 'D' : 'C').'.json')), true);
            $s['projects'][0]['branch'] = 'MATARAM';
            $s['projects'][] = array_replace($s['projects'][0], ['id' => 'other-pp', 'branch' => 'DENPASAR']);
            $key = $spk ? 'spks' : 'deals';
            $doc = $s[$key][0];
            $s[$key] = [];
            $doc['status'] = 'Draft';
            $doc['place'] = $doc['signingPlace'] = 'FORGED CITY';
            $before = $s;
            DB::enableQueryLog();
            DB::flushQueryLog();
            $after = app(PrototypeDomain::class)->execute($s, $spk ? 'saveSpk' : 'saveDeal', [$doc, 'save'])['state'];
            $this->assertSame([], DB::getQueryLog());
            DB::disableQueryLog();
            $this->assertSame('MATARAM', $after[$key][0][$spk ? 'place' : 'signingPlace']);
            $this->assertSame($before, $s);
            $this->assertSame($s['projects'], $after['projects']);
            $this->assertSame($doc['date'], $after[$key][0]['date']);
        }
    }

    public function test_barp_branch_survives_http_and_sql_and_get_does_not_backfill_historical_city(): void
    {
        $s = $this->seedWorkflow('D');
        $s['projects'][0]['branch'] = 'MATARAM';
        $workspace = PrototypeWorkspace::first();
        app(RelationalWorkflowStore::class)->write($workspace, $s);
        $this->asRole('Admin Konstruksi');
        $api = function ($command, $args) {
            return $this->postJson('/api/prototype/commands', ['command' => $command, 'args' => $args, 'revision' => PrototypeWorkspace::first()->revision, 'request_id' => (string) Str::uuid()]);
        };
        $t = $api('createTerm', [$s['spks'][0]['id']])->assertOk()->json('state.terms.0');
        $this->assertSame('MATARAM', $t['barp']['place']);
        $t['barp']['place'] = 'CLIENT FORGERY';
        $t['actuals'] = [$s['deals'][0]['rows'][0]['rowId'] => ['m' => 1, 's' => 1]];
        DB::enableQueryLog();
        DB::flushQueryLog();
        $canonical = BarpTemplate::save($s, $t, $t, false);
        $this->assertSame([], DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertSame('MATARAM', $canonical['barp']['place']);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $saved = $api('saveTerm', [$t, false])->assertOk()->json('state.terms.0');
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertLessThanOrEqual(90, $queries);
        file_put_contents('/private/tmp/area3-branch-query.json', json_encode(['saveTerm_http_queries' => $queries, 'budget' => 90, 'place_helper_queries' => 0]));
        $this->assertSame('MATARAM', $saved['barp']['place']);
        $row = DB::table('payment_terms')->first();
        $doc = json_decode($row->document_data, true);
        $doc['barp']['place'] = 'LEGACY SURABAYA';
        $original = json_encode($doc);
        DB::table('payment_terms')->where('id_payment_term', $row->id_payment_term)->update(['document_data' => $original]);
        $this->getJson('/api/prototype/state')->assertOk();
        $this->assertSame($original, DB::table('payment_terms')->value('document_data'));
    }
}
