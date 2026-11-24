<?php

namespace App\Services;

use App\Models\PrototypeWorkspace;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PrototypeWorkspaceService
{
    public const SCENARIOS = ['overview', 'A', 'B', 'C', 'D', 'E'];

    public function scenario(string $name): array
    {
        if (! in_array($name, self::SCENARIOS, true)) {
            throw ValidationException::withMessages(['scenario' => 'Skenario tidak dikenal.']);
        }

        $state = PrototypeDomain::upgradeState(json_decode(file_get_contents(database_path('demo/'.$name.'.json')), true, 512, JSON_THROW_ON_ERROR));
        // These are explicitly synthetic assignee choices for demo checkpoints only.
        foreach ($state['projects'] as &$project) {
            $project['pmUserId'] = 'user-1';
            $project['managerUserId'] = 'user-2';
            if ($name === 'A' && $project['id'] === 'pp-1') {
                $project['status'] = 'Draft';
                $project['unit'] = 'Konstruksi';
            }
        }
        unset($project);
        foreach ($state['terms'] as &$term) {
            if (in_array($term['status'], ['Draft', 'Diajukan', 'Dikembalikan'], true) && count($term['approvals']) < 2) {
                $term['workflowVersion'] = 2;
                $term['pmUserId'] = 'user-1';
                $term['managerUserId'] = 'user-2';
            }
        }
        unset($term);

        return $state;
    }

    public function workspace(Request $request): PrototypeWorkspace
    {
        app(AuthenticatedWorkspace::class)->account();
        $key = hash('sha256', 'area3-authenticated-workflow-v1');

        $existing = PrototypeWorkspace::runtimeQuery()->where('owner_key', $key)->first();
        if ($existing) {
            return $existing;
        }
        $state = array_fill_keys(['projects', 'deals', 'spks', 'terms', 'bapps', 'events', 'khs', 'users', 'branches'], []);
        $state += ['schema' => 3, 'role' => 'Admin Konstruksi', 'profile' => ['name' => '', 'email' => ''],
            'settings' => ['ppNumberFormat' => PrototypeDomain::DEFAULT_PP_NUMBER_FORMAT, 'nextPpSequence' => 1]];
        $state['generation'] = 'accounts-'.Str::uuid();
        $state['scenario'] = 'Ruang kerja akun';
        $state['lastSaved'] = now()->toISOString();

        return DB::transaction(function () use ($key, $state) {
            $workspace = PrototypeWorkspace::firstOrCreate(['owner_key' => $key], ['state' => app(AuthenticatedWorkspace::class)->bind($state), 'revision' => 0]);
            app(RelationalWorkflowStore::class)->activate($workspace);

            return $workspace->fresh();
        });
    }

    public function payload(PrototypeWorkspace $workspace, ?array $state = null): array
    {
        $account = app(AuthenticatedWorkspace::class)->account();

        return ['state' => app(AuthenticatedWorkspace::class)->visible($state ?? app(RelationalWorkflowStore::class)->read($workspace, $account['branchIds'], $account['workUnits'], $account), $state === null), 'revision' => $workspace->revision, 'csrf' => csrf_token()];
    }

    /** Only an absent root Draft can be added to the current locked workspace. */
    private function canCreateDraftAtCurrentRevision(array $before, array $input, int $currentRevision): bool
    {
        if (($before['schema'] ?? 1) < 3 || $input['revision'] >= $currentRevision
            || ! in_array($input['command'], ['saveProject', 'saveProjectBundle'], true)
            || ($input['args'][1] ?? false) !== false) {
            return false;
        }
        $p = $input['args'][0] ?? null;

        return is_array($p) && is_string($p['id'] ?? null) && $p['id'] === ($p['rootId'] ?? null)
            && empty($p['parentId']) && empty($p['sourceBapp']) && empty($p['allocations'])
            && ! collect($before['projects'])->contains('id', $p['id']);
    }

    public function command(PrototypeWorkspace $workspace, array $input, bool $includePayload = true): array
    {
        return DB::transaction(function () use ($workspace, $input, $includePayload) {
            $row = PrototypeWorkspace::runtimeQuery()->whereKey($workspace->id)->lockForUpdate()->firstOrFail();
            $access = app(AuthenticatedWorkspace::class);
            $before = $access->bind(app(RelationalWorkflowStore::class)->read($row));
            $access->authorize($before, $input['command'], $input['args']);
            $isCancel = $input['command'] === 'cancelProject';
            $isReset = in_array($input['command'], ['resetProjectApproval', 'resetPaymentApproval'], true);
            $cancelHash = $isCancel ? hash('sha256', json_encode(Arr::sortRecursive(array_slice($input['args'], 0, 3)), JSON_THROW_ON_ERROR)) : null;
            $resetHash = $isReset ? hash('sha256', json_encode(Arr::sortRecursive(['command' => $input['command'], 'args' => array_slice($input['args'], 0, 3)]), JSON_THROW_ON_ERROR)) : null;
            $replay = DB::table('prototype_audits')->where('workspace_id', $row->id)->where('request_id', $input['request_id'])->first();
            if ($replay) {
                abort_unless($replay->actor_user_id === auth()->id() && $replay->command === $input['command'], 409, 'ID permintaan sudah digunakan.');
                app(BudgetPidAllocation::class)->replay($input, (int) auth()->id());

                if ($isCancel) {
                    $document = collect($before['projects'])->firstWhere('id', $input['args'][0] ?? '');
                    $marker = $document['cancellation'] ?? [];
                    abort_unless(($marker['requestId'] ?? '') === $input['request_id'] && hash_equals($marker['payloadHash'] ?? '', $cancelHash) && ($marker['actorUserId'] ?? '') === $before['actorUserId'] && ($marker['role'] ?? '') === $before['role'], 409, 'UUID Cancel sudah digunakan untuk isi atau akun lain.');
                }
                if ($isReset) {
                    $documents = $before[$input['command'] === 'resetProjectApproval' ? 'projects' : 'terms'];
                    $document = collect($documents)->firstWhere('id', $input['args'][0] ?? '');
                    $marker = collect($document['approvalResets'] ?? [])->firstWhere('requestId', $input['request_id']);
                    abort_unless($marker && hash_equals($marker['payloadHash'] ?? '', $resetHash) && $marker['actorUserId'] === $before['actorUserId'] && $marker['role'] === $before['role'], 409, 'UUID pembatalan telah dipakai untuk isi atau akun lain.');
                }

                return ($includePayload ? $this->payload($row) : []) + ['replayed' => true];
            }
            // Creating a genuinely new Draft cannot overwrite an existing document.
            // All numbering, catalog, BOQ, scope and PID guards still run on $before
            // under this lock. Existing edits/approvals retain strict revision checks.
            if ($row->revision !== $input['revision'] && ! $this->canCreateDraftAtCurrentRevision($before, $input, $row->revision)) {
                throw new HttpResponseException(response()->json($this->payload($row) + ['message' => 'Data berubah di tab lain. Muat ulang data terbaru; perubahan tidak ditimpa.'], 409));
            }
            $budgetPayloadHash = BudgetPidAllocation::hash($input);
            if ($isCancel) {
                $document = collect($before['projects'])->firstWhere('id', $input['args'][0] ?? '');
                abort_unless($document, 404);
                $plan = ProjectCancellation::plan($before, $document);
                abort_unless($plan['own'], 403, 'Akun tidak berwenang Cancel paket unit ini.');
                abort_if($plan['reason'] !== '', 422, $plan['reason']);
                abort_if(DB::table('upload_tasks')->where('workspace_id', $row->id)->whereIn('document_id', $plan['documentIds'])->whereIn('status', ['queued', 'processing'])->exists(), 422, 'Tunggu seluruh upload paket selesai sebelum Cancel.');
                $input['args'] = [...array_slice($input['args'], 0, 3), ['requestId' => $input['request_id'], 'payloadHash' => $cancelHash]];
            }
            if ($isReset) {
                $projectReset = $input['command'] === 'resetProjectApproval';
                $document = collect($before[$projectReset ? 'projects' : 'terms'])->firstWhere('id', $input['args'][0] ?? '');
                abort_unless($document, 404);
                $resetPlan = ApprovalReset::plan($before, $document, $projectReset);
                abort_unless($resetPlan['own'], 403, 'Hanya pemilik persetujuan aktif yang dapat membatalkannya.');
                abort_if($resetPlan['reason'] !== '', 422, $resetPlan['reason']);
                abort_if(DB::table('upload_tasks')->where('workspace_id', $row->id)->whereIn('document_id', $resetPlan['documentIds'])->whereIn('status', ['queued', 'processing'])->exists(), 422, 'Tunggu upload terkait selesai sebelum membatalkan persetujuan.');
                if (! $projectReset) {
                    $termSql = app(RelationalWorkflowStore::class)->id($row->id, 'terms', $document['id']);
                    abort_if(DB::table('apm_settlements')->where('term_id', $termSql)->exists(), 422, 'Realisasi pembayaran sudah tersimpan; pembatalan ditahan.');
                    abort_if(DB::table('finance_private_files')->where('workspace_id', $row->id)->where('term_id', $termSql)->where('kind', 'transfer-proof')->exists(), 422, 'Bukti transfer sudah tersimpan; pembatalan ditahan.');
                }
                $input['args'] = [...array_slice($input['args'], 0, 3), ['requestId' => $input['request_id'], 'payloadHash' => $resetHash]];
            }
            if ($input['command'] === 'routeLegacyFinance') {
                $ids = array_column($input['args'][0], 'id');
                abort_if(DB::table('upload_tasks')->where('workspace_id', $row->id)->whereIn('document_id', $ids)->where('kind', 'transfer-proof')->whereIn('status', ['queued', 'processing'])->exists(), 422, 'Tunggu antrean bukti transfer selesai sebelum memindahkan pengajuan.');
            }
            if ($input['command'] === 'submitProjectBundle') {
                $project = collect($before['projects'])->firstWhere('id', $input['args'][0] ?? '');
                abort_unless($project && PrototypeDomain::usesContractBundle($project), 422, 'Paket PP tidak ditemukan.');
                $dealIds = collect($before['deals'])->where('projectId', $project['id'])->pluck('id')->all();
                foreach (collect($before['spks'])->whereIn('dealId', $dealIds) as $spk) {
                    app(FinanceDocuments::class)->requirePayee($row, $spk['id']);
                }
                $input['args'][1] = ! empty($project['boqUpload']['id']) ? app(BoqImportStorage::class)->read($row, $project['boqUpload']['id'], $project) : null;
                abort_if(DB::table('upload_tasks')->where('workspace_id', $row->id)->whereIn('document_id', [$project['id'], ...collect($before['spks'])->whereIn('dealId', $dealIds)->pluck('id')->all()])->whereIn('status', ['queued', 'processing'])->exists(), 422, 'Tunggu seluruh upload paket selesai.');
            }
            if (in_array($input['command'], ['saveProject', 'saveProjectBundle'], true)) {
                // Never trust client provenance, comparison prices or an arbitrary file path.
                unset($input['args'][0]['_boqImport']);
                $saved = collect($before['projects'])->firstWhere('id', $input['args'][0]['id'] ?? '');
                $input['args'][0] = BudgetPidAllocation::pin($input['args'][0], $saved);
                if (($before['schema'] ?? 1) >= 3 && (! $saved || (($saved['workflowVersion'] ?? 1) >= 3 && in_array($saved['status'], ['Draft', 'Dikembalikan', 'Ditolak'], true)))) {
                    $input['args'][0] = app(BudgetPidReference::class)->prepare($input['args'][0], $saved, $before['branches']);
                } else {
                    unset($input['args'][0]['budgetPidSelection'], $input['args'][0]['_budgetPidReference']);
                }
                if (! empty($input['args'][0]['boqUpload']['id'])) {
                    $project = collect($before['projects'])->firstWhere('id', $input['args'][0]['id'] ?? '');
                    $input['args'][0]['_boqImport'] = app(BoqImportStorage::class)->read($row, $input['args'][0]['boqUpload']['id'], $project);
                }
            }
            if (in_array($input['command'], ['saveSpk', 'saveSpkFinance'], true)) {
                unset($input['args'][0]['_payeeReady']);
                $oldSpk = collect($before['spks'])->firstWhere('id', $input['args'][0]['id'] ?? '');
                if (($input['args'][1] ?? '') === 'attest' && ($input['command'] === 'saveSpkFinance' || ! $oldSpk || ($oldSpk['financialFlow'] ?? '') === 'kasbon-v1')) {
                    abort_unless($oldSpk, 422, 'Simpan Draft SPK dan lengkapi data Mandor sebelum Sahkan SPK.');
                    app(FinanceDocuments::class)->requirePayee($row, $input['args'][0]['id']);
                    $input['args'][0]['_payeeReady'] = true;
                }
            }
            if (in_array($input['command'], ['saveProject', 'saveTerm', 'submitFinanceTerm'], true) && (($input['args'][1] ?? false) || $input['command'] === 'submitFinanceTerm')) {
                $pending = DB::table('upload_tasks')->where('workspace_id', $row->id)->where('document_id', $input['args'][0]['id'] ?? '')
                    ->where('kind', $input['command'] !== 'saveProject' ? 'evidence' : 'boq')->whereIn('status', ['queued', 'processing'])->exists();
                abort_if($pending, 422, 'Tunggu antrean upload dokumen ini selesai sebelum mengajukan approval.');
            }
            if ($input['command'] === 'attestBarp') {
                abort_if(DB::table('upload_tasks')->where('workspace_id', $row->id)->where('document_id', $input['args'][0]['id'])->where('kind', 'evidence')->whereIn('status', ['queued', 'processing'])->exists(), 422, 'Tunggu upload eviden selesai sebelum mengesahkan BA RP.');
            }
            if ($input['command'] === '_submitSettlementKasbon') {
                abort_if(DB::table('upload_tasks')->where('workspace_id', $row->id)->where('document_id', $input['args'][0])->where('kind', 'evidence')->whereIn('status', ['queued', 'processing'])->exists(), 422, 'Tunggu upload eviden selesai sebelum pengajuan kasbon.');
            }
            if (in_array($input['command'], ['recordApm', 'settleFinancePayment'], true) && PrototypeDomain::usesFinancePayment(collect($before['terms'])->firstWhere('id', $input['args'][0]) ?? [])) {
                abort_if(DB::table('upload_tasks')->where('workspace_id', $row->id)->where('document_id', $input['args'][0])->where('kind', 'transfer-proof')->whereIn('status', ['queued', 'processing'])->exists(), 422, 'Tunggu antrean bukti transfer selesai sebelum pelunasan.');
            }
            if (in_array($input['command'], ['settleFinancePayment', 'updatePaymentApm'], true)) {
                abort_unless(DB::table('migrations')->where('migration', '2026_11_16_000001_allow_optional_payment_apm')->exists(), 503, 'Pelunasan dengan APM opsional memerlukan migration terbaru.');
            }
            $domainCommand = $input['command'] === 'saveSpk' && (! $oldSpk || ($oldSpk['financialFlow'] ?? '') === 'kasbon-v1') ? 'saveSpkFinance' : $input['command'];
            if ($input['command'] === 'saveTerm' && ($input['args'][1] ?? false)) {
                $savedTerm = collect($before['terms'])->firstWhere('id', $input['args'][0]['id'] ?? '');
                $savedSpk = collect($before['spks'])->firstWhere('id', $savedTerm['spkId'] ?? '');
                if (($savedSpk['financialFlow'] ?? '') === 'kasbon-v1' || PrototypeDomain::usesFinancePayment($savedTerm ?? [])) {
                    $domainCommand = 'submitFinanceTerm';
                }
            }
            if ($input['command'] === 'saveProjectBundle' || ($input['command'] === 'saveProject' && ! collect($before['projects'])->contains('id', $input['args'][0]['id'] ?? ''))) {
                $domainCommand = 'saveProjectBundle';
            }
            if ($input['command'] === 'createTerm') {
                $domainCommand = 'createSettlementTerm';
            }
            $state = app(PrototypeDomain::class)->execute($before, $domainCommand, $input['args'], $input['result_id'] ?? null)['state'];
            $budgetPlan = app(BudgetPidAllocation::class)->plan($row, $before, $state, $input, $budgetPayloadHash);
            $newRootDraft = $domainCommand === 'saveProjectBundle' && ! collect($before['projects'])->contains('id', $input['args'][0]['id']);
            $balanceQuery = fn () => DB::table('volume_balances')->join('boq_components as c', 'c.id_boq_component', '=', 'volume_balances.boq_component_id')
                ->join('boq_lines as b', 'b.id_boq', '=', 'c.boq_line_id')->join('project_plans as p', 'p.id_pp', '=', 'b.root_pp_id')
                ->where('p.workspace_id', $row->id)->select('volume_balances.*');
            // New roots have no previous component balances and cannot move old stock.
            $balances = $newRootDraft ? collect() : $balanceQuery()->get()->keyBy('boq_component_id');
            $row->revision++;
            app(RelationalWorkflowStore::class)->write($row, $state, $before);
            app(BudgetPidAllocation::class)->persist($budgetPlan, $row->revision);
            $row->save();
            $beforeHash = hash('sha256', json_encode($before, JSON_THROW_ON_ERROR));
            $afterHash = hash('sha256', json_encode($state, JSON_THROW_ON_ERROR));
            DB::table('prototype_audits')->insert([
                'workspace_id' => $row->id, 'request_id' => $input['request_id'], 'command' => $input['command'],
                'actor_role' => $before['role'], 'actor_user_id' => auth()->id(), 'revision' => $row->revision,
                'before_hash' => $beforeHash,
                'after_hash' => $afterHash, 'created_at' => now(),
            ]);
            $receipt = (string) Str::uuid();
            DB::table('command_receipts')->insert([
                'id_command_receipt' => $receipt, 'request_uuid' => $input['request_id'],
                'actor_user_id' => auth()->id(), 'command_name' => $input['command'],
                'aggregate_type' => 'WORKSPACE', 'aggregate_id' => $row->id,
                'expected_revision' => $input['revision'], 'resulting_revision' => $row->revision,
                'before_sha256' => $beforeHash,
                'after_sha256' => $afterHash, 'created_at' => now(),
            ]);
            foreach ($newRootDraft ? [] : $balanceQuery()->get() as $balance) {
                $old = $balances->get($balance->boq_component_id);
                // Opening stock/draft BOQ quantity changes are recorded by the receipt,
                // not presented as transfers between the four ledger buckets.
                if (! $old || bccomp((string) $old->original_quantity, (string) $balance->original_quantity, 3) !== 0) {
                    continue;
                }
                $deltas = [];
                foreach (['available', 'reserved', 'committed', 'accepted'] as $field) {
                    $deltas[$field.'_delta'] = bcsub((string) $balance->$field, (string) ($old->$field ?? 0), 3);
                }
                if (array_filter($deltas, fn ($v) => bccomp($v, '0', 3) !== 0)) {
                    DB::table('volume_movements')->insert($deltas + [
                        'id_volume_movement' => (string) Str::uuid(), 'receipt_id' => $receipt,
                        'boq_component_id' => $balance->boq_component_id, 'movement_type' => 'WORKFLOW',
                        'source_type' => $input['command'], 'source_id' => $row->id, 'created_at' => now(),
                    ]);
                }
            }

            return $includePayload ? $this->payload($row, $state) : ['revision' => $row->revision];
        }, 3);
    }
}
