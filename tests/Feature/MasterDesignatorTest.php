<?php

namespace Tests\Feature;

use App\Models\PrototypeWorkspace;
use App\Models\User;
use App\Services\AccountAccess;
use App\Services\AuthenticatedWorkspace;
use App\Services\PrototypeDomain;
use App\Services\RelationalWorkflowStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\AuthenticatesArea3;
use Tests\TestCase;

class MasterDesignatorTest extends TestCase
{
    use AuthenticatesArea3, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function change(string $entity, array $data = [], ?int $id = null, string $action = 'save', ?int $revision = null, ?string $uuid = null)
    {
        return $this->postJson('/api/masters/'.$entity, ['action' => $action, 'data' => $data,
            'revision' => $revision ?? PrototypeWorkspace::firstOrFail()->revision, 'request_id' => $uuid ?? (string) Str::uuid()] + ($id ? ['id' => $id] : []));
    }

    public function test_project_support_can_be_provisioned_and_login_with_username_without_gaining_master_rights(): void
    {
        $this->asRole();
        $this->get('/akun/baru')->assertOk()->assertSee('Mgr Project Support');
        $this->post('/akun', ['name' => 'Support QA', 'username' => 'support.qa', 'email' => 'support.qa@example.test', 'password' => 'Test12', 'password_confirmation' => 'Test12',
            'role' => 'MGR_PROJECT_SUPPORT', 'active' => 1, 'branches' => [DB::table('branches')->value('id_branch')]])->assertRedirect('/akun');
        $user = User::where('username', 'support.qa')->firstOrFail();
        $this->assertSame('Mgr Project Support', app(AccountAccess::class)->context($user)['role']);
        $this->assertContains('Mgr Project Support', PrototypeDomain::ROLES);
        $this->assertContains('Mgr Project Support', PrototypeDomain::APPROVERS_V3);
        $this->post('/logout');
        $this->post('/login', ['username' => 'support.qa', 'password' => 'Test12'])->assertRedirect('/dashboard');
        $this->getJson('/api/prototype/state')->assertOk()->assertJsonPath('state.role', 'Mgr Project Support');
        $this->getJson('/api/masters')->assertOk();
        $this->change('paket-khs', ['code' => 'DENIED', 'name' => 'Denied'])->assertForbidden();
        $this->get('/akun')->assertForbidden();
        $this->assertDatabaseMissing('packages', ['code' => 'DENIED']);
    }

    public function test_guests_and_all_non_superadmin_roles_cannot_write_masters_and_csrf_is_required(): void
    {
        $this->getJson('/api/masters')->assertUnauthorized();
        $this->postJson('/api/masters/khs', [])->assertUnauthorized();
        foreach (AccountAccess::ROLES as $role) {
            if ($role === 'Superadmin') {
                continue;
            }
            $this->asRole($role);
            $this->postJson('/api/masters/designator', [])->assertForbidden();
        }
        $this->asRole();
        $this->app->instance('env', 'local');
        $this->postJson('/api/masters/designator', [])->assertStatus(419);
        $this->assertDatabaseCount('master_data_changes', 0);
    }

    public function test_sql_crud_uses_existing_sources_preserves_zero_and_archives_in_dependency_order(): void
    {
        $this->asRole();
        $catalog = $this->getJson('/api/masters')->assertOk()->json('catalog');
        $this->assertCount(724, $catalog['designators']);
        $this->assertCount(1030, $catalog['prices']);
        $this->assertCount(2, $catalog['packages']);
        $pkg = $this->change('paket-khs', ['code' => 'PK-QA', 'name' => 'Paket QA', 'description' => 'Uji memory'])->assertOk()->json('recordId');
        $d = $this->change('designator', ['code' => 'M-QA', 'item_name' => 'Material QA', 'unit' => 'M', 'designator_type_id' => 1])->assertOk()->json('recordId');
        $price = $this->change('khs', ['designator_id' => $d, 'package_id' => $pkg, 'price' => 0])->assertOk()->json('recordId');
        $response = $this->getJson('/api/prototype/state')->assertOk();
        $khs = collect($response->json('state.khs'))->firstWhere('id', 'db-price-'.$price);
        $this->assertEquals(0, $khs['m']);
        $this->assertNull($khs['s']);
        $this->change('designator', ['code' => 'M-QA', 'item_name' => 'Material QA Updated', 'unit' => 'M', 'designator_type_id' => 1], $d)->assertOk();
        $this->change('paket-khs', ['code' => 'PK-QA', 'name' => 'Paket QA Updated', 'description' => 'Updated'], $pkg)->assertOk();
        $updated = $this->change('khs', ['designator_id' => $d, 'package_id' => $pkg, 'price' => '12.99'], $price)->assertOk();
        $khs = collect($updated->json('state.khs'))->firstWhere('id', 'db-price-'.$price);
        $this->assertSame(4, $khs['version']);
        $this->assertEquals(12, $khs['m']);
        $this->change('designator', [], $d, 'delete')->assertUnprocessable();
        $this->change('paket-khs', [], $pkg, 'delete')->assertUnprocessable();
        $deleted = $this->change('khs', [], $price, 'delete')->assertOk();
        $this->assertFalse(collect($deleted->json('state.khs'))->firstWhere('id', 'db-price-'.$price)['active']);
        $this->change('designator', [], $d, 'delete')->assertOk();
        $this->change('paket-khs', [], $pkg, 'delete')->assertOk();
        $this->assertNotNull(DB::table('designators')->where('id_designator', $d)->value('deleted_at'));
        $this->assertNotNull(DB::table('packages')->where('id_package', $pkg)->value('deleted_at'));
        $this->assertCount(724, $this->getJson('/api/masters')->json('catalog.designators'));
        $this->assertDatabaseCount('master_data_changes', 9);
        $this->assertDatabaseCount('command_receipts', 9);
        $audit = DB::table('master_data_changes')->where('entity', 'khs')->where('action', 'update')->first();
        $this->assertEquals(0, json_decode($audit->before_data, true)['price']);
        $this->assertEquals(12, json_decode($audit->after_data, true)['price']);
        foreach (['/master-designator/designator', '/master-designator/khs', '/master-designator/paket-khs', '/master-khs'] as $url) {
            $this->get($url)->assertOk();
        }
    }

