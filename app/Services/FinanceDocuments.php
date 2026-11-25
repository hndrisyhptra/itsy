<?php

namespace App\Services;

use App\Models\PrototypeWorkspace;
use App\Models\User;
use App\Support\WholeRupiah;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/** Private PII stays outside workflow snapshots, boot payloads and exports. */
class FinanceDocuments
{
    public const KINDS = ['mandor-ktp', 'mandor-bank', 'transfer-proof'];

    private const PAYEE_LABELS = ['nik' => 'NIK KTP Mandor', 'accountNumber' => 'Nomor rekening', 'accountName' => 'Nama pemilik rekening', 'bank' => 'Nama bank', 'ktpFileId' => 'Foto KTP Mandor', 'bankFileId' => 'Foto buku tabungan'];

    private const PAYEE_MESSAGES = [
        'nik.regex' => 'NIK KTP Mandor harus terdiri dari tepat 16 digit angka tanpa spasi atau tanda baca.',
        'accountNumber.regex' => 'Nomor rekening harus terdiri dari 1–40 digit angka tanpa spasi atau tanda baca.',
    ];

    public function authorize(PrototypeWorkspace $w, string $id, bool $term = false, bool $write = false, bool $details = false, ?array $account = null, bool $approvalView = false): object
    {
        $account ??= app(AuthenticatedWorkspace::class)->account();
        $readRoles = ['Admin Konstruksi', 'Admin Operation', 'Superadmin', 'Finance', 'Mgr Finance & Settlement'];
        if ($approvalView && $term) {
            $readRoles = [...$readRoles, ...PrototypeDomain::APPROVERS_V3, ...WorkUnitAccess::OPS_APPROVERS];
        }
        abort_unless(in_array($account['role'], $write ? ($term ? ['Finance'] : ['Admin Konstruksi', 'Admin Operation', 'Superadmin']) : $readRoles, true), 403, 'Peran akun tidak diizinkan mengakses dokumen pribadi Mandor atau pembayaran ini.');
        $q = DB::table('work_orders as s')->join('contracts as c', 'c.id_contract', '=', 's.contract_id')->join('project_plans as p', 'p.id_pp', '=', 'c.pp_id')
            ->where('s.workspace_id', $w->id)->where('c.workspace_id', $w->id)->where('p.workspace_id', $w->id)->whereIn('p.branch_id', $account['branchIds']);
        WorkUnitAccess::scope($q, $account, 'p');
        if ($term) {
            $q->join('payment_terms as t', 't.work_order_id', '=', 's.id_spk')->where('t.workspace_id', $w->id)->where('t.source_id', $id);
        } else {
            $q->where('s.source_id', $id);
        }
        $r = $q->first(['s.id_spk', 'p.status as pp_status', 'p.document_data->contractBundle as bundle_data', ...($details ? ['s.document_data as spk_data', 'c.document_data as deal_data', 'p.document_data as pp_data'] : []), ...($term ? ['t.id_payment_term', 't.document_data as term_data'] : [])]);
        abort_unless($r, 404, ($term ? 'Pengajuan pembayaran' : 'SPK').' tidak ditemukan atau berada di luar Branch penugasan akun.');

        abort_if($write && $r->pp_status === 'CANCELLED', 422, ProjectCancellation::MESSAGE);
        if ($approvalView && ! in_array($account['role'], ['Admin Konstruksi', 'Admin Operation', 'Superadmin', 'Finance'], true)) {
            $t = json_decode($r->term_data, true, 512, JSON_THROW_ON_ERROR);
            abort_unless(((PrototypeDomain::usesSettlementPayment($t) && ($t['approverUserIds'][$account['role']] ?? '') === $account['id']) || ($account['role'] === 'Mgr Finance & Settlement' && ($t['legacySettlementReview']['userId'] ?? '') === $account['id'])) && ! in_array($t['status'], ['Draft', 'Ditolak', 'Dikembalikan'], true), 403, 'Kasbon hanya dapat direview oleh approver pengajuan yang ditugaskan.');
        }
        if ($write && ! $term && $r->bundle_data && $r->pp_status === 'SUBMITTED') {
            abort(422, 'Identitas dan berkas Mandor paket PP terkunci selama approval.');
        }

        return $r;
    }

