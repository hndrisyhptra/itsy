<?php

namespace App\Services;

use App\Models\PrototypeWorkspace;
use App\Support\WholeRupiah;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Separate budget ledger for genuinely new PP only; old/paid workflow records are never adopted. */
final class BudgetPidAllocation
{
    public const POLICY = 'pid-budget-mandor-v2';

    public const LEGACY_POLICY = 'pid-budget-v1';

    public static function tracked(array $p): bool
    {
        return in_array($p['budgetPidPolicy'] ?? '', [self::POLICY, self::LEGACY_POLICY], true);
    }

    public const CATEGORIES = ['Material' => 'material', 'Non Material' => 'service', 'Operasional' => 'bop'];

    public static function hash(array $input): string
    {
        return hash('sha256', json_encode(Arr::sortRecursive(Arr::only($input, ['command', 'args', 'result_id'])), JSON_THROW_ON_ERROR));
    }

    public static function pin(array $input, ?array $old): array
    {
        unset($input['budgetPidPolicy']);
        if ($old && self::tracked($old)) {
            $input['budgetPidPolicy'] = $old['budgetPidPolicy'];
        }

        return $input;
    }

    public function replay(array $input, int $actor): void
    {
        if (! in_array($input['command'], ['saveProject', 'saveProjectBundle', 'submitProjectBundle', 'createContinuation', 'cancelProject', 'releaseVolume'], true)) {
            return;
        }
        $row = DB::table('budget_pid_allocation_changes')->where('request_uuid', $input['request_id'])->first(['actor_user_id', 'payload_hash']);
        abort_if($row && ((int) $row->actor_user_id !== $actor || ! hash_equals($row->payload_hash, self::hash($input))), 409, 'UUID alokasi budget sudah digunakan untuk isi atau akun lain.');
    }

    public function identity(string $pid, string $unit, int $branch, ?string $exclude = null, bool $locked = false, bool $branchScoped = true): void
    {
        $q = DB::table('budget_pid_allocations')->where('pid_code', $pid)->where('status', '<>', 'cancelled');
        if ($exclude) {
            $q->where('pp_id', '<>', $exclude);
        }
        $q->where(function ($q) use ($unit, $branch, $branchScoped) {
            $q->where('unit', '<>', $unit);
            if ($branchScoped) {
                $q->orWhere(fn ($q) => $q->where('branch_id', '<>', $branch)->where('status', 'reserved'));
            }
        });
        $conflict = $locked ? $q->lockForUpdate()->first(['id_budget_allocation']) !== null : $q->exists();
        abort_if($conflict, 422, 'PID pada PP baru sudah terikat pada unit atau Branch lain. Periksa ID Project dan pemetaan Finance.');
    }

    /** Exact sums over indexed ledger rows. No old PP graph, private files or archived state. */
    public function usage(string $pid, string $unit, int $branch, ?string $exclude = null, bool $locked = false): array
    {
        $q = DB::table('budget_pid_allocations')->where('pid_code', $pid)->where('unit', $unit)->where('branch_id', $branch)->where('status', 'reserved');
        if ($exclude) {
            $q->where('pp_id', '<>', $exclude);
        }
        if ($locked) {
            // Current locking reads are essential after waiting for the master lock:
            // the workspace's earlier reads may already own a REPEATABLE READ snapshot.
            $values = ['material' => '0', 'service' => '0', 'bop' => '0'];
            $q->select(['id_budget_allocation', 'material', 'service', 'bop'])->lockForUpdate()->chunkById(500, function ($rows) use (&$values) {
                foreach ($rows as $r) {
                    foreach ($values as $column => $sum) {
                        $values[$column] = bcadd($sum, (string) $r->$column, 0);
                    }
                }
            }, 'id_budget_allocation');
            $sum = (object) $values;
        } else {
            $sum = $q->selectRaw('COALESCE(SUM(material),0) as material, COALESCE(SUM(service),0) as service, COALESCE(SUM(bop),0) as bop')->first();
        }
        $result = [];
        foreach (self::CATEGORIES as $key => $column) {
            $result[$key] = self::integer($sum->$column);
        }
        $result['TOTAL'] = self::integer(bcadd((string) $result['Material'], (string) $result['Non Material'], 0));

        return $result;
    }

    public static function integer(mixed $value): int
    {
        abort_unless((! is_string($value) || preg_match('/^\d+(?:\.0+)?$/D', $value) === 1)
            && is_numeric($value) && is_finite((float) $value) && (float) $value >= 0 && floor((float) $value) === (float) $value
            && bccomp(sprintf('%.0f', (float) $value), '9007199254740991', 0) <= 0, 422, 'Nominal alokasi PID tidak valid atau melebihi batas aman.');

        return (int) $value;
    }

