<?php

namespace Tests\Feature;

use App\Models\PrototypeWorkspace;
use App\Services\PrototypeDomain;
use App\Services\PrototypeWorkspaceService;
use App\Services\RelationalWorkflowStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\AuthenticatesArea3;
use Tests\TestCase;

class ProjectSupportApprovalTest extends TestCase
{
    use AuthenticatesArea3, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function api(string $command, array $args)
    {
        return $this->postJson('/api/prototype/commands', ['command' => $command, 'args' => $args,
            'revision' => PrototypeWorkspace::firstOrFail()->revision, 'request_id' => (string) Str::uuid()]);
    }

    private function chain(bool $legacy = false): array
    {
        $chain = [];
        foreach ($legacy ? PrototypeDomain::APPROVERS_V3_LEGACY : PrototypeDomain::APPROVERS_V3 as $role) {
            $chain[$role] = 'db-user-'.$this->accounts[$role]->getKey();
        }

        return $chain;
    }

    public function test_new_pp_ignores_forged_legacy_scheme_and_requires_selected_project_support_at_step_four(): void
    {
        $this->seedWorkflow('B');
        $this->asRole('Admin Konstruksi');
        $state = $this->getJson('/api/prototype/state')->assertOk()->json('state');
        $p = app(PrototypeWorkspaceService::class)->scenario('B')['projects'][0];
        $p['id'] = $p['rootId'] = 'pp-project-support';
        $p['branch'] = 'PEKALONGAN';
        $p['unit'] = 'Konstruksi';
        $p['riskLevel'] = 'SEDANG';
        $p['costs']['permit'] = 0;
        $p['approvalScheme'] = 'business-support'; // A client cannot downgrade the new PP chain.
        $p['approverUserIds'] = $this->chain() + $this->chain(true);
        $k = collect($state['khs'])->firstWhere('active', true);
        $p['boq'] = [['id' => 'boq-support-qa', 'khsId' => $k['id'], 'qty' => 10, 'm' => $k['m'], 's' => $k['s']]];
        $this->api('saveProject', [$p, true])->assertOk();
        foreach (array_slice(PrototypeDomain::APPROVERS_V3, 0, 3) as $role) {
            $this->asRole($role);
            $this->api('decideProject', [$p['id'], 'Setujui', 'QA'])->assertOk();
        }
        $this->asRole('Mgr Bussiness Support');
        $this->api('decideProject', [$p['id'], 'Setujui', 'Denied'])->assertForbidden();
        $this->asRole('Mgr Project Support');
        $this->api('decideProject', [$p['id'], 'Setujui', 'QA'])->assertOk();
        $this->asRole('Head of PMO');
        $saved = collect($this->api('decideProject', [$p['id'], 'Setujui', 'QA'])->assertOk()->json('state.projects'))->firstWhere('id', $p['id']);
        $this->assertSame('Sah', $saved['status']);
        $this->assertSame('project-support', $saved['approvalScheme']);
        $this->assertSame(PrototypeDomain::APPROVERS_V3, array_keys($saved['approverUserIds']));
        $this->assertSame(PrototypeDomain::APPROVERS_V3, array_column($saved['approvals'], 'role'));
        $this->assertSame(['PM Konstruksi', 'Mgr Project Support', 'Mgr Deployment', 'Head of PMO'], array_column($saved['signatureRows'], 'role'));
        $pp = DB::table('project_plans')->where('source_id', $p['id'])->first();
        $run = DB::table('approval_runs')->where('pp_id', $pp->id_pp)->value('id_approval_run');
        $this->assertDatabaseHas('approval_steps', ['run_id' => $run, 'step_no' => 4, 'expected_role' => 'MGR_PROJECT_SUPPORT', 'assigned_user_id' => $this->accounts['Mgr Project Support']->getKey()]);
    }

    public function test_legacy_pp_keeps_business_support_decisions_and_new_payments_require_explicit_current_assignments(): void
    {
        $state = $this->seedWorkflow('D');
        $state['schema'] = 3;
        $state['terms'] = [];
        $p = &$state['projects'][0];
        $p['workflowVersion'] = 3;
        unset($p['approvalScheme']);
        $p['approverUserIds'] = $this->chain(true);
        unset($p['paymentApproverUserIds']);
        $p['status'] = 'Diajukan';
        $p['approvals'] = $p['approvalHistory'] = [];
        $id = $p['id'];
        unset($p);
        app(RelationalWorkflowStore::class)->write(PrototypeWorkspace::firstOrFail(), $state);
        foreach (PrototypeDomain::APPROVERS_V3_LEGACY as $role) {
            $this->asRole($role);
            $this->api('decideProject', [$id, 'Setujui', 'Historical chain QA'])->assertOk();
        }
        $history = DB::table('approval_decisions')->get()->toJson();
        $this->asRole('Admin Konstruksi');
        $spk = $state['spks'][0]['id'];
        $this->api('createTerm', [$spk])->assertUnprocessable();
        $this->api('assignPaymentApprovers', [$id, $this->chain()])->assertOk();
        $response = $this->api('createTerm', [$spk])->assertOk();
        $term = collect($response->json('state.terms'))->firstWhere('status', 'Draft');
        $this->assertSame('project-support', $term['approvalScheme']);
        $this->assertSame(PrototypeDomain::APPROVERS_V3, array_keys($term['approverUserIds']));
        $project = collect($response->json('state.projects'))->firstWhere('id', $id);
        $this->assertSame(PrototypeDomain::APPROVERS_V3_LEGACY, array_column($project['approvals'], 'role'));
        $this->assertSame($history, DB::table('approval_decisions')->get()->toJson());
    }
}
