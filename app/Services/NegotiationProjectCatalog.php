<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Read-only, bounded selection metadata. Commands still validate the complete ledger. */
class NegotiationProjectCatalog
{
    public const PAGE_SIZE = 20;

    private function scope(array $account): Builder
    {
        abort_unless(in_array($account['code'], ['ADMIN_KONSTRUKSI', 'SUPERADMIN'], true), 403);
        $workspace = DB::table('prototype_workspaces')->where('owner_key', hash('sha256', 'area3-authenticated-workflow-v1'))
            ->first(['id', 'relational_migrated_at']);
        abort_unless($workspace?->relational_migrated_at, 503, 'Penyimpanan transaksi belum diaktifkan.');

        return DB::table('project_plans as pp')->join('projects as p', 'p.id_project', '=', 'pp.project_id')
            ->join('branches as b', 'b.id_branch', '=', 'pp.branch_id')
            ->where('pp.workspace_id', $workspace->id)->whereIn('pp.branch_id', $account['branchIds']);
    }

    public function projects(array $account, string $search, int $page): array
    {
        $query = $this->scope($account);
        $name = $query->getGrammar()->wrap('pp.document_data->name');
        $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%';
        $rows = $query->where(function (Builder $q) use ($like, $account) {
            $q->whereRaw("p.project_code LIKE ? ESCAPE '!'", [$like])->orWhereExists(function (Builder $matching) use ($like, $q, $account) {
                $matching->selectRaw('1')->from('project_plans as match_pp')
                    ->whereColumn('match_pp.project_id', 'pp.project_id')->whereColumn('match_pp.workspace_id', 'pp.workspace_id');
                // Match a visible sibling's activity, then count all visible PPs in the group.
                $matching->whereIn('match_pp.branch_id', $account['branchIds'])
                    ->whereRaw($q->getGrammar()->wrap('match_pp.document_data->name')." LIKE ? ESCAPE '!'", [$like]);
            });
        })->select('p.id_project as id', 'p.project_code as code')
            ->selectRaw("COALESCE(MIN(CASE WHEN $name LIKE ? ESCAPE '!' THEN $name END), MIN($name)) as name, COUNT(*) as pp_count, MIN(b.name) as branch, COUNT(DISTINCT pp.branch_id) as branch_count", [$like])
            ->groupBy('p.id_project', 'p.project_code')->orderBy('p.project_code')->orderBy('p.id_project')
            ->offset(($page - 1) * self::PAGE_SIZE)->limit(self::PAGE_SIZE + 1)->get();

        return $this->page($rows->map(fn ($r) => [
            'id' => $r->id, 'code' => $r->code, 'name' => $r->name, 'ppCount' => (int) $r->pp_count,
            'branch' => $r->branch, 'branchCount' => (int) $r->branch_count,
        ])->all(), $page);
    }

    public function plans(array $account, string $project, int $page): array
    {
        $rows = $this->scope($account)->where('pp.project_id', $project)->orderByDesc('pp.usage_date')->orderBy('pp.id_pp')
            ->limit(self::PAGE_SIZE + 1)->offset(($page - 1) * self::PAGE_SIZE)
            ->get(['pp.id_pp', 'pp.source_id as id', 'pp.root_pp_id', 'pp.parent_pp_id', 'pp.pp_no as no', 'pp.status',
                'pp.document_data->name as name', 'pp.document_data->boqStatus as boq_status', 'pp.document_data->allocations as allocations', 'b.name as branch', 'p.project_code as project_code']);
        // Foreign/invisible project IDs must not reveal existence, names or counts.
        if ($rows->isEmpty() && $page === 1) {
            abort(404, 'ID Project tidak ditemukan dalam akses Branch Anda.');
        }
        $visible = $rows->take(self::PAGE_SIZE);
        $roots = $visible->whereNull('parent_pp_id')->pluck('root_pp_id')->unique()->all();
        $available = $roots ? DB::table('boq_lines as l')->join('boq_components as c', 'c.boq_line_id', '=', 'l.id_boq')
            ->join('volume_balances as v', 'v.boq_component_id', '=', 'c.id_boq_component')
            ->whereIn('l.root_pp_id', $roots)->where('v.available', '>', 0)->distinct()->pluck('l.root_pp_id')->all() : [];
        $children = $visible->whereNotNull('parent_pp_id')->pluck('id_pp')->all();
        $childAvailable = [];
        if ($children) {
            // Continuation allocations are canonical in each PP snapshot, not the legacy empty projection table.
            $used = DB::table('contracts as d')->join('contract_components as c', 'c.contract_id', '=', 'd.id_contract')
                ->join('boq_components as bc', 'bc.id_boq_component', '=', 'c.boq_component_id')
                ->join('boq_lines as l', 'l.id_boq', '=', 'bc.boq_line_id')
                ->whereIn('d.pp_id', $children)->groupBy('d.pp_id', 'l.source_row_id', 'bc.component')
                ->select('d.pp_id', 'l.source_row_id', 'bc.component')->selectRaw('SUM(c.quantity) as quantity')->get()
                ->keyBy(fn ($r) => $r->pp_id.'|'.$r->source_row_id.'|'.strtolower($r->component));
            foreach ($visible->whereNotNull('parent_pp_id') as $child) {
                foreach (json_decode($child->allocations ?? '{}', true, 512, JSON_THROW_ON_ERROR) as $row => $components) {
                    foreach (['m', 's'] as $component) {
                        if (round(($components[$component] ?? 0) - ($used->get($child->id_pp.'|'.$row.'|'.$component)->quantity ?? 0), 3) > 0) {
                            $childAvailable[$child->id_pp] = true;
                        }
                    }
                }
            }
        }

        return $this->page($rows->map(function ($r) use ($available, $childAvailable) {
            $reason = $r->status !== 'APPROVED' ? 'PP belum Sah.' : ($r->boq_status !== 'Final' ? 'BOQ belum Final.' : '');
            if (! $reason && ! ($r->parent_pp_id ? isset($childAvailable[$r->id_pp]) : in_array($r->root_pp_id, $available, true))) {
                $reason = 'Volume tersedia sudah habis.';
            }

            return ['id' => $r->id, 'no' => $r->no, 'name' => $r->name, 'branch' => $r->branch,
                'projectCode' => $r->project_code, 'status' => match ($r->status) {
                    'APPROVED' => 'Sah', 'SUBMITTED' => 'Diajukan', 'RETURNED' => 'Dikembalikan', 'REJECTED' => 'Ditolak', default => 'Draft',
                }, 'eligible' => $reason === '', 'reason' => $reason];
        })->all(), $page);
    }

    private function page(array $rows, int $page): array
    {
        return ['items' => array_slice($rows, 0, self::PAGE_SIZE), 'page' => $page, 'hasMore' => count($rows) > self::PAGE_SIZE];
    }
}
