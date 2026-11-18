<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Unit isolation is independent from Branch/Region and from official job titles. */
final class WorkUnitAccess
{
    public const OPS_APPROVERS = ['Officer 1 Operation', 'Manager Branch Operation', 'Officer 1 SS', 'Manager SS', 'General Manager Operation'];

    public const OPS_CODES = ['ADMIN_OPS', 'ADMIN_OPS_AREA', 'OFFICER_OPS1', 'OFFICER_OPS2', 'OFFICER_OPS3', 'MGR_BRANCH_OPS', 'OFFICER_SS1', 'MGR_SS', 'GM_OPS'];

    public static function operation(array $document): bool
    {
        return ($document['approvalScheme'] ?? '') === 'operation-v1';
    }

    public static function head(array $document): string
    {
        return self::operation($document) ? 'General Manager Operation' : 'Head of PMO';
    }

    public static function units(string $code, ?string $selected = null): array
    {
        $allowed = in_array($code, ['SUPERADMIN', 'FINANCE', 'MGR_FINANCE_SETTLEMENT'], true) ? ['Konstruksi', 'Operation'] : [in_array($code, self::OPS_CODES, true) ? 'Operation' : 'Konstruksi'];
        $name = ['KONSTRUKSI' => 'Konstruksi', 'OPERATION' => 'Operation'][$selected ?? ''] ?? null;

        if ($selected !== null && $name === null) {
            return [];
        }

        return $name ? array_values(array_intersect($allowed, [$name])) : $allowed;
    }

    public static function ids(array $account): Builder
    {
        return DB::table('work_units')->select('id_work_unit')->whereIn('code', array_map(fn ($u) => $u === 'Operation' ? 'OPERATION' : 'KONSTRUKSI', $account['workUnits'] ?? self::units($account['code'])));
    }

    public static function scope(Builder $query, array $account, string $alias = 'pp'): Builder
    {
        return $query->where(function ($q) use ($account, $alias) {
            $q->whereIn($alias.'.work_unit_id', self::ids($account));
            if (! empty($account['id']) && in_array($account['role'] ?? '', ['PM Konstruksi', 'Manager Konstruksi', 'Admin Area', 'Mgr Project Support', 'Mgr Bussiness Support', 'Mgr Deployment', 'Head of PMO'], true)) {
                $q->orWhere(function ($legacy) use ($account, $alias) {
                    $legacy->whereIn($alias.'.work_unit_id', DB::table('work_units')->select('id_work_unit')->where('code', 'OPERATION'))
                        ->whereRaw('COALESCE('.$legacy->getGrammar()->wrap($alias.'.document_data->approvalScheme').", '') <> ?", ['operation-v1'])
                        ->where(function ($selected) use ($account, $alias) {
                            $selected->where($alias.'.document_data->approverUserIds->'.$account['role'], $account['id']);
                            if (in_array($account['role'], ['PM Konstruksi', 'Manager Konstruksi'], true)) {
                                $selected->orWhere($alias.'.document_data->'.($account['role'] === 'PM Konstruksi' ? 'pmUserId' : 'managerUserId'), $account['id']);
                            }
                        });
                });
            }
        });
    }

    public static function permits(array $account, array $project): bool
    {
        $legacySelected = ($project['approverUserIds'][$account['role'] ?? ''] ?? null) === ($account['id'] ?? '__missing__');
        if (in_array($account['role'] ?? '', ['PM Konstruksi', 'Manager Konstruksi'], true)) {
            $legacySelected = $legacySelected || ($project[$account['role'] === 'PM Konstruksi' ? 'pmUserId' : 'managerUserId'] ?? null) === ($account['id'] ?? '__missing__');
        }

        return in_array(($project['unit'] ?? '') === 'Operation' ? 'Operation' : 'Konstruksi', $account['workUnits'] ?? self::units($account['code']), true) || (($project['unit'] ?? '') === 'Operation' && ! self::operation($project) && $legacySelected);
    }
}
