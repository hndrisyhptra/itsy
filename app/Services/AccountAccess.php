<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AccountAccess
{
    public const ROLES = [
        'ADMIN_KONSTRUKSI' => 'Admin Konstruksi',
        'PM_KONSTRUKSI' => 'PM Konstruksi',
        'MANAGER_KONSTRUKSI' => 'Manager Konstruksi',
        'ADMIN_AREA' => 'Admin Area',
        'MGR_DEPLOYMENT' => 'Mgr Deployment',
        'MGR_BUSSINESS_SUPPORT' => 'Mgr Bussiness Support',
        'MGR_PROJECT_SUPPORT' => 'Mgr Project Support',
        'HEAD_OF_PMO' => 'Head of PMO',
        'MGR_FINANCE_SETTLEMENT' => 'Mgr Finance & Settlement',
        'FINANCE' => 'Finance',
        'SUPERADMIN' => 'Superadmin',
        'ADMIN_OPS' => 'Admin Operation',
        'ADMIN_OPS_AREA' => 'Admin Operation Area',
        'OFFICER_OPS1' => 'Officer 1 Operation',
        'OFFICER_OPS2' => 'Officer 2 Operation',
        'OFFICER_OPS3' => 'Officer 3 Operation',
        'MGR_BRANCH_OPS' => 'Manager Branch Operation',
        'GM_OPS' => 'General Manager Operation',
    ];

    public function context(?User $user, bool $requireActive = true): ?array
    {
        if (! $user || ($requireActive && ! $user->active)) {
            return null;
        }
        $assignments = $this->assignments([$user->getKey()])->get($user->getKey(), collect());

        return $this->fromAssignments($user, $assignments);
    }

    /** Account list: one assignment query for all loaded users, never one per row. */
    public function contexts(Collection $users): array
    {
        $assignments = $this->assignments($users->modelKeys());

        return $users->mapWithKeys(fn ($user) => [$user->getKey() => $this->fromAssignments($user, $assignments->get($user->getKey(), collect()))])->all();
    }

    private function assignments(array $ids): Collection
    {
        return DB::table('user_assignments as a')->join('roles as r', 'r.id_role', '=', 'a.role_id')
            ->join('branches as b', 'b.id_branch', '=', 'a.branch_id')
            ->when(count($ids) === 1, fn ($query) => $query->where('a.user_id', $ids[0]), fn ($query) => $query->whereIn('a.user_id', $ids))
            ->where('a.active', true)->where('b.is_active', true)->whereNull('b.deleted_at')
            ->whereIn('r.code', array_keys(self::ROLES))->get(['a.user_id', 'r.code', 'b.id_branch', 'b.name'])->groupBy('user_id');
    }

    private function fromAssignments(User $user, Collection $assignments): ?array
    {
        $roles = $assignments->pluck('code')->unique();
        if ($roles->count() !== 1 || $assignments->isEmpty() || $roles->first() !== $user->role) {
            return null;
        }

        return ['id' => 'db-user-'.$user->getKey(), 'userId' => $user->getKey(), 'name' => $user->name, 'email' => $user->email,
            'username' => $user->username, 'jabatan' => $user->jabatan ?? '', 'role' => self::ROLES[$roles->first()], 'code' => $roles->first(),
            'branchIds' => $assignments->pluck('id_branch')->unique()->values()->all(),
            'branches' => $assignments->pluck('name')->unique()->values()->all()];
    }

    public function assign(User $user, string $code, array $branches): void
    {
        // Call only inside the provisioning lock and transaction. Never seed passwords.
        foreach (self::ROLES as $key => $name) {
            if (! DB::table('roles')->where('code', $key)->exists()) {
                DB::table('roles')->insert(['id_role' => ((int) DB::table('roles')->max('id_role')) + 1, 'code' => $key, 'name' => $name]);
            }
        }
        $roleId = DB::table('roles')->where('code', $code)->value('id_role');
        $user->role = $code;
        $user->save();
        DB::table('user_assignments')->where('user_id', $user->getKey())->update(['active' => false]);
        foreach ($branches as $branch) {
            DB::table('user_assignments')->updateOrInsert(['user_id' => $user->getKey(), 'role_id' => $roleId, 'branch_id' => $branch], ['active' => true]);
        }
    }
}
