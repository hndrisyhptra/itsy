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
        $state['branches'] = DB::table('branches')->whereNull('deleted_at')->orderBy('name')->get()->map(fn ($b) => [
            'id' => 'db-branch-'.$b->id_branch, 'code' => $b->kode_branch, 'name' => $b->name, 'active' => (bool) $b->is_active,
        ])->all();
        $assignments = DB::table('user_assignments as a')->join('roles as r', 'r.id_role', '=', 'a.role_id')->join('branches as b', 'b.id_branch', '=', 'a.branch_id')
            ->where('a.active', true)->where('b.is_active', true)->whereNull('b.deleted_at')->get(['a.user_id', 'r.code', 'b.name'])->groupBy('user_id');
        $state['users'] = User::orderBy('name')->get(['id_user', 'name', 'email', 'nik', 'role', 'active'])->map(function ($user) use ($assignments) {
            $rows = $assignments->get($user->getKey(), collect())->whereIn('code', array_keys(AccountAccess::ROLES));
            $codes = $rows->pluck('code')->unique();

            return ['id' => 'db-user-'.$user->getKey(), 'name' => $user->name, 'email' => $user->email, 'nik' => $user->nik ?? '',
                'role' => AccountAccess::ROLES[$codes->first() ?? ''] ?? 'Admin Konstruksi',
                'active' => $user->active && $codes->count() === 1 && $codes->first() === $user->role && $rows->isNotEmpty(), 'branches' => $rows->pluck('name')->unique()->values()->all()];
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
        $state['projects'] = array_values(array_filter($state['projects'], fn ($p) => in_array($p['branch'], $account['branches'], true)));
        $ids = array_fill_keys(array_column($state['projects'], 'id'), true);
        $state['deals'] = array_values(array_filter($state['deals'], fn ($d) => isset($ids[$d['projectId']])));
        $ids = array_fill_keys(array_column($state['deals'], 'id'), true);
        $state['spks'] = array_values(array_filter($state['spks'], fn ($s) => isset($ids[$s['dealId']])));
        $ids = array_fill_keys(array_column($state['spks'], 'id'), true);
        $state['terms'] = array_values(array_filter($state['terms'], fn ($t) => isset($ids[$t['spkId']])));
        $ids = array_fill_keys(array_column($state['terms'], 'id'), true);
        $state['bapps'] = array_values(array_filter($state['bapps'], fn ($b) => isset($ids[$b['termId']])));
        $routes = ['/project-plan' => 'projects', '/boq' => 'projects', '/ba-kh' => 'deals', '/spk' => 'spks', '/termin' => 'terms', '/ba-pp' => 'bapps'];
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
        abort_if(in_array($command, ['role', 'selectActor', 'reset', 'saveUser', 'saveProfile', 'updateKhs', 'importDemo'], true), 403, 'Tindakan simulasi tidak tersedia untuk akun login.');
        if ($command === 'setNumberFormat') {
            abort_unless($this->account()['code'] === 'SUPERADMIN', 403);

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
        $p = match ($command) {
            'saveProject' => $input,
            'decideProject', 'assignProjectApprovers', 'assignPaymentApprovers', 'reviewBoq', 'finalizeBoq' => $project($input),
            'saveDeal' => $project($input['projectId'] ?? ''),
            'saveSpk' => $deal($input['dealId'] ?? ''),
            'createTerm' => $spk($input),
            'saveTerm' => $spk($input['spkId'] ?? ''),
            'decide', 'recordApm', 'recordPayment', 'verifyTermScan', '_attachTermScan', '_attachTermWorkEvidence', 'removeTermWorkEvidence' => $term($input),
            'attestBapp', 'releaseVolume', 'createContinuation' => $bapp($input),
            default => null,
        };
        abort_unless($p && in_array($p['branch'] ?? '', $this->account()['branches'], true), 403, 'Dokumen berada di luar Branch penugasan Anda.');
        // Existing identities cannot be moved between documents/branches by changing a form payload.
        $collection = ['saveProject' => 'projects', 'saveDeal' => 'deals', 'saveSpk' => 'spks', 'saveTerm' => 'terms'][$command] ?? null;
        if ($collection && ($old = collect($state[$collection])->firstWhere('id', $input['id'] ?? ''))) {
            $key = ['projects' => 'branch', 'deals' => 'projectId', 'spks' => 'dealId', 'terms' => 'spkId'][$collection];
            abort_unless(($input[$key] ?? null) === $old[$key], 422, 'Relasi atau Branch dokumen yang sudah tersimpan tidak dapat diganti.');
        }
        if (in_array($command, ['saveProject', 'assignProjectApprovers', 'assignPaymentApprovers'], true)) {
            $old = $command === 'saveProject' ? collect($state['projects'])->firstWhere('id', $input['id'] ?? '') : null;
            $version = ($old['workflowVersion'] ?? 1) >= 2 ? $old['workflowVersion'] : 3;
            $ids = match ($command) {
                'assignProjectApprovers' => [$args[1] ?? '', $args[2] ?? ''],
                'assignPaymentApprovers' => array_values($args[1] ?? []),
                default => $version >= 3 ? array_values($input['approverUserIds'] ?? []) : [$input['pmUserId'] ?? '', $input['managerUserId'] ?? ''],
            };
            foreach ($ids as $id) {
                if ($command === 'saveProject' && ! ($args[1] ?? false) && $id === '') {
                    continue;
                }
                $user = collect($state['users'])->firstWhere('id', $id);
                abort_unless($user && in_array($p['branch'], $user['branches'], true), 422, 'Seluruh approver harus ditugaskan pada Branch PP.');
            }
        }
    }
}
