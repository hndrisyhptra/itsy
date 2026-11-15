<?php

namespace Tests\Feature;

use App\Models\PrototypeWorkspace;
use App\Services\AccountAccess;
use App\Services\AuthenticatedWorkspace;
use App\Services\FinancePaymentCatalog;
use App\Services\PrototypeDomain;
use App\Services\RelationalWorkflowStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\AuthenticatesArea3;
use Tests\TestCase;

class FinancePaymentCatalogTest extends TestCase
{
    use AuthenticatesArea3, RefreshDatabase;

    private function fixtures(int $count = 1): array
    {
        $s = $this->seedWorkflow('E');
        $base = collect($s['terms'])->firstWhere('apmStatus', 'Valid');
        $base['workflowVersion'] = 2;
        $base['approvals'] = array_values(array_filter($base['approvals'], fn ($a) => $a['role'] !== 'Finance'));
        $base['paid'] = app(PrototypeDomain::class)->load($s)->termTotal($base);
        $base['paymentHistory'] = [['source' => 'APM', 'amount' => $base['paid'], 'ref' => $base['apm'], 'at' => now()->toISOString(), 'actor' => 'db-user-'.$this->accounts['Finance']->getKey()]];
        $base['scan'] = ['id' => (string) Str::uuid(), 'extension' => 'pdf', 'name' => 'qa.pdf', 'mime' => 'application/pdf', 'size' => 100, 'sha256' => str_repeat('a', 64), 'status' => 'Valid', 'uploadedAt' => now()->toISOString(), 'verifiedBy' => 'db-user-'.$this->accounts['Finance']->getKey(), 'verifiedAt' => now()->toISOString()];
        $s['terms'] = [$base]; // Retain the real historical APM fixture; no inferred payments.
        $s['bapps'] = [];
        for ($i = 0; $i < $count; $i++) {
            $p = $s['projects'][0];
            $p['id'] = $p['rootId'] = 'monitor-pp-'.$i;
            $p['no'] = 'PP/MONITOR/'.$i;
            $p['name'] = 'Monitor LOP '.$i;
            $p['projectCode'] = 'MONITOR-ID';
            $d = $s['deals'][0];
            $d['id'] = 'monitor-deal-'.$i;
            $d['no'] = 'BA/MONITOR/'.$i;
            $d['projectId'] = $p['id'];
            $v = $s['spks'][0];
            $v['id'] = 'monitor-spk-'.$i;
            $v['no'] = 'SPK/MONITOR/'.$i;
            $v['dealId'] = $d['id'];
            $t = $base;
            $t['id'] = 'monitor-term-'.$i;
            $t['spkId'] = $v['id'];
            $t['number'] = 1;
            $t['status'] = 'Diajukan';
            $t['paid'] = 0;
            $t['apm'] = '';
            $t['apmStatus'] = 'Belum Terbit';
            $t['paymentHistory'] = $t['approvals'] = $t['history'] = [];
            unset($t['scan'], $t['kasbon'], $t['financeApproval']);
            $t['kasbon'] = ['id' => (string) Str::uuid(), 'version' => 1, 'amount' => 295000, 'issuedAt' => now()->toISOString(), 'issuedBy' => 'db-user-'.$this->accounts['Admin Konstruksi']->getKey()];
            $s['projects'][] = $p;
            $s['deals'][] = $d;
            $s['spks'][] = $v;
            $s['terms'][] = $t;
        }
        DB::transaction(fn () => app(RelationalWorkflowStore::class)->write(PrototypeWorkspace::first(), $s));
        foreach (array_slice($s['terms'], 1) as $t) {
            $termId = app(RelationalWorkflowStore::class)->id(PrototypeWorkspace::first()->id, 'terms', $t['id']);
            DB::table('finance_kasbons')->insert(['id_kasbon' => $t['kasbon']['id'], 'term_id' => $termId, 'version' => 1, 'amount' => 295000, 'private_data' => 'MUST-NOT-DECRYPT-OR-EXPOSE', 'issued_by' => $this->accounts['Admin Konstruksi']->getKey(), 'issued_at' => now(), 'request_uuid' => (string) Str::uuid(), 'input_sha256' => str_repeat('a', 64)]);
        }
        $this->asRole('Finance');

        return $s;
    }

