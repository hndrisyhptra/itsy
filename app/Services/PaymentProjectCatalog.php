<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Bounded account/Branch selection. No aggregate, archive, private files or master prices. */
final class PaymentProjectCatalog
{
    public const PAGE_SIZE = 20;

    private function scope(array $account): Builder
    {
        abort_unless(in_array($account['code'], ['ADMIN_KONSTRUKSI', 'SUPERADMIN'], true), 403);
        $workspace = DB::table('prototype_workspaces')->where('owner_key', hash('sha256', 'area3-authenticated-workflow-v1'))->first(['id', 'relational_migrated_at']);
        abort_unless($workspace?->relational_migrated_at, 503, 'Penyimpanan transaksi belum diaktifkan.');

        return DB::table('work_orders as w')->join('contracts as c', 'c.id_contract', '=', 'w.contract_id')
            ->join('project_plans as pp', 'pp.id_pp', '=', 'c.pp_id')->join('projects as p', 'p.id_project', '=', 'pp.project_id')
            ->join('branches as b', 'b.id_branch', '=', 'pp.branch_id')
            ->where('w.workspace_id', $workspace->id)->where('c.workspace_id', $workspace->id)->where('pp.workspace_id', $workspace->id)
            ->where('w.status', 'Sah')->whereIn('pp.branch_id', $account['branchIds']);
    }

    public function projects(array $account, string $search, int $page): array
    {
        $query = $this->scope($account);
        $name = $query->getGrammar()->wrap('pp.document_data->name');
        $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%';
        // A matching authorized Sah-SPK sibling selects the group; its counts include all visible Sah SPKs.
        $matching = clone $query;
        $matching->whereRaw($name." LIKE ? ESCAPE '!'", [$like])->select('p.id_project');
        $rows = $query->where(fn (Builder $q) => $q->whereRaw("p.project_code LIKE ? ESCAPE '!'", [$like])->orWhereIn('p.id_project', $matching))
            ->select('p.id_project as id', 'p.project_code as code')
            ->selectRaw("COALESCE(MIN(CASE WHEN $name LIKE ? ESCAPE '!' THEN $name END), MIN($name)) as name, COUNT(*) as spk_count, MIN(b.name) as branch, COUNT(DISTINCT pp.branch_id) as branch_count", [$like])
            ->groupBy('p.id_project', 'p.project_code')->orderBy('p.project_code')->orderBy('p.id_project')
            ->offset(($page - 1) * self::PAGE_SIZE)->limit(self::PAGE_SIZE + 1)->get();

        return $this->page($rows->map(fn ($r) => ['id' => $r->id, 'code' => $r->code, 'name' => $r->name, 'spkCount' => (int) $r->spk_count, 'branch' => $r->branch, 'branchCount' => (int) $r->branch_count])->all(), $page);
    }

    public function orders(array $account, string $project, int $page): array
    {
        $rows = $this->scope($account)->where('p.id_project', $project)->orderByDesc('pp.usage_date')->orderBy('w.id_spk')
            ->offset(($page - 1) * self::PAGE_SIZE)->limit(self::PAGE_SIZE + 1)
            ->get(['w.id_spk', 'w.contract_id', 'w.source_id as id', 'w.spk_no as no', 'pp.pp_no as pp_no', 'pp.status as pp_status', 'pp.document_data->name as name', 'p.project_code', 'b.name as branch', 'c.mandor_name']);
        if ($rows->isEmpty() && $page === 1) {
            abort(404, 'ID Project dengan SPK Sah tidak ditemukan dalam akses Branch Anda.');
        }
        $visible = $rows->take(self::PAGE_SIZE);
        $terms = $visible->isEmpty() ? collect() : DB::table('payment_terms')->whereIn('work_order_id', $visible->pluck('id_spk'))
            ->orderBy('term_no')->get(['work_order_id', 'source_id as id', 'term_no', 'term_type', 'status', 'workflow_version', 'document_data->apmStatus as apm_status', 'document_data->scan->status as scan_status', 'document_data->financeApproval->at as finance_at', 'document_data->paymentFlow as payment_flow', 'document_data->paymentRequest->status as payment_status', 'document_data->financePayment->status as finance_payment_status'])->groupBy('work_order_id');
        // Domain rounds the combined M/J amount per source BOQ row, then sums those lines.
        $amounts = [];
        if ($visible->isNotEmpty()) {
            $lines = DB::table('contract_components as cc')->join('boq_components as bc', 'bc.id_boq_component', '=', 'cc.boq_component_id')->join('boq_lines as l', 'l.id_boq', '=', 'bc.boq_line_id')
                ->whereIn('cc.contract_id', $visible->pluck('contract_id'))->groupBy('cc.contract_id', 'l.id_boq')
                ->select('cc.contract_id')->selectRaw('ROUND(SUM(cc.quantity * cc.agreed_price), 2) as amount');
            $amounts = DB::query()->fromSub($lines, 'line_amounts')->groupBy('contract_id')
                ->select('contract_id')->selectRaw('SUM(amount) as amount')->pluck('amount', 'contract_id')->all();
        }

        $items = $visible->map(function ($r) use ($terms, $amounts) {
            $history = ($terms->get($r->id_spk) ?? collect())->map(fn ($t) => [
                'id' => $t->id, 'number' => (int) $t->term_no, 'type' => $t->term_type === 'FINAL' ? 'Final' : 'Parsial',
                'status' => match ($t->status) {
                    'APPROVED' => 'Disetujui', 'SUBMITTED' => 'Diajukan', 'RETURNED' => 'Dikembalikan', 'REJECTED' => 'Ditolak', default => 'Draft'
                },
                'version' => (int) $t->workflow_version, 'apmStatus' => $t->apm_status ?? '', 'scanStatus' => $t->scan_status ?? '', 'financeApproved' => ! empty($t->finance_at),
                ...($t->payment_flow === 'kasbon-v1' ? ['financePaymentStatus' => ($t->finance_payment_status === 'Menunggu Approval' && $t->status === 'APPROVED') ? 'Menunggu Kasbon Finance' : ($t->finance_payment_status ?? 'Menunggu Kasbon Finance')] : []),
                ...($t->payment_flow === 'partial-payment-v1' ? ['partialPaymentStatus' => $t->payment_status] : []),
            ])->all();
            $latest = $history ? $history[array_key_last($history)] : null;

            return array_merge(['id' => $r->id, 'no' => $r->no, 'ppNo' => $r->pp_no, 'name' => $r->name, 'projectCode' => $r->project_code, 'branch' => $r->branch, 'mandor' => $r->mandor_name, 'amount' => round($amounts[$r->contract_id] ?? 0, 2), 'latest' => $latest], PaymentSelection::action($history, $r->pp_status === 'APPROVED'));
        })->all();

        return ['items' => $items, 'page' => $page, 'hasMore' => $rows->count() > self::PAGE_SIZE];
    }

    private function page(array $rows, int $page): array
    {
        return ['items' => array_slice($rows, 0, self::PAGE_SIZE), 'page' => $page, 'hasMore' => count($rows) > self::PAGE_SIZE];
    }
}
