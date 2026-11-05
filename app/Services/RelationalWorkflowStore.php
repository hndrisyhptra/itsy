<?php

namespace App\Services;

use App\Models\PrototypeWorkspace;
use App\Support\Area3MigrationTarget;
use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Business records own runtime data. The old workspace JSON is a read-only archive. */
class RelationalWorkflowStore
{
    private const RECORDS = [
        'projects' => ['project_plans', 'id_pp'], 'deals' => ['contracts', 'id_contract'],
        'spks' => ['work_orders', 'id_spk'], 'terms' => ['payment_terms', 'id_payment_term'],
        'bapps' => ['ba_completions', 'id_ba_pp'],
    ];

    public function id(string $workspace, string $type, string $source): string
    {
        $hash = hash('sha256', $workspace.'|'.$type.'|'.$source);

        return substr($hash, 0, 8).'-'.substr($hash, 8, 4).'-5'.substr($hash, 13, 3).'-a'.substr($hash, 17, 3).'-'.substr($hash, 20, 12);
    }

    private function json(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }

    private function user(?string $id): ?int
    {
        if (! preg_match('/^db-user-(\d+)$/D', $id ?? '', $m)) {
            return null;
        }

        return DB::table('users')->where('id_user', $m[1])->value('id_user');
    }

    private function dateTime(?string $value): ?string
    {
        return $value ? Carbon::parse($value)->utc()->format('Y-m-d H:i:s.u') : null;
    }

    private function status(string $status): string
    {
        return match ($status) {
            'Sah', 'Disetujui' => 'APPROVED', 'Diajukan' => 'SUBMITTED',
            'Dikembalikan' => 'RETURNED', 'Ditolak' => 'REJECTED', default => 'DRAFT',
        };
    }

    public function read(PrototypeWorkspace $workspace): array
    {
        Area3MigrationTarget::assertAllowed();
        abort_unless($workspace->relational_migrated_at, 503, 'Penyimpanan transaksi belum diaktifkan. Jalankan area3:activate-storage setelah migration.');
        $metadata = DB::table('workflow_metadata')->where('id_workspace', $workspace->id)->value('metadata');
        if ($metadata === null) {
            throw new RuntimeException('Metadata transaksi tidak ditemukan; arsip lama tidak digunakan sebagai pengganti.');
        }
        $state = json_decode($metadata, true, 512, JSON_THROW_ON_ERROR);
        foreach (self::RECORDS as $collection => [$table]) {
            $state[$collection] = DB::table($table)->where('workspace_id', $workspace->id)->orderBy('position')->get('document_data')
                ->map(fn ($r) => json_decode($r->document_data, true, 512, JSON_THROW_ON_ERROR))->all();
        }
        $state['events'] = DB::table('workflow_events')->where('workspace_id', $workspace->id)->orderBy('position')->get('event_data')
            ->map(fn ($r) => json_decode($r->event_data, true, 512, JSON_THROW_ON_ERROR))->all();

        return $state;
    }

