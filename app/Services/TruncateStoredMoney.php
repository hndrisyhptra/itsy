<?php

namespace App\Services;

use App\Models\PrototypeWorkspace;
use App\Support\Area3MigrationTarget;
use App\Support\WholeRupiah;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

/** One-time, its-only monetary correction. Original rows remain encrypted in the conversion audit. */
final class TruncateStoredMoney
{
    public const KEY = 'idr-trunc-v1';

    private const KEYS = [
        'designator_package_prices' => 'id_designator_package_price', 'khs_prices' => 'id_khs_price',
        'project_plans' => 'id_pp', 'project_plan_costs' => 'id_pp_cost', 'boq_components' => 'id_boq_component',
        'contract_components' => 'id_contract_component', 'payment_terms' => 'id_payment_term',
        'apm_settlements' => 'id_apm_settlement', 'partial_payment_requests' => 'id_payment_request', 'finance_kasbons' => 'id_kasbon',
    ];

    public function run(bool $apply = false): array
    {
        Area3MigrationTarget::assertAllowed();
        if (Schema::hasTable('currency_conversion_runs') && DB::table('currency_conversion_runs')->where('conversion_key', self::KEY)->exists()) {
            return ['alreadyApplied' => true, 'changedRows' => 0];
        }
        if (! $apply) {
            return $this->plan()['summary'];
        }
        if (! Schema::hasTable('currency_conversion_runs') || ! Schema::hasTable('currency_conversion_changes')) {
            throw new RuntimeException('Jalankan migration untuk menyiapkan audit konversi sebelum apply.');
        }

        return DB::transaction(function () {
            // Maintenance must also pause queue writers; these locks serialize against normal account commands.
            $workspaces = PrototypeWorkspace::select(['id', 'revision'])->orderBy('id')->lockForUpdate()->get();
            if (DB::table('currency_conversion_runs')->where('conversion_key', self::KEY)->exists()) {
                return ['alreadyApplied' => true, 'changedRows' => 0];
            }
            $plan = $this->plan(); // Validate every document before the first monetary UPDATE.
            $run = (string) Str::uuid();
            DB::table('currency_conversion_runs')->insert(['id_currency_run' => $run, 'conversion_key' => self::KEY, 'summary' => json_encode($plan['summary'], JSON_THROW_ON_ERROR), 'created_at' => now()]);
            foreach ($plan['changes'] as $change) {
                DB::table('currency_conversion_changes')->insert([
                    'id_currency_change' => (string) Str::uuid(), 'run_id' => $run, 'table_name' => $change['table'], 'record_id' => (string) $change['id'],
                    'before_data' => Crypt::encryptString(json_encode($change['before'], JSON_THROW_ON_ERROR)),
                    'after_data' => Crypt::encryptString(json_encode($change['after'], JSON_THROW_ON_ERROR)),
                    'after_sha256' => hash('sha256', json_encode($change['after'], JSON_THROW_ON_ERROR)), 'created_at' => now(),
                ]);
                DB::table($change['table'])->where($change['key'], $change['id'])->update($change['after']);
            }
            foreach ($workspaces as $w) {
                if (! isset($plan['workspaces'][$w->id])) {
                    continue;
                }
                $w->revision++;
                $w->save(); // Never replace prototype_workspaces.state.
            }

            return $plan['summary'] + ['alreadyApplied' => false];
        });
    }

