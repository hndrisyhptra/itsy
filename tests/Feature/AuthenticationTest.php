<?php

namespace Tests\Feature;

use App\Models\PrototypeWorkspace;
use App\Models\User;
use App\Services\AccountAccess;
use App\Services\AuthenticatedWorkspace;
use App\Services\PrototypeDomain;
use App\Services\PrototypeWorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\AuthenticatesArea3;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use AuthenticatesArea3, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function command(string $command, array $args, int $revision = 0)
    {
        return $this->postJson('/api/prototype/commands', ['command' => $command, 'args' => $args, 'revision' => $revision, 'request_id' => (string) Str::uuid()]);
    }

    public function test_guest_cannot_access_workspace_exports_scans_or_accounts(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
        $this->get('/akun')->assertRedirect('/login');
        foreach (['state', 'backup', 'exports/csv?report=Monitoring%20Project%20Plan', 'payments/term-1/scan'] as $path) {
            $this->getJson('/api/prototype/'.$path)->assertUnauthorized();
        }
        $this->command('role', ['Superadmin'])->assertUnauthorized();
        $this->assertDatabaseCount('prototype_workspaces', 0);
        $this->get('/login')->assertOk()->assertSee('Siapkan akses pertama')->assertSee('name="_token"', false);
    }

    public function test_local_first_setup_uses_chosen_credentials_and_closes_after_one_account(): void
    {
        $this->withSession(['before' => 'setup']);
        $oldId = session()->getId();
        $this->post('/setup', ['name' => 'Admin Uji', 'username' => 'admin-test', 'email' => 'admin@example.test', 'password' => 'ChosenPassword123', 'password_confirmation' => 'ChosenPassword123'])->assertRedirect('/akun');
        $user = User::firstOrFail();
        $this->assertTrue(Hash::check('ChosenPassword123', $user->password));
        $this->assertNotSame($oldId, session()->getId());
        $context = app(AccountAccess::class)->context($user);
        $this->assertSame('Superadmin', $context['role']);
        $this->assertCount(16, $context['branches']);
        $this->get('/akun')->assertOk()->assertSee('Kelola akun pengguna');
        $this->post('/logout')->assertRedirect('/login');
        $this->get('/login')->assertOk()->assertDontSee('Siapkan akses pertama');
        $this->post('/setup', [])->assertForbidden();
        $this->assertDatabaseCount('users', 1);
    }

    public function test_remote_first_setup_and_weak_or_overlong_passwords_are_rejected(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.25'])->get('/login')->assertDontSee('Siapkan akses pertama');
        $this->post('/setup', [])->assertForbidden();
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1']);
        foreach (['short1', str_repeat('x', 73).'1', str_repeat('é', 36).'1'] as $password) {
            $this->post('/setup', ['name' => 'Admin', 'username' => 'admin-test', 'email' => 'admin@example.test', 'password' => $password, 'password_confirmation' => $password])->assertSessionHasErrors('password');
        }
        $this->assertDatabaseCount('users', 0);
    }

    public function test_login_and_setup_require_csrf_on_real_web_requests(): void
    {
        $this->app->instance('env', 'local');
        $this->post('/login', ['username' => 'admin-test', 'email' => 'admin@example.test', 'password' => 'ChosenPassword123'])->assertStatus(419);
        $this->post('/setup', ['name' => 'Admin', 'username' => 'admin-test', 'email' => 'admin@example.test', 'password' => 'ChosenPassword123', 'password_confirmation' => 'ChosenPassword123'])->assertStatus(419);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('prototype_workspaces', 0);
    }

    public function test_login_uses_account_role_regenerates_session_and_logout_revokes_access(): void
    {
        $user = $this->asRole('PM Konstruksi');
        Auth::logout();
        $this->withSession(['before' => 'login']);
        $oldId = session()->getId();
        $this->post('/login', ['username' => $user->username, 'password' => 'password'])->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
        $this->assertNotSame($oldId, session()->getId());
        $this->getJson('/api/prototype/state')->assertOk()->assertJsonPath('state.role', 'PM Konstruksi')->assertJsonPath('state.actorUserId', 'db-user-'.$user->getKey())->assertJsonCount(0, 'state.projects')->assertJsonCount(1030, 'state.khs');
        $this->get('/dashboard')->assertOk()->assertViewHas('boot', fn ($boot) => $boot['scenarios'] === [] && $boot['account']['id'] === 'db-user-'.$user->getKey());
        $this->get('/logout')->assertStatus(405);
        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
        $this->getJson('/api/prototype/state')->assertUnauthorized();
    }

    public function test_failed_login_is_generic_and_rate_limited_and_inactive_access_is_revoked(): void
    {
        $user = $this->asRole('Finance');
        Auth::logout();
        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['username' => $user->username, 'password' => 'wrong'])->assertSessionHasErrors('username');
        }
        $this->post('/login', ['username' => $user->username, 'password' => 'password'])->assertSessionHasErrors('username', fn ($message) => str_contains($message, 'Terlalu banyak'));
        $this->asRole('Finance');
        User::whereKey($user->getKey())->update(['active' => false]);
        Auth::setUser($user->fresh());
        $this->getJson('/api/prototype/state')->assertUnauthorized();
        $this->assertGuest();
        $this->post('/login', ['username' => 'missing', 'password' => 'wrong'])->assertSessionHasErrors('username', fn ($message) => str_contains($message, 'Username atau kata sandi'));
    }

    public function test_ambiguous_or_missing_assignment_fails_closed(): void
    {
        $user = $this->asRole('Finance');
        $branch = DB::table('branches')->value('id_branch');
        DB::table('user_assignments')->insert(['user_id' => $user->getKey(), 'role_id' => DB::table('roles')->where('code', 'PM_KONSTRUKSI')->value('id_role'), 'branch_id' => $branch, 'active' => true]);
        $this->getJson('/api/prototype/state')->assertUnauthorized();
        DB::table('user_assignments')->where('user_id', $user->getKey())->delete();
        $this->actingAs($user)->getJson('/api/prototype/state')->assertUnauthorized();
    }

    public function test_superadmin_creates_updates_and_disables_real_accounts_without_self_lockout(): void
    {
        $admin = $this->asRole();
        $branch = DB::table('branches')->where('name', 'PEKALONGAN')->value('id_branch');
        $input = ['name' => 'PM Test', 'username' => 'pm-test', 'email' => 'pm@example.test', 'password' => 'ChosenPassword123', 'password_confirmation' => 'ChosenPassword123', 'role' => 'PM_KONSTRUKSI', 'branches' => [$branch], 'active' => 1];
        $this->post('/akun', $input)->assertRedirect('/akun');
        $pm = User::where('email', $input['email'])->firstOrFail();
        $this->assertTrue(Hash::check($input['password'], $pm->password));
        $this->assertSame(['PEKALONGAN'], app(AccountAccess::class)->context($pm)['branches']);
        $this->get('/akun/'.$pm->getKey().'/edit')->assertOk()->assertSee('PM Test');
        $input['password'] = $input['password_confirmation'] = '';
        $input['role'] = 'MANAGER_KONSTRUKSI';
        $this->put('/akun/'.$pm->getKey(), $input)->assertRedirect('/akun');
        $this->assertTrue(Hash::check('ChosenPassword123', $pm->fresh()->password));
        $this->assertSame('Manager Konstruksi', app(AccountAccess::class)->context($pm->fresh())['role']);
        $input['active'] = 0;
        $this->put('/akun/'.$pm->getKey(), $input)->assertRedirect('/akun');
        $this->assertNull(app(AccountAccess::class)->context($pm->fresh()));
        $input['email'] = $admin->email;
        $input['username'] = $admin->username;
        $this->put('/akun/'.$admin->getKey(), $input)->assertUnprocessable();
        $this->asRole('Finance');
        $this->get('/akun')->assertForbidden();
        $this->post('/akun', $input)->assertForbidden();
        $this->put('/akun/'.$admin->getKey(), $input)->assertForbidden();
    }

    public function test_managed_accounts_accept_six_character_passwords_and_reject_shorter_or_unconfirmed_values(): void
    {
        $this->asRole();
        $this->get('/akun/baru')->assertOk()->assertSee('minlength="6"', false)->assertSee('Minimal 6 karakter dengan huruf dan angka')->assertDontSee('minlength="12"', false);
        $input = ['name' => 'PM Six Test', 'username' => 'pm-six', 'email' => 'pm-six@example.test', 'role' => 'PM_KONSTRUKSI',
            'branches' => [DB::table('branches')->where('name', 'PEKALONGAN')->value('id_branch')], 'active' => 1];
        foreach ([['ab123', 'ab123'], ['abc123', 'abc124'], ['abcdef', 'abcdef'], ['123456', '123456']] as [$password, $confirmation]) {
            $this->post('/akun', $input + ['password' => $password, 'password_confirmation' => $confirmation])->assertSessionHasErrors('password');
        }
        $this->assertDatabaseCount('users', 1);
        $this->post('/akun', $input + ['password' => 'abc123', 'password_confirmation' => 'abc123'])->assertRedirect('/akun');
        $user = User::where('username', 'pm-six')->firstOrFail();
        $this->assertTrue(Hash::check('abc123', $user->password));
        $hash = $user->password;
        $this->put('/akun/'.$user->getKey(), $input + ['password' => '', 'password_confirmation' => ''])->assertRedirect('/akun');
        $this->assertSame($hash, $user->fresh()->password);
        $this->put('/akun/'.$user->getKey(), $input + ['password' => 'xyz12', 'password_confirmation' => 'xyz12'])->assertSessionHasErrors('password');
        $this->assertSame($hash, $user->fresh()->password);
        $this->put('/akun/'.$user->getKey(), $input + ['password' => 'xyz789', 'password_confirmation' => 'xyz789'])->assertRedirect('/akun');
        $this->assertTrue(Hash::check('xyz789', $user->fresh()->password));
        Auth::logout();
        $this->post('/login', ['username' => 'pm-six', 'password' => 'xyz789'])->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
    }

    public function test_branch_scope_applies_to_reads_exports_mutations_and_assignee_selection(): void
    {
        $this->seedWorkflow();
        $finance = $this->asRole('Finance');
        $branch = DB::table('branches')->where('name', 'SEMARANG')->value('id_branch');
        app(AccountAccess::class)->assign($finance, 'FINANCE', [$branch]);
        $this->getJson('/api/prototype/state')->assertJsonCount(0, 'state.projects')->assertJsonCount(0, 'state.terms')->assertJsonCount(0, 'state.events')->assertJsonCount(1, 'state.branches');
        $response = $this->get('/api/prototype/backup')->assertOk();
        $this->assertEmpty(json_decode($response->streamedContent(), true)['projects']);
        $this->assertStringNotContainsString('PP/026/AREA3', $this->get('/api/prototype/exports/csv?report=Outstanding%20Payment')->streamedContent());
        $foreignTerm = PrototypeWorkspace::first()->state['terms'][0]['id'];
        $this->command('recordApm', [$foreignTerm, 'APM/TEST/001', true])->assertForbidden();
        $this->asRole();
        foreach (['role', 'selectActor', 'reset', 'saveUser', 'updateKhs', 'saveProfile', 'importDemo'] as $command) {
            app(AuthenticatedWorkspace::class)->account();
            try {
                app(AuthenticatedWorkspace::class)->authorize(PrototypeWorkspace::first()->state, $command, []);
                $this->fail('Forbidden command accepted');
            } catch (HttpException $error) {
                $this->assertSame(403, $error->getStatusCode());
            }
        }
    }

    public function test_mysql_master_boq_number_and_five_account_pp_approvals_are_bound_on_server(): void
    {
        $this->seedWorkflow('B');
        $this->asRole('Admin Konstruksi');
        $state = $this->getJson('/api/prototype/state')->json('state');
        $p = app(PrototypeWorkspaceService::class)->scenario('B')['projects'][0];
        $p['id'] = $p['rootId'] = 'pp-real-account';
        $p['riskLevel'] = 'SEDANG';
        $p['costs']['permit'] = 0;
        $p['no'] = 'FORGED';
        $p['unit'] = 'Konstruksi';
        $p['branch'] = 'PEKALONGAN';
        $p['pmUserId'] = 'db-user-'.$this->accounts['PM Konstruksi']->getKey();
        $p['managerUserId'] = 'db-user-'.$this->accounts['Manager Konstruksi']->getKey();
        $k = collect($state['khs'])->firstWhere('active', true);
        $p['approverUserIds'] = [];
        foreach (PrototypeDomain::APPROVERS_V3 as $role) {
            $p['approverUserIds'][$role] = 'db-user-'.$this->accounts[$role]->getKey();
        }
        $p['boq'] = [['id' => 'real-boq-row', 'khsId' => $k['id'], 'qty' => 2, 'm' => 1, 's' => 1]];
        $pm = $this->accounts['PM Konstruksi'];
        $branch = DB::table('branches')->where('name', 'SEMARANG')->value('id_branch');
        app(AccountAccess::class)->assign($pm, 'PM_KONSTRUKSI', [$branch]);
        $this->command('saveProject', [$p, true])->assertUnprocessable();
        $this->assertDatabaseCount('prototype_audits', 0);
        app(AccountAccess::class)->assign($pm, 'PM_KONSTRUKSI', DB::table('branches')->pluck('id_branch')->all());
        $saved = collect($this->command('saveProject', [$p, true])->assertOk()->json('state.projects'))->firstWhere('id', $p['id']);
        $this->assertMatchesRegularExpression('/^0001\/LG\.000\/TA-03R424\/\d{2}-\d{4}$/', $saved['no']);
        $this->assertEquals($k['m'], $saved['boq'][0]['m']);
        $this->asRole('Manager Konstruksi');
        $this->command('decideProject', [$p['id'], 'Setujui', ''], 1)->assertForbidden();
        $otherPm = User::factory()->create()->refresh();
        app(AccountAccess::class)->assign($otherPm, 'PM_KONSTRUKSI', DB::table('branches')->pluck('id_branch')->all());
        $this->actingAs($otherPm)->withSession(['password_hash_web' => $otherPm->password]);
        $this->command('decideProject', [$p['id'], 'Setujui', ''], 1)->assertForbidden();
        foreach (PrototypeDomain::APPROVERS_V3 as $index => $role) {
            $this->asRole($role);
            $result = $this->command('decideProject', [$p['id'], 'Setujui', $role.' Test'], $index + 1)->assertOk();
            $approved = collect($result->json('state.projects'))->firstWhere('id', $p['id']);
            $this->assertSame($index === 4 ? 'Sah' : 'Diajukan', $approved['status']);
            $this->assertSame($p['approverUserIds'][$role], $approved['approvals'][$index]['userId']);
        }
        $this->assertDatabaseHas('prototype_audits', ['command' => 'decideProject', 'actor_user_id' => $this->accounts['Head of PMO']->getKey()]);
    }
}