    /** Caller holds the workspace lock and one transaction for records, revision and audit. */
    public function write(PrototypeWorkspace $workspace, array $state): void
    {
        Area3MigrationTarget::assertAllowed();
        if (DB::transactionLevel() === 0) {
            throw new RuntimeException('Penyimpanan transaksi memerlukan transaksi database dan kunci workspace.');
        }
        $w = $workspace->id;
        $key = fn ($type, $source) => $this->id($w, $type, $source);
        $domain = app(PrototypeDomain::class)->load($state);
        $projects = collect($state['projects'])->keyBy('id');
        $deals = collect($state['deals'])->keyBy('id');
        $spks = collect($state['spks'])->keyBy('id');
        $now = now()->utc()->format('Y-m-d H:i:s.u');
        $rule = DB::table('numbering_rules')->where('document_type', 'PP')->first();
        $ruleData = ['pattern' => $state['settings']['ppNumberFormat'], 'updated_at' => $now];
        if ($workspace->relational_migrated_at && $this->user($state['actorUserId'] ?? null)) {
            $ruleData['updated_by'] = $this->user($state['actorUserId']);
        }
        if ($rule) {
            if ($rule->pattern !== $ruleData['pattern']) {
                DB::table('numbering_rules')->where('id_numbering_rule', $rule->id_numbering_rule)->update($ruleData);
            }
            $ruleId = $rule->id_numbering_rule;
        } else {
            $ruleId = DB::table('numbering_rules')->insertGetId($ruleData + ['document_type' => 'PP', 'updated_by' => null]);
        }
        DB::table('number_sequences')->updateOrInsert(['rule_id' => $ruleId, 'scope_key' => 'GLOBAL'], ['next_value' => $state['settings']['nextPpSequence']]);

        // Insert parent PP headers before linking root/parent references (including historical ordering).
        foreach ($state['projects'] as $i => $p) {
            $id = $key('projects', $p['id']);
            $branch = DB::table('branches')->whereRaw('UPPER(name) = ?', [mb_strtoupper($p['branch'])])->first();
            if (! $branch) {
                throw new RuntimeException('Branch PP tidak ditemukan: '.$p['branch']);
            }
            $unitCode = $p['unit'] === 'Operation' ? 'OPERATION' : 'KONSTRUKSI';
            $unit = DB::table('work_units')->where('code', $unitCode)->value('id_work_unit');
            if (! $unit) {
                $unit = $unitCode === 'KONSTRUKSI' ? 1 : 2;
                DB::table('work_units')->insert(['id_work_unit' => $unit, 'code' => $unitCode, 'name' => $p['unit']]);
            }
            $projectId = DB::table('projects')->where('project_code', $p['projectCode'])->value('id_project');
            if (! $projectId) {
                $projectId = $key('project', mb_strtolower($p['projectCode']));
                DB::table('projects')->insert(['id_project' => $projectId, 'project_code' => $p['projectCode'], 'branch_id' => $branch->id_branch, 'work_unit_id' => $unit, 'name' => $p['name']]);
            }
            $existing = DB::table('project_plans')->where('id_pp', $id)->first();
            $number = $state['settings']['issuedPpNumbers'][$p['no']] ?? null;
            $this->record($workspace, 'projects', $p, $i, [
                'project_id' => $projectId, 'pp_no' => $p['no'],
                'usage_date' => $p['date'], 'budget' => $p['budget'],
                'pm_user_id' => $this->user($p['pmUserId'] ?? null),
                'manager_user_id' => $this->user($p['managerUserId'] ?? null),
                'introduction' => $p['intro'], 'risk_plan' => $p['risk'],
                'communication_plan' => $p['communication'], 'quality_plan' => $p['quality'],
                'status' => $this->status($p['status']), 'revision' => $workspace->revision,
                'workflow_version' => $p['workflowVersion'] ?? 1,
                'created_by' => $existing->created_by ?? $this->user($p['preparedBy']['userId'] ?? null),
                'created_at' => $existing->created_at ?? $this->dateTime($p['preparedBy']['at'] ?? $p['created'] ?? $p['date']),
                'updated_at' => $now,
            ] + ($number ? [
                'numbering_rule_id' => $ruleId, 'number_scope_key' => 'GLOBAL', 'number_sequence' => $number['sequence'],
                'number_pattern_snapshot' => $number['pattern'], 'kode_branch_snapshot' => $number['branch'],
                'number_period' => $number['period'], 'numbered_at' => $this->dateTime($number['at']),
            ] : []));
        }
        foreach ($state['projects'] as $p) {
            $id = $key('projects', $p['id']);
            DB::table('project_plans')->where('id_pp', $id)->update(['root_pp_id' => $key('projects', $p['rootId']), 'parent_pp_id' => empty($p['parentId']) ? null : $key('projects', $p['parentId'])]);
            $this->projectChildren($workspace, $p, $state);
            $this->approvals($workspace, $p, true);
        }
        foreach ($state['deals'] as $i => $d) {
            $spk = $spks->firstWhere('dealId', $d['id']);
            $this->record($workspace, 'deals', $d, $i, [
                'pp_id' => $key('projects', $d['projectId']), 'mandor_name' => $d['mandor'],
                'mandor_address' => $spk['address'] ?? '', 'bakh_no' => $d['no'] ?: null, 'spk_no' => ($spk['no'] ?? '') ?: null,
                'stage' => $spk ? match ($spk['status']) {
                    'Sah' => 'SPK_ATTESTED', 'Terbit' => 'SPK_ISSUED', default => 'SPK_DRAFT'
                } : match ($d['status']) {
                    'Sah' => 'BAKH_ATTESTED', 'Terbit' => 'BAKH_ISSUED', default => 'NEGOTIATION'
                },
                'start_date' => $d['start'] ?: null, 'end_date' => $d['end'] ?: null,
                'supervisor' => $spk['supervisor'] ?? '', 'supervisor_phone' => $spk['phone'] ?? '',
                'signer_name' => $spk['signer'] ?? $d['signer'] ?? '', 'signer_position' => $spk['position'] ?? '',
            ]);
            $p = $projects[$d['projectId']];
            foreach ($d['rows'] as $r) {
                foreach (['m', 's'] as $c) {
                    if (empty($r[$c]) || ($r[$c === 'm' ? 'qm' : 'qs'] ?? 0) <= 0) {
                        continue;
                    }
                    $component = $key('boq-component', $p['rootId'].'|'.$r['rowId'].'|'.$c);
                    DB::table('contract_components')->updateOrInsert(['id_contract_component' => $key('contract-component', $d['id'].'|'.$r['rowId'].'|'.$c)], [
                        'contract_id' => $key('deals', $d['id']), 'boq_component_id' => $component,
                        'quantity' => $r[$c === 'm' ? 'qm' : 'qs'], 'khs_ceiling_snapshot' => $r[$c === 'm' ? 'capM' : 'capS'],
                        'sph_price' => $r[$c === 'm' ? 'sphM' : 'sphS'], 'offered_price' => $r[$c === 'm' ? 'offerM' : 'offerS'], 'agreed_price' => $r[$c === 'm' ? 'pm' : 'ps'],
                    ]);
                }
            }
            $keep = collect($d['rows'])->flatMap(fn ($r) => collect(['m', 's'])->filter(fn ($c) => ! empty($r[$c]) && $r[$c === 'm' ? 'qm' : 'qs'] > 0)->map(fn ($c) => $key('contract-component', $d['id'].'|'.$r['rowId'].'|'.$c)))->all();
            DB::table('contract_components')->where('contract_id', $key('deals', $d['id']))->whereNotIn('id_contract_component', $keep)->delete();
        }
        foreach ($state['spks'] as $i => $spk) {
            $this->record($workspace, 'spks', $spk, $i, ['contract_id' => $key('deals', $spk['dealId']), 'spk_no' => $spk['no'], 'status' => $spk['status']]);
        }
        foreach ($state['terms'] as $i => $t) {
            $spk = $spks[$t['spkId']];
            $this->record($workspace, 'terms', $t, $i, [
                'contract_id' => $key('deals', $spk['dealId']), 'work_order_id' => $key('spks', $t['spkId']),
                'term_no' => $t['number'], 'term_type' => $t['type'] === 'Final' ? 'FINAL' : 'PARTIAL',
                'status' => $this->status($t['status']), 'workflow_version' => $t['workflowVersion'] ?? 1,
                'pm_user_id' => $this->user($t['pmUserId'] ?? null), 'manager_user_id' => $this->user($t['managerUserId'] ?? null),
                'approved_amount' => $t['status'] === 'Disetujui' ? $domain->termTotal($t) : null,
                'submitted_at' => $this->dateTime($t['submitted'] ?? null), 'revision' => $workspace->revision,
            ]);
            $actualComponents = [];
            foreach ($t['actuals'] as $row => $values) {
                foreach (['m', 's'] as $c) {
                    $component = $key('contract-component', $spk['dealId'].'|'.$row.'|'.$c);
                    if (DB::table('contract_components')->where('id_contract_component', $component)->exists()) {
                        $actualComponents[] = $component;
                        DB::table('term_actuals')->updateOrInsert(['term_id' => $key('terms', $t['id']), 'contract_component_id' => $component], ['actual_quantity' => $values[$c] ?? 0]);
                    }
                }
            }
            DB::table('term_actuals')->where('term_id', $key('terms', $t['id']))->whereNotIn('contract_component_id', $actualComponents)->delete();
            $this->approvals($workspace, $t, false);
            $this->paymentFiles($workspace, $t);
            $this->workEvidenceFiles($workspace, $t);
        }
        foreach ($state['bapps'] as $i => $b) {
            $this->record($workspace, 'bapps', $b, $i, ['final_term_id' => $key('terms', $b['termId']), 'document_no' => $b['no'], 'status' => $b['status'] === 'Sah' ? 'ATTESTED' : 'DRAFT', 'evidence_reference' => $b['evidence'] ?: null]);
        }
        foreach ($state['events'] as $i => $e) {
            DB::table('workflow_events')->updateOrInsert(['id_event' => $key('event', $e['id'])], ['workspace_id' => $w, 'source_id' => $e['id'], 'position' => $i, 'event_data' => $this->json($e)]);
        }
        // Small configuration/reference metadata only: never an alternate copy of transactions.
        DB::table('workflow_metadata')->updateOrInsert(['id_workspace' => $w], ['metadata' => $this->json(Arr::except($state, [...array_keys(self::RECORDS), 'events']))]);
    }

