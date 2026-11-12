<?php

namespace Tests\Feature;

use App\Models\PrototypeWorkspace;
use App\Services\AccountAccess;
use App\Services\AuthenticatedWorkspace;
use App\Services\PaymentProjectCatalog;
use App\Services\PaymentSelection;
use App\Services\PrototypeDomain;
use App\Services\RelationalWorkflowStore;
use App\Support\WholeRupiah;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\AuthenticatesArea3;
use Tests\TestCase;

class PaymentProjectCatalogTest extends TestCase
{
    use AuthenticatesArea3, RefreshDatabase;

    private const URL = '/api/prototype/payment-selection/projects';

    private function source(): array
    {
        $s = $this->seedWorkflow('D');
        $this->asRole('Admin Konstruksi');

        return $s;
    }

    private function replace(array $s): void
    {
        DB::transaction(fn () => app(RelationalWorkflowStore::class)->write(PrototypeWorkspace::firstOrFail(), $s));
    }

    private function addOrder(array &$s, string $suffix, string $status = 'Sah', ?string $branch = null): void
    {
        $p = $s['projects'][0];
        $p['id'] = $p['rootId'] = 'pay-pp-'.$suffix;
        $p['no'] = 'PP/PAY/'.$suffix;
        $p['branch'] = $branch ?? $p['branch'];
        $d = $s['deals'][0];
        $d['id'] = 'pay-deal-'.$suffix;
        $d['projectId'] = $p['id'];
        $d['no'] = 'BA/PAY/'.$suffix;
        $v = $s['spks'][0];
        $v['id'] = 'pay-spk-'.$suffix;
        $v['dealId'] = $d['id'];
        $v['no'] = 'SPK/PAY/'.$suffix;
        $v['status'] = $status;
        $s['projects'][] = $p;
        $s['deals'][] = $d;
        $s['spks'][] = $v;
    }

    public function test_group_search_and_spk_list_include_only_attested_spks_with_actual_pp_mapping_and_no_writes(): void
    {
        $s = $this->source();
        $s['projects'][0]['projectCode'] = 'PAY-SHARED';
        $this->addOrder($s, 'second');
        $s['projects'][1]['name'] = 'Needle activity';
        $this->addOrder($s, 'draft', 'Draft');
        $this->addOrder($s, 'issued', 'Terbit');
        $this->replace($s);
        $before = DB::table('prototype_workspaces')->first(['state', 'revision']);
        $group = $this->getJson(self::URL.'?q=Needle')->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('items.0.spkCount', 2)->assertJsonPath('items.0.name', 'Needle activity');
        $list = $this->getJson(self::URL.'/'.$group->json('items.0.id').'/orders')->assertOk()->assertJsonCount(2, 'items');
        $second = collect($list->json('items'))->firstWhere('id', 'pay-spk-second');
        $this->assertSame('PP/PAY/second', $second['ppNo']);
        $this->assertSame('SPK/PAY/second', $second['no']);
        $this->assertSame('create', $second['action']);
        $this->assertSame(1, $second['nextNumber']);
        $this->assertSame(null, $second['latest']);
        $this->assertEquals(app(PrototypeDomain::class)->load($s)->dealTotal($s['deals'][1]), $second['amount']);
        $this->assertSame((array) $before, (array) DB::table('prototype_workspaces')->first(['state', 'revision']));
        $this->assertDatabaseCount('prototype_audits', 0);
        $this->assertDatabaseCount('payment_terms', 0);
        foreach (['document_data', 'signer', 'approverUserIds', 'storage_key', 'actuals'] as $private) {
            $this->assertStringNotContainsString($private, $list->getContent());
        }
    }

    public function test_shared_project_branch_filters_apply_before_matching_counts_and_listing_even_if_master_branch_is_hidden(): void
    {
        $s = $this->source();
        $s['projects'][0]['projectCode'] = 'PAY-SHARED';
        $s['projects'][0]['name'] = 'Visible activity';
        $branch = $s['projects'][0]['branch'];
        $this->addOrder($s, 'hidden', 'Sah', 'KUPANG');
        $s['projects'][1]['name'] = 'Secret sibling needle';
        $this->addOrder($s, 'private', 'Sah', 'KUPANG');
        $s['projects'][2]['projectCode'] = 'PRIVATE-PAY';
        $s['projects'] = [$s['projects'][1], $s['projects'][0], $s['projects'][2]];
        $this->replace($s);
        app(AccountAccess::class)->assign($this->accounts['Admin Konstruksi'], 'ADMIN_KONSTRUKSI', [DB::table('branches')->where('name', $branch)->value('id_branch')]);
        $group = $this->getJson(self::URL.'?q=PAY-SHARED')->assertOk()->assertJsonPath('items.0.spkCount', 1)->assertJsonPath('items.0.branchCount', 1);
        $this->getJson(self::URL.'?q=Secret')->assertOk()->assertJsonCount(0, 'items');
        $this->getJson(self::URL.'?q=PRIVATE')->assertOk()->assertJsonCount(0, 'items');
        $this->getJson(self::URL.'/'.$group->json('items.0.id').'/orders')->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('items.0.name', 'Visible activity');
        $hidden = DB::table('projects')->where('project_code', 'PRIVATE-PAY')->value('id_project');
        $this->getJson(self::URL.'/'.$hidden.'/orders')->assertNotFound();
        $this->getJson(self::URL.'/'.$hidden.'/orders?page=2')->assertOk()->assertJsonCount(0, 'items');
    }

