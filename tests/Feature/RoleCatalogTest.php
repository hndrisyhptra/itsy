<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AccountAccess;
use App\Services\RoleCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\AuthenticatesArea3;
use Tests\TestCase;

class RoleCatalogTest extends TestCase
{
    use AuthenticatesArea3, RefreshDatabase;

    private function payload(array $data = []): array
    {
        return array_replace(['action' => 'save', 'code' => 'OFFICER_SS1', 'name' => 'Officer 1 SS', 'request_id' => (string) Str::uuid()], $data);
    }

    public function test_catalog_crud_persists_with_actual_actor_audit_and_uuid_replay_without_workflow_changes(): void
    {
        $user = $this->asRole();
        $roles = DB::table('roles')->count();
        $assignments = DB::table('user_assignments')->get()->toJson();
        $payload = $this->payload(['permissions' => ['SUPERADMIN'], 'actor_user_id' => 999]);
        $response = $this->postJson('/api/account/roles', $payload)->assertOk();
        $row = $response->json('role');
        $this->assertFalse($row['system']);
        $this->assertNull($row['workflow_name']);
        $this->postJson('/api/account/roles', $payload)->assertOk()->assertExactJson($response->json());
        $this->assertDatabaseCount('roles', $roles + 1);
        $this->assertDatabaseCount('master_data_changes', 1);
        $this->postJson('/api/account/roles', $this->payload(['action' => 'delete']))->assertUnprocessable();
        $missingName = $this->payload();
        unset($missingName['name']);
        $this->postJson('/api/account/roles', $missingName)->assertUnprocessable();
        $this->assertDatabaseHas('master_data_changes', ['entity' => 'role', 'actor_user_id' => $user->id_user, 'record_id' => $row['id_role']]);
        $this->postJson('/api/account/roles', array_replace($payload, ['name' => 'Different']))->assertConflict();
        $edit = $this->payload(['id' => $row['id_role'], 'version' => $row['version'], 'name' => 'Officer 1 Shared Service']);
        $changed = $this->postJson('/api/account/roles', $edit)->assertOk()->json('role');
        $this->postJson('/api/account/roles', $edit)->assertOk();
        $this->postJson('/api/account/roles', $this->payload(['action' => 'delete', 'id' => $changed['id_role'], 'version' => $changed['version']]))->assertOk()->assertJsonPath('role', null);
        $this->assertDatabaseCount('roles', $roles);
        $this->assertDatabaseCount('master_data_changes', 3);
        $created = $this->postJson('/api/account/roles', $this->payload())->assertOk()->json('role');
        $this->assertGreaterThan($row['id_role'], $created['id_role']);
        $this->assertSame($assignments, DB::table('user_assignments')->get()->toJson());
        foreach (['prototype_workspaces', 'project_plans', 'approval_decisions', 'payment_terms'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->get('/akun/baru')->assertOk()->assertDontSee('value="OFFICER_SS1"', false);
    }

    public function test_builtin_role_label_can_change_but_code_authority_and_superadmin_access_are_preserved(): void
    {
        $user = $this->asRole();
        $role = collect($this->getJson('/api/account/roles')->assertOk()->json('rows'))->firstWhere('code', 'SUPERADMIN');
        $this->postJson('/api/account/roles', $this->payload(['code' => 'SUPERADMIN', 'id' => $role['id_role'], 'version' => $role['version'], 'name' => 'Administrator Utama']))->assertOk()->assertJsonPath('role.workflow_name', 'Superadmin');
        $this->assertSame('Superadmin', app(AccountAccess::class)->context($user)['role']);
        $role = collect($this->getJson('/api/account/roles')->json('rows'))->firstWhere('code', 'SUPERADMIN');
        $this->postJson('/api/account/roles', $this->payload(['action' => 'delete', 'code' => 'SUPERADMIN', 'id' => $role['id_role'], 'version' => $role['version']]))->assertUnprocessable();
        $this->postJson('/api/account/roles', $this->payload(['code' => 'OTHER_ADMIN', 'id' => $role['id_role'], 'version' => $role['version']]))->assertUnprocessable();
        $this->assertDatabaseHas('roles', ['code' => 'SUPERADMIN', 'name' => 'Administrator Utama']);
    }

    public function test_referenced_custom_role_including_inactive_assignments_cannot_be_deleted(): void
    {
        $user = $this->asRole();
        $row = $this->postJson('/api/account/roles', $this->payload())->json('role');
        DB::table('user_assignments')->insert(['user_id' => $user->id_user, 'role_id' => $row['id_role'], 'branch_id' => DB::table('branches')->value('id_branch'), 'active' => false]);
        $this->postJson('/api/account/roles', $this->payload(['action' => 'delete', 'id' => $row['id_role'], 'version' => $row['version']]))->assertUnprocessable();
        $this->assertDatabaseHas('roles', ['id_role' => $row['id_role']]);
        DB::table('user_assignments')->where('role_id', $row['id_role'])->delete();
        User::factory()->create(['role' => 'OFFICER_SS1']);
        $this->postJson('/api/account/roles', $this->payload(['action' => 'delete', 'id' => $row['id_role'], 'version' => $row['version']]))->assertUnprocessable();
        $this->assertDatabaseCount('master_data_changes', 1);
    }

    public function test_stale_versions_duplicate_codes_missing_records_and_invalid_input_fail_without_audit_writes(): void
    {
        $this->asRole();
        $row = $this->postJson('/api/account/roles', $this->payload())->json('role');
        $this->postJson('/api/account/roles', $this->payload())->assertUnprocessable();
        DB::table('roles')->where('id_role', $row['id_role'])->update(['name' => 'Changed elsewhere']);
        foreach (['save', 'delete'] as $action) {
            $this->postJson('/api/account/roles', $this->payload(['action' => $action, 'id' => $row['id_role'], 'version' => $row['version']]))->assertConflict();
        }
        $this->postJson('/api/account/roles', $this->payload(['id' => 65000, 'version' => $row['version']]))->assertNotFound();
        foreach ([['code' => 'invalid'], ['code' => 'X'], ['name' => '<script>'], ['name' => "One\nTwo"], ['name' => ' '], ['name' => str_repeat('a', 101)], ['id' => $row['id_role']]] as $bad) {
            $this->postJson('/api/account/roles', $this->payload($bad))->assertUnprocessable();
        }
        $this->assertDatabaseCount('master_data_changes', 1);
    }

    public function test_guest_nonadmin_csrf_and_deactivated_actor_are_denied(): void
    {
        $this->getJson('/api/account/roles')->assertUnauthorized();
        $this->postJson('/api/account/roles', $this->payload())->assertUnauthorized();
        foreach (['Admin Konstruksi', 'Finance', 'Mgr Finance & Settlement', 'Admin Operation'] as $role) {
            $this->asRole($role);
            $this->getJson('/api/account/roles')->assertForbidden();
            $this->postJson('/api/account/roles', $this->payload())->assertForbidden();
        }
        $user = $this->asRole();
        $this->app->instance('env', 'local');
        $this->postJson('/api/account/roles', $this->payload())->assertStatus(419);
        $this->app->instance('env', 'testing');
        DB::table('users')->where('id_user', $user->id_user)->update(['active' => false]);
        try {
            app(RoleCatalog::class)->change($user->id_user, $this->payload());
            $this->fail('Inactive actor must fail.');
        } catch (HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
        $this->assertDatabaseCount('master_data_changes', 0);
    }

    public function test_catalog_is_bounded_and_batch_counts_use_three_queries_even_with_many_roles_and_accounts(): void
    {
        $this->asRole();
        for ($i = 100; $i < 160; $i++) {
            DB::table('roles')->insert(['id_role' => $i, 'code' => 'TEST_'.$i, 'name' => 'Role '.$i]);
        }
        User::factory()->count(30)->create(['role' => 'TEST_100']);
        DB::enableQueryLog();
        $page = app(RoleCatalog::class)->listing('TEST_', 1);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertCount(3, $queries);
        $this->assertCount(20, $page['rows']);
        $this->assertTrue($page['has_more']);
        $this->assertSame(30, $page['rows'][0]['users_count']);
        $this->assertSame('TEST_100', $page['rows'][0]['code']);
        $last = app(RoleCatalog::class)->listing('TEST_', 3);
        $this->assertCount(20, $last['rows']);
        $this->assertFalse($last['has_more']);
        $this->assertSame([], app(RoleCatalog::class)->listing('%', 1)['rows']);
        $this->assertSame([], app(RoleCatalog::class)->listing("' OR 1=1 --", 1)['rows']);
        foreach ($queries as $query) {
            $this->assertDoesNotMatchRegularExpression('/prototype_workspaces|project_plans|payment_terms|finance_|password/', $query['query']);
        }
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson('/api/account/roles?q=TEST_')->assertOk()->assertHeader('Cache-Control', 'max-age=0, no-store, private');
        $httpQueries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertCount(4, $httpQueries);
    }

    public function test_role_mutation_rolls_back_if_durable_audit_fails(): void
    {
        $this->asRole();
        DB::listen(function ($event) {
            if (str_starts_with($event->sql, 'insert into "master_data_changes"')) {
                throw new \RuntimeException('Synthetic audit failure');
            }
        });
        $this->postJson('/api/account/roles', $this->payload())->assertServerError();
        $this->assertDatabaseMissing('roles', ['code' => 'OFFICER_SS1']);
        $this->assertDatabaseCount('master_data_changes', 0);
    }

    public function test_mutation_query_counts_are_constant_and_uuid_replay_is_read_only(): void
    {
        $this->asRole();
        User::factory()->count(30)->create();
        $send = function (array $input, int $expected): array {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $response = $this->postJson('/api/account/roles', $input)->assertOk();
            $queries = DB::getQueryLog();
            DB::disableQueryLog();
            $this->assertCount($expected, $queries);
            foreach ($queries as $query) {
                $this->assertDoesNotMatchRegularExpression('/prototype_workspaces|project_plans|payment_terms|finance_/', $query['query']);
            }

            return $response->json();
        };
        $create = $this->payload();
        $row = $send($create, 9)['role'];
        $send($create, 4);
        $row = $send($this->payload(['id' => $row['id_role'], 'version' => $row['version'], 'name' => 'Officer SS']), 8)['role'];
        $delete = $this->payload(['action' => 'delete', 'id' => $row['id_role'], 'version' => $row['version']]);
        $send($delete, 9);
        $send($delete, 4);
        $this->assertDatabaseCount('master_data_changes', 3);
    }
}