    private function record(PrototypeWorkspace $workspace, string $collection, array $document, int $position, array $columns): void
    {
        [$table, $pk] = self::RECORDS[$collection];
        DB::table($table)->updateOrInsert([$pk => $this->id($workspace->id, $collection, $document['id'])], $columns + [
            'workspace_id' => $workspace->id, 'source_id' => $document['id'], 'position' => $position, 'document_data' => $this->json($document),
        ]);
    }

    private function paymentFiles(PrototypeWorkspace $workspace, array $term): void
    {
        $termId = $this->id($workspace->id, 'terms', $term['id']);
        foreach ([...($term['scanHistory'] ?? []), ...(isset($term['scan']) ? [$term['scan']] : [])] as $i => $scan) {
            $fileId = $this->id($workspace->id, 'scan-file', $scan['id']);
            DB::table('document_files')->updateOrInsert(['id_document_file' => $fileId], [
                'storage_disk' => 'local', 'storage_key' => 'prototype-scans/'.$workspace->id.'/'.$term['id'].'/'.$scan['id'].'.'.$scan['extension'],
                'original_name' => $scan['name'], 'mime_type' => $scan['mime'], 'byte_size' => $scan['size'], 'sha256' => $scan['sha256'],
                'uploaded_by' => $this->user($scan['uploadedBy'] ?? null), 'uploaded_at' => $this->dateTime($scan['uploadedAt']),
            ]);
            $id = $this->id($workspace->id, 'scan', $scan['id']);
            DB::table('term_scans')->updateOrInsert(['id_term_scan' => $id], [
                'term_id' => $termId, 'file_id' => $fileId, 'version_no' => $i + 1,
                'status' => match ($scan['status']) {
                    'Valid' => 'VALID', 'Perlu Koreksi' => 'CORRECTION', default => 'WAITING'
                },
                'verified_by' => $this->user($scan['verifiedBy'] ?? null), 'verified_at' => $this->dateTime($scan['verifiedAt'] ?? null), 'note' => $scan['note'] ?? '',
            ]);
        }
        if (($term['workflowVersion'] ?? 1) >= 2 && $term['apmStatus'] === 'Valid') {
            $payment = collect($term['paymentHistory'])->first(fn ($p) => ($p['source'] ?? '') === 'APM' && $p['ref'] === $term['apm']);
            if (! $payment) {
                throw new RuntimeException('Riwayat pembayaran APM tidak ditemukan.');
            }
            $settlement = [
                'id_apm_settlement' => $this->id($workspace->id, 'apm', $term['id']), 'term_id' => $termId,
                'verified_scan_id' => $this->id($workspace->id, 'scan', $term['scan']['id']), 'apm_no' => $term['apm'],
                'amount' => $payment['amount'], 'recorded_by' => $this->user($payment['actor']), 'recorded_at' => $this->dateTime($payment['at']),
            ];
            if (! DB::table('apm_settlements')->where('id_apm_settlement', $settlement['id_apm_settlement'])->exists()) {
                DB::table('apm_settlements')->insert($settlement);
            }
        }
    }

