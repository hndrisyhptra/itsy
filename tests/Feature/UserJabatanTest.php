<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AccountAccess;
use App\Services\BakhSigner;
use App\Services\BarpTemplate;
use App\Services\SpkSigner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\AuthenticatesArea3;
use Tests\TestCase;

class UserJabatanTest extends TestCase
{
    use AuthenticatesArea3, RefreshDatabase;

    public function test_jabatan_is_managed_by_superadmin_and_does_not_change_access_or_documents(): void
    {
        $this->withoutVite();
        $this->asRole();
        $this->assertTrue(Schema::hasColumn('users', 'jabatan'));
        $branch = DB::table('branches')->where('name', 'PEKALONGAN')->value('id_branch');
        $input = ['name' => 'QA Jabatan', 'username' => 'qa.jabatan', 'email' => 'jabatan@example.test', 'password' => 'Test123', 'password_confirmation' => 'Test123', 'role' => 'PM_KONSTRUKSI', 'branches' => [$branch], 'active' => 1, 'jabatan' => 'Officer 1 Project Deployment Area Jawa Bali'];
        $this->post('/akun', $input)->assertRedirect('/akun');
        $user = User::where('username', 'qa.jabatan')->firstOrFail();
        $access = app(AccountAccess::class)->context($user);
        $this->assertSame($input['jabatan'], $access['jabatan']);
        $this->assertSame('PM Konstruksi', $access['role']);
        $this->assertSame([$branch], $access['branchIds']);
        $hash = $user->password;
        $this->get('/akun/'.$user->getKey().'/edit')->assertOk()->assertSee('name="jabatan"', false)->assertSee($input['jabatan']);
        $this->get('/akun')->assertOk()->assertSee('<th>Jabatan</th>', false)->assertSee($input['jabatan']);
        $input['password'] = $input['password_confirmation'] = '';
        $input['jabatan'] = 'Jabatan resmi baru';
        $this->put('/akun/'.$user->getKey(), $input)->assertRedirect('/akun');
        $this->assertSame($hash, $user->fresh()->password);
        $this->assertSame('Jabatan resmi baru', $user->fresh()->jabatan);
        $assignments = DB::table('user_assignments')->where('user_id', $user->getKey())->get()->toJson();
        foreach ([str_repeat('x', 251), "Jabatan\nBaris", '<script>alert(1)</script>'] as $invalid) {
            $this->putJson('/akun/'.$user->getKey(), array_replace($input, ['jabatan' => $invalid]))->assertUnprocessable()->assertJsonValidationErrors('jabatan');
            $this->assertSame('Jabatan resmi baru', $user->fresh()->jabatan);
        }
        $this->asRole('Admin Konstruksi');
        $this->putJson('/akun/'.$user->getKey(), $input)->assertForbidden();
        $this->assertSame($assignments, DB::table('user_assignments')->where('user_id', $user->getKey())->get()->toJson());
        $this->assertSame(0, DB::table('project_plans')->count());
    }

    public function test_account_list_batches_assignments_and_rollback_refuses_filled_titles(): void
    {
        $this->withoutVite();
        $this->asRole();
        User::factory()->count(30)->create();
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->get('/akun')->assertOk();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $reads = array_filter($queries, fn ($q) => str_contains($q['query'], '"user_assignments"'));
        $this->assertCount(2, $reads, 'One auth context and one batch for the entire account list.');
        $migration = require database_path('migrations/2026_11_18_000001_add_jabatan_to_users.php');
        $migration->down();
        $this->assertFalse(Schema::hasColumn('users', 'jabatan'));
        $migration->up();
        User::first()->update(['jabatan' => 'QA resmi']);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('rollback ditolak');
        $migration->down();
    }

    public function test_document_titles_use_selected_saved_identity_and_never_client_or_unrelated_account(): void
    {
        $project = ['id' => 'p', 'name' => 'QA project', 'workflowVersion' => 3, 'approverUserIds' => ['Head of PMO' => 'h'], 'signatureUsers' => ['Head of PMO' => ['userId' => 'h', 'name' => 'Head QA', 'nik' => 'QA', 'job' => 'Jabatan Head tersimpan']]];
        $deal = ['id' => 'd', 'projectId' => 'p', 'status' => 'Draft', 'signer' => 'forged', 'signerJob' => 'FORGED', 'mandor' => 'Mandor QA'];
        $spk = ['id' => 's', 'dealId' => 'd', 'status' => 'Draft', 'start' => '2026-11-18', 'end' => '2026-11-19', 'supervisor' => 'Waspang QA', 'position' => 'FORGED'];
        $state = ['projects' => [$project], 'deals' => [$deal], 'spks' => [$spk], 'users' => [['id' => 'h', 'role' => 'Head of PMO', 'name' => 'Head QA', 'nik' => 'QA', 'jabatan' => 'Jabatan akun telah berubah']]];
        $before = $state;
        DB::enableQueryLog();
        DB::flushQueryLog();
        $saved = BakhSigner::sync($state, $deal, true);
        $this->assertSame('Jabatan Head tersimpan', $saved['signerJob']);
        $this->assertSame('Jabatan Head tersimpan', SpkSigner::sync($state, $spk, true)['position']);
        $barp = BarpTemplate::defaults($state, ['workflowVersion' => 3, 'spkId' => 's', 'approverUserIds' => ['Head of PMO' => 'h']]);
        $this->assertSame('Jabatan Head tersimpan', $barp['headJob']);
        $term = ['spkId' => 's', 'barp' => $barp];
        $this->assertSame($barp['headJob'], BarpTemplate::save($state, $term, ['barp' => ['headJob' => 'FORGED']], false)['barp']['headJob']);
        $this->assertSame([], DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertSame($before, $state);
        foreach (['Terbit', 'Sah'] as $status) {
            $state['deals'][0] = $saved + [];
            $state['deals'][0]['status'] = $status;
            $this->assertSame('Jabatan Head tersimpan', BakhSigner::sync($state, $deal, true)['signerJob']);
            unset($state['deals'][0]['signerJob']);
            $this->assertArrayNotHasKey('signerJob', BakhSigner::sync($state, $deal, true));
        }
    }
}
