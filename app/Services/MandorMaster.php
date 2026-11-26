<?php

namespace App\Services;

use App\Models\PrototypeWorkspace;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Throwable;

/** Shared directory across Branches/units within the authenticated workspace. PII is never included in workspace JSON or list/search results. */
class MandorMaster
{
    public const ROLES = ['Admin Konstruksi', 'Admin Operation', 'Admin Area', 'Officer 1 Operation', 'Superadmin'];

    public const KINDS = ['master-ktp', 'master-bank'];

    public function account(): array
    {
        $user = User::find(auth()->id());
        $a = app(AccountAccess::class)->context($user) ?? [];
        abort_unless(in_array($a['role'] ?? '', self::ROLES, true), 403, 'Master Mandor hanya untuk Admin Konstruksi/Operation, Admin Area, Officer 1 Operation dan Superadmin.');

        return $a;
    }

    private function query(PrototypeWorkspace $w)
    {
        return DB::table('mandors as m')->where('m.workspace_id', $w->id);
    }

    public function authorize(PrototypeWorkspace $w, string $id, bool $lock = false): object
    {
        $this->account();
        $q = $this->query($w)->where('m.id_mandor', $id);
        $r = $lock ? $q->lockForUpdate()->first() : $q->first();
        abort_unless($r, 404, 'Mandor tidak ditemukan dalam master aplikasi.');

        return $r;
    }