    public static function amounts(array $state, array $p): array
    {
        if (($p['budgetPidPolicy'] ?? '') === self::POLICY) {
            return self::mandorAmounts($state, $p);
        }
        $owners = $p['planBudgetTotals'] ?? null;
        abort_unless(is_array($owners) && isset($owners['ownerM'], $owners['ownerS'], $owners['bop']), 422, 'Rincian BOQ Owner dan BOP belum lengkap untuk alokasi budget PID.');
        $m = self::integer($owners['ownerM']);
        $s = self::integer($owners['ownerS']);
        $bop = self::integer($owners['bop']);
        $deals = array_column($state['deals'], null, 'id');
        $spks = array_column($state['spks'], null, 'id');
        $terms = array_column($state['terms'], null, 'id');
        $projects = array_column($state['projects'], null, 'id');
        $rows = array_column($projects[$p['rootId']]['boq'], null, 'id');
        $released = ['m' => 0, 's' => 0];
        foreach ($state['bapps'] as $b) {
            $term = $terms[$b['termId']] ?? [];
            $spk = $spks[$term['spkId'] ?? ''] ?? [];
            if (($deals[$spk['dealId'] ?? '']['projectId'] ?? '') !== $p['id']) {
                continue;
            }
            foreach ($b['released'] ?? [] as $rowId => $quantities) {
                foreach (['m', 's'] as $c) {
                    $qty = $quantities[$c] ?? 0;
                    if ($qty == 0) {
                        continue;
                    }
                    abort_unless(isset($rows[$rowId]) && $rows[$rowId][$c] !== null, 422, 'Sumber pelepasan budget tidak sesuai BOQ Owner.');
                    $part = WholeRupiah::amount(bcmul((string) $qty, (string) $rows[$rowId][$c], 6), $p);
                    $released[$c] = self::integer(bcadd((string) $released[$c], (string) $part, 0));
                }
            }
        }
        abort_if($released['m'] > $m || $released['s'] > $s, 422, 'Pelepasan melebihi alokasi budget PP.');

        return ['material' => $m - $released['m'], 'service' => $s - $released['s'], 'bop' => $bop,
            'planned_material' => $m, 'planned_service' => $s, 'planned_bop' => $bop];
    }

    /** Cost basis stays frozen on issued PP; only explicit physical unused-volume release earns credit. */
    private static function mandorAmounts(array $state, array $p): array
    {
        $totals = $p['planBudgetTotals'] ?? [];
        abort_unless(isset($totals['mandorM'], $totals['mandorS'], $totals['bop']) && isset($p['planBudget']['mandor']), 422, 'Isi BOQ TA - Mandor sebelum validasi budget PID.');
        $m = self::integer($totals['mandorM']);
        $s = self::integer($totals['mandorS']);
        $bop = self::integer($totals['bop']);
        abort_unless($m + $s > 0, 422, 'Isi volume dan harga positif BOQ TA - Mandor sebelum mengajukan paket PP.');
        $planned = ['material' => $m, 'service' => $s, 'bop' => $bop, 'planned_material' => $m, 'planned_service' => $s, 'planned_bop' => $bop];
        if (empty($state['bapps'])) {
            return $planned;
        }
        $deals = array_column($state['deals'], null, 'id');
        $spks = array_column($state['spks'], null, 'id');
        $terms = array_column($state['terms'], null, 'id');
        $owned = fn ($t) => ($deals[$spks[$t['spkId'] ?? '']['dealId'] ?? '']['projectId'] ?? '') === $p['id'];
        $accepted = $releasedQty = [];
        foreach ($state['bapps'] as $b) {
            if (! $owned($terms[$b['termId']] ?? [])) {
                continue;
            }
            foreach ($b['released'] ?? [] as $id => $q) {
                foreach (['m', 's'] as $c) {
                    $releasedQty[$id][$c] = ($releasedQty[$id][$c] ?? 0) + ($q[$c] ?? 0);
                }
            }
        }
        if (! $releasedQty) {
            return $planned;
        }
        foreach ($state['terms'] as $t) {
            if (! $owned($t) || $t['status'] !== 'Disetujui') {
                continue;
            }
            foreach ($t['actuals'] as $id => $q) {
                foreach (['m', 's'] as $c) {
                    $accepted[$id][$c] = ($accepted[$id][$c] ?? 0) + ($q[$c] ?? 0);
                }
            }
        }
        $credit = ['m' => 0, 's' => 0];
        foreach ($p['planBudget']['mandor'] as $row) {
            foreach (['m' => ['qtyM', 'priceM'], 's' => ['qtyS', 'priceS']] as $c => [$qty, $price]) {
                // A larger physical SPK release can never free accepted/paid or unbudgeted Mandor volume.
                $unused = max(0, $row[$qty] - ($accepted[$row['rowId']][$c] ?? 0));
                $released = min($releasedQty[$row['rowId']][$c] ?? 0, $unused);
                $amount = WholeRupiah::amount(bcmul((string) $released, (string) $row[$price], 6), $p);
                $credit[$c] = self::integer(bcadd((string) $credit[$c], (string) $amount, 0));
            }
        }
        abort_if($credit['m'] > $m || $credit['s'] > $s, 422, 'Pelepasan melebihi alokasi BOQ TA - Mandor.');

        return ['material' => $m - $credit['m'], 'service' => $s - $credit['s'], 'bop' => $bop,
            'planned_material' => $m, 'planned_service' => $s, 'planned_bop' => $bop];
    }