    private function workEvidenceFiles(PrototypeWorkspace $workspace, array $term): void
    {
        foreach ($term['workEvidence'] ?? [] as $file) {
            $id = $this->id($workspace->id, 'term-evidence-file', $file['id']);
            DB::table('document_files')->updateOrInsert(['id_document_file' => $id], [
                'storage_disk' => 'local', 'storage_key' => 'work-evidence/'.$workspace->id.'/'.$term['id'].'/'.$file['id'].'.'.$file['extension'],
                'original_name' => $file['name'], 'mime_type' => $file['mime'], 'byte_size' => $file['size'], 'sha256' => $file['sha256'],
                'uploaded_by' => $this->user($file['uploadedBy']), 'uploaded_at' => $this->dateTime($file['uploadedAt']),
            ]);
            DB::table('term_work_evidence')->updateOrInsert(['id_term_evidence' => $this->id($workspace->id, 'term-evidence', $file['id'])], [
                'source_id' => $file['id'], 'term_id' => $this->id($workspace->id, 'terms', $term['id']), 'file_id' => $id,
                'uploaded_by' => $this->user($file['uploadedBy']), 'uploaded_at' => $this->dateTime($file['uploadedAt']),
                'removed_by' => $this->user($file['removedBy'] ?? null), 'removed_at' => $this->dateTime($file['removedAt'] ?? null),
            ]);
        }
    }