    private function plan(): array
    {
        $changes = [];
        $affected = [];
        $overrides = [];
        $budgetRows = [];
        $loadedWorkspaces = [];
        $add = function (string $table, string $key, array $before, array $after) use (&$changes) {
            $changed = [];
            foreach ($after as $field => $value) {
                if ((string) ($before[$field] ?? '') !== (string) ($value ?? '')) {
                    $changed[$field] = $value;
                }
            }
            if ($changed) {
                $changes[] = ['table' => $table, 'key' => $key, 'id' => $before[$key], 'before' => $before, 'after' => $changed];
            }
        };
        $store = app(RelationalWorkflowStore::class);
        PrototypeWorkspace::select('id')->whereNotNull('relational_migrated_at')->orderBy('id')->chunk(20, function ($workspaces) use ($store, $add, &$affected, &$overrides, &$budgetRows, &$loadedWorkspaces) {
            foreach ($workspaces as $w) {
                $loadedWorkspaces[$w->id] = true;
                $before = [];
                foreach (['projects' => 'project_plans', 'deals' => 'contracts', 'spks' => 'work_orders', 'terms' => 'payment_terms'] as $collection => $table) {
                    $before[$collection] = DB::table($table)->where('workspace_id', $w->id)->orderBy('position')->pluck('document_data')->map(fn ($json) => json_decode($json, true, 512, JSON_THROW_ON_ERROR))->all();
                }
                $after = $before;
                foreach (['projects', 'deals', 'terms'] as $collection) {
                    foreach ($after[$collection] as &$document) {
                        $document = $this->document($document, $collection);
                    }
                }
                unset($document);
                $projects = array_column($after['projects'], null, 'id');
                foreach ($after['projects'] as &$p) {
                    if (isset($p['planBudget'])) {
                        $root = $projects[$p['rootId']] ?? $p;
                        $owners = [];
                        foreach ($root['boq'] as $r) {
                            if (! empty($p['parentId']) && ! isset($p['allocations'][$r['id']])) {
                                continue;
                            }
                            $owners[] = ['rowId' => $r['id'], 'm' => $r['m'], 's' => $r['s'], 'qm' => $r['m'] === null ? 0 : (! empty($p['parentId']) ? $p['allocations'][$r['id']]['m'] : $r['qty']), 'qs' => $r['s'] === null ? 0 : (! empty($p['parentId']) ? $p['allocations'][$r['id']]['s'] : $r['qty'])];
                        }
                        $derived = ProjectPlanBudget::apply($p, $owners, false);
                        $p['costs'] = $derived['costs'];
                        $p['planBudgetTotals'] = $derived['planBudgetTotals'];
                        // Preserve historical schedule identities/dates; only automatic v2 recap amounts are derived.
                        if (($p['planBudget']['version'] ?? 1) === 2) {
                            $usage = array_column($derived['planBudget']['usage'], 'amount', 'id');
                            foreach ($p['planBudget']['usage'] as &$r) {
                                $r['amount'] = $usage[$r['id']] ?? 0;
                            }unset($r);
                        }
                    }
                }unset($p);
                $domain = app(PrototypeDomain::class)->load($after);
                foreach ($after['projects'] as &$p) {
                    if (($p['boqPricingPolicy'] ?? '') === 'khs-auto-v1' && empty($p['parentId'])) {
                        $p['budget'] = $domain->boqTotal($p);
                    }
                    if (! empty($p['parentId']) && isset($p['planBudgetTotals'])) {
                        $p['budget'] = $p['planBudgetTotals']['ownerTotal'];
                    }
                    if ($p['budget'] <= 0 && ! ($p['status'] === 'Draft' && ($p['workflowVersion'] ?? 1) >= 3)) {
                        throw new RuntimeException('Konversi dihentikan: budget PP '.$p['id'].' menjadi nol.');
                    }
                    $id = $store->id($w->id, 'projects', $p['id']);
                    $overrides['project_plans'][$id] = ['budget' => $p['budget']];
                    $overrides['costs'][$id] = ['MANDOR' => $p['costs']['mandor'], 'NONSTOCK' => $p['costs']['material'], 'BOP' => $p['costs']['bop'], 'PERMIT' => $p['costs']['permit']];
                    foreach (['mandor', 'materials', 'bop', 'usage'] as $category) {
                        foreach ($p['planBudget'][$category] ?? [] as $r) {
                            $budgetRows[$id][$category][$r['id'] ?? $r['rowId']] = $r;
                        }
                    }
                }unset($p);
                foreach ($after['terms'] as $t) {
                    if ($t['status'] === 'Draft') {
                        continue;
                    }
                    $bill = $domain->termTotal($t);
                    if ($bill <= 0) {
                        throw new RuntimeException('Konversi dihentikan: tagihan termin '.$t['id'].' menjadi nol.');
                    }
                    if ($t['status'] === 'Disetujui') {
                        $overrides['payment_terms'][$store->id($w->id, 'terms', $t['id'])] = ['approved_amount' => $bill];
                    }
                    if (isset($t['kasbon']) && $t['apmStatus'] !== 'Valid' && $t['paid'] <= 0 && $t['kasbon']['amount'] != $domain->termTotal($t)) {
                        throw new RuntimeException('Konversi dihentikan: kasbon dan tagihan termin '.$t['id'].' perlu direkonsiliasi.');
                    }
                    if (! empty($t['paymentHistory']) && array_sum(array_column($t['paymentHistory'], 'amount')) != $t['paid']) {
                        throw new RuntimeException('Konversi dihentikan: rincian pembayaran dan paid pada '.$t['id'].' berbeda setelah pemotongan.');
                    }
                }
                foreach (['projects' => ['project_plans', 'id_pp'], 'deals' => ['contracts', 'id_contract'], 'terms' => ['payment_terms', 'id_payment_term']] as $collection => [$table,$key]) {
                    $original = array_column($before[$collection], null, 'id');
                    foreach ($after[$collection] as $d) {
                        if ($d === $original[$d['id']]) {
                            continue;
                        }
                        $id = $store->id($w->id, $collection, $d['id']);
                        $add($table, $key, [$key => $id, 'document_data' => json_encode($original[$d['id']], JSON_THROW_ON_ERROR)], ['document_data' => json_encode($d, JSON_THROW_ON_ERROR)]);
                        $affected[$w->id] = true;
                    }
                }
            }
        });
        // Scalar projections: select only monetary columns/identity. Truncate explicitly before changing SQL types.
        foreach (WholeRupiah::COLUMNS as $table => $columns) {
            $key = self::KEYS[$table];
            DB::table($table)->select([$key, ...array_keys($columns), ...($table === 'project_plan_costs' ? ['pp_id', 'cost_type'] : [])])->orderBy($key)->chunk(200, function ($rows) use ($table, $columns, $key, $add, $overrides) {
                foreach ($rows as $row) {
                    $before = (array) $row;
                    $after = [];
                    foreach ($columns as $column => $_) {
                        $v = $row->$column;
                        if ($v === null) {
                            continue;
                        }$cut = bcdiv((string) $v, '1', 0);
                        $cut = $overrides[$table][$row->$key][$column] ?? $cut;
                        if ($table === 'project_plan_costs') {
                            $cut = $overrides['costs'][$row->pp_id][$row->cost_type] ?? $cut;
                        }
                        if (in_array($table, ['apm_settlements', 'partial_payment_requests', 'finance_kasbons'], true) && bccomp((string) $v, '0', 2) > 0 && $cut === '0') {
                            throw new RuntimeException('Konversi dihentikan: '.$table.' memiliki nominal positif kurang dari Rp1. Status pembayaran tidak dihapus otomatis.');
                        }
                        if (bccomp((string) $v, $cut, 6) !== 0) {
                            $after[$column] = $cut;
                        }
                    }
                    $add($table, $key, $before, $after);
                }
            });
        }
        foreach ([
            ['boq_lines', 'id_boq', 'source_data', 'boq'], ['project_plan_budget_rows', 'id_budget_row', 'row_data', 'budget-row'],
            ['partial_payment_requests', 'id_payment_request', 'request_data', 'request'], ['workflow_metadata', 'id_workspace', 'metadata', 'metadata'],
            ['finance_kasbons', 'id_kasbon', 'private_data', 'kasbon'],
        ] as [$table,$key,$field,$kind]) {
            DB::table($table)->select([$key, $field, ...($kind === 'budget-row' ? ['pp_id', 'category'] : [])])->orderBy($key)->chunk(200, function ($rows) use ($table, $key, $field, $kind, $add, $budgetRows) {
                foreach ($rows as $row) {
                    $raw = $row->$field;
                    if ($raw === null) {
                        continue;
                    }
                    $data = json_decode($kind === 'kasbon' ? Crypt::decryptString($raw) : $raw, true, 512, JSON_THROW_ON_ERROR);
                    $after = $this->document($data, $kind);
                    if ($kind === 'budget-row') {
                        $after = $budgetRows[$row->pp_id][$row->category][$data['id'] ?? $data['rowId']] ?? $after;
                    }
                    if (json_encode($after, JSON_THROW_ON_ERROR) === json_encode($data, JSON_THROW_ON_ERROR)) {
                        continue;
                    }
                    if ($kind === 'kasbon' && array_sum(array_column($after['rows'], 'amount')) != $after['amount']) {
                        throw new RuntimeException('Konversi dihentikan: rincian kasbon '.$row->$key.' tidak sama dengan total setelah pemotongan.');
                    }
                    $encoded = json_encode($after, JSON_THROW_ON_ERROR);
                    $add($table, $key, (array) $row, [$field => $kind === 'kasbon' ? Crypt::encryptString($encoded) : $encoded]);
                }
            });
        }

        if (array_filter($changes, fn ($c) => in_array($c['table'], ['designator_package_prices', 'khs_prices'], true))) {
            $affected += $loadedWorkspaces;
        }

        return ['changes' => $changes, 'workspaces' => $affected, 'summary' => ['changedRows' => count($changes), 'affectedWorkspaces' => count($affected), 'policy' => self::KEY]];
    }

