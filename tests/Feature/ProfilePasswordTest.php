<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AccountAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Tests\Concerns\AuthenticatesArea3;
use Tests\TestCase;

class ProfilePasswordTest extends TestCase
{
    use AuthenticatesArea3, RefreshDatabase;

    private function input(): array
    {
        return ['current_password' => 'password', 'password' => 'abc123', 'password_confirmation' => 'abc123'];
    }

    public function test_every_active_role_changes_only_its_own_password_without_workflow_or_assignment_writes(): void
    {
        Log::spy();
        $other = User::factory()->create()->refresh();
        $otherBefore = $other->getAttributes();
        $queryCounts = [];
        foreach (AccountAccess::ROLES as $role) {
            $user = $this->asRole($role);
            $before = $user->getAttributes();
            $assignments = DB::table('user_assignments')->orderBy('id_assignment')->get()->toJson();
            $sessionId = session()->getId();
            $csrf = session()->token();
            DB::enableQueryLog();
            DB::flushQueryLog();
            $response = $this->putJson('/api/account/password', $this->input() + ['id_user' => $other->getKey(), 'role' => 'SUPERADMIN', 'branches' => []]);
            $queries = DB::getQueryLog();
            DB::disableQueryLog();
            $queryCounts[] = count($queries);
            $response->assertOk()->assertExactJson(['message' => 'Kata sandi berhasil diubah.']);
            $response->assertHeader('Cache-Control', 'max-age=0, no-store, private');
            $saved = $user->fresh();
            $this->assertTrue(Hash::check('abc123', $saved->password));
            $this->assertFalse(Hash::check('password', $saved->password));
            $this->assertNotSame($before['remember_token'], $saved->remember_token);
            $this->assertNotSame($sessionId, session()->getId());
            $this->assertSame($csrf, session()->token());
            foreach ($before as $key => $value) {
                if (! in_array($key, ['password', 'remember_token', 'updated_at'])) {
                    $this->assertSame($value, $saved->getAttributes()[$key], $key.' changed for '.$role);
                }
            }
            $this->assertSame($assignments, DB::table('user_assignments')->orderBy('id_assignment')->get()->toJson());
            $this->assertLessThanOrEqual(5, count($queries));
            $this->assertCount(1, array_filter($queries, fn ($q) => str_starts_with($q['query'], 'update "users"')));
            $this->assertSame([], array_values(array_filter($queries, fn ($q) => preg_match('/prototype_workspaces|project_plans|payment_terms|designator_package_prices|prototype_audits/', $q['query']))));
            $this->assertAuthenticatedAs($saved);
            Log::shouldHaveReceived('notice')->with('Area3 account password changed', ['user_id' => $saved->getKey()])->once();
        }
        fwrite(STDERR, "\nPassword change HTTP: ".max($queryCounts)." queries across all active roles.\n");
        $this->assertSame($otherBefore, $other->fresh()->getAttributes());
        $this->assertDatabaseCount('prototype_workspaces', 0);
        $this->assertDatabaseCount('prototype_audits', 0);
    }

    public function test_bad_current_weak_mismatched_unchanged_and_overlong_passwords_leave_account_unchanged(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $user = $this->asRole('PM Konstruksi');
        $user->password = 'old123';
        $user->save();
        $this->actingAs($user)->withSession(['password_hash_web' => $user->password]);
        $before = $user->fresh()->getAttributes();
        $valid = ['current_password' => 'old123', 'password' => 'abc123', 'password_confirmation' => 'abc123'];
        $cases = [array_replace($valid, ['current_password' => 'wrong']), array_replace($valid, ['current_password' => '']), array_replace($valid, ['password_confirmation' => 'abc124']), array_replace($valid, ['current_password' => 'pass'.chr(0).'word'])];
        foreach (['ab123', 'abcdef', '123456', 'old123', str_repeat('a', 72).'1', str_repeat('é', 36).'1', 'ab'.chr(0).'123'] as $password) {
            $cases[] = array_replace($valid, ['password' => $password, 'password_confirmation' => $password]);
        }
        foreach ($cases as $input) {
            $this->putJson('/api/account/password', $input)->assertUnprocessable();
            $this->assertSame($before, $user->fresh()->getAttributes());
        }
        $this->assertDatabaseCount('prototype_workspaces', 0);
    }

    public function test_current_session_survives_but_old_session_hash_and_old_password_fail_after_change(): void
    {
        $user = $this->asRole('Finance');
        $oldHash = $user->password;
        $this->putJson('/api/account/password', $this->input())->assertOk();
        $this->getJson('/akun')->assertForbidden(); // Current Finance session remains authenticated.
        $this->assertAuthenticatedAs($user->fresh());
        Auth::setUser($user->fresh());
        $this->withSession(['password_hash_web' => $oldHash])->getJson('/api/prototype/revision')->assertUnauthorized();
        $this->assertGuest();
        $this->post('/login', ['username' => $user->username, 'password' => 'password'])->assertSessionHasErrors('username');
        $this->post('/login', ['username' => $user->username, 'password' => 'abc123'])->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user->fresh());
        $this->assertDatabaseCount('prototype_workspaces', 0);
    }

    public function test_stale_authenticated_user_cannot_overwrite_a_concurrent_password_reset(): void
    {
        $user = $this->asRole('PM Konstruksi');
        $newHash = Hash::make('reset123');
        // Model authentication already completed when another request updates the SQL row.
        DB::table('users')->where('id_user', $user->getKey())->update(['password' => $newHash]);
        $before = User::findOrFail($user->getKey())->getAttributes();
        $this->putJson('/api/account/password', $this->input())->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $this->assertSame($before, $user->fresh()->getAttributes());
        $this->assertTrue(Hash::check('reset123', $user->fresh()->password));
    }

    public function test_guest_inactive_missing_csrf_and_repeated_attempts_are_rejected(): void
    {
        $this->putJson('/api/account/password', $this->input())->assertUnauthorized();
        $user = $this->asRole('Admin Konstruksi');
        $user->active = false;
        $user->save();
        $this->putJson('/api/account/password', $this->input())->assertUnauthorized();
        $user->active = true;
        $user->save();
        $user = $this->asRole('Finance');
        $before = $user->getAttributes();
        for ($i = 0; $i < 5; $i++) {
            $this->putJson('/api/account/password', array_replace($this->input(), ['current_password' => 'wrong']))->assertUnprocessable();
        }
        $this->putJson('/api/account/password', $this->input())->assertStatus(429);
        $this->assertSame($before, $user->fresh()->getAttributes());
        $this->app->instance('env', 'local');
        $this->putJson('/api/account/password', $this->input())->assertStatus(419);
        $this->assertSame($before, $user->fresh()->getAttributes());
    }
}