    private function projectChildren(PrototypeWorkspace $workspace, array $p, array $state): void
    {
        $key = fn ($type, $source) => $this->id($workspace->id, $type, $source);
        $pp = $key('projects', $p['id']);
        if (isset($p['boqUpload'])) {
            $source = $p['boqUpload'];
            DB::table('document_files')->updateOrInsert(['id_document_file' => $key('boq-file', $source['id'])], [
                'storage_disk' => 'local', 'storage_key' => 'prototype-boq/'.$workspace->id.'/'.$source['id'].'/source.xlsx',
                'original_name' => $source['name'], 'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'byte_size' => $source['size'], 'sha256' => $source['sha256'], 'uploaded_by' => $this->user('db-user-'.$source['uploadedBy']), 'uploaded_at' => $this->dateTime($source['uploadedAt']),
            ]);
        }
        DB::table('project_plan_team')->where('pp_id', $pp)->delete();
        foreach ($p['team'] as $row) {
            DB::table('project_plan_team')->insert(['pp_id' => $pp, 'user_id' => $this->user($row['userId'] ?? null), 'name_snapshot' => $row['name'], 'job_snapshot' => $row['job']]);
        }
        foreach ($p['schedule'] as $i => $row) {
            DB::table('project_plan_schedule')->updateOrInsert(['pp_id' => $pp, 'stage_no' => $i + 1], ['stage_name' => $row['name'], 'work_days' => $row['days'], 'start_date' => $row['start'] ?: null, 'end_date' => $row['end'] ?: null]);
        }
        foreach (['mandor' => 'MANDOR', 'material' => 'NONSTOCK', 'bop' => 'BOP', 'permit' => 'PERMIT'] as $field => $type) {
            DB::table('project_plan_costs')->updateOrInsert(['pp_id' => $pp, 'cost_type' => $type], ['amount' => $p['costs'][$field]]);
        }
        DB::table('project_plan_budget_rows')->where('pp_id', $pp)->delete();
        foreach (['mandor', 'materials', 'bop', 'usage'] as $category) {
            foreach ($p['planBudget'][$category] ?? [] as $i => $row) {
                DB::table('project_plan_budget_rows')->insert(['id_budget_row' => $key('budget', $p['id'].'|'.$category.'|'.$i), 'pp_id' => $pp, 'category' => $category, 'position' => $i, 'row_data' => $this->json($row)]);
            }
        }
        if (! empty($p['parentId'])) {
            return;
        }
        $domain = app(PrototypeDomain::class)->load($state);
        $keep = [];
        foreach ($p['boq'] as $row) {
            $boq = $key('boq', $p['id'].'|'.$row['id']);
            $keep[] = $boq;
            $k = collect($state['khs'])->firstWhere('id', $row['khsId']);
            $source = preg_match('/^db-price-(\d+)$/D', $row['khsId'], $m) ? (int) $m[1] : null;
            DB::table('boq_lines')->updateOrInsert(['id_boq' => $boq], ['root_pp_id' => $pp, 'khs_price_id' => null, 'source_price_id' => $source, 'source_row_id' => $row['id'], 'source_data' => $this->json($row), 'designator_code_snapshot' => $k['code'], 'description_snapshot' => $k['name'], 'unit_snapshot' => $k['unit']]);
            foreach (['m', 's'] as $c) {
                if ($row[$c] === null) {
                    continue;
                }
                $id = $key('boq-component', $p['id'].'|'.$row['id'].'|'.$c);
                DB::table('boq_components')->updateOrInsert(['id_boq_component' => $id], ['boq_line_id' => $boq, 'component' => strtoupper($c), 'quantity' => $row['qty'], 'official_unit_price' => $row[$c]]);
                $ledger = $domain->ledger($p['id'], $row['id'], $c);
                DB::table('volume_balances')->updateOrInsert(['boq_component_id' => $id], ['original_quantity' => $ledger['total'], 'available' => $ledger['available'], 'reserved' => $ledger['reserved'], 'committed' => $ledger['committed'], 'accepted' => $ledger['accepted'], 'revision' => $workspace->revision]);
            }
        }
        $removed = DB::table('boq_lines')->where('root_pp_id', $pp)->whereNotIn('id_boq', $keep)->pluck('id_boq');
        $components = DB::table('boq_components')->whereIn('boq_line_id', $removed)->pluck('id_boq_component');
        DB::table('volume_balances')->whereIn('boq_component_id', $components)->delete();
        DB::table('boq_components')->whereIn('id_boq_component', $components)->delete();
        DB::table('boq_lines')->whereIn('id_boq', $removed)->delete();
    }

