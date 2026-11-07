<?php

namespace Tests\Feature;

use App\Models\PrototypeWorkspace;
use App\Services\AccountAccess;
use App\Services\AuthenticatedWorkspace;
use App\Services\NegotiationProjectCatalog;
use App\Services\PrototypeDomain;
use App\Services\RelationalWorkflowStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\AuthenticatesArea3;
use Tests\TestCase;

class NegotiationProjectCatalogTest extends TestCase
{
    use AuthenticatesArea3, RefreshDatabase;

    private const URL = '/api/prototype/negotiation/projects';

    private function replace(array $state): void
    {
        $workspace = PrototypeWorkspace::firstOrFail();
        DB::transaction(fn () => app(RelationalWorkflowStore::class)->write($workspace, $state));
    }

    private function source(string $scenario = 'B'): array
    {
        $s = $this->seedWorkflow($scenario);
        $this->asRole('Admin Konstruksi');

        return $s;
    }

    public function test_search_groups_all_visible_pps_even_when_only_one_activity_matches_and_never_reads_archive_or_catalog(): void
    {
        $s = $this->source();
        $p = $s['projects'][0];
        $s['projects'][0]['projectCode'] = 'SEARCH-ID';
        $s['projects'][0]['name'] = 'Normal activity';
        $copy = $s['projects'][0];
        $copy['id'] = $copy['rootId'] = 'picker-second';
        $copy['no'] = 'PICKER/SECOND';
        $copy['name'] = 'Unique Activity Needle';
        $copy['status'] = 'Draft';
        $s['projects'][] = $copy;
        $this->replace($s);
        $archive = PrototypeWorkspace::firstOrFail()->state;
        DB::enableQueryLog();
        DB::flushQueryLog();
        $response = $this->getJson(self::URL.'?q=Needle')->assertOk();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertCount(1, $response->json('items'));
        $this->assertSame('SEARCH-ID', $response->json('items.0.code'));
        $this->assertSame(2, $response->json('items.0.ppCount'));
        $this->assertSame('Unique Activity Needle', $response->json('items.0.name'));
        $this->assertFalse($response->json('hasMore'));
        $this->assertQueries($queries, 6);
        $json = $response->getContent();
        $this->assertStringNotContainsString('approver', $json);
        $this->assertStringNotContainsString('boq', $json);
        $this->assertStringNotContainsString('document_data', $json);
        $this->assertSame($archive, PrototypeWorkspace::firstOrFail()->state);
        $this->assertDatabaseCount('prototype_audits', 0);
    }

    public function test_shared_id_filters_by_each_pp_branch_and_does_not_leak_hidden_names_counts_or_project_ids(): void
    {
        $s = $this->source();
        $s['projects'][0]['projectCode'] = 'SHARED-CODE';
        $s['projects'][0]['name'] = 'Visible work';
        $copy = $s['projects'][0];
        $copy['id'] = $copy['rootId'] = 'picker-hidden';
        $copy['no'] = 'HIDDEN/PP';
        $copy['name'] = 'Hidden activity needle';
        $copy['branch'] = 'KUPANG';
        // Insert the hidden PP first, so projects.branch_id points to the inaccessible Branch.
        $s['projects'] = [$copy, $s['projects'][0]];
        $private = $copy;
        $private['id'] = $private['rootId'] = 'picker-private';
        $private['no'] = 'PRIVATE/PP';
        $private['projectCode'] = 'PRIVATE-CODE';
        $s['projects'][] = $private;
        $this->replace($s);
        $branch = DB::table('branches')->where('name', $s['projects'][1]['branch'])->value('id_branch');
        app(AccountAccess::class)->assign($this->accounts['Admin Konstruksi'], 'ADMIN_KONSTRUKSI', [$branch]);
        $r = $this->getJson(self::URL.'?q=SHARED')->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('items.0.ppCount', 1);
        $this->getJson(self::URL.'?q=Hidden')->assertOk()->assertJsonCount(0, 'items');
        $this->getJson(self::URL.'?q=PRIVATE')->assertOk()->assertJsonCount(0, 'items');
        $this->getJson(self::URL.'/'.$r->json('items.0.id').'/plans')->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('items.0.name', 'Visible work');
        $hiddenId = DB::table('projects')->where('project_code', 'PRIVATE-CODE')->value('id_project');
        $this->getJson(self::URL.'/'.$hiddenId.'/plans')->assertNotFound();
    }

