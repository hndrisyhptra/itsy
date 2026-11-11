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
    private array $userCache = [];

    private array $khsIndex = [];

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

        return $this->userCache[$id] ??= DB::table('users')->where('id_user', $m[1])->value('id_user');
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

    public function read(PrototypeWorkspace $workspace, ?array $branchIds = null): array
    {
        Area3MigrationTarget::assertAllowed();
        abort_unless($workspace->relational_migrated_at, 503, 'Penyimpanan transaksi belum diaktifkan. Jalankan area3:activate-storage setelah migration.');
        $metadata = DB::table('workflow_metadata')->where('id_workspace', $workspace->id)->value('metadata');
        if ($metadata === null) {
            throw new RuntimeException('Metadata transaksi tidak ditemukan; arsip lama tidak digunakan sebagai pengganti.');
        }
        $state = json_decode($metadata, true, 512, JSON_THROW_ON_ERROR);
        $allowed = [];
        foreach (self::RECORDS as $collection => [$table, $pk]) {
            $query = DB::table($table)->where('workspace_id', $workspace->id)->orderBy('position');
            if ($branchIds !== null) {
                match ($collection) {
                    'projects' => $query->whereIn('branch_id', $branchIds),
                    'deals' => $query->whereIn('pp_id', $allowed['projects']),
                    'spks' => $query->whereIn('contract_id', $allowed['deals']),
                    'terms' => $query->whereIn('work_order_id', $allowed['spks']),
                    'bapps' => $query->whereIn('final_term_id', $allowed['terms']),
                };
            }
            $rows = $query->get([$pk, 'document_data']);
            $allowed[$collection] = $rows->pluck($pk)->all();
            $state[$collection] = $rows->map(fn ($r) => json_decode($r->document_data, true, 512, JSON_THROW_ON_ERROR))->all();
        }
        $state['events'] = DB::table('workflow_events')->where('workspace_id', $workspace->id)->orderBy('position')->get('event_data')
            ->map(fn ($r) => json_decode($r->event_data, true, 512, JSON_THROW_ON_ERROR))->all();

        return $state;
    }

    /** Caller holds the workspace lock and one transaction for records, revision and audit. */
    public function write(PrototypeWorkspace $workspace, array $state, ?array $previous = null): void
    {
        Area3MigrationTarget::assertAllowed();
        if (DB::transactionLevel() === 0) {
            throw new RuntimeException('Penyimpanan transaksi memerlukan transaksi database dan kunci workspace.');
        }
        $this->userCache = [];
        $this->khsIndex = array_column($state['khs'], null, 'id');
        $changed = [];
        foreach (self::RECORDS as $collection => $_) {
            $old = array_column($previous[$collection] ?? [], null, 'id');
            foreach ($state[$collection] as $document) {
                if (! isset($old[$document['id']]) || $old[$document['id']] != $document) {
                    $changed[$collection][$document['id']] = true;
                }
            }
        }
        // Contract SQL projections also contain SPK fields even when deal data is unchanged.
        foreach ($state['spks'] as $spk) {
            if (isset($changed['spks'][$spk['id']])) {
                $changed['deals'][$spk['dealId']] = true;
            }
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
            if (! isset($changed['projects'][$p['id']])) {
                continue;
            }
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
                'project_id' => $projectId, 'branch_id' => $branch->id_branch, 'pp_no' => $p['no'],
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
            if (! isset($changed['projects'][$p['id']])) {
                continue;
            }
            $id = $key('projects', $p['id']);
            DB::table('project_plans')->where('id_pp', $id)->update(['root_pp_id' => $key('projects', $p['rootId']), 'parent_pp_id' => empty($p['parentId']) ? null : $key('projects', $p['parentId'])]);
            $prior = collect($previous['projects'] ?? [])->firstWhere('id', $p['id']);
            $planning = ['boqUpload', 'team', 'schedule', 'costs', 'planBudget', 'boq', 'parentId', 'rootId'];
            if (! $prior || Arr::only($prior, $planning) != Arr::only($p, $planning)) {
                $this->projectChildren($workspace, $p, $state);
            }
            $this->approvals($workspace, $p, true);
        }
        foreach ($state['deals'] as $i => $d) {
            if (! isset($changed['deals'][$d['id']])) {
                continue;
            }
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
                'signer_name' => $spk['signer'] ?? $d['signer'] ?? '', 'signer_position' => $spk['position'] ?? $d['signerRole'] ?? '',
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
            if (! isset($changed['spks'][$spk['id']])) {
                continue;
            }
            $this->record($workspace, 'spks', $spk, $i, ['contract_id' => $key('deals', $spk['dealId']), 'spk_no' => $spk['no'], 'status' => $spk['status']]);
        }
        $previousTerms = array_column($previous['terms'] ?? [], null, 'id');
        foreach ($state['terms'] as $i => $t) {
            if (! isset($changed['terms'][$t['id']])) {
                continue;
            }
            $spk = $spks[$t['spkId']];
            $this->record($workspace, 'terms', $t, $i, [
                'contract_id' => $key('deals', $spk['dealId']), 'work_order_id' => $key('spks', $t['spkId']),
                'term_no' => $t['number'], 'term_type' => $t['type'] === 'Final' ? 'FINAL' : 'PARTIAL',
                'status' => $this->status($t['status']), 'workflow_version' => $t['workflowVersion'] ?? 1,
                'pm_user_id' => $this->user($t['pmUserId'] ?? null), 'manager_user_id' => $this->user($t['managerUserId'] ?? null),
                'approved_amount' => $t['status'] === 'Disetujui' ? $domain->termTotal($t) : null,
                'submitted_at' => $this->dateTime($t['submitted'] ?? null), 'revision' => $workspace->revision,
            ]);
            $oldTerm = $previousTerms[$t['id']] ?? null;
            if (! $oldTerm || $oldTerm['actuals'] !== $t['actuals']) {
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
            }
            $approvalFields = ['status', 'workflowVersion', 'approvalScheme', 'approverUserIds', 'pmUserId', 'managerUserId', 'approvals', 'history', 'submitted', 'preparedBy'];
            if (! $oldTerm || Arr::only($oldTerm, $approvalFields) != Arr::only($t, $approvalFields)) {
                $this->approvals($workspace, $t, false);
            }
            $this->partialPaymentRequests($workspace, $t, $oldTerm);
            $fileFields = ['scan', 'scanHistory', 'apmStatus', 'apm', 'paymentHistory'];
            if (! $oldTerm || Arr::only($oldTerm, $fileFields) != Arr::only($t, $fileFields)) {
                $this->paymentFiles($workspace, $t);
            }
            if (! $oldTerm || ($oldTerm['workEvidence'] ?? []) != ($t['workEvidence'] ?? [])) {
                $this->workEvidenceFiles($workspace, $t);
            }
        }
        foreach ($state['bapps'] as $i => $b) {
            if (! isset($changed['bapps'][$b['id']])) {
                continue;
            }
            $this->record($workspace, 'bapps', $b, $i, ['final_term_id' => $key('terms', $b['termId']), 'document_no' => $b['no'], 'status' => $b['status'] === 'Sah' ? 'ATTESTED' : 'DRAFT', 'evidence_reference' => ($b['evidence'] ?? '') ?: null]);
        }
        $this->refreshBalances($workspace, $state, $changed);
        $events = [];
        foreach ($state['events'] as $i => $e) {
            $events[] = ['id_event' => $key('event', $e['id']), 'workspace_id' => $w, 'source_id' => $e['id'], 'position' => $i, 'event_data' => $this->json($e)];
        }
        foreach (array_chunk($events, 100) as $chunk) {
            DB::table('workflow_events')->upsert($chunk, ['id_event'], ['position', 'event_data']);
        }
        // Small configuration/reference metadata only: never an alternate copy of transactions.
        DB::table('workflow_metadata')->updateOrInsert(['id_workspace' => $w], ['metadata' => $this->json(Arr::except($state, [...array_keys(self::RECORDS), 'events']))]);
    }

    /** Recalculate only roots touched by this command, batch-write actual balance changes. */
    private function refreshBalances(PrototypeWorkspace $workspace, array $state, array $changed): void
    {
        $projects = array_column($state['projects'], null, 'id');
        $deals = array_column($state['deals'], null, 'id');
        $spks = array_column($state['spks'], null, 'id');
        $terms = array_column($state['terms'], null, 'id');
        $roots = [];
        foreach (self::RECORDS as $collection => $_) {
            foreach ($state[$collection] as $document) {
                if (! isset($changed[$collection][$document['id']])) {
                    continue;
                }
                $projectId = match ($collection) {
                    'projects' => $document['id'], 'deals' => $document['projectId'],
                    'spks' => $deals[$document['dealId']]['projectId'],
                    'terms' => $deals[$spks[$document['spkId']]['dealId']]['projectId'],
                    'bapps' => $deals[$spks[$terms[$document['termId']]['spkId']]['dealId']]['projectId'],
                };
                $roots[$projects[$projectId]['rootId']] = true;
            }
        }
        if (! $roots) {
            return;
        }
        $domain = app(PrototypeDomain::class)->load($state);
        $balances = DB::table('volume_balances')->join('boq_components as c', 'c.id_boq_component', '=', 'volume_balances.boq_component_id')
            ->join('boq_lines as b', 'b.id_boq', '=', 'c.boq_line_id')->whereIn('b.root_pp_id', array_map(fn ($id) => $this->id($workspace->id, 'projects', $id), array_keys($roots)))
            ->get('volume_balances.*')->keyBy('boq_component_id');
        $updates = [];
        foreach (array_keys($roots) as $root) {
            foreach ($projects[$root]['boq'] as $row) {
                foreach (['m', 's'] as $c) {
                    if ($row[$c] === null) {
                        continue;
                    }
                    $id = $this->id($workspace->id, 'boq-component', $root.'|'.$row['id'].'|'.$c);
                    $ledger = $domain->ledger($root, $row['id'], $c);
                    $values = ['original_quantity' => $ledger['total'], 'available' => $ledger['available'], 'reserved' => $ledger['reserved'], 'committed' => $ledger['committed'], 'accepted' => $ledger['accepted']];
                    $old = $balances->get($id);
                    if ($old && ! array_filter($values, fn ($v, $key) => bccomp((string) $v, (string) $old->$key, 3) !== 0, ARRAY_FILTER_USE_BOTH)) {
                        continue;
                    }
                    $updates[] = $values + ['boq_component_id' => $id, 'revision' => $workspace->revision];
                }
            }
        }
        foreach (array_chunk($updates, 100) as $chunk) {
            DB::table('volume_balances')->upsert($chunk, ['boq_component_id'], ['original_quantity', 'available', 'reserved', 'committed', 'accepted', 'revision']);
        }
    }

    /** Master edits share the workspace lock but must not rewrite business snapshots. */
    public function writeReferenceMetadata(PrototypeWorkspace $workspace, array $state): void
    {
        Area3MigrationTarget::assertAllowed();
        if (DB::transactionLevel() === 0 || ! $workspace->relational_migrated_at) {
            throw new RuntimeException('Pembaruan master memerlukan transaksi dan penyimpanan aktif.');
        }
        DB::table('workflow_metadata')->where('id_workspace', $workspace->id)->update([
            'metadata' => $this->json(Arr::except($state, [...array_keys(self::RECORDS), 'events'])),
        ]);
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
                'verified_scan_id' => (PrototypeDomain::usesPartialPayment($term) || PrototypeDomain::usesFinancePayment($term)) ? null : $this->id($workspace->id, 'scan', $term['scan']['id']),
                'payment_request_id' => PrototypeDomain::usesPartialPayment($term) ? $this->id($workspace->id, 'payment-request', $term['paymentRequest']['id']) : null,
                'kasbon_id' => PrototypeDomain::usesFinancePayment($term) ? $term['kasbon']['id'] : null,
                'transfer_file_id' => PrototypeDomain::usesFinancePayment($term) ? $term['transferProof']['id'] : null,
                'apm_no' => $term['apm'],
                'amount' => $payment['amount'], 'recorded_by' => $this->user($payment['actor']), 'recorded_at' => $this->dateTime($payment['at']),
            ];
            if (! DB::table('apm_settlements')->where('id_apm_settlement', $settlement['id_apm_settlement'])->exists()) {
                DB::table('apm_settlements')->insert($settlement);
            }
        }
    }

    private function partialPaymentRequests(PrototypeWorkspace $workspace, array $term, ?array $previous): void
    {
        if (! PrototypeDomain::usesPartialPayment($term)) {
            return;
        }
        $before = array_column([...($previous['paymentRequestHistory'] ?? []), ...(isset($previous['paymentRequest']) ? [$previous['paymentRequest']] : [])], null, 'id');
        foreach ([...($term['paymentRequestHistory'] ?? []), $term['paymentRequest']] as $request) {
            if (($before[$request['id']] ?? null) == $request) {
                continue;
            }
            DB::table('partial_payment_requests')->updateOrInsert(['id_payment_request' => $this->id($workspace->id, 'payment-request', $request['id'])], [
                'term_id' => $this->id($workspace->id, 'terms', $term['id']), 'cycle_no' => $request['cycle'],
                'status' => ['Diajukan' => 'WAITING', 'Diverifikasi' => 'VERIFIED', 'Dikembalikan' => 'RETURNED', 'Dibayar' => 'PAID'][$request['status']],
                'amount' => $request['amount'], 'requested_by' => $this->user($request['requestedBy']), 'requested_at' => $this->dateTime($request['requestedAt']),
                'documents_at_request' => $request['documentsAtRequest'], 'documents_at_payment' => $request['documentsAtPayment'] ?? null,
                'finance_user_id' => $this->user($request['decision']['userId'] ?? null), 'finance_decided_at' => $this->dateTime($request['decision']['at'] ?? null), 'finance_note' => $request['decision']['note'] ?? null,
                'request_data' => $this->json($request),
            ]);
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
            $k = $this->khsIndex[$row['khsId']];
            $source = preg_match('/^db-price-(\d+)$/D', $row['khsId'], $m) ? (int) $m[1] : null;
            DB::table('boq_lines')->updateOrInsert(['id_boq' => $boq], ['root_pp_id' => $pp, 'khs_price_id' => null, 'source_price_id' => $source, 'source_row_id' => $row['id'], 'source_data' => $this->json($row), 'designator_code_snapshot' => $k['code'], 'description_snapshot' => $k['name'], 'unit_snapshot' => $k['unit']]);
            foreach (['m', 's'] as $c) {
                if ($row[$c] === null) {
                    continue;
                }
                $id = $key('boq-component', $p['id'].'|'.$row['id'].'|'.$c);
                DB::table('boq_components')->updateOrInsert(['id_boq_component' => $id], ['boq_line_id' => $boq, 'component' => strtoupper($c), 'quantity' => $row['qty'], 'official_unit_price' => $row[$c]]);

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
        $roles = PrototypeDomain::storedApprovalRoles($document, $project);
        $cycles = ProjectHeadReopen::cycles($document, $project);
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
                DB::table('approval_steps')->updateOrInsert(['id_approval_step' => $id], ['run_id' => $run, 'step_no' => $step + 1, 'expected_role' => array_search($role, AccountAccess::ROLES, true), 'assigned_user_id' => $user, 'assigned_name_snapshot' => $decision['actorName'] ?? ($user ? DB::table('users')->where('id_user', $user)->value('name') : ''), 'workflow_version' => $document['workflowVersion'], 'approval_order_version' => ($document['approvalScheme'] ?? '') === 'project-support-first' ? 2 : 1]);
                if ($decision) {
                    $data = ['step_id' => $id, 'actor_user_id' => $this->user($decision['userId'] ?? null), 'actor_name_snapshot' => $decision['actorName'] ?? '', 'decision' => match ($decision['decision']) {
                        'Setujui' => 'APPROVE', 'Kembalikan' => 'RETURN', 'Tolak' => 'REJECT'
                    }, 'note' => $decision['note'], 'decided_at' => $this->dateTime($decision['at']), 'decision_data' => $this->json($decision)];
                    $decisionId = $this->id($workspace->id, 'approval-decision', $id);
                    $saved = DB::table('approval_decisions')->where('id_approval_decision', $decisionId)->first();
                    if ($saved && $saved->decision_data !== $data['decision_data']) {
                        // JSON serialization may differ across drivers; compare values.
                        if (Arr::sortRecursive(json_decode($saved->decision_data, true)) !== Arr::sortRecursive($decision)) {
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
