<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Role labels/catalog only. Workflow authorities remain explicit code, never editable permissions. */
class RoleCatalog
{
    public function listing(string $search = '', int $page = 1): array
    {
        $query = DB::table('roles')->select(['id_role', 'code', 'name']);
        if ($search !== '') {
            $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%';
            $query->where(fn ($q) => $q->whereRaw("code LIKE ? ESCAPE '!'", [$like])->orWhereRaw("name LIKE ? ESCAPE '!'", [$like]));
        }
        $rows = $query->orderBy('code')->offset(($page - 1) * 20)->limit(21)->get();
        $more = $rows->count() > 20;
        $rows = $rows->take(20);
        $users = DB::table('users')->whereIn('role', $rows->pluck('code'))->selectRaw('role, count(*) as total')->groupBy('role')->pluck('total', 'role');
        // Include inactive historical assignments: deletion must never orphan their references.
        $assignments = DB::table('user_assignments')->whereIn('role_id', $rows->pluck('id_role'))->selectRaw('role_id, count(*) as total')->groupBy('role_id')->pluck('total', 'role_id');

        return ['rows' => $rows->map(fn ($row) => $this->present((array) $row) + [
            'users_count' => (int) ($users[$row->code] ?? 0),
            'assignments_count' => (int) ($assignments[$row->id_role] ?? 0),
        ])->values()->all(), 'page' => $page, 'has_more' => $more];
    }

    public function change(int $actorId, array $input): array
    {
        // Serialize with account creation/assignment, not the unrelated workflow aggregate.
        return Cache::lock('area3-account-provisioning', 15)->block(5, fn () => DB::transaction(function () use ($actorId, $input) {
            $actor = User::whereKey($actorId)->lockForUpdate()->firstOrFail();
            abort_unless((app(AccountAccess::class)->context($actor)['code'] ?? null) === 'SUPERADMIN', 403);
            $hash = hash('sha256', json_encode($input, JSON_THROW_ON_ERROR));
            $prior = DB::table('master_data_changes')->where('request_uuid', $input['request_id'])->first();
            if ($prior) {
                $saved = json_decode($prior->after_data, true, 512, JSON_THROW_ON_ERROR);
                abort_unless($prior->entity === 'role' && (int) $prior->actor_user_id === $actorId && hash_equals($saved['request_hash'] ?? '', $hash), 409, 'Identitas permintaan sudah digunakan untuk perubahan lain.');

                return $saved['result'];
            }
            $before = isset($input['id']) ? (array) DB::table('roles')->where('id_role', $input['id'])->lockForUpdate()->first() : null;
            abort_if(isset($input['id']) && ! $before, 404, 'Peran tidak ditemukan.');
            if ($before) {
                abort_unless(hash_equals($this->version($before), $input['version']), 409, 'Data peran berubah. Tutup formulir dan muat ulang daftar.');
                abort_unless($input['code'] === $before['code'], 422, 'Kode peran tersimpan tidak dapat diubah.');
            }
            if ($input['action'] === 'delete') {
                abort_if(isset(AccountAccess::ROLES[$before['code']]), 422, 'Peran bawaan dilindungi karena digunakan aturan akses aplikasi.');
                abort_if(DB::table('users')->where('role', $before['code'])->exists()
                    || DB::table('user_assignments')->where('role_id', $before['id_role'])->exists(), 422, 'Peran sudah digunakan akun atau riwayat penugasan dan tidak dapat dihapus.');
                DB::table('roles')->where('id_role', $before['id_role'])->delete();
                $after = null;
            } else {
                abort_if(DB::table('roles')->where('code', $input['code'])->when($before, fn ($q) => $q->where('id_role', '<>', $before['id_role']))->exists(), 422, 'Kode peran sudah tersedia.');
                // Audit IDs participate so a deleted highest ID is never reused.
                $id = $before['id_role'] ?? max((int) DB::table('roles')->max('id_role'), (int) DB::table('master_data_changes')->where('entity', 'role')->max('record_id')) + 1;
                abort_if($id > 65535, 422, 'Kapasitas identitas peran telah penuh.');
                $after = ['id_role' => $id, 'code' => $input['code'], 'name' => $input['name']];
                if ($before) {
                    DB::table('roles')->where('id_role', $id)->update(['name' => $input['name']]);
                } else {
                    DB::table('roles')->insert($after);
                }
            }
            $result = ['role' => $after ? $this->present($after) : null, 'message' => $after ? 'Peran pengguna disimpan.' : 'Peran pengguna dihapus.'];
            DB::table('master_data_changes')->insert(['id_master_change' => (string) Str::uuid(), 'entity' => 'role', 'record_id' => ($after ?? $before)['id_role'],
                'action' => $input['action'], 'before_data' => $before ? json_encode($before, JSON_THROW_ON_ERROR) : null,
                'after_data' => json_encode(['record' => $after, 'request_hash' => $hash, 'result' => $result], JSON_THROW_ON_ERROR),
                'actor_user_id' => $actorId, 'request_uuid' => $input['request_id'], 'created_at' => now()]);

            return $result;
        }));
    }

    private function present(array $row): array
    {
        return $row + ['system' => isset(AccountAccess::ROLES[$row['code']]), 'workflow_name' => AccountAccess::ROLES[$row['code']] ?? null, 'version' => $this->version($row)];
    }

    private function version(array $row): string
    {
        return hash('sha256', json_encode([(int) $row['id_role'], $row['code'], $row['name']], JSON_THROW_ON_ERROR));
    }
}