    public function test_lists_are_bounded_stable_and_paginated_with_constant_query_counts_for_hundreds_of_pps(): void
    {
        $s = $this->source();
        $p = $s['projects'][0];
        $s['projects'] = [];
        for ($i = 0; $i < 120; $i++) {
            $v = $p;
            $v['id'] = $v['rootId'] = 'picker-bulk-'.$i;
            $v['projectCode'] = 'BULK-'.str_pad((string) ($i < 30 ? 0 : $i % 30), 3, '0', STR_PAD_LEFT);
            $v['no'] = 'PICKER/BULK/'.$i;
            $s['projects'][] = $v;
        }
        $this->replace($s);
        $first = $this->getJson(self::URL.'?q=BULK')->assertOk()->assertJsonCount(20, 'items')->assertJsonPath('hasMore', true)->json('items');
        $second = $this->getJson(self::URL.'?q=BULK&page=2')->assertOk()->assertJsonCount(10, 'items')->assertJsonPath('hasMore', false)->json('items');
        $this->assertEmpty(array_intersect(array_column($first, 'id'), array_column($second, 'id')));
        $this->assertSame(33, $first[0]['ppCount']);
        $plansOne = $this->getJson(self::URL.'/'.$first[0]['id'].'/plans')->assertOk()->assertJsonCount(20, 'items')->assertJsonPath('hasMore', true)->json('items');
        $plansTwo = $this->getJson(self::URL.'/'.$first[0]['id'].'/plans?page=2')->assertOk()->assertJsonCount(13, 'items')->assertJsonPath('hasMore', false)->json('items');
        $this->assertEmpty(array_intersect(array_column($plansOne, 'id'), array_column($plansTwo, 'id')));
        $account = app(AuthenticatedWorkspace::class)->account();
        DB::enableQueryLog();
        DB::flushQueryLog();
        $catalog = app(NegotiationProjectCatalog::class);
        $catalog->projects($account, 'BULK', 1);
        $this->assertCount(2, DB::getQueryLog());
        DB::flushQueryLog();
        $catalog->plans($account, $first[0]['id'], 1);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertCount(3, $queries);
        $this->assertQueries($queries, 3);
    }

    public function test_eligibility_matches_domain_capacity_for_roots_and_continuations_without_double_counting_allocations(): void
    {
        $s = $this->source('overview');
        foreach ($s['projects'] as &$project) {
            if (! empty($project['parentId'])) {
                $project['status'] = 'Sah';
                $project['boqStatus'] = 'Final';
            }
        }
        unset($project);
        $this->replace($s);
        $domain = app(PrototypeDomain::class)->load($s);
        $account = app(AuthenticatedWorkspace::class)->account();
        foreach ($s['projects'] as $p) {
            $id = DB::table('project_plans')->where('source_id', $p['id'])->value('project_id');
            DB::enableQueryLog();
            DB::flushQueryLog();
            $page = app(NegotiationProjectCatalog::class)->plans($account, $id, 1);
            $queries = DB::getQueryLog();
            DB::disableQueryLog();
            $this->assertQueries($queries, 4);
            $choice = collect($page['items'])->firstWhere('id', $p['id']);
            $root = collect($s['projects'])->firstWhere('id', $p['rootId']);
            $hasCapacity = false;
            foreach ($root['boq'] as $row) {
                foreach (['m', 's'] as $component) {
                    // For continuations, domain capacity is allocation less all BA KH assignments.
                    $assigned = 0;
                    foreach ($s['deals'] as $deal) {
                        if ($deal['projectId'] === $p['id']) {
                            $r = collect($deal['rows'])->firstWhere('rowId', $row['id']);
                            $assigned += ($r[$component] ?? false) ? $r[$component === 'm' ? 'qm' : 'qs'] : 0;
                        }
                    }
                    $remaining = empty($p['parentId']) ? $domain->ledger($p['rootId'], $row['id'], $component)['available'] : round(($p['allocations'][$row['id']][$component] ?? 0) - $assigned, 3);
                    $hasCapacity |= $row[$component] !== null && $remaining > 0;
                }
            }
            $this->assertSame($p['status'] === 'Sah' && $p['boqStatus'] === 'Final' && (bool) $hasCapacity, $choice['eligible'], $p['no']);
        }
    }