    public function test_list_filters_paid_and_unpaid_with_safe_metadata_and_no_writes(): void
    {
        $s = $this->fixtures();
        $before = (array) DB::table('prototype_workspaces')->first(['state', 'revision']);
        $this->getJson('/api/finance/payments')->assertOk()->assertJsonPath('summary.total', 2)->assertJsonPath('summary.paid', 1)->assertJsonPath('summary.unpaid', 1);
        $pending = $this->getJson('/api/finance/payments?status=unpaid')->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('items.0.id', 'monitor-term-0')->assertJsonPath('items.0.amount', 295000)->assertJsonPath('items.0.documents', 'NOK');
        $this->getJson('/api/finance/payments?status=paid')->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('items.0.paid', true);
        $this->getJson('/api/finance/payments?q=Monitor%20LOP')->assertOk()->assertJsonCount(1, 'items');
        $this->getJson('/api/finance/payments?q=%25')->assertOk()->assertJsonCount(0, 'items');
        $this->getJson('/api/finance/payments?branch=NO-ACCESS')->assertOk()->assertJsonCount(0, 'items')->assertJsonPath('summary.total', 0);
        foreach (['private_data', 'MUST-NOT', 'accountNumber', 'storage_key', 'actuals', 'assignees', 'decisions'] as $private) {
            $this->assertStringNotContainsString($private, $pending->getContent());
        }
        foreach (['Admin Konstruksi', 'Superadmin', 'PM Konstruksi'] as $role) {
            $this->asRole($role);
            $this->getJson('/api/finance/payments')->assertForbidden();
        }
        $this->asRole('Mgr Finance & Settlement');
        $this->getJson('/api/finance/payments')->assertOk();
        $this->getJson('/api/finance/payments?status=invalid')->assertUnprocessable();
        $this->getJson('/api/finance/payments?page=10001')->assertUnprocessable();
        $this->assertSame($before, (array) DB::table('prototype_workspaces')->first(['state', 'revision']));
        $this->assertDatabaseCount('prototype_audits', 0);
    }

    public function test_branch_is_scoped_on_pp_before_matching_and_summary_not_project_master(): void
    {
        $s = $this->fixtures();
        $branch = DB::table('branches')->where('name', 'KUPANG')->value('id_branch');
        $pp = DB::table('project_plans')->where('source_id', 'monitor-pp-0')->first();
        DB::table('project_plans')->where('id_pp', $pp->id_pp)->update(['branch_id' => $branch]);
        $visible = DB::table('branches')->where('name', $s['projects'][0]['branch'])->value('id_branch');
        app(AccountAccess::class)->assign($this->accounts['Finance'], 'FINANCE', [$visible]);
        $this->getJson('/api/finance/payments?q=Monitor')->assertOk()->assertJsonCount(0, 'items')->assertJsonPath('summary.total', 0);
        $this->getJson('/api/finance/payments')->assertOk()->assertJsonPath('summary.total', 1);
        // The shared Project's master Branch/name cannot expand access or leak a sibling's name.
        DB::table('projects')->where('id_project', $pp->project_id)->update(['branch_id' => $visible]);
        $this->getJson('/api/finance/payments?q=Monitor')->assertOk()->assertJsonCount(0, 'items');
    }

    public function test_service_uses_four_queries_and_twenty_plus_one_rows_for_hundreds_of_payments(): void
    {
        $this->fixtures(100);
        $account = app(AuthenticatedWorkspace::class)->account();
        DB::enableQueryLog();
        DB::flushQueryLog();
        $data = app(FinancePaymentCatalog::class)->listing($account, ['status' => 'unpaid', 'page' => 1]);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertCount(4, $queries);
        $this->assertCount(20, $data['items']);
        $this->assertTrue($data['hasMore']);
        $this->assertSame(100, $data['summary']['unpaid']);
        $sql = implode("\n", array_column($queries, 'query'));
        $this->assertStringNotContainsString('private_data', $sql);
        $this->assertDoesNotMatchRegularExpression('/(?:^|\n)select\s+\*|prototype_workspaces"\."state|designator_package_prices|term_actuals/i', $sql);
        $this->assertDoesNotMatchRegularExpression('/^(insert|update|delete)/im', $sql);
        fwrite(STDERR, "\nFinance Payment list service: ".count($queries)." queries / 100 pending payments.\n");
    }
}
