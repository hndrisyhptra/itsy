<?php

namespace App\Services;

use App\Models\PrototypeWorkspace;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Bounded, atomic upsert by NIK. PII never enters list, preview, receipts or logs in plaintext. */
class MandorBulkImport
{
    private const OPTIONAL = ['accountNumber', 'bank', 'accountName'];

    public function rows(string $path): array
    {
        $rows = app(MasterImportWorkbook::class)->read($path, 'mandor');
        $seen = [];
        foreach ($rows as &$r) {
            $v = $r['data'];
            $data = ['name' => $v['nama_mandor'], 'address' => $v['alamat'], 'nik' => $v['nik_ktp'], 'accountNumber' => $v['no_rekening'], 'bank' => $v['nama_bank'], 'accountName' => $v['nama_pemilik_rekening']];
            try {
                $r['data'] = MandorMaster::validateData($data);
            } catch (ValidationException $e) {
                throw ValidationException::withMessages(['file' => 'Baris '.$r['row'].': '.implode(' ', Arr::flatten($e->errors()))]);
            }
            $r['nik_hash'] = hash_hmac('sha256', $r['data']['nik'], config('app.key'));
            if (isset($seen[$r['nik_hash']])) {
                throw ValidationException::withMessages(['file' => 'Baris '.$r['row'].': NIK KTP duplikat pada file (sebelumnya baris '.$seen[$r['nik_hash']].').']);
            }
            $seen[$r['nik_hash']] = $r['row'];
        }
        unset($r);

        return $rows;
    }

    private function pack(mixed $v): string
    {
        return Crypt::encryptString(json_encode($v, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    private function plan(PrototypeWorkspace $w, array $rows): array
    {
        $existing = [];
        foreach (array_chunk(array_column($rows, 'nik_hash'), 250) as $hashes) {
            foreach (DB::table('mandors')->where('workspace_id', $w->id)->whereIn('nik_hash', $hashes)->lockForUpdate()->get() as $m) {
                $existing[$m->nik_hash] = $m;
            }
        }
        $plan = [];
        $summary = ['created' => 0, 'updated' => 0, 'unchanged' => 0];
        foreach ($rows as $r) {
            $old = $existing[$r['nik_hash']] ?? null;
            $data = $r['data'];
            $oldPrivate = $old ? json_decode(Crypt::decryptString($old->private_data), true, 512, JSON_THROW_ON_ERROR) : [];
            foreach (self::OPTIONAL as $key) {
                if ($data[$key] === '' && $old) {
                    $data[$key] = $oldPrivate[$key] ?? '';
                }
            }
            $private = array_diff_key($data, ['name' => true]);
            $same = $old && $old->name === $data['name'] && Arr::sortRecursive($oldPrivate) === Arr::sortRecursive($private);
            $action = ! $old ? 'created' : ($same ? 'unchanged' : 'updated');
            $summary[$action]++;
            $plan[] = $r + ['old' => $old, 'name' => $data['name'], 'private' => $private, 'action' => $action];
        }

        return ['rows' => $plan, 'summary' => $summary];
    }

    private function token(PrototypeWorkspace $w, string $sha, array $plan): string
    {
        $sources = array_map(fn ($r) => [$r['row'], $r['nik_hash'], $r['name'], $r['private'], $r['old']?->id_mandor, $r['old']?->version, $r['action']], $plan['rows']);

        return hash_hmac('sha256', json_encode(Arr::sortRecursive([$w->id, (int) auth()->id(), $sha, $sources]), JSON_THROW_ON_ERROR), config('app.key'));
    }

    public function run(PrototypeWorkspace $workspace, array $rows, string $sha, array $input): array
    {
        return DB::transaction(function () use ($workspace, $rows, $sha, $input) {
            $w = PrototypeWorkspace::runtimeQuery()->whereKey($workspace->id)->lockForUpdate()->firstOrFail();
            app(MandorMaster::class)->account();
            $commit = $input['stage'] === 'commit';
            $payloadHash = $commit ? hash('sha256', json_encode([$w->id, (int) auth()->id(), $sha, $input['token']], JSON_THROW_ON_ERROR)) : '';
            if ($commit) {
                $receipt = DB::table('mandor_imports')->where('id_mandor_import', $input['request_id'])->first();
                if ($receipt) {
                    abort_unless($receipt->workspace_id === $w->id && (int) $receipt->actor_user_id === (int) auth()->id() && hash_equals($receipt->payload_hash, $payloadHash), 409, 'UUID impor telah digunakan untuk akun atau isi file berbeda.');

                    return ['summary' => json_decode($receipt->summary, true, 512, JSON_THROW_ON_ERROR), 'replayed' => true];
                }
                abort_if(DB::table('mandor_changes')->where('id_mandor_change', $input['request_id'])->exists(), 409, 'UUID impor sudah digunakan untuk perubahan Mandor lain.');
            }
            $plan = $this->plan($w, $rows);
            $token = $this->token($w, $sha, $plan);
            if (! $commit) {
                return ['sha256' => $sha, 'token' => $token, 'summary' => $plan['summary'], 'total' => count($rows),
                    'rows' => array_map(fn ($r) => ['row' => $r['row'], 'name' => $r['name'], 'action' => $r['action']], $plan['rows'])];
            }
            abort_unless(hash_equals($input['token'], $token), 409, 'Master Mandor berubah setelah pemeriksaan. Periksa file kembali sebelum menyimpan.');
            $now = now();
            $actor = auth()->id();
            DB::table('mandor_imports')->insert(['id_mandor_import' => $input['request_id'], 'workspace_id' => $w->id, 'actor_user_id' => $actor, 'file_sha256' => $sha, 'payload_hash' => $payloadHash, 'summary' => json_encode($plan['summary'], JSON_THROW_ON_ERROR), 'created_at' => $now]);
            $records = $audits = [];
            foreach ($plan['rows'] as $r) {
                if ($r['action'] === 'unchanged') {
                    continue;
                }
                $old = $r['old'];
                $id = $old?->id_mandor ?? (string) Str::uuid();
                $record = ['id_mandor' => $id, 'workspace_id' => $w->id, 'branch_id' => $old?->branch_id, 'unit' => $old?->unit, 'name' => $r['name'], 'nik_hash' => $r['nik_hash'],
                    'private_data' => $this->pack($r['private']), 'ktp_file_id' => $old?->ktp_file_id, 'bank_file_id' => $old?->bank_file_id,
                    'version' => (int) ($old?->version ?? 0) + 1, 'created_by' => $old?->created_by ?? $actor, 'updated_by' => $actor, 'created_at' => $old?->created_at ?? $now, 'updated_at' => $now];
                $records[] = $record;
                $audits[] = ['id_mandor_change' => (string) Str::uuid(), 'mandor_id' => $id, 'import_id' => $input['request_id'], 'action' => 'import', 'actor_user_id' => $actor, 'payload_hash' => $payloadHash,
                    'before_data' => $old ? $this->pack($old) : null, 'after_data' => $this->pack($record), 'created_at' => $now];
            }
            foreach (array_chunk($records, 50) as $chunk) {
                DB::table('mandors')->upsert($chunk, ['id_mandor'], ['name', 'private_data', 'version', 'updated_by', 'updated_at']);
            }
            foreach (array_chunk($audits, 50) as $chunk) {
                DB::table('mandor_changes')->insert($chunk);
            }

            return ['summary' => $plan['summary']];
        });
    }
}