    public function test_fully_assigned_continuation_is_unavailable_and_one_thousandth_remaining_is_available(): void
    {
        $s = $this->source('overview');
        $childIndex = array_search(true, array_map(fn ($p) => ! empty($p['parentId']), $s['projects']), true);
        $s['projects'][$childIndex]['status'] = 'Sah';
        $s['projects'][$childIndex]['boqStatus'] = 'Final';
        $child = $s['projects'][$childIndex];
        $deal = collect($s['deals'])->firstWhere('projectId', $child['rootId']);
        $deal['id'] = 'picker-child-fully-assigned';
        $deal['projectId'] = $child['id'];
        $deal['no'] = 'QA/PICKER/CHILD';
        $deal['status'] = 'Draft';
        foreach ($deal['rows'] as &$row) {
            $row['qm'] = $child['allocations'][$row['rowId']]['m'] ?? 0;
            $row['qs'] = $child['allocations'][$row['rowId']]['s'] ?? 0;
            $row['m'] = $row['qm'] > 0;
            $row['s'] = $row['qs'] > 0;
        }
        unset($row);
        $s['deals'][] = $deal;
        $this->replace($s);
        $id = DB::table('project_plans')->where('source_id', $child['id'])->value('project_id');
        $url = self::URL.'/'.$id.'/plans';
        $find = fn ($response) => collect($response->json('items'))->firstWhere('id', $child['id']);
        $this->assertSame('Volume tersedia sudah habis.', $find($this->getJson($url)->assertOk())['reason']);
        foreach ($s['deals'][array_key_last($s['deals'])]['rows'] as &$row) {
            if ($row['qm'] > 0) {
                $row['qm'] -= .001;
                break;
            }
        }
        unset($row);
        $this->replace($s);
        $this->assertTrue($find($this->getJson($url)->assertOk())['eligible']);
    }

    public function test_roles_validation_literal_wildcards_and_unactivated_storage_fail_closed(): void
    {
        $s = $this->source();
        $s['projects'][0]['projectCode'] = 'LITERAL_%!';
        $this->replace($s);
        $this->getJson(self::URL.'?'.http_build_query(['q' => '_%!']))->assertOk()->assertJsonCount(1, 'items');
        $this->getJson(self::URL.'?q=zz')->assertOk()->assertJsonCount(0, 'items');
        foreach (['?q=a', '?q[]=bad', '?q='.str_repeat('x', 101), '?q=ok&page=0', '?q=ok&page=1001'] as $query) {
            $this->getJson(self::URL.$query)->assertUnprocessable();
        }
        $this->asRole('PM Konstruksi');
        $this->getJson(self::URL.'?q=LITERAL')->assertForbidden();
        $this->asRole('Admin Konstruksi');
        PrototypeWorkspace::firstOrFail()->update(['relational_migrated_at' => null]);
        $this->getJson(self::URL.'?q=LITERAL')->assertStatus(503);
    }

    private function assertQueries(array $queries, int $maximum): void
    {
        $this->assertLessThanOrEqual($maximum, count($queries));
        foreach ($queries as $q) {
            $this->assertDoesNotMatchRegularExpression('/^\s*(insert|update|delete|replace)\b/i', $q['query']);
            $this->assertDoesNotMatchRegularExpression('/workflow_metadata|designator_package_prices|workflow_events|approval_decisions/i', $q['query']);
            $this->assertDoesNotMatchRegularExpression('/select\s+\*|["`]?prototype_workspaces["`]?\.["`]?state/i', $q['query']);
        }
    }
}
