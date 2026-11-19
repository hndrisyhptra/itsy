<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/** SQL identities and reference data bound to the existing validated workflow. */
class AuthenticatedWorkspace
{
    public function account(): array
    {
        $account = app(AccountAccess::class)->context(auth()->user());
        abort_unless($account, 403, 'Akun tidak memiliki penugasan aktif.');

        return $account;
    }

    public function bind(array $state): array
    {
        $account = $this->account();
        $state = PrototypeDomain::upgradeState($state);
        $state['schema'] = 3;
        $state['role'] = $account['role'];
        $state['actorUserId'] = $account['id'];
        $state['profile'] = ['name' => $account['name'], 'email' => $account['email']];
        $state['branches'] = DB::table('branches as b')
            ->leftJoin('regions as r', fn ($join) => $join->on('r.id_region', '=', 'b.region_id')->whereNull('r.deleted_at'))
            ->whereNull('b.deleted_at')->orderBy('b.name')
            ->get(['b.id_branch', 'b.kode_branch', 'b.name', 'b.is_active', 'r.id_region as visible_region_id', 'r.name as region_name'])
            ->map(fn ($b) => [
                'id' => 'db-branch-'.$b->id_branch, 'code' => $b->kode_branch, 'name' => $b->name, 'active' => (bool) $b->is_active,
                'regionId' => $b->visible_region_id === null ? null : 'db-region-'.$b->visible_region_id, 'regionName' => $b->region_name ?? '',
            ])->all();
        $users = User::orderBy('name')->get(['id_user', 'name', 'email', 'nik', 'jabatan', 'role', 'active', 'work_unit_id', 'access_scope', 'id_regions']);
        $contexts = app(AccountAccess::class)->contexts($users);
        $state['users'] = $users->map(function ($user) use ($contexts) {
            $c = $contexts[$user->getKey()] ?? null;

            return ['id' => 'db-user-'.$user->getKey(), 'name' => $user->name, 'email' => $user->email, 'nik' => $user->nik ?? '',
                'jabatan' => $user->jabatan ?? '', 'role' => $c['role'] ?? (AccountAccess::ROLES[$user->role] ?? 'Admin Konstruksi'),
                'active' => $user->active && $c !== null, 'branches' => $c['branches'] ?? [], 'workUnits' => $c['workUnits'] ?? [], 'accessScope' => $c['accessScope'] ?? 'BRANCH'];
        })->all();
        $state['khs'] = $this->catalog($state['khs']);

        return $state;
    }

    /** Reference-only path for uploads: no document/account graph is loaded. */
    public function catalog(array $previous): array
    {
        $old = collect($previous)->keyBy('id');
        $prices = DB::table('designator_package_prices as p')->join('designators as d', 'd.id_designator', '=', 'p.designator_id')
            ->join('packages as k', 'k.id_package', '=', 'p.package_id')->join('designator_types as t', 't.id_designator_type', '=', 'd.designator_type_id')
            ->whereIn('d.designator_type_id', [1, 2])->orderBy('p.id_designator_package_price')->get([
                'p.id_designator_package_price', 'p.price', 'p.deleted_at as price_deleted', 'd.code', 'd.item_name', 'd.unit', 'd.designator_type_id',
                'd.deleted_at as designator_deleted', 'k.name as package', 'k.deleted_at as package_deleted', 't.is_active', 't.deleted_at as type_deleted',
            ]);
        $catalog = $prices->map(function ($p) use ($old) {
            $id = 'db-price-'.$p->id_designator_package_price;
            $m = (int) $p->designator_type_id === 1 ? (float) $p->price : null;
            $s = (int) $p->designator_type_id === 2 ? (float) $p->price : null;
            $previous = $old->get($id);
            $active = (bool) $p->is_active && ! $p->price_deleted && ! $p->designator_deleted && ! $p->package_deleted && ! $p->type_deleted;
            $changed = $previous && ($previous['m'] != $m || $previous['s'] != $s || ($previous['m'] === null) !== ($m === null) || ($previous['s'] === null) !== ($s === null)
                || $previous['code'] !== $p->code || $previous['name'] !== $p->item_name || $previous['unit'] !== $p->unit || $previous['package'] !== $p->package || $previous['active'] !== $active);

            // Version is a local snapshot counter, not a fabricated KHS contract version.
            return ['id' => $id, 'code' => $p->code, 'name' => $p->item_name, 'unit' => $p->unit, 'package' => $p->package,
                'm' => $m, 's' => $s, 'version' => ($previous['version'] ?? 1) + ($changed ? 1 : 0),
                'active' => $active,
                'history' => $previous['history'] ?? []];
        })->keyBy('id');
        // Retain removed reference labels for already saved document snapshots.
        foreach ($old as $id => $item) {
            if (! $catalog->has($id)) {
                $item['active'] = false;
                $catalog->put($id, $item);
            }
        }

        return $catalog->values()->all();

    }