    private function approvals(PrototypeWorkspace $workspace, array $document, bool $project): void
    {
        if (($document['workflowVersion'] ?? 1) < 2) {
            return; // Legacy identity-less decisions remain verbatim in the document record.
        }
        $roles = ($document['workflowVersion'] ?? 1) >= 3 ? PrototypeDomain::APPROVERS_V3 : ['PM Konstruksi', 'Manager Konstruksi'];
        $history = $project ? ($document['approvalHistory'] ?? $document['approvals'] ?? []) : ($document['history'] ?? $document['approvals'] ?? []);
        $cycles = [[]];
        foreach ($history as $decision) {
            $cycles[array_key_last($cycles)][] = $decision;
            if ($decision['decision'] !== 'Setujui') {
                $cycles[] = [];
            }
        }
        foreach ($cycles as $i => $decisions) {
            if (! $decisions && ! in_array($document['status'], ['Diajukan', 'Sah', 'Disetujui'], true)) {
                continue;
            }
            $run = $this->id($workspace->id, 'approval-run', ($project ? 'PP' : 'TERM').'|'.$document['id'].'|'.$i);
            $last = $decisions ? $decisions[array_key_last($decisions)] : null;
            $status = match ($last['decision'] ?? '') {
                'Kembalikan' => 'RETURNED', 'Tolak' => 'REJECTED', default => count($decisions) === count($roles) ? 'APPROVED' : 'PENDING'
            };
            DB::table('approval_runs')->updateOrInsert(['id_approval_run' => $run], ['pp_id' => $project ? $this->id($workspace->id, 'projects', $document['id']) : null, 'term_id' => $project ? null : $this->id($workspace->id, 'terms', $document['id']), 'cycle_no' => $i + 1, 'status' => $status, 'submitted_by' => $this->user($document['preparedBy']['userId'] ?? null), 'submitted_at' => $this->dateTime($document['submitted'] ?? null), 'workflow_version' => $document['workflowVersion']]);
            foreach ($roles as $step => $role) {
                $decision = collect($decisions)->firstWhere('role', $role);
                $selected = $decision['userId'] ?? (($document['workflowVersion'] ?? 1) >= 3 ? ($document['approverUserIds'][$role] ?? null) : ($document[$step === 0 ? 'pmUserId' : 'managerUserId'] ?? null));
                $user = $this->user($selected);
                $id = $this->id($workspace->id, 'approval-step', $run.'|'.$step);
                DB::table('approval_steps')->updateOrInsert(['id_approval_step' => $id], ['run_id' => $run, 'step_no' => $step + 1, 'expected_role' => array_search($role, AccountAccess::ROLES, true), 'assigned_user_id' => $user, 'assigned_name_snapshot' => $decision['actorName'] ?? ($user ? DB::table('users')->where('id_user', $user)->value('name') : ''), 'workflow_version' => $document['workflowVersion']]);
                if ($decision) {
                    $data = ['step_id' => $id, 'actor_user_id' => $this->user($decision['userId'] ?? null), 'actor_name_snapshot' => $decision['actorName'] ?? '', 'decision' => match ($decision['decision']) {
                        'Setujui' => 'APPROVE', 'Kembalikan' => 'RETURN', 'Tolak' => 'REJECT'
                    }, 'note' => $decision['note'], 'decided_at' => $this->dateTime($decision['at']), 'decision_data' => $this->json($decision)];
                    $decisionId = $this->id($workspace->id, 'approval-decision', $id);
                    $saved = DB::table('approval_decisions')->where('id_approval_decision', $decisionId)->first();
                    if ($saved && $saved->decision_data !== $data['decision_data']) {
                        // JSON serialization may differ across drivers; compare values.
                        if (json_decode($saved->decision_data, true) !== $decision) {
                            throw new RuntimeException('Riwayat keputusan approval tidak boleh ditimpa.');
                        }
                    } elseif (! $saved) {
                        DB::table('approval_decisions')->insert(['id_approval_decision' => $decisionId] + $data);
                    }
                }
            }
        }
    }

    public function activate(PrototypeWorkspace $workspace): bool
    {
        return DB::transaction(function () use ($workspace) {
            $row = PrototypeWorkspace::whereKey($workspace->id)->lockForUpdate()->firstOrFail();
            if ($row->relational_migrated_at) {
                return false;
            }
            $state = PrototypeDomain::upgradeState($row->state);
            $this->write($row, $state);
            $row->relational_migrated_at = now();
            $row->save();
            if ($this->read($row) != $state) {
                throw new RuntimeException('Verifikasi pemindahan transaksi gagal; seluruh perubahan dibatalkan.');
            }

            return true;
        }, 3);
    }
}