    public function test_queries_are_bounded_and_constant_for_hundreds_of_orders_across_many_pps_and_projects(): void
    {
        $s = $this->source();
        $s['projects'][0]['projectCode'] = 'BULK-PAY-000';
        for ($i = 1; $i < 120; $i++) {
            $this->addOrder($s, (string) $i);
            $s['projects'][$i]['projectCode'] = 'BULK-PAY-'.str_pad((string) ($i < 30 ? 0 : $i % 30), 3, '0', STR_PAD_LEFT);
        }
        $this->replace($s);
        $catalog = app(PaymentProjectCatalog::class);
        $account = app(AuthenticatedWorkspace::class)->account();
        DB::enableQueryLog();
        DB::flushQueryLog();
        $a = $catalog->projects($account, 'BULK-PAY', 1);
        $this->assertQueries(DB::getQueryLog(), 2);
        DB::flushQueryLog();
        $list = $catalog->orders($account, $a['items'][0]['id'], 1);
        $this->assertQueries(DB::getQueryLog(), 4);
        DB::disableQueryLog();
        $b = $catalog->projects($account, 'BULK-PAY', 2);
        $this->assertCount(20, $a['items']);
        $this->assertTrue($a['hasMore']);
        $this->assertCount(10, $b['items']);
        $this->assertFalse($b['hasMore']);
        $this->assertSame(33, $a['items'][0]['spkCount']);
        $this->assertEmpty(array_intersect(array_column($a['items'], 'id'), array_column($b['items'], 'id')));
        $next = $catalog->orders($account, $a['items'][0]['id'], 2);
        $this->assertCount(20, $list['items']);
        $this->assertCount(13, $next['items']);
        $this->assertTrue($list['hasMore']);
        $this->assertFalse($next['hasMore']);
        $this->assertEmpty(array_intersect(array_column($list['items'], 'id'), array_column($next['items'], 'id')));
    }

    public function test_term_follow_up_routes_to_existing_draft_rejected_and_approved_unsettled_documents_and_keeps_final_closed(): void
    {
        $s = $this->seedWorkflow('overview');
        $this->asRole('Admin Konstruksi');
        $catalog = app(PaymentProjectCatalog::class);
        foreach ($s['spks'] as $v) {
            $d = collect($s['deals'])->firstWhere('id', $v['dealId']);
            $project = DB::table('project_plans')->where('source_id', $d['projectId'])->value('project_id');
            $list = $catalog->orders(app(AuthenticatedWorkspace::class)->account(), $project, 1);
            $choice = collect($list['items'])->firstWhere('id', $v['id']);
            $this->assertSame('open', $choice['action']);
            $this->assertNotEmpty($choice['termId']);
        }
        $vectors = json_decode(file_get_contents(base_path('tests/Fixtures/payment-selection.json')), true);
        foreach ($vectors as $vector) {
            $action = PaymentSelection::action($vector['terms'], $vector['approved']);
            $this->assertSame($vector['expected'], [$action['action'], $action['label'], $action['termId'], $action['nextNumber']], $vector['name']);
        }
    }

    public function test_component_totals_follow_combined_line_rounding_and_do_not_use_owner_or_master_prices(): void
    {
        $s = $this->source();
        $s['deals'][0]['rows'] = [array_merge($s['deals'][0]['rows'][0], ['m' => true, 's' => true, 'qm' => 1, 'qs' => 1, 'pm' => .005, 'ps' => .005])];
        // SQL prices are two-decimal snapshots: use a three-decimal quantity to exercise half-cent amounts.
        $s['deals'][0]['rows'][0]['qm'] = $s['deals'][0]['rows'][0]['qs'] = .005;
        $s['deals'][0]['rows'][0]['pm'] = $s['deals'][0]['rows'][0]['ps'] = 1;
        $this->replace($s);
        $group = $this->getJson(self::URL.'?q='.urlencode($s['projects'][0]['projectCode']))->assertOk();
        $list = $this->getJson(self::URL.'/'.$group->json('items.0.id').'/orders')->assertOk();
        $this->assertEquals(.01, $list->json('items.0.amount'));
        $this->assertEquals(app(PrototypeDomain::class)->load($s)->dealTotal($s['deals'][0]), $list->json('items.0.amount'));
    }