    public function visible(array $state, bool $bind = true): array
    {
        $account = $this->account();
        if ($bind) {
            $state = $this->bind($state);
        }
        $state['branches'] = array_values(array_filter($state['branches'], fn ($b) => in_array($b['name'], $account['branches'], true)));
        $state['users'] = array_values(array_filter($state['users'], fn ($u) => $u['id'] === $account['id'] || array_intersect($u['branches'], $account['branches'])));
        $state['projects'] = array_values(array_filter($state['projects'], fn ($p) => in_array($p['branch'], $account['branches'], true) && WorkUnitAccess::permits($account, $p)));
        $ids = array_fill_keys(array_column($state['projects'], 'id'), true);
        $state['deals'] = array_values(array_filter($state['deals'], fn ($d) => isset($ids[$d['projectId']])));
        $ids = array_fill_keys(array_column($state['deals'], 'id'), true);
        $state['spks'] = array_values(array_filter($state['spks'], fn ($s) => isset($ids[$s['dealId']])));
        $ids = array_fill_keys(array_column($state['spks'], 'id'), true);
        $state['terms'] = array_values(array_filter($state['terms'], fn ($t) => isset($ids[$t['spkId']])));
        $ids = array_fill_keys(array_column($state['terms'], 'id'), true);
        $state['bapps'] = array_values(array_filter($state['bapps'], fn ($b) => isset($ids[$b['termId']])));
        $routes = ['/project-plan' => 'projects', '/boq' => 'projects', '/ba-kh' => 'deals', '/spk' => 'spks', '/termin' => 'terms', '/ba-rp' => 'terms', '/ba-pp' => 'bapps'];
        $routeIds = [];
        foreach ($routes as $prefix => $collection) {
            $routeIds[$prefix] = array_fill_keys(array_column($state[$collection], 'id'), true);
        }
        $state['events'] = array_values(array_filter($state['events'], function ($e) use ($routeIds, $routes, $account) {
            foreach ($routes as $prefix => $collection) {
                if (str_starts_with($e['route'], $prefix.'/')) {
                    return isset($routeIds[$prefix][substr($e['route'], strlen($prefix) + 1)]);
                }
            }

            return $account['code'] === 'SUPERADMIN';
        }));

        return $state;
    }

