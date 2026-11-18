<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Database\Query\Builder;
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
        'OFFICER_SS1' => 'Officer 1 SS',
        'MGR_SS' => 'Manager SS',
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

    public function assignmentQuery(?array $ids = null): Builder
    {
        $columns = ['u.id_user as user_id', 'r.code', 'b.id_branch', 'b.name', 'w.code as unit_code'];
        $branches = DB::table('user_assignments as a')->join('users as u', 'u.id_user', '=', 'a.user_id')
            ->join('roles as r', 'r.id_role', '=', 'a.role_id')->join('branches as b', 'b.id_branch', '=', 'a.branch_id')
            ->leftJoin('work_units as w', 'w.id_work_unit', '=', 'u.work_unit_id')
            ->where('u.access_scope', 'BRANCH')->where('a.active', true)->where('b.is_active', true)->whereNull('b.deleted_at')
            ->whereIn('r.code', array_keys(self::ROLES))->when($ids !== null, fn ($q) => count($ids) === 1 ? $q->where('u.id_user', $ids[0]) : $q->whereIn('u.id_user', $ids))->select($columns);
        $regions = DB::table('users as u')->join('roles as r', 'r.code', '=', 'u.role')
            ->join('regions as region', 'region.id_region', '=', 'u.id_regions')->join('branches as b', 'b.region_id', '=', 'u.id_regions')
            ->leftJoin('work_units as w', 'w.id_work_unit', '=', 'u.work_unit_id')
            ->where('u.access_scope', 'REGION')->whereIn('r.code', ['OFFICER_SS1', 'MGR_SS', 'GM_OPS'])
            ->where('region.is_active', true)->whereNull('region.deleted_at')->where('b.is_active', true)->whereNull('b.deleted_at')
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('user_assignments as a')->whereColumn('a.user_id', 'u.id_user')->whereColumn('a.role_id', 'r.id_role')->where('a.active', true))
            ->when($ids !== null, fn ($q) => count($ids) === 1 ? $q->where('u.id_user', $ids[0]) : $q->whereIn('u.id_user', $ids))->select($columns);

        return $branches->union($regions);
    }

    private function assignments(array $ids): Collection
    {
        return $this->assignmentQuery($ids)->get()->groupBy('user_id');
    }

    private function fromAssignments(User $user, Collection $assignments): ?array
    {
        $roles = $assignments->pluck('code')->unique();
        if ($roles->count() !== 1 || $assignments->isEmpty() || $roles->first() !== $user->role) {
            return null;
        }

        $units = WorkUnitAccess::units($roles->first(), $assignments->first()->unit_code);
        if (! $units) {
            return null;
        }

        return ['workUnits' => $units, 'accessScope' => $user->access_scope ?? 'BRANCH', 'regionId' => $user->id_regions, 'id' => 'db-user-'.$user->getKey(), 'userId' => $user->getKey(), 'name' => $user->name, 'email' => $user->email,
            'username' => $user->username, 'jabatan' => $user->jabatan ?? '', 'role' => self::ROLES[$roles->first()], 'code' => $roles->first(),
            'branchIds' => $assignments->pluck('id_branch')->unique()->values()->all(),
            'branches' => $assignments->pluck('name')->unique()->values()->all()];
    }

    public function assign(User $user, string $code, array $branches): void
    {
        // Call only inside the provisioning lock and transaction. Never seed passwords.
        foreach (self::ROLES as $key => $name) {
            if (! DB::table('roles')->where('code', $key)->exists()) {
                DB::table('roles')->insert(['id_role' => max((int) DB::table('roles')->max('id_role'), (int) DB::table('master_data_changes')->where('entity', 'role')->max('record_id')) + 1, 'code' => $key, 'name' => $name]);
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