    public function test_whole_rupiah_order_amount_matches_domain_with_the_same_four_query_budget(): void
    {
        $s = $this->source();
        $s['deals'][0]['moneyPolicy'] = WholeRupiah::POLICY;
        $s['deals'][0]['rows'] = [array_replace($s['deals'][0]['rows'][0], ['m' => true, 's' => true, 'qm' => .015, 'qs' => .015, 'pm' => 100, 'ps' => 100])];
        $this->replace($s);
        $account = app(AuthenticatedWorkspace::class)->account();
        $project = DB::table('projects')->where('project_code', $s['projects'][0]['projectCode'])->value('id_project');
        DB::enableQueryLog();
        $result = app(PaymentProjectCatalog::class)->orders($account, $project, 1);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertQueries($queries, 4);
        $this->assertEquals(2, $result['items'][0]['amount']);
        $this->assertEquals(app(PrototypeDomain::class)->load($s)->dealTotal($s['deals'][0]), $result['items'][0]['amount']);
    }

    public function test_role_validation_empty_storage_and_literal_wildcards_fail_closed_without_creating_workspaces(): void
    {
        $s = $this->source();
        $s['projects'][0]['projectCode'] = 'PAY_%!';
        $this->replace($s);
        $group = $this->getJson(self::URL.'?'.http_build_query(['q' => '_%!']))->assertOk()->assertJsonCount(1, 'items');
        $project = $group->json('items.0.id');
        foreach (['?q=a', '?q[]=bad', '?q='.str_repeat('x', 101), '?q=ok&page=0', '?q=ok&page=1001'] as $query) {
            $this->getJson(self::URL.$query)->assertUnprocessable();
        }
        $this->getJson(self::URL.'/'.$project.'/orders?page=0')->assertUnprocessable();
        $this->getJson(self::URL.'/bad-id/orders')->assertNotFound();
        $this->asRole('Finance');
        $this->getJson(self::URL.'?q=PAY')->assertForbidden();
        $this->getJson(self::URL.'/'.$project.'/orders')->assertForbidden();
        $this->asRole('Admin Konstruksi');
        PrototypeWorkspace::firstOrFail()->update(['relational_migrated_at' => null]);
        $this->getJson(self::URL.'?q=PAY')->assertStatus(503);
        $this->getJson(self::URL.'/'.$project.'/orders')->assertStatus(503);
        $this->assertDatabaseCount('prototype_workspaces', 1);
        $this->assertDatabaseCount('prototype_audits', 0);
    }

    public function test_current_command_rechecks_spk_branch_approvers_and_duplicate_requests_without_a_second_draft(): void
    {
        $s = $this->source();
        $input = ['command' => 'createTerm', 'args' => [$s['spks'][0]['id']], 'revision' => 0, 'request_id' => (string) Str::uuid(), 'result_id' => 'payment-selection-new'];
        $otherBranch = DB::table('branches')->where('name', '!=', $s['projects'][0]['branch'])->value('id_branch');
        app(AccountAccess::class)->assign($this->accounts['Admin Konstruksi'], 'ADMIN_KONSTRUKSI', [$otherBranch]);
        $this->postJson('/api/prototype/commands', $input)->assertForbidden();
        $this->assertDatabaseCount('payment_terms', 0);
        app(AccountAccess::class)->assign($this->accounts['Admin Konstruksi'], 'ADMIN_KONSTRUKSI', DB::table('branches')->pluck('id_branch')->all());
        $this->accounts['Head of PMO']->forceFill(['active' => false])->save();
        $this->postJson('/api/prototype/commands', $input)->assertUnprocessable();
        $this->assertDatabaseCount('payment_terms', 0);
        $this->accounts['Head of PMO']->forceFill(['active' => true])->save();
        $r = $this->postJson('/api/prototype/commands', $input)->assertOk();
        $this->assertSame('payment-selection-new', $r->json('state.terms.0.id'));
        $this->postJson('/api/prototype/commands', $input)->assertOk();
        $this->assertDatabaseCount('payment_terms', 1);
        $input['request_id'] = (string) Str::uuid();
        $input['revision'] = $r->json('revision');
        $input['result_id'] = 'payment-selection-duplicate';
        $this->postJson('/api/prototype/commands', $input)->assertUnprocessable();
        $this->assertDatabaseCount('payment_terms', 1);
        $this->assertDatabaseCount('prototype_audits', 1);
        $input['revision'] = 0;
        $this->postJson('/api/prototype/commands', $input)->assertConflict();
        $this->asRole('Finance');
        $input['revision'] = 1;
        $this->postJson('/api/prototype/commands', $input)->assertForbidden();
    }

    private function assertQueries(array $queries, int $count): void
    {
        $this->assertCount($count, $queries);
        foreach ($queries as $q) {
            $this->assertDoesNotMatchRegularExpression('/^\s*(insert|update|delete|replace)\b|workflow_metadata|workflow_events|approval_decisions|designator_package_prices|select\s+\*|["`]?prototype_workspaces["`]?\.["`]?state/i', $q['query']);
        }
    }
}