    public function authorize(array $state, string $command, array $args): void
    {
        $account = $this->account();
        abort_if(in_array($command, ['role', 'selectActor', 'reset', 'saveUser', 'saveProfile', 'updateKhs', 'importDemo'], true), 403, 'Tindakan simulasi tidak tersedia untuk akun login.');
        if ($command === 'setNumberFormat') {
            abort_unless($account['code'] === 'SUPERADMIN', 403);

            return;
        }
        $lookup = function ($collection, $id) use ($state) {
            $record = collect($state[$collection])->firstWhere('id', $id);
            abort_unless($record, 404);

            return $record;
        };
        $project = fn ($id) => $lookup('projects', $id);
        $deal = fn ($id) => $project($lookup('deals', $id)['projectId']);
        $spk = fn ($id) => $deal($lookup('spks', $id)['dealId']);
        $term = fn ($id) => $spk($lookup('terms', $id)['spkId']);
        $bapp = fn ($id) => $term($lookup('bapps', $id)['termId']);
        $input = $args[0] ?? null;
        if ($command === 'routeLegacyFinance') {
            $branches = $account['branches'];
            abort_unless(is_array($input) && count($input) >= 1 && count($input) <= 50, 422);
            $terms = array_column($state['terms'], null, 'id');
            $spks = array_column($state['spks'], null, 'id');
            $deals = array_column($state['deals'], null, 'id');
            $projects = array_column($state['projects'], null, 'id');
            foreach ($input as $target) {
                abort_unless(is_array($target), 422);
                $t = $terms[$target['id'] ?? ''] ?? null;
                abort_unless($t, 404);
                $p = $projects[$deals[$spks[$t['spkId']]['dealId']]['projectId']];
                abort_unless(in_array($p['branch'], $branches, true) && WorkUnitAccess::permits($account, $p), 403);
            }

            return;
        }
        $p = match ($command) {
            'saveProject', 'saveProjectBundle' => $input,
            'submitProjectBundle' => $project($input),
            'adoptApprovalOrder' => ($input === 'projects' ? $project($args[1] ?? '') : ($input === 'terms' ? $term($args[1] ?? '') : null)),
            'decideProject', 'assignProjectApprovers', 'assignPaymentApprovers', 'reviewBoq', 'finalizeBoq' => $project($input),
            'saveDeal' => $project($input['projectId'] ?? ''),
            '_recordPayeeFile', '_setPayeeVersion' => $spk($input),
            'saveSpkFinance', 'saveSpk' => $deal($input['dealId'] ?? ''),
            'createTerm', 'createSettlementTerm' => $spk($input),
            'submitFinanceTerm', 'saveTerm' => $spk($input['spkId'] ?? ''),
            'attestBarp' => $term($input['id'] ?? ''),
            'prepareBapp', 'adoptFinancePayment', '_issueKasbon', '_submitSettlementKasbon', '_attachTransferProof', 'decideFinancePayment', 'requestPartialPayment', 'verifyPartialPayment', 'payPartialPayment', 'decide', 'recordApm', 'settleFinancePayment', 'updatePaymentApm', 'setKasbonPrintSigner', 'decideLegacySettlementReview', 'recordPayment', 'verifyTermScan', '_attachTermScan', '_attachTermWorkEvidence', 'removeTermWorkEvidence' => $term($input),
            'attestBapp', 'releaseVolume', 'createContinuation' => $bapp($input),
            default => null,
        };
        abort_unless($p && in_array($p['branch'] ?? '', $account['branches'], true) && WorkUnitAccess::permits($account, $p), 403, 'Dokumen berada di luar Branch penugasan Anda.');
        // Existing identities cannot be moved between documents/branches by changing a form payload.
        $collection = ['saveProject' => 'projects', 'saveProjectBundle' => 'projects', 'saveDeal' => 'deals', 'saveSpk' => 'spks', 'saveSpkFinance' => 'spks', 'saveTerm' => 'terms', 'submitFinanceTerm' => 'terms'][$command] ?? null;
        if ($collection && ($old = collect($state[$collection])->firstWhere('id', $input['id'] ?? ''))) {
            $key = ['projects' => 'branch', 'deals' => 'projectId', 'spks' => 'dealId', 'terms' => 'spkId'][$collection];
            abort_unless(($input[$key] ?? null) === $old[$key] && ($collection !== 'projects' || ($input['unit'] ?? '') === ($old['unit'] === 'Operation' ? 'Operation' : 'Konstruksi')), 422, 'Relasi atau Branch dokumen yang sudah tersimpan tidak dapat diganti.');
        }
        if (in_array($command, ['saveProject', 'saveProjectBundle', 'assignProjectApprovers', 'assignPaymentApprovers'], true)) {
            $old = in_array($command, ['saveProject', 'saveProjectBundle'], true) ? collect($state['projects'])->firstWhere('id', $input['id'] ?? '') : null;
            $version = ($old['workflowVersion'] ?? 1) >= 2 ? $old['workflowVersion'] : 3;
            $ids = match ($command) {
                'assignProjectApprovers' => [$args[1] ?? '', $args[2] ?? ''],
                'assignPaymentApprovers' => array_values($args[1] ?? []),
                default => $version >= 3 ? array_values($input['approverUserIds'] ?? []) : [$input['pmUserId'] ?? '', $input['managerUserId'] ?? ''],
            };
            foreach ($ids as $id) {
                if (in_array($command, ['saveProject', 'saveProjectBundle'], true) && ! ($args[1] ?? false) && $id === '') {
                    continue;
                }
                $user = collect($state['users'])->firstWhere('id', $id);
                abort_unless($user && in_array($p['branch'], $user['branches'], true), 422, 'Seluruh approver harus ditugaskan pada Branch PP.');
            }
        }
    }
}