    /** Called only after domain guards succeed, under the existing workspace transaction/lock. */
    public function plan(PrototypeWorkspace $w, array $before, array &$state, array $input, string $payloadHash): ?array
    {
        if (! in_array($input['command'], ['saveProject', 'saveProjectBundle', 'submitProjectBundle', 'createContinuation', 'cancelProject', 'releaseVolume'], true)) {
            return null;
        }
        $old = array_column($before['projects'], null, 'id');
        $command = $input['command'];
        $editTarget = in_array($command, ['saveProject', 'saveProjectBundle', 'submitProjectBundle'], true) ? (is_array($input['args'][0]) ? $input['args'][0]['id'] : $input['args'][0]) : null;
        $target = null;
        foreach ($state['projects'] as &$p) {
            if (isset($old[$p['id']]) && self::tracked($old[$p['id']])) {
                $p['budgetPidPolicy'] = $old[$p['id']]['budgetPidPolicy'];
                if ($p['id'] === $editTarget && in_array($old[$p['id']]['status'], ['Draft', 'Dikembalikan', 'Ditolak'], true)) {
                    $p['budgetPidPolicy'] = self::POLICY;
                }
            } elseif (! isset($old[$p['id']]) && ($p['workflowVersion'] ?? 1) >= 3) {
                $p['budgetPidPolicy'] = self::POLICY;
                $target = $p['id'];
            } else {
                unset($p['budgetPidPolicy']);
            }
        }
        unset($p);
        if (in_array($command, ['saveProject', 'saveProjectBundle', 'submitProjectBundle', 'cancelProject'], true)) {
            $target = is_array($input['args'][0]) ? $input['args'][0]['id'] : $input['args'][0];
        } elseif ($command === 'releaseVolume') {
            $b = collect($state['bapps'])->firstWhere('id', $input['args'][0]);
            $t = collect($state['terms'])->firstWhere('id', $b['termId']);
            $v = collect($state['spks'])->firstWhere('id', $t['spkId']);
            $target = collect($state['deals'])->firstWhere('id', $v['dealId'])['projectId'];
        }
        $p = collect($state['projects'])->firstWhere('id', $target);
        if (! $p || ! self::tracked($p)) {
            return null;
        }
        if (! empty($p['parentId'])) {
            $root = collect($state['projects'])->firstWhere('id', $p['rootId']);
            abort_unless($root && BudgetPidReference::code($root['projectCode']) === BudgetPidReference::code($p['projectCode'])
                && ($root['unit'] === 'Operation' ? 'Operation' : 'Konstruksi') === $p['unit'] && $root['branch'] === $p['branch'], 422, 'PID, unit dan Branch PP lanjutan harus mengikuti PP sumber.');
        }
        $formal = $command === 'submitProjectBundle' || ($command === 'saveProject' && ($input['args'][1] ?? false));
        // Revision drafts keep the prior reservation until explicit resubmission, never free it early.
        if (! $formal && in_array($command, ['saveProject', 'saveProjectBundle'], true)
            && (! empty($old[$target]['contractBundle']['submissions']) || ! empty($old[$target]['approvalHistory']) || ! empty($old[$target]['approvals']))) {
            return null;
        }
        DB::table('budget_pid_controls')->where('id_budget_control', 1)->lockForUpdate()->first();
        $ppId = app(RelationalWorkflowStore::class)->id($w->id, 'projects', $p['id']);
        $existing = DB::table('budget_pid_allocations')->where('pp_id', $ppId)->lockForUpdate()->first();
        abort_if(isset($old[$target]) && ! $existing, 503, 'Alokasi PID untuk PP baru tidak ditemukan. Hubungi Superadmin.');
        abort_unless(is_string($p['projectCode'] ?? null) && mb_strlen($p['projectCode']) <= 100 && ! preg_match('/[\x00-\x1F\x7F]/', $p['projectCode']), 422, 'ID Project maksimal 100 karakter dan tidak boleh berisi karakter kontrol.');
        $pid = BudgetPidReference::code($p['projectCode']);
        $branch = collect($state['branches'])->firstWhere('name', $p['branch']);
        $branchId = (int) str_replace('db-branch-', '', $branch['id'] ?? '0');
        abort_unless($branchId > 0, 422, 'Branch PP tidak sesuai master SQL.');
        $master = DB::table('budget_pids')->where('pid', $pid)->lockForUpdate()->first(['id_budget_pid', 'unit', 'branch_id']);
        $cancel = $command === 'cancelProject';
        $release = $command === 'releaseVolume';
        if (! $cancel && ! $release) {
            // Unregistered/unmapped PID keeps the existing temporary manual Branch flow.
            // Once mapped, Finance must reconcile any cross-Branch records before budget use.
            $this->identity($pid, $p['unit'], $branchId, $ppId, true, $formal || ($master && $master->branch_id !== null));
            abort_if($master && ($master->unit !== $p['unit'] || ($master->branch_id !== null && (int) $master->branch_id !== $branchId)), 422, 'PID tidak sesuai Unit Kerja atau pemetaan Branch.');
        }
        $status = $cancel ? 'cancelled' : ($formal || $release ? 'reserved' : 'draft');
        $amounts = $status === 'reserved' ? self::amounts($state, $p) : array_fill_keys(['material', 'service', 'bop', 'planned_material', 'planned_service', 'planned_bop'], 0);
        if ($formal && $master && $master->branch_id !== null) {
            $available = DB::table('budget_pid_items')->where('budget_pid_id', $master->id_budget_pid)->whereIn('category', ['TOTAL', ...array_keys(self::CATEGORIES)])->lockForUpdate()->pluck('available', 'category')->all();
            abort_unless(count($available) === 4, 503, 'Rincian budget PID belum lengkap.');
            $used = $this->usage($pid, $p['unit'], $branchId, $ppId, true);
            $requested = ['TOTAL' => self::integer(bcadd((string) $amounts['material'], (string) $amounts['service'], 0))];
            foreach (self::CATEGORIES as $category => $column) {
                $requested[$category] = $amounts[$column];
            }
            foreach ($requested as $category => $amount) {
                $remaining = (int) $available[$category] - $used[$category];
                abort_if($amount > $remaining, 422, "Budget PID {$category} tidak mencukupi: kebutuhan Rp ".number_format($amount, 0, ',', '.').', sisa Rp '.number_format($remaining, 0, ',', '.').'. Simpan draf untuk perbaikan atau hubungi Finance.');
            }
        }
        $record = ['pp_id' => $ppId, 'pid_code' => $pid, 'unit' => $p['unit'], 'branch_id' => $branchId,
            'budget_pid_id' => $master && $master->unit === $p['unit'] && (int) $master->branch_id === $branchId ? $master->id_budget_pid : null,
            'status' => $status] + $amounts;
        $basis = $p['budgetPidPolicy'];
        $record['source_hash'] = hash('sha256', json_encode(Arr::sortRecursive($basis === self::POLICY ? $record + ['budget_basis' => $basis] : $record), JSON_THROW_ON_ERROR));
        if (! $formal && $existing && $existing->source_hash === $record['source_hash']) {
            return null;
        }

        return ['before' => $existing ? (array) $existing : null, 'record' => $record,
            'id' => $existing->id_budget_allocation ?? (string) Str::uuid(), 'request' => $input['request_id'], 'hash' => $payloadHash,
            'basis' => $basis, 'action' => $cancel ? 'cancel' : ($release ? 'release' : ($formal ? 'reserve' : 'draft')), 'actor' => (int) auth()->id()];
    }

    public function persist(?array $plan, int $revision): void
    {
        if (! $plan) {
            return;
        }
        $record = $plan['record'] + ['source_revision' => $revision, 'updated_at' => now()];
        if ($plan['before']) {
            DB::table('budget_pid_allocations')->where('id_budget_allocation', $plan['id'])->update($record + ['created_at' => DB::raw('created_at')]);
        } else {
            DB::table('budget_pid_allocations')->insert(['id_budget_allocation' => $plan['id'], 'created_at' => now()] + $record);
        }
        DB::table('budget_pid_allocation_changes')->insert(['id_budget_allocation_change' => (string) Str::uuid(), 'allocation_id' => $plan['id'],
            'request_uuid' => $plan['request'], 'actor_user_id' => $plan['actor'], 'action' => $plan['action'], 'payload_hash' => $plan['hash'],
            'before_data' => $plan['before'] ? json_encode($plan['before'], JSON_THROW_ON_ERROR) : null,
            'after_data' => json_encode($record + ['budget_basis' => $plan['basis']], JSON_THROW_ON_ERROR), 'created_at' => now()]);
    }
}
