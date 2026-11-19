<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** SQL current-run metadata only: no workspace archive, BOQ, private files or financial ciphertext. */
class TelegramPendingApprovals
{
    private function base(bool $payment): Builder
    {
        $q = DB::table('approval_runs as r')->join('approval_steps as st', 'st.run_id', '=', 'r.id_approval_run');
        if ($payment) {
            $q->join('payment_terms as t', 't.id_payment_term', '=', 'r.term_id')->join('work_orders as wo', 'wo.id_spk', '=', 't.work_order_id')->join('contracts as c', 'c.id_contract', '=', 'wo.contract_id')->join('project_plans as pp', 'pp.id_pp', '=', 'c.pp_id')->whereColumn('t.workspace_id', 'pp.workspace_id')->where('t.status', 'SUBMITTED')->where('pp.status', 'APPROVED');
        } else {
            $q->join('project_plans as pp', 'pp.id_pp', '=', 'r.pp_id')->where('pp.status', 'SUBMITTED');
        }

        return $q->where('r.status', 'PENDING')->whereNotExists(fn ($d) => $d->selectRaw('1')->from('approval_decisions as d')->whereColumn('d.step_id', 'st.id_approval_step'))
            ->whereNotExists(fn ($later) => $later->selectRaw('1')->from('approval_runs as later')->whereColumn('later.'.($payment ? 'term_id' : 'pp_id'), 'r.'.($payment ? 'term_id' : 'pp_id'))->whereColumn('later.cycle_no', '>', 'r.cycle_no'));
    }

    public function each(callable $callback, ?int $userId = null): void
    {
        foreach ([false, true] as $payment) {
            $q = $this->base($payment)->join('work_units as w', 'w.id_work_unit', '=', 'pp.work_unit_id')
                ->leftJoin('telegram_finance_pics as pic', fn ($j) => $j->on('pic.branch_id', '=', 'pp.branch_id')->on('pic.work_unit_id', '=', 'pp.work_unit_id')->where('st.expected_role', 'FINANCE'));
            $q->when($userId, fn ($q) => $q->whereRaw('COALESCE(st.assigned_user_id, pic.user_id) = ?', [$userId]));
            $doc = $payment ? 't' : 'pp';
            $q->select(['st.id_approval_step', 'st.expected_role', 'st.assigned_user_id', 'pic.user_id as pic_user_id', 'pp.branch_id', 'w.name as unit', 'pp.document_data->approvalScheme as pp_scheme', 'pp.document_data->approverUserIds as pp_assignees', 'pp.document_data->pmUserId as pp_pm', 'pp.document_data->managerUserId as pp_manager', $doc.'.source_id as source_id', $doc.'.document_data->kasbon->id as kasbon_id', $doc.'.document_data->approvals as decisions', $doc.'.document_data->approverUserIds as assignees', $doc.'.document_data->approvalScheme as scheme', $doc.'.document_data->workflowVersion as version', $doc.'.document_data->paymentFlow as flow', $doc.'.document_data->pmUserId as pm_id', $doc.'.document_data->managerUserId as manager_id'])
                ->orderBy('st.id_approval_step')->chunkById(300, function ($rows) use ($callback, $payment) {
                    $ids = $rows->map(fn ($r) => $r->assigned_user_id ?? $r->pic_user_id)->filter()->unique()->all();
                    $users = User::where('active', true)->whereIn('id_user', $ids)->get(['id_user', 'name', 'email', 'username', 'jabatan', 'role', 'active', 'work_unit_id', 'access_scope', 'id_regions']);
                    $contexts = app(AccountAccess::class)->contexts($users);
                    foreach ($rows as $row) {
                        $doc = ['workflowVersion' => (int) $row->version, 'approvalScheme' => $row->scheme, 'paymentFlow' => $row->flow, 'approverUserIds' => json_decode($row->assignees ?? '{}', true) ?? [], 'pmUserId' => $row->pm_id, 'managerUserId' => $row->manager_id];
                        $valid = [];
                        foreach (json_decode($row->decisions ?? '[]', true) ?? [] as $decision) {
                            $selected = (int) $row->version >= 3 ? ($doc['approverUserIds'][$decision['role']] ?? '') : ($doc[$decision['role'] === 'PM Konstruksi' ? 'pmUserId' : 'managerUserId'] ?? '');
                            $financeActor = $payment && $row->flow === 'kasbon-settlement-v2' && $decision['role'] === 'Finance' && ! $selected;
                            if (($decision['decision'] ?? '') === 'Setujui' && ((int) $row->version < 2 || ! empty($decision['userId']) && ($decision['userId'] === $selected || $financeActor)) && (! $payment || $row->flow !== 'kasbon-settlement-v2' || $row->kasbon_id && ($decision['kasbonId'] ?? '') === $row->kasbon_id)) {
                                $valid[] = $decision;
                            }
                        }
                        $doc['approvals'] = $valid;
                        $waiting = PrototypeDomain::nextApprovalRole($doc, ! $payment);
                        if (! $waiting || (AccountAccess::ROLES[$row->expected_role] ?? '') !== $waiting || $payment && $row->flow === 'kasbon-settlement-v2' && ! $row->kasbon_id) {
                            continue;
                        }
                        $id = $row->assigned_user_id ?? $row->pic_user_id;
                        $account = $contexts[$id] ?? null;
                        $pp = ['unit' => $row->unit, 'approvalScheme' => $row->pp_scheme, 'approverUserIds' => json_decode($row->pp_assignees ?? '{}', true) ?? [], 'pmUserId' => $row->pp_pm, 'managerUserId' => $row->pp_manager];
                        if (! $account || $account['code'] !== $row->expected_role || ! in_array($row->branch_id, $account['branchIds']) || ! WorkUnitAccess::permits($account, $pp)) {
                            continue;
                        }
                        $callback(['key' => hash('sha256', $row->id_approval_step.'|'.$id.'|'.$row->kasbon_id), 'user_id' => (int) $id, 'kind' => $payment ? 'payment' : 'project', 'target_id' => $row->source_id]);
                    }
                }, 'st.id_approval_step', 'id_approval_step');
        }
        $this->legacy($callback, $userId);
    }

