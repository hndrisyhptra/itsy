<?php

namespace Tests\Concerns;

use App\Models\PrototypeWorkspace;
use App\Models\User;
use App\Services\AccountAccess;
use App\Services\AuthenticatedWorkspace;
use App\Services\BoqImportStorage;
use App\Services\FinanceDocuments;
use App\Services\PrototypeDomain;
use App\Services\PrototypeWorkspaceService;
use App\Services\RelationalWorkflowStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

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

    /** Explicit pre-bundle fixture: never used by current-flow tests or production. */
    private function historicalProjectCommand(array $p, bool $submit, callable $send)
    {
        $w = PrototypeWorkspace::first();
        if (DB::getDriverName() !== 'sqlite' || DB::connection()->getDatabaseName() !== ':memory:' || ! app()->environment('testing')) {
            throw new \RuntimeException('Memory only');
        }
        $store = app(RelationalWorkflowStore::class);
        if ($submit && ! DB::table('project_plans')->where('workspace_id', $w->id)->where('source_id', $p['id'])->exists()) {
            $state = app(AuthenticatedWorkspace::class)->bind($store->read($w));
            $input = $p;
            try {
                if (! empty($p['boqUpload']['id'])) {
                    $input['_boqImport'] = app(BoqImportStorage::class)->read($w, $p['boqUpload']['id'], null);
                }
                // Validate the original standalone submission before preparing its existing Draft.
                app(PrototypeDomain::class)->execute($state, 'saveProject', [$input, true]);
                $draft = app(PrototypeDomain::class)->execute($state, 'saveProject', [$input, false])['state'];
                DB::transaction(fn () => $store->write($w, $draft, $state));
            } catch (ValidationException $error) { /* Let HTTP assert invalid input. */
            }
        }
        $response = $send();
        if (! $submit && $response->status() === 200) {
            foreach ($response->json('state.projects') as $project) {
                if ($project['id'] === $p['id'] && isset($project['contractBundle'])) {
                    unset($project['contractBundle']);
                    // Marker only: explicit synthetic pre-upgrade fixture, not application behavior.
                    DB::table('project_plans')->where('workspace_id', $w->id)->where('source_id', $p['id'])->update(['document_data' => json_encode($project, JSON_THROW_ON_ERROR)]);
                }
            }
        }

        return $response;
    }

    /** Explicit pre-seven-step kasbon fixture; historical approval coverage remains intact. */
    private function historicalKasbonFixture(string $id): array
    {
        $this->historicalPaymentFixture($id);
        $w = PrototypeWorkspace::first();
        $store = app(RelationalWorkflowStore::class);
        $before = $store->read($w);
        $after = $before;
        foreach ($after['terms'] as &$term) {
            if ($term['id'] === $id) {
                $term['paymentFlow'] = 'kasbon-v1';
                $term['financePayment'] = ['status' => 'Menunggu Approval'];
                $found = $term;
            }
        }
        unset($term);
        DB::transaction(fn () => $store->write($w, $after, $before));

        return $found;
    }

    /** Freeze a pre-kasbon v3 fixture; production never removes this marker. */
    private function historicalPaymentFixture(string $id): array
    {
        if (DB::getDriverName() !== 'sqlite' || DB::connection()->getDatabaseName() !== ':memory:' || ! app()->environment('testing')) {
            throw new \RuntimeException('Memory only');
        }
        $w = PrototypeWorkspace::first();
        $store = app(RelationalWorkflowStore::class);
        $state = $store->read($w);
        $before = $state;
        foreach ($state['terms'] as &$t) {
            if ($t['id'] === $id) {
                unset($t['paymentFlow'], $t['financePayment'], $t['completionFlow'], $t['barpFlow'], $t['barpAttestation'], $t['barpAttestationHistory']); // This fixture predates per-payment BA PP.
                $found = $t;
            }
        }
        unset($t);
        DB::transaction(fn () => $store->write($w, $state, $before));

        return $found;
    }

    /** Synthetic PII/files only on the existing fail-closed memory fixture. */
    private function completeMandorPayee(string $spk): void
    {
        if (DB::getDriverName() !== 'sqlite' || DB::connection()->getDatabaseName() !== ':memory:' || ! app()->environment('testing')) {
            throw new \RuntimeException('Memory only');
        }
        Storage::fake('local');
        $w = PrototypeWorkspace::first();
        $id = app(RelationalWorkflowStore::class)->id($w->id, 'spks', $spk);
        $files = [];
        foreach (['ktpFileId' => 'mandor-ktp', 'bankFileId' => 'mandor-bank'] as $key => $kind) {
            $file = (string) Str::uuid();
            $path = 'finance-private/'.$w->id.'/'.$spk.'/'.$file.'.png';
            Storage::disk('local')->put($path, 'QA private bytes');
            DB::table('finance_private_files')->insert(['id_finance_file' => $file, 'workspace_id' => $w->id, 'spk_id' => $id, 'term_id' => null, 'kind' => $kind, 'storage_key' => $path, 'mime' => 'image/png', 'extension' => 'png', 'size' => 16, 'sha256' => hash('sha256', 'QA private bytes'), 'uploaded_by' => auth()->id(), 'created_at' => now()]);
            $files[$key] = $file;
        }
        app(FinanceDocuments::class)->savePayee($w, $spk, ['data' => $files + ['nik' => '0011223344556677', 'accountNumber' => '0001234567', 'accountName' => 'QA Mandor', 'bank' => 'QA Bank'], 'revision' => $w->revision, 'request_id' => (string) Str::uuid()]);
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
            foreach (['budget_pid_allocation_changes', 'budget_pid_allocations', 'term_work_evidence', 'volume_movements', 'command_receipts', 'continuation_allocations', 'volume_releases', 'ba_completions',
                'apm_settlements', 'finance_kasbons', 'mandor_payee_profiles', 'finance_private_files', 'partial_payment_requests', 'term_scans', 'approval_decisions', 'approval_steps', 'approval_runs', 'term_actuals', 'payment_terms',
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
