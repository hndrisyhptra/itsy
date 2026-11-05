<?php

namespace Tests\Concerns;

use App\Models\PrototypeWorkspace;
use App\Models\User;
use App\Services\AccountAccess;
use App\Services\PrototypeDomain;
use App\Services\PrototypeWorkspaceService;
use App\Services\RelationalWorkflowStore;
use Illuminate\Support\Facades\DB;

trait AuthenticatesArea3
{
    private array $accounts = [];

    private function asRole(string $role = 'Superadmin'): User
    {
        if (! isset($this->accounts[$role])) {
            $user = User::factory()->create(['name' => $role.' Test', 'email' => strtolower(str_replace(' ', '-', $role)).'@example.test']);
            $user->refresh();
            $code = array_search($role, AccountAccess::ROLES, true);
            app(AccountAccess::class)->assign($user, $code, DB::table('branches')->pluck('id_branch')->all());
            $this->accounts[$role] = $user;
        }
        $user = $this->accounts[$role];
        $this->actingAs($user)->withSession(['password_hash_web' => $user->password]);

        return $user;
    }

    private function seedWorkflow(string $scenario = 'overview'): array
    {
        foreach (AccountAccess::ROLES as $role) {
            $this->asRole($role);
        }
        $this->asRole();
        $state = app(PrototypeWorkspaceService::class)->scenario($scenario);
        $pm = 'db-user-'.$this->accounts['PM Konstruksi']->getKey();
        $manager = 'db-user-'.$this->accounts['Manager Konstruksi']->getKey();
        foreach ($state['projects'] as &$p) {
            $p['branch'] = strtoupper($p['branch']);
            $p['pmUserId'] = $pm;
            $p['managerUserId'] = $manager;
            $p['paymentApproverUserIds'] = [];
            foreach (PrototypeDomain::APPROVERS_V3 as $role) {
                $p['paymentApproverUserIds'][$role] = 'db-user-'.$this->accounts[$role]->getKey();
            }
        }
        unset($p);
        foreach ($state['terms'] as &$t) {
            $t['pmUserId'] = $pm;
            $t['managerUserId'] = $manager;
        }
        unset($t);
        $existing = PrototypeWorkspace::where('owner_key', hash('sha256', 'area3-authenticated-workflow-v1'))->first();
        if ($existing?->relational_migrated_at) {
            // Fixture replacement is strictly test-only; the live app has no reset endpoint.
            if (DB::getDriverName() !== 'sqlite' || DB::connection()->getDatabaseName() !== ':memory:' || ! app()->environment('testing')) {
                throw new \RuntimeException('Fixture replacement requires isolated memory storage.');
            }
            foreach (['term_work_evidence', 'volume_movements', 'command_receipts', 'continuation_allocations', 'volume_releases', 'ba_completions',
                'apm_settlements', 'term_scans', 'approval_decisions', 'approval_steps', 'approval_runs', 'term_actuals', 'payment_terms',
                'work_orders', 'contract_documents', 'contract_components', 'contracts', 'volume_balances', 'boq_components', 'boq_lines',
                'boq_import_rows', 'boq_import_batches', 'boq_import_templates', 'document_files', 'project_plan_budget_rows',
                'project_plan_team', 'project_plan_schedule', 'project_plan_costs', 'project_plans', 'projects',
                'number_sequences', 'numbering_rules', 'workflow_events', 'workflow_metadata', 'prototype_audits'] as $table) {
                DB::table($table)->delete();
            }
        }
        $workspace = PrototypeWorkspace::updateOrCreate(['owner_key' => hash('sha256', 'area3-authenticated-workflow-v1')], ['state' => $state, 'revision' => 0, 'relational_migrated_at' => null]);
        app(RelationalWorkflowStore::class)->activate($workspace);

        return $state;
    }
}
