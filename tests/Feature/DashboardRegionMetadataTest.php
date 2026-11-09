<?php

namespace Tests\Feature;

use App\Models\PrototypeWorkspace;
use App\Services\AccountAccess;
use App\Services\AuthenticatedWorkspace;
use App\Services\RelationalWorkflowStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\AuthenticatesArea3;
use Tests\TestCase;

class DashboardRegionMetadataTest extends TestCase
{
    use AuthenticatesArea3, RefreshDatabase;

    public function test_region_metadata_uses_one_joined_reference_query_at_small_and_large_catalog_sizes(): void
    {
        $s = $this->seedWorkflow('D');
        $service = app(AuthenticatedWorkspace::class);
        foreach ([0, 200] as $size) {
            if ($size) {
                for ($i = 0; $i < $size; $i++) {
                    $region = DB::table('regions')->insertGetId(['code' => 'QA-REG-'.$i, 'name' => 'Region '.$i], 'id_region');
                    DB::table('branches')->insert(['code' => 'QA-BR-'.$i, 'kode_branch' => 'QA-'.$i, 'name' => 'Branch '.$i, 'region_id' => $region]);
                }
            }
            DB::enableQueryLog();
            DB::flushQueryLog();
            $bound = $service->bind($s);
            $queries = DB::getQueryLog();
            DB::disableQueryLog();
            $this->assertCount(5, $queries);
            $regionQueries = array_values(array_filter($queries, fn ($q) => str_contains($q['query'], 'regions')));
            $this->assertCount(1, $regionQueries);
            $this->assertStringContainsString('left join "regions"', $regionQueries[0]['query']);
            $this->assertStringNotContainsString('select *', $regionQueries[0]['query']);
            $this->assertGreaterThanOrEqual($size, count($bound['branches']));
            if ($size) {
                $branch = collect($bound['branches'])->firstWhere('name', 'Branch 199');
                $this->assertSame('Region 199', $branch['regionName']);
                $this->assertStringStartsWith('db-region-', $branch['regionId']);
            }
        }
    }

    public function test_deleted_or_missing_region_keeps_branch_with_explicit_unassigned_metadata(): void
    {
        $s = $this->seedWorkflow('D');
        $name = $s['projects'][0]['branch'];
        $region = DB::table('regions')->insertGetId(['code' => 'QA-DELETED', 'name' => 'Deleted region', 'deleted_at' => now()], 'id_region');
        foreach ([$region, null] as $id) {
            DB::table('branches')->where('name', $name)->update(['region_id' => $id, 'region' => 'Do not guess this legacy label']);
            $bound = app(AuthenticatedWorkspace::class)->bind($s);
            $branch = collect($bound['branches'])->firstWhere('name', $name);
            $this->assertNull($branch['regionId']);
            $this->assertSame('', $branch['regionName']);
        }
    }

    public function test_dashboard_state_scopes_region_and_pp_by_authorized_branch_without_archive_or_audit_writes(): void
    {
        $s = $this->seedWorkflow('D');
        $name = $s['projects'][0]['branch'];
        $branch = DB::table('branches')->where('name', $name)->value('id_branch');
        $visibleRegion = DB::table('regions')->insertGetId(['code' => 'QA-VISIBLE', 'name' => 'Visible region'], 'id_region');
        $secretRegion = DB::table('regions')->insertGetId(['code' => 'QA-SECRET', 'name' => 'Secret region'], 'id_region');
        DB::table('branches')->where('name', $name)->update(['region_id' => $visibleRegion]);
        DB::table('branches')->where('name', 'KUPANG')->update(['region_id' => $secretRegion]);
        $hidden = $s['projects'][0];
        $hidden['id'] = $hidden['rootId'] = 'hidden-matrix-pp';
        $hidden['no'] = 'PP/MATRIX/HIDDEN';
        $hidden['name'] = 'Hidden matrix activity';
        $hidden['branch'] = 'KUPANG';
        // Shared Project ID whose master points at the hidden Branch must not hide/leak visible PP.
        array_unshift($s['projects'], $hidden);
        DB::transaction(fn () => app(RelationalWorkflowStore::class)->write(PrototypeWorkspace::firstOrFail(), $s));
        $before = (array) DB::table('prototype_workspaces')->first(['state', 'revision']);
        foreach (['Admin Konstruksi', 'Head of PMO', 'Finance'] as $role) {
            $user = $this->asRole($role);
            app(AccountAccess::class)->assign($user, array_search($role, AccountAccess::ROLES, true), [$branch]);
            $response = $this->getJson('/api/prototype/state')->assertOk();
            $state = $response->json('state');
            $this->assertCount(1, $state['branches']);
            $this->assertSame('Visible region', $state['branches'][0]['regionName']);
            $this->assertSame('db-region-'.$visibleRegion, $state['branches'][0]['regionId']);
            $this->assertCount(1, $state['projects']);
            $this->assertSame($name, $state['projects'][0]['branch']);
            $this->assertStringNotContainsString('Secret region', $response->getContent());
            $this->assertStringNotContainsString('Hidden matrix activity', $response->getContent());
        }
        $this->assertSame($before, (array) DB::table('prototype_workspaces')->first(['state', 'revision']));
        $this->assertDatabaseCount('prototype_audits', 0);
        $this->assertDatabaseCount('command_receipts', 0);
    }
}
