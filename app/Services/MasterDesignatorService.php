<?php

namespace App\Services;

use App\Models\PrototypeWorkspace;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;

/** Manages supplied SQL masters, never the offline demonstration catalog. */
class MasterDesignatorService
{
    private const TABLES = ['designator' => ['designators', 'id_designator'], 'khs' => ['designator_package_prices', 'id_designator_package_price'], 'paket-khs' => ['packages', 'id_package']];

    /** Download references only; never load prices, workflow graphs, or lock a workspace. */
    public function templateCatalog(string $entity): array
    {
        $types = DB::table('designator_types')->where('is_active', true)->whereNull('deleted_at')->orderBy('id_designator_type')->get(['id_designator_type', 'code', 'name']);

        return [
            'types' => $types,
            'packages' => DB::table('packages')->whereNull('deleted_at')->orderBy('code')->get(['code', 'name']),
            'designators' => $entity === 'khs'
                ? DB::table('designators')->whereNull('deleted_at')->whereIn('designator_type_id', [1, 2])
                    ->whereIn('designator_type_id', $types->pluck('id_designator_type'))->orderBy('code')->get(['code', 'item_name', 'unit', 'designator_type_id'])
                : [],
        ];
    }

    public function catalog(): array
    {
        return [
            'designators' => DB::table('designators')->whereNull('deleted_at')->orderBy('code')->get(['id_designator', 'code', 'item_name', 'unit', 'designator_type_id']),
            'packages' => DB::table('packages')->whereNull('deleted_at')->orderBy('code')->get(['id_package', 'code', 'name', 'description']),
            'prices' => DB::table('designator_package_prices as p')->join('designators as d', 'd.id_designator', '=', 'p.designator_id')
                ->join('packages as k', 'k.id_package', '=', 'p.package_id')->join('designator_types as t', 't.id_designator_type', '=', 'd.designator_type_id')
                ->whereNull('p.deleted_at')->orderBy('d.code')->orderBy('k.code')
                ->get(['p.id_designator_package_price', 'p.designator_id', 'p.package_id', 'p.price', 'd.code', 'd.item_name', 'd.unit', 't.name as component', 'k.name as package']),
            'types' => DB::table('designator_types')->where('is_active', true)->whereNull('deleted_at')->orderBy('id_designator_type')->get(['id_designator_type', 'code', 'name']),
        ];
    }

    public function change(PrototypeWorkspace $workspace, string $entity, array $input): array
    {
        abort_unless(app(AuthenticatedWorkspace::class)->account()['code'] === 'SUPERADMIN', 403, 'Hanya Superadmin yang dapat mengelola master.');

        return DB::transaction(function () use ($workspace, $entity, $input) {
            $row = PrototypeWorkspace::whereKey($workspace->id)->lockForUpdate()->firstOrFail();
            $command = 'master.'.$entity.'.'.$input['action'];
            $replay = DB::table('prototype_audits')->where('workspace_id', $row->id)->where('request_id', $input['request_id'])->first();
            if ($replay) {
                abort_unless($replay->actor_user_id === auth()->id() && $replay->command === $command, 409, 'ID permintaan sudah digunakan.');

                return app(PrototypeWorkspaceService::class)->payload($row) + ['catalog' => $this->catalog(), 'replayed' => true];
            }
            if ($row->revision !== $input['revision']) {
                throw new HttpResponseException(response()->json(['message' => 'Data berubah. Muat ulang daftar master sebelum menyimpan kembali.'], 409));
            }
            [$table, $pk] = self::TABLES[$entity];
            $before = isset($input['id']) ? DB::table($table)->where($pk, $input['id'])->whereNull('deleted_at')->lockForUpdate()->first() : null;
            abort_if(isset($input['id']) && ! $before, 404, 'Data master tidak ditemukan atau telah dihapus.');
            abort_if($input['action'] === 'delete' && ! $before, 422, 'Pilih data yang akan dihapus.');
            $state = app(AuthenticatedWorkspace::class)->bind(app(RelationalWorkflowStore::class)->read($row));
            if ($input['action'] === 'delete') {
                if ($entity !== 'khs') {
                    $foreign = $entity === 'designator' ? 'designator_id' : 'package_id';
                    if (DB::table('designator_package_prices')->where($foreign, $before->$pk)->whereNull('deleted_at')->exists()) {
                        throw ValidationException::withMessages(['master' => 'Hapus harga KHS yang memakai data ini terlebih dahulu. Riwayat harga tetap disimpan.']);
                    }
                }
                $fields = ['deleted_at' => now()];
            } else {
                $fields = $this->validateFields($entity, $input['data'] ?? [], $before);
            }
            $fields += ['updated_at' => now(), 'updated_by' => auth()->id()];
            if ($before) {
                $id = $before->$pk;
                DB::table($table)->where($pk, $id)->update($fields);
            } else {
                $id = DB::table($table)->insertGetId($fields + ['created_at' => now(), 'created_by' => auth()->id()], $pk);
            }
            $after = DB::table($table)->where($pk, $id)->first();
            // References only: issued transaction rows and their snapshots are never rewritten here.
            $updated = app(AuthenticatedWorkspace::class)->bind($state);
            $updated['lastSaved'] = now()->toISOString();
            app(RelationalWorkflowStore::class)->writeReferenceMetadata($row, $updated);
            $row->revision++;
            $row->save();
            $beforeJson = $before ? json_encode($before, JSON_THROW_ON_ERROR) : null;
            $afterJson = json_encode($after, JSON_THROW_ON_ERROR);
            $beforeHash = hash('sha256', $beforeJson ?? 'null');
            $afterHash = hash('sha256', $afterJson);
            DB::table('master_data_changes')->insert(['id_master_change' => (string) Str::uuid(), 'entity' => $entity, 'record_id' => $id,
                'action' => $input['action'] === 'delete' ? 'delete' : ($before ? 'update' : 'create'), 'before_data' => $beforeJson, 'after_data' => $afterJson,
                'actor_user_id' => auth()->id(), 'request_uuid' => $input['request_id'], 'created_at' => now()]);
            DB::table('prototype_audits')->insert(['workspace_id' => $row->id, 'request_id' => $input['request_id'], 'command' => $command,
                'actor_role' => 'Superadmin', 'actor_user_id' => auth()->id(), 'revision' => $row->revision, 'before_hash' => $beforeHash, 'after_hash' => $afterHash, 'created_at' => now()]);
            DB::table('command_receipts')->insert(['id_command_receipt' => (string) Str::uuid(), 'request_uuid' => $input['request_id'],
                'actor_user_id' => auth()->id(), 'command_name' => $command, 'aggregate_type' => 'MASTER', 'aggregate_id' => $row->id,
                'expected_revision' => $input['revision'], 'resulting_revision' => $row->revision, 'before_sha256' => $beforeHash, 'after_sha256' => $afterHash, 'created_at' => now()]);

            return app(PrototypeWorkspaceService::class)->payload($row, $updated) + ['catalog' => $this->catalog(), 'recordId' => $id];
        }, 3);
    }

