<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Payment metadata only: no aggregate/archive, private ciphertext, account or file reads. */
final class FinancePaymentCatalog
{
    public const PAGE_SIZE = 20;

    public function listing(array $account, array $filters): array
    {
        abort_unless(in_array($account['code'], ['FINANCE', 'MGR_FINANCE_SETTLEMENT'], true), 403);
        $workspace = DB::table('prototype_workspaces')->where('owner_key', hash('sha256', 'area3-authenticated-workflow-v1'))->first(['id', 'relational_migrated_at', 'revision']);
        abort_unless($workspace?->relational_migrated_at, 503, 'Penyimpanan transaksi belum diaktifkan.');
        abort_unless(DB::table('migrations')->where('migration', '2026_11_11_000001_create_finance_kasbon_workflow')->exists(), 503, 'Daftar Payment memerlukan migration kasbon.');
        $q = DB::table('payment_terms as t')->join('work_orders as w', 'w.id_spk', '=', 't.work_order_id')
            ->join('contracts as c', 'c.id_contract', '=', 'w.contract_id')->join('project_plans as pp', 'pp.id_pp', '=', 'c.pp_id')
            ->join('projects as p', 'p.id_project', '=', 'pp.project_id')->join('branches as b', 'b.id_branch', '=', 'pp.branch_id')
            ->leftJoin('finance_kasbons as k', fn ($j) => $j->on('k.term_id', '=', 't.id_payment_term')->on('k.id_kasbon', '=', 't.document_data->kasbon->id'))
            ->leftJoin('apm_settlements as a', 'a.term_id', '=', 't.id_payment_term')
            ->where('t.workspace_id', $workspace->id)->where('w.workspace_id', $workspace->id)->where('c.workspace_id', $workspace->id)->where('pp.workspace_id', $workspace->id)
            ->whereIn('pp.branch_id', $account['branchIds'])
            ->where(fn (Builder $q) => $q->whereNotNull('k.id_kasbon')->orWhereNotNull('t.document_data->paymentRequest->id')->orWhereNotNull('a.id_apm_settlement'));
        if ($filters['branch'] ?? '') {
            $q->where('b.name', $filters['branch']);
        }
        if ($filters['q'] ?? '') {
            $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $filters['q']).'%';
            $q->where(function (Builder $q) use ($like) {
                foreach (['p.project_code', 'pp.document_data->name', 'pp.pp_no', 'w.spk_no', 'c.mandor_name', 'a.apm_no'] as $column) {
                    $q->orWhereRaw($q->getGrammar()->wrap($column)." LIKE ? ESCAPE '!'", [$like]);
                }
            });
        }
        $grammar = $q->getGrammar();
        $transferPaid = $grammar->wrap('t.document_data->paymentFlow')." IN ('kasbon-v1', 'kasbon-settlement-v2') AND ".$grammar->wrap('t.document_data->financePayment->status')." = 'Dibayar' AND ".$grammar->wrap('t.document_data->financePayment->settledAt').' IS NOT NULL AND '.$grammar->wrap('t.document_data->financePayment->kasbonId').' = a.kasbon_id AND '.$grammar->wrap('t.document_data->financePayment->transferFileId').' = a.transfer_file_id';
        $paid = 'a.id_apm_settlement IS NOT NULL AND a.amount > 0 AND (COALESCE('.$grammar->wrap('t.document_data->apmStatus').", '') = 'Valid' OR (".$transferPaid.'))';
        $amount = 'COALESCE(k.amount, '.$grammar->wrap('t.document_data->paymentRequest->amount').', t.approved_amount, 0)';
        $paid .= ' AND a.amount >= '.$amount;
        $summary = (clone $q)->selectRaw("COUNT(*) AS total, COUNT(DISTINCT NULLIF(p.project_code, '')) AS projects, SUM(CASE WHEN $paid THEN 1 ELSE 0 END) AS paid_count, SUM(CASE WHEN $paid THEN a.amount ELSE 0 END) AS paid_amount, SUM(CASE WHEN $paid THEN 0 ELSE $amount END) AS unpaid_amount")->first();
        $status = $filters['status'] ?? 'all';
        if ($status !== 'all') {
            $q->whereRaw($status === 'paid' ? "($paid)" : "NOT ($paid)");
        }
        $page = (int) ($filters['page'] ?? 1);
        $rows = $q->orderByDesc('t.submitted_at')->orderBy('t.id_payment_term')->offset(($page - 1) * self::PAGE_SIZE)->limit(self::PAGE_SIZE + 1)
            ->select(['t.source_id as id', 'pp.source_id as pp_id', 'pp.pp_no', 'p.project_code', 'pp.document_data->name as job', 'w.spk_no', 'c.mandor_name', 'b.name as branch', 't.term_no', 't.term_type', 't.status', 't.submitted_at', 't.document_data->workflowVersion as workflow_version', 't.document_data->approvalScheme as approval_scheme', 't.document_data->paymentFlow as flow', 't.document_data->approvals as decisions', 't.document_data->approverUserIds as assignees', 't.document_data->financePayment->status as finance_status', 't.document_data->legacySettlementReview->version as legacy_review_version', 't.document_data->legacySettlementReview->decision->decision as legacy_review_decision', 't.document_data->scan->status as scan_status', 'a.apm_no', 'a.recorded_at', 'a.amount as paid_amount'])
            ->selectRaw("$amount AS amount, CASE WHEN $paid THEN 1 ELSE 0 END AS is_paid")->get();
        $items = $rows->take(self::PAGE_SIZE)->map(function ($r) {
            $t = ['status' => $r->status === 'SUBMITTED' ? 'Diajukan' : '', 'workflowVersion' => (int) $r->workflow_version, 'approvalScheme' => $r->approval_scheme, 'paymentFlow' => $r->flow,
                'approvals' => json_decode($r->decisions ?? '[]', true), 'approverUserIds' => json_decode($r->assignees ?? '{}', true)];
            $status = match ($r->status) {
                'DRAFT' => 'Draf', 'SUBMITTED' => 'Menunggu '.PrototypeDomain::nextApprovalRole($t), 'RETURNED' => 'Dikembalikan', 'REJECTED' => 'Ditolak', 'APPROVED' => 'Approval lengkap', default => $r->status,
            };

            if ($r->legacy_review_version && $r->status === 'APPROVED' && ! $r->is_paid) {
                $status = $r->finance_status === 'Dikembalikan' ? 'Kasbon dikembalikan ke Admin' : ($r->finance_status === 'Diverifikasi' ? 'Finance disetujui · Proses transfer' : (($r->legacy_review_decision ?? '') === 'Setujui' ? 'Mgr Finance disetujui · Menunggu Finance' : 'Menunggu Mgr Finance & Settlement'));
            }

            return ['id' => $r->id, 'ppId' => $r->pp_id, 'ppNo' => $r->pp_no, 'projectCode' => $r->project_code, 'name' => $r->job, 'spkNo' => $r->spk_no, 'mandor' => $r->mandor_name, 'branch' => $r->branch,
                'number' => (int) $r->term_no, 'type' => $r->term_type === 'FINAL' ? 'Final' : 'Parsial', 'amount' => (float) $r->amount, 'paid' => (bool) $r->is_paid, 'paidAmount' => $r->is_paid ? (float) $r->paid_amount : 0,
                'approval' => $status, 'documents' => $r->scan_status ? 'OK' : 'NOK', 'scanStatus' => $r->scan_status, 'apm' => $r->apm_no, 'paidAt' => $r->recorded_at, 'submittedAt' => $r->submitted_at];
        })->all();

        return ['revision' => (int) $workspace->revision, 'items' => $items, 'page' => $page, 'hasMore' => $rows->count() > self::PAGE_SIZE,
            'summary' => ['total' => (int) $summary->total, 'projects' => (int) $summary->projects, 'paid' => (int) $summary->paid_count, 'unpaid' => (int) $summary->total - (int) $summary->paid_count, 'paidAmount' => (float) $summary->paid_amount, 'unpaidAmount' => (float) $summary->unpaid_amount]];
    }
}
