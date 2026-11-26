<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\AuthenticatesArea3;
use Tests\TestCase;

class AccountPaginationTest extends TestCase
{
    use AuthenticatesArea3, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->asRole('Superadmin');
        User::factory()->count(104)->sequence(fn ($s) => ['name' => sprintf('Account QA %03d', $s->index), 'username' => sprintf('user.qa.%03d', $s->index), 'email' => sprintf('qa.%03d@example.test', $s->index), 'nik' => sprintf('EMP%05d', $s->index), 'jabatan' => 'Jabatan QA', 'role' => 'FINANCE', 'active' => false])->create();
    }

    public function test_sql_pages_have_exact_totals_stable_order_and_bounded_assignment_reads(): void
    {
        $counts = [];
        foreach ([10, 20, 50, 100] as $size) {
            DB::enableQueryLog();
            DB::flushQueryLog();
            $r = $this->get('/akun?per_page='.$size)->assertOk();
            $queries = DB::getQueryLog();
            DB::disableQueryLog();
            $counts[] = count($queries);
            $users = $r->viewData('users');
            $this->assertSame(105, $users->total());
            $this->assertCount($size, $users->items());
            $this->assertCount($size, $r->viewData('contexts'));
            $this->assertSame('Account QA 000', $users->items()[0]->name);
            $assignment = array_filter($queries, fn ($q) => str_contains($q['query'], 'union') && str_contains($q['query'], 'user_assignments'));
            // One for current actor and one batch for the selected page; never one per listed user.
            $this->assertLessThanOrEqual(2, count($assignment));
            foreach ($queries as $q) {
                if (str_contains($q['query'], 'order by "name"')) {
                    $this->assertStringNotContainsString('password', $q['query']);
                    $this->assertStringContainsString('limit '.$size, $q['query']);
                }
            }
            $this->assertStringNotContainsString('remember_token', $r->getContent());
            $this->assertStringContainsString('per_page='.$size, $users->nextPageUrl());
        }
        $this->assertCount(1, array_unique($counts));
        fwrite(STDERR, "\nAccount SQL paging: {$counts[0]} HTTP queries for10/20/50/100 rows.\n");
        $last = $this->get('/akun?per_page=100&page=2')->assertOk()->viewData('users');
        $this->assertSame(105, $last->total());
        $this->assertCount(5, $last->items());
        $this->assertSame(2, $last->currentPage());
    }

    public function test_search_matches_identity_job_and_role_keeps_query_across_pages_and_resets_on_form_submit(): void
    {
        foreach (['Account QA 042', 'USER.QA.042', 'EMP00042', 'qa.042@example.test'] as $q) {
            $r = $this->get('/akun?q='.urlencode($q))->assertOk();
            $this->assertSame(1, $r->viewData('users')->total());
            $this->assertSame('user.qa.042', $r->viewData('users')->items()[0]->username);
        }
        $r = $this->get('/akun?q=Jabatan%20QA&per_page=10&page=3')->assertOk();
        $this->assertSame(104, $r->viewData('users')->total());
        $this->assertCount(10, $r->viewData('users')->items());
        $url = $r->viewData('users')->nextPageUrl();
        parse_str(parse_url($url, PHP_URL_QUERY), $params);
        $this->assertSame('Jabatan QA', $params['q']);
        $this->assertSame('10', $params['per_page']);
        $this->assertSame('4', $params['page']);
        $this->get('/akun?q=Finance')->assertOk()->assertViewHas('users', fn ($p) => $p->total() === 104);
        $html = $r->getContent();
        $this->assertStringContainsString('Reset pencarian', $html);
        $this->assertStringNotContainsString('name="page"', $html);
        $this->assertStringContainsString('name="q"', $html);
    }

    public function test_literal_wildcards_empty_results_and_invalid_parameters_do_not_bypass_access_or_write(): void
    {
        $u = User::where('username', 'user.qa.042')->first();
        $u->update(['name' => 'Literal_%! QA']);
        $this->get('/akun?q=Literal_%25!')->assertOk()->assertViewHas('users', fn ($p) => $p->total() === 1);
        $this->get('/akun?q=unknown-user-qa')->assertOk()->assertSee('Tidak ada akun yang sesuai pencarian.')->assertViewHas('users', fn ($p) => $p->total() === 0);
        $this->get('/akun?q=%3Cscript%3E')->assertOk()->assertSee('&lt;script&gt;', false)->assertDontSee('<script>', false);
        foreach (['per_page=1000', 'page=0', 'q='.str_repeat('a', 151)] as $query) {
            $this->getJson('/akun?'.$query)->assertUnprocessable();
        }
        $this->assertDatabaseCount('users', 105);
        foreach (['Admin Konstruksi', 'Admin Operation', 'Finance'] as $role) {
            $this->asRole($role);
            $this->get('/akun?q=Account')->assertForbidden();
        }
        $this->post('/logout');
        $this->get('/akun')->assertRedirect('/login');
    }
}