    /** Explicit field allowlists: never truncate quantities, percentages, NIK, bank strings, IDs or source Excel. */
    public function document(array $d, string $kind): array
    {
        $cut = function (array $row, array $fields): array {
            foreach ($fields as $key) {
                if (isset($row[$key]) && is_numeric($row[$key])) {
                    $row[$key] = WholeRupiah::round($row[$key]);
                }
            }

            return $row;
        };
        $map = function (array $rows, array $fields) use ($cut): array {
            return array_map(fn ($r) => $cut($r, $fields), $rows);
        };
        if ($kind === 'projects') {
            $d = $cut($d, ['budget']);
            $d['costs'] = $cut($d['costs'], ['mandor', 'material', 'bop', 'permit']);
            $d['boq'] = $map($d['boq'], ['m', 's']);
            if (isset($d['planBudget'])) {
                $b = $d['planBudget'];
                $b = $cut($b, ['permit', 'bopLimit']);
                foreach (['mandor' => ['priceM', 'priceS'], 'materials' => ['price'], 'bop' => ['total'], 'usage' => ['amount']] as $k => $fields) {
                    $b[$k] = $map($b[$k] ?? [], $fields);
                }
                $b['moneyPolicy'] = WholeRupiah::POLICY;
                $d['planBudget'] = $b;
            }
            if (isset($d['planBudgetTotals'])) {
                $d['planBudgetTotals'] = $cut($d['planBudgetTotals'], ['ownerM', 'ownerS', 'ownerTotal', 'mandorM', 'mandorS', 'mandor', 'material', 'bop', 'permit', 'total', 'revenue', 'cogs', 'grossProfit']);
                if (isset($d['planBudgetTotals']['usage'])) {
                    $d['planBudgetTotals']['usage'] = $cut($d['planBudgetTotals']['usage'], ['mandor', 'material', 'bop', 'permit']);
                }
            }
        } elseif ($kind === 'deals') {
            $d['rows'] = $map($d['rows'], ['pm', 'ps', 'sphM', 'sphS', 'offerM', 'offerS', 'capM', 'capS']);
        } elseif ($kind === 'terms') {
            $d = $cut($d, ['paid']);
            $d['paymentHistory'] = $map($d['paymentHistory'] ?? [], ['amount']);
            foreach (['kasbon', 'paymentRequest'] as $k) {
                if (isset($d[$k])) {
                    $d[$k] = $cut($d[$k], ['amount']);
                }
            }
            foreach (['kasbonHistory', 'paymentRequestHistory'] as $k) {
                if (isset($d[$k])) {
                    $d[$k] = $map($d[$k], ['amount']);
                }
            }
        } elseif ($kind === 'metadata') {
            foreach ($d['khs'] ?? [] as $i => $row) {
                $next = $cut($row, ['m', 's']);
                if (($next['m'] ?? null) != ($row['m'] ?? null) || ($next['s'] ?? null) != ($row['s'] ?? null)) {
                    $next['version'] = ($row['version'] ?? 1) + 1;
                } $d['khs'][$i] = $next;
            }
        } elseif ($kind === 'boq') {
            return $cut($d, ['m', 's']);
        } elseif ($kind === 'budget-row') {
            return $cut($d, ['priceM', 'priceS', 'price', 'total', 'amount']);
        } elseif ($kind === 'request') {
            return $cut($d, ['amount']);
        } elseif ($kind === 'kasbon') {
            $d = $cut($d, ['amount']);
            $d['rows'] = $map($d['rows'], ['amount']);

            return $d;
        }
        if (in_array($kind, ['projects', 'deals', 'terms'], true)) {
            $d['moneyPolicy'] = WholeRupiah::POLICY;
        }

        return $d;
    }
}