    private function pack(mixed $data): string
    {
        return Crypt::encryptString(json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    private function unpack(object $r): array
    {
        return json_decode(Crypt::decryptString($r->private_data), true, 512, JSON_THROW_ON_ERROR);
    }

    public function listing(PrototypeWorkspace $w, array $input): array
    {
        $this->account();
        $q = $this->query($w);
        if ($search = trim($input['search'] ?? '')) {
            $q->where('m.name', 'like', '%'.addcslashes($search, '\\%_').'%');
        }
        $page = (int) ($input['page'] ?? 1);
        $size = (int) ($input['per_page'] ?? 20);
        $total = (clone $q)->count();
        $rows = $q->orderBy('m.name')->orderBy('m.id_mandor')->offset(($page - 1) * $size)->limit($size)->get(['m.id_mandor as id', 'm.name', 'm.version', 'm.ktp_file_id', 'm.bank_file_id']);

        return ['rows' => $rows->map(fn ($r) => ['id' => $r->id, 'name' => $r->name, 'version' => (int) $r->version, 'ready' => (bool) ($r->ktp_file_id && $r->bank_file_id)])->all(), 'total' => $total, 'page' => $page, 'per_page' => $size];
    }

    public function detail(PrototypeWorkspace $w, string $id): array
    {
        $r = $this->authorize($w, $id);

        return ['id' => $r->id_mandor, 'name' => $r->name, 'version' => (int) $r->version, 'ready' => (bool) ($r->ktp_file_id && $r->bank_file_id), 'profile' => $this->unpack($r), 'ktpFileId' => $r->ktp_file_id, 'bankFileId' => $r->bank_file_id];
    }

    private function replay(string $uuid, string $hash, ?string $legacyHash = null): bool
    {
        $r = DB::table('mandor_changes')->where('id_mandor_change', $uuid)->first(['actor_user_id', 'payload_hash']);
        abort_if($r && ((int) $r->actor_user_id !== (int) auth()->id() || (! hash_equals($r->payload_hash, $hash) && ($legacyHash === null || ! hash_equals($r->payload_hash, $legacyHash)))), 409, 'UUID sudah dipakai untuk akun atau data berbeda.');

        return (bool) $r;
    }

    private function audit(string $id, string $uuid, string $action, string $hash, mixed $before, mixed $after): void
    {
        DB::table('mandor_changes')->insert(['id_mandor_change' => $uuid, 'mandor_id' => $id, 'action' => $action, 'actor_user_id' => auth()->id(), 'payload_hash' => $hash, 'before_data' => $before === null ? null : $this->pack($before), 'after_data' => $this->pack($after), 'created_at' => now()]);
    }

    public static function validateData(array $data): array
    {
        $data = array_replace(['accountNumber' => '', 'accountName' => '', 'bank' => ''], $data);
        foreach ($data as $k => $v) {
            if (is_string($v)) {
                $data[$k] = trim($v);
            }
        }
        $validated = Validator::make($data, ['name' => 'required|string|max:150|not_regex:/[<>\x00-\x1F\x7F]/u', 'address' => 'required|string|max:1000|not_regex:/[<>\x00-\x1F\x7F]/u', 'nik' => 'required|string|regex:/^\d{16}$/D', 'accountNumber' => 'nullable|string|regex:/^\d{1,40}$/D', 'accountName' => 'nullable|string|max:150|not_regex:/[<>\x00-\x1F\x7F]/u', 'bank' => 'nullable|string|max:100|not_regex:/[<>\x00-\x1F\x7F]/u'], ['nik.regex' => 'NIK KTP harus tepat 16 digit angka.', 'accountNumber.regex' => 'Nomor rekening harus 1–40 digit angka.'])->validate();
        foreach (['accountNumber', 'accountName', 'bank'] as $key) {
            $validated[$key] = $validated[$key] ?? '';
        }

        return $validated;
    }

    public function save(PrototypeWorkspace $w, array $input): array
    {
        $data = self::validateData($input['data']);
        $hash = hash('sha256', json_encode([$input['id'], (int) $input['version'], $data], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($w, $input, $data, $hash) {
            PrototypeWorkspace::runtimeQuery()->whereKey($w->id)->lockForUpdate()->firstOrFail();
            $this->account();
            $old = DB::table('mandors')->where('id_mandor', $input['id'])->lockForUpdate()->first();
            if ($old) {
                $this->authorize($w, $input['id']);
            }
            if ($this->replay($input['request_id'], $hash, isset($input['unit'], $input['branch_id']) ? hash('sha256', json_encode([$input['id'], $input['unit'], (int) $input['branch_id'], (int) $input['version'], $data], JSON_THROW_ON_ERROR)) : null)) {
                return $this->detail($w, $input['id']) + ['replayed' => true];
            }
            abort_unless(($old ? (int) $old->version : 0) === (int) $input['version'], 409, 'Master Mandor berubah. Muat ulang sebelum menyimpan.');
            $nikHash = hash_hmac('sha256', $data['nik'], config('app.key'));
            abort_if(DB::table('mandors')->where('workspace_id', $w->id)->where('nik_hash', $nikHash)->where('id_mandor', '<>', $input['id'])->exists(), 422, 'NIK KTP sudah terdaftar pada Master Mandor bersama. Gunakan atau perbarui data Mandor yang tersedia.');
            $record = ['name' => $data['name'], 'nik_hash' => $nikHash, 'private_data' => $this->pack(array_diff_key($data, ['name' => true])), 'version' => (int) $input['version'] + 1, 'updated_by' => auth()->id(), 'updated_at' => now()];
            if ($old) {
                DB::table('mandors')->where('id_mandor', $input['id'])->update($record);
            } else {
                DB::table('mandors')->insert($record + ['id_mandor' => $input['id'], 'workspace_id' => $w->id, 'branch_id' => null, 'unit' => null, 'created_by' => auth()->id(), 'created_at' => now()]);
            }
            $this->audit($input['id'], $input['request_id'], 'save', $hash, $old, $record);

            return $this->detail($w, $input['id']);
        });
    }

    public function attach(PrototypeWorkspace $w, object $task, string $mime, string $ext, string $target): void
    {
        DB::transaction(function () use ($w, $task, $mime, $ext, $target) {
            PrototypeWorkspace::runtimeQuery()->whereKey($w->id)->lockForUpdate()->firstOrFail();
            $r = $this->authorize($w, $task->document_id, true);
            $existing = DB::table('mandor_files')->where('id_mandor_file', $task->id_upload_task)->exists();
            if (! $existing) {
                DB::table('mandor_files')->insert(['id_mandor_file' => $task->id_upload_task, 'mandor_id' => $r->id_mandor, 'kind' => $task->kind, 'storage_key' => $target, 'mime' => $mime, 'extension' => $ext, 'size' => $task->size, 'sha256' => $task->sha256, 'uploaded_by' => auth()->id(), 'created_at' => now()]);
                $field = $task->kind === 'master-ktp' ? 'ktp_file_id' : 'bank_file_id';
                DB::table('mandors')->where('id_mandor', $r->id_mandor)->update([$field => $task->id_upload_task, 'version' => $r->version + 1, 'updated_by' => auth()->id(), 'updated_at' => now()]);
                $this->audit($r->id_mandor, $task->request_uuid, 'upload', hash('sha256', $task->sha256.'|'.$task->kind.'|'.$r->id_mandor), ['version' => $r->version], ['version' => $r->version + 1, 'file' => $task->id_upload_task, 'kind' => $task->kind]);
            }
            DB::table('upload_tasks')->where('id_upload_task', $task->id_upload_task)->update(['status' => 'completed', 'completed_at' => now(), 'updated_at' => now(), 'error' => null]);
        });
    }

    public function file(PrototypeWorkspace $w, string $id): object
    {
        $r = DB::table('mandor_files')->where('id_mandor_file', $id)->first();
        abort_unless($r, 404);
        $this->authorize($w, $r->mandor_id);
        abort_unless(str_starts_with($r->storage_key, 'mandor-private/'.$w->id.'/') && Storage::disk('local')->exists($r->storage_key), 404);

        return $r;
    }

    /** Validate a transient choice; no private identity is stored in the workflow graph. */
    public function selectForDeal(PrototypeWorkspace $w, array $state, array &$input): ?array
    {
        $d = &$input['args'][0];
        unset($d['_selectedMandor']);
        if (! array_key_exists('mandorSelection', $d)) {
            return null;
        }
        $choice = Validator::make(['choice' => $d['mandorSelection']], ['choice' => 'required|array:id,version', 'choice.id' => 'required|uuid', 'choice.version' => 'required|integer|min:1'])->validate()['choice'];
        $master = $this->authorize($w, $choice['id'], true);
        abort_unless((int) $master->version === (int) $choice['version'], 409, 'Data Master Mandor berubah. Cari dan pilih kembali Mandor sebelum menyimpan BA KH.');
        $old = collect($state['deals'])->firstWhere('id', $d['id']);
        abort_if($old && $old['status'] !== 'Draft', 422, 'Pilihan Mandor pada BA KH terbit/Sah terkunci.');
        $hasSpk = collect($state['spks'])->contains('dealId', $d['id']);
        abort_if($hasSpk && ($old['masterMandor'] ?? null) !== ['id' => $master->id_mandor, 'version' => (int) $master->version], 422, 'BA KH sudah memiliki SPK. Ubah pilihan Mandor melalui draf SPK terkait.');
        $d['_selectedMandor'] = ['id' => $master->id_mandor, 'version' => (int) $master->version, 'name' => $master->name];

        return $d['_selectedMandor'];
    }

    public function assertCommandReplay(array $input, string $hash): void
    {
        $r = DB::table('mandor_changes')->where('id_mandor_change', $input['request_id'])->first(['actor_user_id', 'payload_hash']);
        abort_if($r && ((int) $r->actor_user_id !== (int) auth()->id() || ! hash_equals($r->payload_hash, $hash)), 409, 'UUID pilihan Mandor sudah digunakan untuk isi atau akun lain.');
    }

    /** New SPK only: prepare immutable copies under the command transaction's workspace lock. */
    public function prepareInheritedSpk(PrototypeWorkspace $w, array &$state, array $before, array $input, array &$copied): ?array
    {
        $id = $input['args'][0]['id'];
        if (collect($before['spks'])->contains('id', $id)) {
            return null;
        }
        foreach ($state['spks'] as &$v) {
            if ($v['id'] !== $id) {
                continue;
            }
            $deal = collect($state['deals'])->firstWhere('id', $v['dealId']);
            $choice = $deal['masterMandor'] ?? null;
            if (! $choice) {
                return null;
            }
            $master = $this->authorize($w, $choice['id'], true);
            abort_unless((int) $master->version === (int) $choice['version'], 409, 'Data Master Mandor berubah sejak BA KH disimpan. Pilih ulang Mandor pada BA KH sebelum membuat SPK.');
            abort_if($v['status'] !== 'Draft', 422, 'Simpan draf SPK terlebih dahulu agar identitas Master Mandor tersimpan sebelum penerbitan.');
            abort_if(DB::table('upload_tasks')->where('workspace_id', $w->id)->whereIn('document_id', [$id, $master->id_mandor])->whereIn('status', ['queued', 'processing'])->exists(), 422, 'Tunggu upload Mandor selesai sebelum menyimpan SPK.');
            [$profile, $files] = $this->copySnapshot($w, $master, $id, app(RelationalWorkflowStore::class)->id($w->id, 'spks', $id), $copied);
            $v['address'] = $profile['address'];
            $v['payeeVersion'] = 1;
            $v['masterMandor'] = $choice;

            return ['masterId' => $master->id_mandor, 'spkId' => $id, 'profile' => $profile, 'files' => $files];
        }
        unset($v);

        return null;
    }

    public function persistInheritedSpk(PrototypeWorkspace $w, array $snapshot, string $uuid, string $hash): void
    {
        foreach ($snapshot['files'] as $file) {
            DB::table('finance_private_files')->insert($file);
        }
        DB::table('mandor_payee_profiles')->insert(['id_payee' => (string) Str::uuid(), 'spk_id' => app(RelationalWorkflowStore::class)->id($w->id, 'spks', $snapshot['spkId']), 'version' => 1, 'private_data' => $this->pack($snapshot['profile']), 'created_by' => auth()->id(), 'created_at' => now(), 'request_uuid' => $uuid, 'input_sha256' => $hash]);
        $this->audit($snapshot['masterId'], $uuid, 'inherit-spk', $hash, null, ['spk' => $snapshot['spkId'], 'version' => $snapshot['profile']['masterMandorVersion'], 'payeeVersion' => 1]);
    }

    public function auditDealChoice(array $choice, array $input, string $hash): void
    {
        $this->audit($choice['id'], $input['request_id'], 'select-deal', $hash, null, ['deal' => $input['args'][0]['id'], 'version' => $choice['version']]);
    }

    /** Delete only copies that are proven unlinked after success, rollback or a transaction retry. */
    public function cleanupCopies(array $copied): void
    {
        if (! $copied) {
            return;
        }
        try {
            $linked = DB::table('finance_private_files')->whereIn('id_finance_file', array_keys($copied))->pluck('id_finance_file')->all();
            foreach ($copied as $id => $path) {
                if (! in_array($id, $linked, true)) {
                    Storage::disk('local')->delete($path);
                }
            }
        } catch (Throwable) {
            // If persistence cannot be checked, retain private copies rather than risk deleting committed files.
        }
    }

    private function copySnapshot(PrototypeWorkspace $w, object $master, string $spk, string $sqlSpk, array &$copied): array
    {
        $sources = DB::table('mandor_files')->where('mandor_id', $master->id_mandor)->whereIn('id_mandor_file', [$master->ktp_file_id, $master->bank_file_id])->get()->keyBy('kind');
        $profile = $this->unpack($master);
        $profile['masterMandorId'] = $master->id_mandor;
        $profile['masterMandorVersion'] = (int) $master->version;
        $profile['ktpFileId'] = $profile['bankFileId'] = '';
        $files = [];
        foreach (['master-ktp' => ['ktpFileId', 'mandor-ktp'], 'master-bank' => ['bankFileId', 'mandor-bank']] as $kind => [$field, $targetKind]) {
            $sourceId = $kind === 'master-ktp' ? $master->ktp_file_id : $master->bank_file_id;
            if (! $sourceId) {
                continue;
            }
            $f = $sources->get($kind);
            abort_unless($f && Storage::disk('local')->exists($f->storage_key) && hash_file('sha256', Storage::disk('local')->path($f->storage_key)) === $f->sha256, 422, 'Foto Mandor tidak tersedia atau berubah. Unggah ulang pada Master Mandor.');
            $id = (string) Str::uuid();
            $path = 'finance-private/'.$w->id.'/'.$spk.'/'.$id.'.'.$f->extension;
            abort_unless(Storage::disk('local')->copy($f->storage_key, $path), 500, 'Berkas Mandor belum dapat disalin.');
            $copied[$id] = $path;
            $files[] = ['id_finance_file' => $id, 'workspace_id' => $w->id, 'spk_id' => $sqlSpk, 'term_id' => null, 'kind' => $targetKind, 'storage_key' => $path, 'mime' => $f->mime, 'extension' => $f->extension, 'size' => $f->size, 'sha256' => $f->sha256, 'uploaded_by' => auth()->id(), 'created_at' => now()];
            $profile[$field] = $id;
        }

        return [$profile, $files];
    }

    /** Explicit copy: future master changes never modify a selected SPK or issued kasbon. */
    public function apply(PrototypeWorkspace $w, string $spk, array $input): array
    {
        $copied = [];
        try {
            return DB::transaction(function () use ($w, $spk, $input, &$copied) {
                $w = PrototypeWorkspace::runtimeQuery()->whereKey($w->id)->lockForUpdate()->firstOrFail();
                $data = $input['data'];
                $r = app(FinanceDocuments::class)->authorize($w, $spk, false, true, true);
                $master = $this->authorize($w, $data['id'], true);
                $hash = hash('sha256', json_encode([$spk, $data['id'], (int) $data['version'], (int) $input['revision']], JSON_THROW_ON_ERROR));
                if ($this->replay($input['request_id'], $hash)) {
                    return app(PrototypeWorkspaceService::class)->payload($w) + ['replayed' => true];
                }
                abort_unless($w->revision === (int) $input['revision'] && (int) $master->version === (int) $data['version'], 409, 'SPK atau Master Mandor berubah. Muat ulang lalu pilih kembali.');
                $p = json_decode($r->pp_data, true, 512, JSON_THROW_ON_ERROR);
                $d = json_decode($r->deal_data, true, 512, JSON_THROW_ON_ERROR);
                $v = json_decode($r->spk_data, true, 512, JSON_THROW_ON_ERROR);
                abort_unless(in_array($p['status'], ['Draft', 'Dikembalikan', 'Ditolak'], true) && $d['status'] === 'Draft' && $v['status'] === 'Draft', 422, 'Pilih Mandor pada draf paket sebelum approval. Dokumen terbit/Sah tetap memakai identitas tersimpan.');
                abort_if(DB::table('payment_terms')->where('work_order_id', $r->id_spk)->exists(), 422, 'SPK sudah memiliki BA RP/pembayaran; penerima tersimpan tidak diganti dari master.');
                abort_if(DB::table('upload_tasks')->where('workspace_id', $w->id)->whereIn('document_id', [$spk, $master->id_mandor])->whereIn('status', ['queued', 'processing'])->exists(), 422, 'Tunggu upload SPK selesai sebelum memilih Mandor.');
                [$profile, $files] = $this->copySnapshot($w, $master, $spk, $r->id_spk, $copied);
                foreach ($files as $file) {
                    DB::table('finance_private_files')->insert($file);
                }
                $version = (int) DB::table('mandor_payee_profiles')->where('spk_id', $r->id_spk)->max('version') + 1;
                DB::table('mandor_payee_profiles')->insert(['id_payee' => (string) Str::uuid(), 'spk_id' => $r->id_spk, 'version' => $version, 'private_data' => $this->pack($profile), 'created_by' => auth()->id(), 'created_at' => now(), 'request_uuid' => $input['request_id'], 'input_sha256' => $hash]);
                $result = app(PrototypeWorkspaceService::class)->command($w, ['command' => '_selectMasterMandor', 'args' => [$spk, ['id' => $master->id_mandor, 'version' => (int) $master->version, 'name' => $master->name, 'address' => $profile['address'], 'payeeVersion' => $version]], 'revision' => $w->revision, 'request_id' => $input['request_id']]);
                $this->audit($master->id_mandor, $input['request_id'], 'select', $hash, null, ['spk' => $spk, 'version' => $master->version, 'payeeVersion' => $version]);

                return $result;
            });
        } catch (Throwable $e) {
            foreach ($copied as $id => $path) {
                if (! DB::table('finance_private_files')->where('id_finance_file', $id)->exists()) {
                    Storage::disk('local')->delete($path);
                }
            }
            throw $e;
        }
    }
}
