<?php

namespace Tests\Concerns;

use App\Models\PrototypeWorkspace;
use App\Models\User;
use App\Services\AccountAccess;
use App\Services\PrototypeDomain;
use App\Services\PrototypeWorkspaceService;
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
        PrototypeWorkspace::updateOrCreate(['owner_key' => hash('sha256', 'area3-authenticated-workflow-v1')], ['state' => $state, 'revision' => 0]);

        return $state;
    }
}