    public function import(PrototypeWorkspace $workspace, string $entity, array $input, array $rows, bool $preview): array
    {
        abort_unless(app(AuthenticatedWorkspace::class)->account()['code'] === 'SUPERADMIN', 403);

        return DB::transaction(function () use ($workspace, $entity, $input, $rows, $preview) {
            $workspace = PrototypeWorkspace::whereKey($workspace->id)->lockForUpdate()->firstOrFail();
            // Full SHA-256 in base64url keeps replay identity inside the existing 60-character audit column.
            $command = 'master.imp.'.($entity === 'designator' ? 'd' : 'k').'.'.($input['mode'] === 'create' ? 'c' : 'u').'.'.rtrim(strtr(base64_encode(hex2bin($input['sha256'])), '+/', '-_'), '=');
            $requestId = $input['request_id'] ?? null;
            if (! $preview) {
                $replay = DB::table('prototype_audits')->where('workspace_id', $workspace->id)->where('request_id', $requestId)->first();
                if ($replay) {
                    abort_unless($replay->actor_user_id === auth()->id() && $replay->command === $command, 409, 'ID permintaan sudah digunakan untuk data lain.');
                    $changes = DB::table('master_data_changes')->whereIn('request_uuid', array_map(fn ($r) => (string) Uuid::uuid5($requestId, 'master-row-'.$r['row']), $rows))->get();

                    return app(PrototypeWorkspaceService::class)->payload($workspace) + ['catalog' => $this->catalog(), 'replayed' => true,
                        'summary' => ['created' => $changes->where('action', 'create')->count(), 'updated' => $changes->where('action', 'update')->count(), 'unchanged' => count($rows) - $changes->count()]];
                }
            }
            // A read-only inspection may start from an older screen. It returns
            // the current locked revision; only a commit must match that preview.
            abort_if(! $preview && $workspace->revision !== $input['revision'], 409, 'Data berubah setelah pemeriksaan. Klik Periksa file kembali sebelum Simpan impor.');
            $key = fn ($v) => mb_strtolower(trim((string) $v));
            $designators = DB::table('designators')->get()->keyBy(fn ($r) => $key($r->code));
            $packages = DB::table('packages')->get()->keyBy(fn ($r) => $key($r->code));
            $types = DB::table('designator_types')->where('is_active', true)->whereNull('deleted_at')->get()->keyBy(fn ($r) => $key($r->code));
            $prices = $entity === 'khs' ? DB::table('designator_package_prices')->get()->keyBy(fn ($r) => $r->designator_id.':'.$r->package_id) : collect();
            $plan = [];
            $errors = [];
            $seen = [];
            $summary = ['created' => 0, 'updated' => 0, 'unchanged' => 0];
            [$table, $pk] = self::TABLES[$entity];
            foreach ($rows as $source) {
                $n = $source['row'];
                $data = $source['data'];
                try {
                    if ($entity === 'designator') {
                        $type = $types->get($key($data['kode_jenis']));
                        if (! $type) {
                            throw ValidationException::withMessages(['kode_jenis' => 'Kode jenis tidak tersedia/aktif. Lihat sheet Jenis.']);
                        }
                        $identity = $key($data['kode_designator']);
                        $before = $designators->get($identity);
                        $fields = ['code' => $data['kode_designator'], 'item_name' => $data['uraian_pekerjaan'], 'unit' => $data['satuan'], 'designator_type_id' => $type->id_designator_type];
                    } else {
                        $d = $designators->get($key($data['kode_designator']));
                        $p = $packages->get($key($data['kode_paket']));
                        if (! $d || $d->deleted_at || ! in_array((int) $d->designator_type_id, [1, 2], true) || ! $types->contains('id_designator_type', $d->designator_type_id)) {
                            throw ValidationException::withMessages(['kode_designator' => 'Designator Material/Jasa tidak tersedia/aktif. Impor Designator terlebih dahulu.']);
                        }
                        if (! $p || $p->deleted_at) {
                            throw ValidationException::withMessages(['kode_paket' => 'Kode paket tidak tersedia/aktif. Lihat sheet Paket.']);
                        }
                        $identity = $d->id_designator.':'.$p->id_package;
                        $before = $prices->get($identity);
                        $fields = ['designator_id' => $d->id_designator, 'package_id' => $p->id_package, 'price' => $data['harga']];
                    }
                    if (isset($seen[$identity])) {
                        throw ValidationException::withMessages(['duplikat' => 'Duplikat dengan baris '.$seen[$identity].'. Gabungkan pada satu baris.']);
                    }
                    $seen[$identity] = $n;
                    if ($before?->deleted_at) {
                        throw ValidationException::withMessages(['arsip' => 'Identitas/pasangan telah diarsip; tidak dapat dipulihkan melalui impor.']);
                    }
                    if ($before && $input['mode'] === 'create') {
                        throw ValidationException::withMessages(['duplikat' => 'Data sudah ada. Pilih mode Tambah dan perbarui bila memang ingin memperbaruinya.']);
                    }
                    $fields = $this->validateFields($entity, $fields, $before);
                    $same = $before !== null;
                    foreach ($fields as $field => $value) {
                        $same = $same && ($field === 'price' ? bccomp((string) $before->$field, (string) $value, 2) === 0 : (string) $before->$field === (string) $value);
                    }
                    $action = $same ? 'unchanged' : ($before ? 'updated' : 'created');
                    $summary[$action]++;
                    $plan[] = ['row' => $n, 'code' => $data['kode_designator'], 'package' => $data['kode_paket'] ?? null, 'data' => $fields, 'before' => $before, 'action' => $action];
                } catch (ValidationException $e) {
                    foreach ($e->errors() as $field => $messages) {
                        $errors['rows.'.$n.'.'.$field] = array_map(fn ($m) => 'Baris '.$n.': '.$m, $messages);
                    }
                }
            }
            if ($errors) {
                throw ValidationException::withMessages($errors);
            }
            if ($preview) {
                return ['summary' => $summary, 'rows' => array_map(fn ($r) => array_diff_key($r, ['before' => true]), $plan), 'revision' => $workspace->revision, 'sha256' => $input['sha256']];
            }
            $state = app(AuthenticatedWorkspace::class)->bind(app(RelationalWorkflowStore::class)->read($workspace));
            $changes = [];
            $afterRows = [];
            foreach ($plan as $r) {
                if ($r['action'] === 'unchanged') {
                    continue;
                }
                $before = $r['before'];
                $fields = $r['data'] + ['updated_at' => now(), 'updated_by' => auth()->id()];
                if ($before) {
                    $id = $before->$pk;
                    DB::table($table)->where($pk, $id)->update($fields);
                } else {
                    $id = DB::table($table)->insertGetId($fields + ['created_at' => now(), 'created_by' => auth()->id()], $pk);
                }
                $after = DB::table($table)->where($pk, $id)->first();
                $afterRows[] = $after;
                $changes[] = ['id_master_change' => (string) Str::uuid(), 'entity' => $entity, 'record_id' => $id,
                    'action' => $before ? 'update' : 'create', 'before_data' => $before ? json_encode($before, JSON_THROW_ON_ERROR) : null,
                    'after_data' => json_encode($after, JSON_THROW_ON_ERROR), 'actor_user_id' => auth()->id(),
                    'request_uuid' => (string) Uuid::uuid5($requestId, 'master-row-'.$r['row']), 'created_at' => now()];
            }
            if ($changes) {
                DB::table('master_data_changes')->insert($changes);
            }
            $updated = app(AuthenticatedWorkspace::class)->bind($state);
            $updated['lastSaved'] = now()->toISOString();
            app(RelationalWorkflowStore::class)->writeReferenceMetadata($workspace, $updated);
            $workspace->revision++;
            $workspace->save();
            $beforeHash = hash('sha256', json_encode(array_column($plan, 'before'), JSON_THROW_ON_ERROR));
            $afterHash = hash('sha256', json_encode($afterRows, JSON_THROW_ON_ERROR));
            DB::table('prototype_audits')->insert(['workspace_id' => $workspace->id, 'request_id' => $requestId, 'command' => $command,
                'actor_role' => 'Superadmin', 'actor_user_id' => auth()->id(), 'revision' => $workspace->revision, 'before_hash' => $beforeHash, 'after_hash' => $afterHash, 'created_at' => now()]);
            DB::table('command_receipts')->insert(['id_command_receipt' => (string) Str::uuid(), 'request_uuid' => $requestId, 'actor_user_id' => auth()->id(),
                'command_name' => $command, 'aggregate_type' => 'MASTER', 'aggregate_id' => $workspace->id, 'expected_revision' => $input['revision'],
                'resulting_revision' => $workspace->revision, 'before_sha256' => $beforeHash, 'after_sha256' => $afterHash, 'created_at' => now()]);

            return app(PrototypeWorkspaceService::class)->payload($workspace, $updated) + ['catalog' => $this->catalog(), 'summary' => $summary];
        }, 3);
    }

