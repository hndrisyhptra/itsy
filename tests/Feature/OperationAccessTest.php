<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AccountAccess;
use App\Services\WorkUnitAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\AuthenticatesArea3;
use Tests\TestCase;

class OperationAccessTest extends TestCase
{
    use AuthenticatesArea3, RefreshDatabase;

    public function test_superadmin_creates_region_manager_and_scope_tracks_current_region_branches_in_one_query(): void
    {
        $this->withoutVite();
        $this->asRole();
        $region = DB::table('branches')->where('name', 'PEKALONGAN')->value('region_id');
        $unit = DB::table('work_units')->where('code', 'OPERATION')->value('id_work_unit');
        $form = ['name' => 'Manager SS QA', 'username' => 'manager-ss-qa', 'email' => 'manager-ss@example.test', 'password' => 'Secret123', 'password_confirmation' => 'Secret123', 'role' => 'MGR_SS', 'active' => 1,
            'id_regions' => $region, 'access_scope' => 'REGION', 'work_unit_id' => $unit];
        $this->post('/akun', $form)->assertRedirect('/akun');
        $manager = User::where('username', 'manager-ss-qa')->firstOrFail();
        $context = app(AccountAccess::class)->context($manager);
        $this->assertSame(['Operation'], $context['workUnits']);
        $this->assertSame('REGION', $context['accessScope']);
        $this->assertSame(DB::table('branches')->where('region_id', $region)->where('is_active', true)->whereNull('deleted_at')->count(), count($context['branchIds']));
        DB::table('branches')->insert(['code' => 'QA-REGION-NEW', 'kode_branch' => 'QA-NEW', 'name' => 'QA REGION NEW', 'region_id' => $region]);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $context = app(AccountAccess::class)->context($manager);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertCount(1, $queries);
        $this->assertContains('QA REGION NEW', $context['branches']);
        $form['username'] = 'bad-scope';
        $form['email'] = 'bad@example.test';
        $form['access_scope'] = 'BRANCH';
        $form['branches'] = [DB::table('branches')->value('id_branch')];
        $this->post('/akun', $form)->assertStatus(422);
        $this->assertDatabaseMissing('users', ['username' => 'bad-scope']);
        $form['access_scope'] = 'REGION';
        $form['work_unit_id'] = DB::table('work_units')->where('code', 'KONSTRUKSI')->value('id_work_unit');
        $this->post('/akun', $form)->assertStatus(422);
    }

    public function test_officer_ss_may_use_branch_or_region_but_primary_placement_alone_does_not_expand_access(): void
    {
        $user = $this->asRole('Officer 1 SS');
        $branch = DB::table('branches')->where('name', 'PEKALONGAN')->first();
        app(AccountAccess::class)->assign($user, 'OFFICER_SS1', [$branch->id_branch]);
        DB::table('users')->where('id_user', $user->id_user)->update(['id_regions' => $branch->region_id]);
        $context = app(AccountAccess::class)->context($user->fresh());
        $this->assertSame([$branch->id_branch], $context['branchIds']);
        DB::table('users')->where('id_user', $user->id_user)->update(['access_scope' => 'REGION']);
        $context = app(AccountAccess::class)->context($user->fresh());
        $this->assertGreaterThanOrEqual(1, count($context['branchIds']));
        $other = DB::table('branches')->where('region_id', '<>', $branch->region_id)->pluck('id_branch')->all();
        $this->assertSame([], array_intersect($other, $context['branchIds']));
        DB::table('user_assignments')->where('user_id', $user->id_user)->update(['active' => false]);
        $this->assertNull(app(AccountAccess::class)->context($user->fresh()));
    }

    public function test_unit_scope_preserves_only_actual_legacy_operation_assignee_without_rewriting_old_chain(): void
    {
        $s = $this->seedWorkflow('B');
        $pm = $this->asRole('PM Konstruksi');
        $account = app(AccountAccess::class)->context($pm);
        $legacy = $s['projects'][0];
        $legacy['unit'] = 'Operation';
        $this->assertTrue(WorkUnitAccess::permits($account, $legacy));
        $legacy['pmUserId'] = 'db-user-999';
        $this->assertFalse(WorkUnitAccess::permits($account, $legacy));
        $legacy['pmUserId'] = $account['id'];
        $legacy['approvalScheme'] = 'operation-v1';
        $this->assertFalse(WorkUnitAccess::permits($account, $legacy));
        $this->assertSame(['Operation'], WorkUnitAccess::units('ADMIN_OPS'));
        $this->assertSame(['Konstruksi'], WorkUnitAccess::units('ADMIN_KONSTRUKSI'));
        $this->assertSame(['Konstruksi', 'Operation'], WorkUnitAccess::units('FINANCE'));
        $this->assertSame(['Operation'], WorkUnitAccess::units('FINANCE', 'OPERATION'));
        $this->assertSame([], WorkUnitAccess::units('ADMIN_OPS', 'KONSTRUKSI'));
    }

    public function test_migration_flags_each_pp_from_its_own_snapshot_and_preserves_legacy_selected_actor_access(): void
    {
        $this->seedWorkflow('B');
        $pm = $this->asRole('PM Konstruksi');
        $account = app(AccountAccess::class)->context($pm);
        $row = DB::table('project_plans')->first();
        $data = json_decode($row->document_data, true);
        $data['unit'] = 'Operation';
        $original = json_encode($data);
        DB::table('project_plans')->where('id_pp', $row->id_pp)->update(['work_unit_id' => null, 'document_data' => $original]);
        $migration = require database_path('migrations/2026_11_18_000002_enable_operation_workflow.php');
        $migration->up();
        $this->assertSame($original, DB::table('project_plans')->where('id_pp', $row->id_pp)->value('document_data'));
        $this->assertSame(DB::table('work_units')->where('code', 'OPERATION')->value('id_work_unit'), DB::table('project_plans')->where('id_pp', $row->id_pp)->value('work_unit_id'));
        $query = fn () => WorkUnitAccess::scope(DB::table('project_plans as pp')->where('pp.id_pp', $row->id_pp), $account)->count();
        $this->assertSame(1, $query());
        $data['pmUserId'] = 'db-user-999';
        DB::table('project_plans')->where('id_pp', $row->id_pp)->update(['document_data' => json_encode($data)]);
        $this->assertSame(0, $query());
        $data['pmUserId'] = $account['id'];
        $data['approvalScheme'] = 'operation-v1';
        DB::table('project_plans')->where('id_pp', $row->id_pp)->update(['document_data' => json_encode($data)]);
        $this->assertSame(0, $query());
    }
}