    private function legacy(callable $callback, ?int $userId): void
    {
        // Five-stage kasbon-v1 supplemental review uses its saved UUID, not a fabricated approval run.
        $q = DB::table('payment_terms as t')->join('work_orders as wo', 'wo.id_spk', '=', 't.work_order_id')->join('contracts as c', 'c.id_contract', '=', 'wo.contract_id')->join('project_plans as pp', 'pp.id_pp', '=', 'c.pp_id')->join('work_units as w', 'w.id_work_unit', '=', 'pp.work_unit_id')
            ->leftJoin('telegram_finance_pics as pic', fn ($j) => $j->on('pic.branch_id', '=', 'pp.branch_id')->on('pic.work_unit_id', '=', 'pp.work_unit_id'))
            ->whereColumn('t.workspace_id', 'pp.workspace_id')->where('t.status', 'APPROVED')->where('pp.status', 'APPROVED')->where(fn ($q) => $q->where('t.document_data->paymentFlow', 'kasbon-v1')->where('t.document_data->financePayment->status', 'Kasbon Terbit')->orWhere('t.document_data->paymentFlow', 'partial-payment-v1')->orWhereNull('t.document_data->paymentFlow'));
        $q->when($userId, fn ($q) => $q->where(fn ($q) => $q->where('pic.user_id', $userId)->orWhere('t.document_data->legacySettlementReview->userId', 'db-user-'.$userId)));
        $q->select(['t.id_payment_term', 't.source_id', 't.document_data->kasbon->id as kasbon_id', 't.document_data->legacySettlementReview as review', 't.document_data->financePayment->decision as finance_decision', 't.document_data->paid as paid', 't.document_data->paymentFlow as flow', 't.document_data->paymentRequest as payment_request', 't.document_data->scan as scan', 't.document_data->workflowVersion as version', 'pp.branch_id', 'w.name as unit', 'pic.user_id as finance_id'])
            ->orderBy('t.id_payment_term')->chunkById(300, function ($rows) use ($callback, $userId) {
                $candidates = [];
                foreach ($rows as $r) {
                    if ((float) $r->paid > 0 || $r->flow === 'kasbon-v1' && ! $r->kasbon_id) {
                        continue;
                    }
                    $review = json_decode($r->review ?? 'null', true);
                    $finance = json_decode($r->finance_decision ?? 'null', true);
                    $role = 'FINANCE';
                    $id = $r->finance_id;
                    if ($r->flow === 'partial-payment-v1') {
                        $request = json_decode($r->payment_request ?? 'null', true);
                        if (($request['status'] ?? '') !== 'Diajukan') {
                            continue;
                        }
                    } elseif ($r->flow !== 'kasbon-v1') {
                        $scan = json_decode($r->scan ?? 'null', true);
                        if ((int) $r->version < 2 || ($scan['status'] ?? '') !== 'Menunggu Verifikasi') {
                            continue;
                        }
                    }
                    if ($review) {
                        if (($review['kasbonId'] ?? '') !== $r->kasbon_id) {
                            continue;
                        }
                        $decision = $review['decision'] ?? [];
                        if (in_array($decision['decision'] ?? '', ['Tolak', 'Kembalikan'], true)) {
                            continue;
                        }
                        $approved = ($decision['decision'] ?? '') === 'Setujui' && ($decision['userId'] ?? '') === $review['userId'] && ($decision['kasbonId'] ?? '') === $r->kasbon_id && ($decision['role'] ?? '') === 'Mgr Finance & Settlement';
                        if (! $approved) {
                            $role = 'MGR_FINANCE_SETTLEMENT';
                            $id = preg_match('/^db-user-([0-9]+)$/D', $review['userId'] ?? '', $m) ? (int) $m[1] : null;
                        }
                    }
                    if ($role === 'FINANCE' && in_array($finance['decision'] ?? '', ['Setujui', 'Tolak', 'Kembalikan'], true) && ($finance['kasbonId'] ?? '') === $r->kasbon_id) {
                        continue;
                    }
                    if (! $id || ($userId && (int) $id !== $userId)) {
                        continue;
                    }
                    $candidates[] = [$r, $id, $role];
                }
                $users = User::where('active', true)->whereIn('id_user', array_unique(array_column($candidates, 1)))->get(['id_user', 'name', 'email', 'username', 'jabatan', 'role', 'active', 'work_unit_id', 'access_scope', 'id_regions']);
                $contexts = app(AccountAccess::class)->contexts($users);
                foreach ($candidates as [$r, $id, $role]) {
                    $a = $contexts[$id] ?? null;
                    if ($a && $a['code'] === $role && in_array($r->branch_id, $a['branchIds']) && in_array($r->unit, $a['workUnits'])) {
                        $callback(['key' => hash('sha256', $r->id_payment_term.'|'.$r->kasbon_id.'|'.$id.'|'.$role), 'user_id' => (int) $id, 'kind' => 'payment', 'target_id' => $r->source_id]);
                    }
                }
            }, 't.id_payment_term', 'id_payment_term');
    }
}