    private function validateFields(string $entity, array $data, ?object $before): array
    {
        [$table, $pk] = self::TABLES[$entity];
        foreach ($data as $key => $value) {
            if (is_string($value)) {
                $data[$key] = trim($value);
            }
        }
        $uniqueCode = Rule::unique($table, 'code')->ignore($before?->$pk, $pk);
        $rules = match ($entity) {
            'designator' => ['code' => ['required', 'string', 'max:255', $uniqueCode], 'item_name' => 'required|string|max:5000', 'unit' => 'required|string|max:255',
                'designator_type_id' => ['required', 'integer', Rule::exists('designator_types', 'id_designator_type')->where('is_active', true)->whereNull('deleted_at')]],
            'paket-khs' => ['code' => ['required', 'string', 'max:255', $uniqueCode], 'name' => 'required|string|max:255', 'description' => 'nullable|string|max:5000'],
            'khs' => ['designator_id' => ['required', 'integer', Rule::exists('designators', 'id_designator')->whereIn('designator_type_id', [1, 2])->whereNull('deleted_at')],
                'package_id' => ['required', 'integer', Rule::exists('packages', 'id_package')->whereNull('deleted_at')], 'price' => ['required', 'numeric', 'min:0', 'max:9999999999999', 'decimal:0,2']],
        };
        $fields = Validator::make($data, $rules)->validate();
        if ($entity === 'khs') {
            $fields['price'] = bcdiv((string) $fields['price'], '1', 0);
            if ($before && ((int) $fields['designator_id'] !== (int) $before->designator_id || (int) $fields['package_id'] !== (int) $before->package_id)) {
                throw ValidationException::withMessages(['master' => 'Relasi harga tersimpan tidak dapat diganti. Tambah KHS baru untuk pasangan lain.']);
            }
            if (DB::table($table)->where('designator_id', $fields['designator_id'])->where('package_id', $fields['package_id'])
                ->when($before, fn ($q) => $q->where($pk, '!=', $before->$pk))->exists()) {
                throw ValidationException::withMessages(['master' => 'Pasangan designator dan paket sudah terdaftar, termasuk arsip. Gunakan pasangan lain.']);
            }
        } elseif ($before) {
            $prices = DB::table('designator_package_prices')->where($entity === 'designator' ? 'designator_id' : 'package_id', $before->$pk)->pluck('id_designator_package_price');
            $used = DB::table('boq_lines')->whereIn('source_price_id', $prices)->exists()
                || ($entity === 'designator' && DB::table('khs_prices')->where('designator_id', $before->$pk)->exists());
            $identity = $entity === 'designator' ? ['code', 'item_name', 'unit', 'designator_type_id'] : ['code', 'name'];
            foreach ($identity as $key) {
                if ($used && (string) $fields[$key] !== (string) $before->$key) {
                    throw ValidationException::withMessages(['master' => 'Identitas sudah digunakan dalam BOQ. Buat data baru agar dokumen lama tidak berubah.']);
                }
            }
        }

        return $fields;
    }
}
