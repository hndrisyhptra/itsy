<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\ProjectHeadCorrection;
use App\Support\Area3MigrationTarget;
use Illuminate\Console\Command;
use Throwable;

final class ReopenProjectHead extends Command
{
    protected $signature = 'area3:reopen-pp-head {plan : Berkas JSON rencana koreksi yang sudah diperiksa} {--operator= : Username Superadmin aktif} {--workspace-revision= : Revisi hasil pemeriksaan terakhir} {--apply : Terapkan; tanpa opsi ini hanya pemeriksaan}';

    protected $description = 'Periksa/terapkan koreksi approval Head PP tanpa menghapus siklus keputusan lama';

    public function handle(ProjectHeadCorrection $service): int
    {
        try {
            Area3MigrationTarget::assertAllowed();
            $operator = User::where('username', $this->option('operator'))->firstOrFail();
            $path = $this->argument('plan');
            if (! is_file($path) || filesize($path) > 16384) {
                $this->error('Rencana harus berupa berkas JSON maksimal 16 KB.');

                return self::FAILURE;
            }
            $revision = $this->option('workspace-revision');
            if ($revision !== null && ! ctype_digit($revision)) {
                $this->error('Revisi workspace harus bilangan bulat hasil pemeriksaan.');

                return self::FAILURE;
            }
            $result = $service->run(json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR), $operator, $revision === null ? null : (int) $revision, (bool) $this->option('apply'));
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            $this->info(($result['replayed'] ?? false) ? 'Permintaan ini sudah tercatat; tidak diterapkan ulang.' : (($result['applied'] ?? false) ? 'Koreksi tercatat. Muat ulang halaman; Head harus memutuskan melalui akunnya sendiri.' : 'Pemeriksaan selesai; belum ada perubahan. --apply memerlukan --workspace-revision sesuai hasil ini.'));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