    public function test_duplicate_invalid_prices_stale_revisions_and_replays_do_not_partially_mutate(): void
    {
        $this->asRole();
        $this->getJson('/api/masters');
        $uuid = (string) Str::uuid();
        $pkg = $this->change('paket-khs', ['code' => 'PK-REPLAY', 'name' => 'Replay'], null, 'save', 0, $uuid)->assertOk()->json('recordId');
        $this->change('paket-khs', ['code' => 'PK-REPLAY', 'name' => 'Replay'], null, 'save', 0, $uuid)->assertOk()->assertJsonPath('replayed', true)->assertJsonPath('revision', 1);
        $this->change('paket-khs', ['code' => 'STALE', 'name' => 'Stale'], null, 'save', 0)->assertConflict();
        $this->change('paket-khs', ['code' => 'PK-REPLAY', 'name' => 'Duplicate'])->assertUnprocessable();
        $source = DB::table('designator_package_prices')->first();
        foreach ([-1, '1.234', '10000000000000', 'NaN'] as $price) {
            $this->change('khs', ['designator_id' => $source->designator_id, 'package_id' => $pkg, 'price' => $price])->assertUnprocessable();
        }
        $this->change('khs', ['designator_id' => 999999, 'package_id' => $pkg, 'price' => 1])->assertUnprocessable();
        $this->change('khs', ['designator_id' => $source->designator_id, 'package_id' => $source->package_id, 'price' => 1])->assertUnprocessable();
        $this->change('khs', ['designator_id' => $source->designator_id, 'package_id' => $pkg, 'price' => 1], $source->id_designator_package_price)->assertUnprocessable();
        $this->assertDatabaseCount('master_data_changes', 1);
        $this->assertDatabaseCount('command_receipts', 1);
        $this->assertDatabaseCount('prototype_audits', 1);
        $this->assertSame(1, PrototypeWorkspace::first()->revision);
        $this->assertDatabaseMissing('packages', ['code' => 'STALE']);
    }

    public function test_used_identity_and_issued_snapshots_survive_price_changes_and_deletion(): void
    {
        $this->seedWorkflow('B');
        $workspace = PrototypeWorkspace::firstOrFail();
        $state = app(AuthenticatedWorkspace::class)->bind(app(RelationalWorkflowStore::class)->read($workspace));
        $khs = collect($state['khs'])->firstWhere('active', true);
        $p = $state['projects'][0];
        $p['boq'][0] = array_merge($p['boq'][0], ['khsId' => $khs['id'], 'm' => $khs['m'], 's' => $khs['s'], 'version' => $khs['version']]);
        $state['projects'][0] = $p;
        DB::transaction(fn () => app(RelationalWorkflowStore::class)->write($workspace, $state));
        $snapshot = DB::table('project_plans')->pluck('document_data', 'id_pp')->all();
        $lines = DB::table('boq_lines')->get()->toJson();
        $archive = $workspace->getRawOriginal('state');
        $source = DB::table('designator_package_prices')->where('id_designator_package_price', substr($khs['id'], 9))->first();
        $d = DB::table('designators')->where('id_designator', $source->designator_id)->first();
        $pkg = DB::table('packages')->where('id_package', $source->package_id)->first();
        $this->change('designator', ['code' => $d->code, 'item_name' => 'Changed identity', 'unit' => $d->unit, 'designator_type_id' => $d->designator_type_id], $d->id_designator)->assertUnprocessable();
        $this->change('paket-khs', ['code' => $pkg->code, 'name' => 'Changed identity'], $pkg->id_package)->assertUnprocessable();
        $response = $this->change('khs', ['designator_id' => $d->id_designator, 'package_id' => $pkg->id_package, 'price' => (float) $source->price + 1], $source->id_designator_package_price)->assertOk();
        $changed = collect($response->json('state.khs'))->firstWhere('id', $khs['id']);
        $this->assertSame($khs['version'] + 1, $changed['version']);
        $this->change('khs', [], $source->id_designator_package_price, 'delete')->assertOk();
        $this->assertSame($snapshot, DB::table('project_plans')->pluck('document_data', 'id_pp')->all());
        $this->assertSame($lines, DB::table('boq_lines')->get()->toJson());
        $this->assertSame($archive, $workspace->fresh()->getRawOriginal('state'));
        $state = $this->getJson('/api/prototype/state')->assertOk()->json('state');
        $this->assertFalse(collect($state['khs'])->firstWhere('id', $khs['id'])['active']);
        $this->assertEquals($p['boq'][0], $state['projects'][0]['boq'][0]);
    }
}
