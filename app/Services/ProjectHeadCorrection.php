<?php

namespace App\Services;

use App\Models\PrototypeWorkspace;
use App\Models\User;
use App\Support\Area3MigrationTarget;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** CLI-only correction of the inspected, unpaid PP/contract/term graph. */
final class ProjectHeadCorrection
{
    public function run(array $plan, User $operator, ?int $revision = null, bool $apply = false): array
    {
        Area3MigrationTarget::assertAllowed();
        foreach (['workspaceId', 'ppId', 'sourceId', 'ppNo', 'ppRevision', 'runId', 'headStepId', 'headDecisionId', 'contractId', 'spkId', 'termId', 'requestId', 'reason'] as $key) {
            abort_unless(isset($plan[$key]), 422, 'Rencana koreksi belum lengkap: '.$key);
        }
        abort_unless(Str::isUuid($plan['requestId']) && is_int($plan['ppRevision']) && is_string($plan['reason']) && mb_strlen(trim($plan['reason'])) >= 10 && mb_strlen($plan['reason']) <= 1000, 422, 'UUID/revisi/alasan koreksi tidak valid.');
        $hash = hash('sha256', json_encode($plan, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($plan, $operator, $revision, $apply, $hash) {
            $w = PrototypeWorkspace::whereKey($plan['workspaceId'])->lockForUpdate()->firstOrFail();
            abort_unless($w->owner_key === hash('sha256', 'area3-authenticated-workflow-v1'), 422, 'Bukan workspace akun Area 3.');
            $operator = User::whereKey($operator->getKey())->lockForUpdate()->firstOrFail();
            $account = app(AccountAccess::class)->context($operator);
            abort_unless(($account['code'] ?? '') === 'SUPERADMIN', 403, 'Koreksi hanya oleh akun Superadmin aktif.');
            $row = DB::table('project_plans')->where('workspace_id', $w->id)->where('id_pp', $plan['ppId'])->first();
            abort_unless($row && $row->source_id === $plan['sourceId'] && $row->pp_no === $plan['ppNo'] && in_array($row->branch_id, $account['branchIds']), 422, 'Identitas/Branch PP tidak sesuai rencana.');
            $store = app(RelationalWorkflowStore::class);
            $before = $store->read($w);
            $index = array_search($plan['sourceId'], array_column($before['projects'], 'id'), true);
            abort_if($index === false, 422, 'Snapshot PP tidak ditemukan.');
            $p = $before['projects'][$index];
            $prior = DB::table('prototype_audits')->where('workspace_id', $w->id)->where('request_id', $plan['requestId'])->first();
            if ($prior) {
                abort_unless($prior->command === 'reopenProjectHead' && (int) $prior->actor_user_id === (int) $operator->getKey()
                    && ($p['headApprovalReopen']['planHash'] ?? '') === $hash, 409, 'UUID koreksi sudah dipakai untuk isi/akun lain.');

                return ['replayed' => true, 'applied' => true, 'workspaceRevision' => $w->revision, 'ppNo' => $p['no'], 'status' => $p['status']];
            }
            abort_unless(! isset($p['headApprovalReopen']) && (int) $row->revision === $plan['ppRevision'] && $row->status === 'APPROVED'
                && $p['status'] === 'Sah' && $p['no'] === $plan['ppNo'] && $p['boqStatus'] === 'Final' && ($p['workflowVersion'] ?? 1) === 3 && ($p['approvalScheme'] ?? '') === 'project-support-first', 409, 'PP telah berubah atau tidak memenuhi rencana koreksi.');
            abort_if($apply && $revision !== $w->revision, 409, 'Revisi workspace berubah. Jalankan pemeriksaan ulang; jangan menebak revisi.');
            $active = $p['approvals'] ?? [];
            $history = $p['approvalHistory'] ?? [];
            abort_unless(count($active) === 5 && array_column($active, 'role') === PrototypeDomain::APPROVERS_V3
                && array_slice($history, -5) === $active, 422, 'Lima keputusan aktif tidak cocok dengan riwayat.');
            foreach ($active as $a) {
                abort_unless($a['decision'] === 'Setujui' && $a['userId'] === ($p['approverUserIds'][$a['role']] ?? ''), 422, 'Keputusan tidak cocok dengan assignee PP.');
            }
            $headId = (int) substr($active[4]['userId'], strlen('db-user-'));
            $head = app(AccountAccess::class)->context(User::whereKey($headId)->lockForUpdate()->first());
            abort_unless(($head['code'] ?? '') === 'HEAD_OF_PMO' && in_array($row->branch_id, $head['branchIds']), 422, 'Head terpilih tidak aktif atau tidak memiliki Branch PP.');

            $runs = DB::table('approval_runs')->where('pp_id', $row->id_pp)->get();
            $steps = DB::table('approval_steps')->whereIn('run_id', $runs->pluck('id_approval_run'))->orderBy('step_no')->get();
            $decisions = DB::table('approval_decisions')->whereIn('step_id', $steps->pluck('id_approval_step'))->get()->keyBy('step_id');
            abort_unless($runs->count() === 1 && $runs[0]->id_approval_run === $plan['runId'] && $runs[0]->status === 'APPROVED'
                && $steps->count() === 5 && $decisions->count() === 5, 409, 'Siklus approval SQL tidak sesuai hasil pemeriksaan.');
            foreach ($steps as $i => $step) {
                $d = $decisions->get($step->id_approval_step);
                abort_unless($d && $d->decision === 'APPROVE' && $step->expected_role === array_search($active[$i]['role'], AccountAccess::ROLES, true)
                    && (int) $step->assigned_user_id === (int) substr($active[$i]['userId'], 8)
                    && Arr::sortRecursive(json_decode($d->decision_data, true, 512, JSON_THROW_ON_ERROR)) === Arr::sortRecursive($active[$i]), 409, 'Keputusan SQL dan snapshot berbeda.');
            }
            abort_unless($steps[4]->id_approval_step === $plan['headStepId'] && $decisions[$plan['headStepId']]->id_approval_decision === $plan['headDecisionId'], 409, 'Keputusan Head telah berubah.');

            $contracts = DB::table('contracts')->where('workspace_id', $w->id)->where('pp_id', $row->id_pp)->get();
            $spks = DB::table('work_orders')->where('workspace_id', $w->id)->whereIn('contract_id', $contracts->pluck('id_contract'))->get();
            $terms = DB::table('payment_terms')->where('workspace_id', $w->id)->whereIn('contract_id', $contracts->pluck('id_contract'))->get();
            abort_unless($contracts->count() === 1 && $contracts[0]->id_contract === $plan['contractId'] && $contracts[0]->stage === 'SPK_ATTESTED'
                && $spks->count() === 1 && $spks[0]->id_spk === $plan['spkId'] && $spks[0]->status === 'Sah'
                && $terms->count() === 1 && $terms[0]->id_payment_term === $plan['termId'] && $terms[0]->work_order_id === $plan['spkId']
                && $terms[0]->status === 'REJECTED' && (int) $terms[0]->term_no === 1 && $terms[0]->term_type === 'FINAL', 409, 'Dokumen turunan berubah; koreksi ditahan.');
            $deal = json_decode($contracts[0]->document_data, true, 512, JSON_THROW_ON_ERROR);
            $spk = json_decode($spks[0]->document_data, true, 512, JSON_THROW_ON_ERROR);
            $term = json_decode($terms[0]->document_data, true, 512, JSON_THROW_ON_ERROR);
            abort_unless($deal['status'] === 'Sah' && $deal['projectId'] === $p['id'] && $term['status'] === 'Ditolak'
                && $spk['status'] === 'Sah' && $spk['dealId'] === $deal['id'] && $term['spkId'] === $spk['id'] && $term['type'] === 'Final' && $term['number'] === 1
                && (float) ($term['paid'] ?? 0) === 0.0 && empty($term['apm']) && empty($term['paymentHistory'])
                && ($term['apmStatus'] ?? '') !== 'Valid' && empty($term['kasbon']) && empty($term['kasbonHistory']) && empty($term['transferProof']) && empty($term['transferProofHistory'])
                && empty($term['scan']) && empty($term['scanHistory']) && empty($term['financeApproval']) && empty($term['financeHistory'])
                && empty($term['paymentRequest']) && empty($term['paymentRequestHistory']) && empty($term['financePaymentHistory'])
                && in_array($term['financePayment']['status'] ?? 'Menunggu Approval', ['Menunggu Approval'], true), 409, 'Termin memiliki proses finansial/scan yang perlu diperiksa terpisah.');
            abort_if(DB::table('apm_settlements')->whereIn('term_id', $terms->pluck('id_payment_term'))->exists(), 409, 'Pembayaran sudah memiliki APM.');
            abort_if(collect($before['projects'])->contains(fn ($v) => ($v['parentId'] ?? '') === $p['id'])
                || collect($before['bapps'])->contains(fn ($v) => $v['termId'] === $term['id']), 409, 'Sudah ada lanjutan/BA PP; koreksi ditahan.');
            abort_if(DB::table('upload_tasks')->where('workspace_id', $w->id)->whereIn('document_id', [$p['id'], $deal['id'], $spk['id'], $term['id']])->whereIn('status', ['queued', 'processing'])->exists(), 409, 'Tunggu upload terkait selesai.');
            $result = ['applied' => false, 'ppNo' => $p['no'], 'workspaceRevision' => $w->revision, 'ppRevision' => $row->revision,
                'from' => 'Sah', 'to' => 'Diajukan', 'nextApprover' => 'Head of PMO', 'retainedApprovals' => 4];
            if (! $apply) {
                return $result;
            }

            $backup = 'head-corrections/'.$w->id.'/'.$plan['requestId'].'.json';
            $bytes = json_encode(['plan' => $plan, 'workspaceRevision' => $w->revision, 'state' => $before, 'ppRow' => $row,
                'runs' => $runs, 'steps' => $steps, 'decisions' => $decisions->values(), 'contracts' => $contracts, 'spks' => $spks, 'terms' => $terms], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
            $disk = Storage::disk('local');
            if ($disk->exists($backup)) {
                abort_unless(hash_equals(hash('sha256', $bytes), hash('sha256', $disk->get($backup))), 409, 'Cadangan request ini sudah ada dengan isi lain.');
            } else {
                abort_unless($disk->put($backup, $bytes, ['visibility' => 'private']), 500, 'Cadangan privat gagal dibuat.');
            }
            abort_unless(hash_equals(hash('sha256', $bytes), hash('sha256', $disk->get($backup))), 500, 'Verifikasi cadangan gagal.');
            $state = $before;
            $at = now()->toISOString();
            $p['headApprovalReopen'] = ['version' => 1, 'historyOffset' => count($history), 'carriedApprovals' => array_slice($active, 0, 4),
                'carriedDecisionIds' => $steps->take(4)->mapWithKeys(fn ($step, $i) => [$active[$i]['role'] => $decisions[$step->id_approval_step]->id_approval_decision])->all(),
                'previousHeadDecision' => $active[4], 'previousRunId' => $plan['runId'], 'previousDecisionId' => $plan['headDecisionId'],
                'actorUserId' => $account['id'], 'reason' => trim($plan['reason']), 'at' => $at, 'requestId' => $plan['requestId'], 'planHash' => $hash];
            $p['approvals'] = array_slice($active, 0, 4);
            $p['status'] = 'Diajukan';
            $state['projects'][$index] = $p;
            $state['actorUserId'] = $account['id'];
            $state['role'] = $account['role'];
            $state['profile'] = ['name' => $account['name'], 'email' => $account['email']];
            $state['lastSaved'] = $at;
            array_unshift($state['events'], ['id' => 'event-'.Str::uuid(), 'title' => 'Approval Head PP dibuka ulang', 'detail' => $p['no'].' · '.$plan['reason'], 'route' => '/project-plan/'.$p['id'], 'actor' => $account['role'], 'at' => $at]);
            $beforeHash = hash('sha256', json_encode($before, JSON_THROW_ON_ERROR));
            $afterHash = hash('sha256', json_encode($state, JSON_THROW_ON_ERROR));
            $w->revision++;
            $store->write($w, $state, $before);
            $w->save();
            DB::table('prototype_audits')->insert(['workspace_id' => $w->id, 'request_id' => $plan['requestId'], 'command' => 'reopenProjectHead',
                'actor_role' => $account['role'], 'actor_user_id' => $operator->getKey(), 'revision' => $w->revision,
                'before_hash' => $beforeHash, 'after_hash' => $afterHash, 'created_at' => now()]);
            DB::table('command_receipts')->insert(['id_command_receipt' => (string) Str::uuid(), 'request_uuid' => $plan['requestId'], 'actor_user_id' => $operator->getKey(),
                'command_name' => 'reopenProjectHead', 'aggregate_type' => 'WORKSPACE', 'aggregate_id' => $w->id, 'expected_revision' => $revision,
                'resulting_revision' => $w->revision, 'before_sha256' => $beforeHash, 'after_sha256' => $afterHash, 'created_at' => now()]);

            return array_replace($result, ['applied' => true, 'workspaceRevision' => $w->revision, 'backup' => $backup, 'backupSha256' => hash('sha256', $bytes)]);
        }, 3);
    }
}