    private function pack(array $data): string
    {
        return Crypt::encryptString(json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    private function unpack(?object $row): ?array
    {
        return $row ? json_decode(Crypt::decryptString($row->private_data), true, 512, JSON_THROW_ON_ERROR) : null;
    }

    public function payee(PrototypeWorkspace $w, string $id, bool $includeFiles = true): array
    {
        $r = $this->authorize($w, $id);
        $profile = DB::table('mandor_payee_profiles')->where('spk_id', $r->id_spk)->orderByDesc('version')->first(['private_data', 'version']);
        $files = $includeFiles ? DB::table('finance_private_files')->where('workspace_id', $w->id)->where('spk_id', $r->id_spk)->orderByDesc('created_at')->limit(20)->get(['id_finance_file as id', 'kind', 'created_at as uploadedAt']) : collect();

        return ['profile' => $this->unpack($profile), 'version' => $profile->version ?? 0, 'files' => $files];
    }

    public function requirePayee(PrototypeWorkspace $w, string $id): array
    {
        $r = $this->authorize($w, $id);

        return $this->payeeSnapshot($w, $r->id_spk);
    }

    private function payeeSnapshot(PrototypeWorkspace $w, string $spkId): array
    {
        $profile = DB::table('mandor_payee_profiles')->where('spk_id', $spkId)->orderByDesc('version')->first(['id_payee', 'private_data', 'version']);
        $data = $this->unpack($profile);
        Validator::make($data ?? [], ['nik' => 'required|regex:/^\d{16}$/D', 'accountNumber' => 'required|regex:/^\d{1,40}$/D', 'accountName' => 'required|string|max:150', 'bank' => 'required|string|max:100', 'ktpFileId' => 'required|uuid', 'bankFileId' => 'required|uuid'], self::PAYEE_MESSAGES, self::PAYEE_LABELS)->validate();
        $this->validatePayeeFiles($w, $spkId, $data);

        return $data + ['idPayee' => $profile->id_payee, 'version' => $profile->version];
    }

    private function validatePayeeFiles(PrototypeWorkspace $w, string $spk, array $data): void
    {
        $ids = array_filter([$data['ktpFileId'] ?? '', $data['bankFileId'] ?? '']);
        $files = DB::table('finance_private_files')->where('workspace_id', $w->id)->where('spk_id', $spk)->whereIn('id_finance_file', $ids)->get()->keyBy('id_finance_file');
        foreach (['ktpFileId' => 'mandor-ktp', 'bankFileId' => 'mandor-bank'] as $key => $kind) {
            if (empty($data[$key])) {
                continue;
            }
            $f = $files->get($data[$key]);
            abort_unless($f && $f->kind === $kind && Storage::disk('local')->exists($f->storage_key), 422, 'Foto KTP/buku tabungan harus berasal dari upload SPK ini.');
        }
    }

    private function locked(PrototypeWorkspace $w, callable $fn): array
    {
        return DB::transaction(function () use ($w, $fn) {
            $locked = PrototypeWorkspace::runtimeQuery()->whereKey($w->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->relational_migrated_at, 503, 'Penyimpanan relasional belum aktif.');

            return $fn($locked);
        }, 3);
    }

    private function replay(string $table, string $request, string $hash): ?object
    {
        $row = DB::table($table)->where('request_uuid', $request)->first();
        if ($row) {
            abort_unless($row->input_sha256 === $hash && (int) ($row->created_by ?? $row->issued_by) === (int) auth()->id(), 409, 'ID permintaan sudah digunakan untuk data lain.');
        }

        return $row;
    }

    public function savePayee(PrototypeWorkspace $w, string $id, array $input): array
    {
        $raw = $input['data'] ?? [];
        foreach (array_keys(self::PAYEE_LABELS) as $key) {
            if (isset($raw[$key]) && is_string($raw[$key])) {
                $raw[$key] = trim($raw[$key]);
            }
        }
        $data = Validator::make($raw, ['nik' => 'nullable|string|regex:/^\d{16}$/D', 'accountNumber' => 'nullable|string|regex:/^\d{1,40}$/D', 'accountName' => 'nullable|string|max:150', 'bank' => 'nullable|string|max:100', 'ktpFileId' => 'nullable|uuid', 'bankFileId' => 'nullable|uuid'], self::PAYEE_MESSAGES, self::PAYEE_LABELS)->validate();
        foreach (['nik', 'accountNumber', 'accountName', 'bank', 'ktpFileId', 'bankFileId'] as $key) {
            $data[$key] = trim($data[$key] ?? '');
        }
        $hash = hash('sha256', json_encode([$id, $data], JSON_THROW_ON_ERROR));

        return $this->locked($w, function ($row) use ($id, $input, $data, $hash) {
            $r = $this->authorize($row, $id, false, true);
            if ($this->replay('mandor_payee_profiles', $input['request_id'], $hash)) {
                return app(PrototypeWorkspaceService::class)->payload($row) + ['replayed' => true];
            }
            abort_unless($row->revision === (int) $input['revision'], 409, 'Data berubah; muat ulang sebelum menyimpan data Mandor.');
            $this->validatePayeeFiles($row, $r->id_spk, $data);
            $version = (int) DB::table('mandor_payee_profiles')->where('spk_id', $r->id_spk)->max('version') + 1;
            DB::table('mandor_payee_profiles')->insert(['id_payee' => (string) Str::uuid(), 'spk_id' => $r->id_spk, 'version' => $version, 'private_data' => $this->pack($data), 'created_by' => auth()->id(), 'created_at' => now(), 'request_uuid' => $input['request_id'], 'input_sha256' => $hash]);

            return app(PrototypeWorkspaceService::class)->command($row, ['command' => '_setPayeeVersion', 'args' => [$id, $version], 'request_id' => $input['request_id'], 'revision' => $row->revision]);
        });
    }

    public function kasbon(PrototypeWorkspace $w, string $id, bool $includeFiles = true, bool $currentOnly = false): array
    {
        $r = $this->authorize($w, $id, true, false, false, null, true);
        $includeFiles = ! $currentOnly && $includeFiles && in_array(app(AuthenticatedWorkspace::class)->account()['role'], ['Admin Konstruksi', 'Admin Operation', 'Superadmin', 'Finance', 'Mgr Finance & Settlement'], true);
        $t = json_decode($r->term_data, true, 512, JSON_THROW_ON_ERROR);
        $rows = DB::table('finance_kasbons')->where('term_id', $r->id_payment_term)
            ->when($currentOnly, fn ($query) => $query->where('id_kasbon', $t['kasbon']['id'] ?? ''))
            ->orderByDesc('version')->limit($currentOnly ? 1 : 10)->get(['id_kasbon', 'version', 'private_data']);
        $files = $includeFiles ? DB::table('finance_private_files')->where('workspace_id', $w->id)->where('term_id', $r->id_payment_term)->orderByDesc('created_at')->limit(10)->get(['id_finance_file as id', 'kind', 'created_at as uploadedAt']) : collect();

        return ['documents' => $rows->map(fn ($v) => ['id' => $v->id_kasbon, 'version' => $v->version, 'data' => $this->kasbonView($v, $t)])->all(), 'files' => $files, 'flow' => PrototypeDomain::usesFinancePayment($t)];
    }

    private function kasbonView(object $row, array $term): array
    {
        $data = $this->unpack($row);
        // Current legacy version only. Encrypted original and archived versions stay untouched.
        if (PrototypeDomain::usesFinancePayment($term) && ! PrototypeDomain::usesSettlementPayment($term)
            && ($data['templateVersion'] ?? 1) !== 3 && ($term['kasbon']['id'] ?? '') === $row->id_kasbon
            && isset($term['kasbonPrintSigners'][$row->id_kasbon])) {
            $data['printSigner'] = $term['kasbonPrintSigners'][$row->id_kasbon];
        }

        if (($term['kasbon']['id'] ?? '') === $row->id_kasbon && isset($term['legacySettlementReview'])) {
            $r = $term['legacySettlementReview'];
            $data['printSigner'] = ['userId' => $r['userId'], 'name' => $r['name'], 'job' => $r['job'] ?? 'Mgr Finance & Settlement Area Jawa Bali', 'at' => $r['assignedAt'], 'by' => $r['assignedBy']];
            if (PrototypeDomain::legacyReviewApproved($term)) {
                $data['printSignerApproval'] = $r['decision'];
            }
        }

        return $data;
    }

    public function issue(PrototypeWorkspace $w, string $id, array $input): array
    {
        $defaults = ['place' => 'Surabaya', 'date' => now('Asia/Jakarta')->toDateString(), 'evp' => 'I GEDE WAHYU DANYNDRA', 'businessSupport' => "ARIZA ARBA'ATUS S"];
        $data = Validator::make(($input['data'] ?? []) + $defaults, [
            'payeeVersion' => 'required|integer|min:1', 'settlementUserId' => 'nullable|string|max:100',
            'costCenter' => 'required|string|max:100', 'wbs' => 'required|string|max:100', 'activity' => 'required|string|max:150', 'place' => 'required|string|max:100', 'date' => 'required|date_format:Y-m-d',
            'rows' => 'required|array|min:1|max:20', 'rows.*' => 'required|array:component,accountCode,accountName,text,amount', 'rows.*.component' => 'sometimes|required|in:service,material', 'rows.*.accountCode' => 'required|string|max:50', 'rows.*.accountName' => 'required|string|max:150', 'rows.*.text' => 'required|string|max:300', 'rows.*.amount' => ['required', 'numeric', 'gt:0', 'max:999999999999.99', 'regex:/^\d+(\.\d{1,2})?$/D'],
            'evp' => 'required|string|max:150', 'businessSupport' => 'required|string|max:150',
        ])->validate();
        $hash = hash('sha256', json_encode([$id, $data], JSON_THROW_ON_ERROR));

        return $this->locked($w, function ($row) use ($id, $input, $data, $hash) {
            $account = app(AuthenticatedWorkspace::class)->account();
            abort_unless(in_array($account['role'], ['Admin Konstruksi', 'Admin Operation', 'Superadmin'], true), 403, 'Pengajuan kasbon hanya dibuat Admin Konstruksi atau Superadmin.');
            $r = $this->authorize($row, $id, true, false, true, $account);
            if ($this->replay('finance_kasbons', $input['request_id'], $hash)) {
                return app(PrototypeWorkspaceService::class)->payload($row) + ['replayed' => true];
            }
            abort_unless($row->revision === (int) $input['revision'], 409, 'Data berubah; muat ulang sebelum menerbitkan kasbon.');
            $t = json_decode($r->term_data, true, 512, JSON_THROW_ON_ERROR);
            abort_unless(PrototypeDomain::adminKasbonPending($t), 422, 'Kasbon belum dapat diajukan pada status ini. Pengajuan baru memerlukan draf pembayaran belum lunas; dokumen historis mengikuti alur tersimpan.');
            $spk = json_decode($r->spk_data, true, 512, JSON_THROW_ON_ERROR);
            $p = json_decode($r->pp_data, true, 512, JSON_THROW_ON_ERROR);
            $d = json_decode($r->deal_data, true, 512, JSON_THROW_ON_ERROR);
            $settlementQuote = KasbonSettlement::quote($d, $t);
            $total = $settlementQuote['net'];
            abort_if($total <= 0, 422, 'Nilai bersih pengajuan kasbon harus positif.');
            // The current form sends typed canonical components. Untyped historical clients
            // retain the existing total-only validation; issued documents are never rewritten.
            if (collect($data['rows'])->contains(fn ($line) => array_key_exists('component', $line))) {
                $components = KasbonSettlement::components($settlementQuote);
                $byComponent = [];
                foreach ($data['rows'] as $line) {
                    $component = $line['component'] ?? '';
                    abort_unless(isset($components[$component]) && ! isset($byComponent[$component]), 422, 'Rincian kasbon harus memisahkan satu baris Jasa dan satu baris Material sesuai komponen tagihan.');
                    abort_unless(bccomp((string) $line['amount'], $components[$component], 2) === 0, 422, 'Nilai '.($component === 'service' ? 'Jasa setelah potongan PPh' : 'Material').' pada kasbon harus sesuai nilai tagihan tersimpan.');
                    $line['amount'] = $components[$component];
                    $byComponent[$component] = $line;
                }
                abort_unless(count($byComponent) === count($components), 422, 'Lengkapi rincian Jasa dan Material sesuai komponen tagihan kasbon.');
                $data['rows'] = array_map(fn ($component) => $byComponent[$component], array_keys($components));
            }
            $sum = '0';
            foreach ($data['rows'] as &$line) {
                if (($t['moneyPolicy'] ?? '') === WholeRupiah::POLICY) {
                    $line['amount'] = bcdiv((string) $line['amount'], '1', 0);
                }
                abort_if(bccomp((string) $line['amount'], '0', 2) <= 0, 422, 'Nominal kasbon setelah pemotongan harus positif.');
                $sum = bcadd($sum, (string) $line['amount'], 2);
            }
            unset($line);
            abort_unless(bccomp($sum, (string) $total, 2) === 0, 422, 'Jumlah rincian kasbon harus sama dengan nilai pengajuan kasbon saat ini.');
            $payee = $this->payeeSnapshot($row, $r->id_spk);
            abort_unless((int) $data['payeeVersion'] === (int) $payee['version'], 409, 'Data Mandor berubah. Muat ulang data penerima dan periksa rekening sebelum mengajukan kasbon kembali.');
            $settlement = PrototypeDomain::usesSettlementPayment($t);
            $signers = [];
            if ($settlement) {
                $ids = array_intersect_key($t['approverUserIds'], array_flip(PrototypeDomain::approvalRoles($t, true)));
                $ids['Mgr Finance & Settlement'] = $data['settlementUserId'] ?? '';
                $users = User::whereIn('id_user', array_map(fn ($id) => (int) str_replace('db-user-', '', $id), array_values($ids)))->get();
                $contexts = app(AccountAccess::class)->contexts($users);
                $users = $users->keyBy('id_user');
                foreach ($ids as $role => $selected) {
                    $u = $users->get((int) str_replace('db-user-', '', $selected));
                    abort_unless($u && $u->active && ($contexts[$u->id_user]['role'] ?? '') === $role && in_array($p['branch'], $contexts[$u->id_user]['branches'], true) && WorkUnitAccess::permits($contexts[$u->id_user], $p), 422, 'Pilih approver aktif dengan peran '.$role.' pada Branch PP.');
                    $signers[$role] = ['userId' => $selected, 'name' => $u->name, 'job' => trim($u->jabatan ?? '')];
                }
            } else {
                foreach (PrototypeDomain::approvalRoles($t) as $role) {
                    $a = collect($t['approvals'])->first(fn ($a) => $a['role'] === $role && ($a['userId'] ?? '') === ($t['approverUserIds'][$role] ?? ''));
                    abort_unless($a && $a['decision'] === 'Setujui', 422, 'Kelima approval harus lengkap sebelum penerbitan kasbon.');
                    $signers[$role] = ['name' => $a['actorName'], 'userId' => $a['userId'], 'job' => $a['actorJob'] ?? $p['signatureUsers'][$role]['job'] ?? ''];
                }
            }
            $version = (int) DB::table('finance_kasbons')->where('term_id', $r->id_payment_term)->max('version') + 1;
            $kid = (string) Str::uuid();
            $snapshot = $data + ['approvalScheme' => $t['approvalScheme'] ?? 'project-support-first', 'templateVersion' => $settlement ? 3 : 2, 'issuerRole' => $account['role'], 'issuerJob' => $account['jabatan'] ?? '', 'payee' => $payee, 'mandor' => $d['mandor'], 'unit' => $p['unit'].' · '.$p['branch'], 'project' => $p['name'], 'projectCode' => $p['projectCode'], 'ppNo' => $p['no'], 'spkNo' => $spk['no'], 'termNumber' => $t['number'], 'termType' => $t['type'], 'amount' => $total, 'settlement' => $settlementQuote, 'signers' => $signers, 'issuer' => $account['name']];
            DB::table('finance_kasbons')->insert(['id_kasbon' => $kid, 'term_id' => $r->id_payment_term, 'version' => $version, 'amount' => $total, 'private_data' => $this->pack($snapshot), 'issued_by' => auth()->id(), 'issued_at' => now(), 'request_uuid' => $input['request_id'], 'input_sha256' => $hash]);

            return app(PrototypeWorkspaceService::class)->command($row, ['command' => $settlement ? '_submitSettlementKasbon' : '_issueKasbon', 'args' => [$id, ['id' => $kid, 'version' => $version, 'amount' => $total, 'settlement' => $settlementQuote, 'issuedBy' => 'db-user-'.auth()->id(), 'issuedRole' => $account['role'], ...($settlement ? ['settlementUserId' => $data['settlementUserId']] : []), 'issuedAt' => now()->toISOString()]], 'revision' => $row->revision, 'request_id' => $input['request_id']]);
        });
    }

    public function authorizeUpload(PrototypeWorkspace $w, string $id, string $kind): object
    {
        abort_unless(in_array($kind, self::KINDS, true), 422);
        $r = $this->authorize($w, $id, $kind === 'transfer-proof', true);
        if ($kind === 'transfer-proof') {
            $t = json_decode($r->term_data, true, 512, JSON_THROW_ON_ERROR);
            if (PrototypeDomain::requiresBapp($t)) {
                $data = DB::table('ba_completions')->where('workspace_id', $w->id)->where('final_term_id', $r->id_payment_term)->value('document_data');
                abort_unless($data && PrototypeDomain::bappCurrent(json_decode($data, true, 512, JSON_THROW_ON_ERROR), $t), 422, 'Sahkan BA PP sesuai BA RP sebelum unggah bukti transfer pembayaran.');
            }
            abort_unless(PrototypeDomain::legacyReviewApproved($t) && PrototypeDomain::usesFinancePayment($t) && $t['status'] === 'Disetujui' && $t['apmStatus'] !== 'Valid' && isset($t['kasbon']) && ($t['financePayment']['status'] ?? '') === 'Diverifikasi', 422, 'Bukti transfer hanya untuk kasbon yang sudah disetujui Finance dan belum lunas.');
        }

        return $r;
    }

    public function attach(PrototypeWorkspace $w, object $task, string $mime, string $ext, string $target): void
    {
        $this->locked($w, function ($row) use ($task, $mime, $ext, $target) {
            $r = $this->authorizeUpload($row, $task->document_id, $task->kind);
            $isTerm = $task->kind === 'transfer-proof';
            if ($isTerm) {
                $term = json_decode($r->term_data, true, 512, JSON_THROW_ON_ERROR);
                abort_unless(($task->context_id ?? '') === $term['kasbon']['id'], 422, 'Versi kasbon telah berubah. Unggah bukti transfer sesuai kasbon terbaru.');
            }
            $existing = DB::table('finance_private_files')->where('id_finance_file', $task->id_upload_task)->first();
            if (! $existing) {
                DB::table('finance_private_files')->insert(['id_finance_file' => $task->id_upload_task, 'workspace_id' => $row->id, 'spk_id' => $isTerm ? null : $r->id_spk, 'term_id' => $isTerm ? $r->id_payment_term : null, 'kind' => $task->kind, 'storage_key' => $target, 'mime' => $mime, 'extension' => $ext, 'size' => $task->size, 'sha256' => $task->sha256, 'uploaded_by' => auth()->id(), 'created_at' => now()]);
            }
            app(PrototypeWorkspaceService::class)->command($row, ['command' => $isTerm ? '_attachTransferProof' : '_recordPayeeFile', 'args' => [$task->document_id, ['id' => $task->id_upload_task, 'uploadedBy' => 'db-user-'.auth()->id(), 'uploadedAt' => now()->toISOString()]], 'revision' => $row->revision, 'request_id' => $task->request_uuid], false);
            DB::table('upload_tasks')->where('id_upload_task', $task->id_upload_task)->update(['status' => 'completed', 'completed_at' => now(), 'updated_at' => now(), 'error' => null]);

            return [];
        });
    }

    public function file(PrototypeWorkspace $w, string $id): object
    {
        $f = DB::table('finance_private_files')->where('workspace_id', $w->id)->where('id_finance_file', $id)->first();
        abort_unless($f, 404);
        $type = $f->term_id ? 'terms' : 'spks';
        $source = DB::table($type === 'terms' ? 'payment_terms' : 'work_orders')->where($type === 'terms' ? 'id_payment_term' : 'id_spk', $f->term_id ?: $f->spk_id)->value('source_id');
        $this->authorize($w, $source, $type === 'terms');
        abort_unless(str_starts_with($f->storage_key, 'finance-private/'.$w->id.'/') && Storage::disk('local')->exists($f->storage_key), 404);

        return $f;
    }
}
