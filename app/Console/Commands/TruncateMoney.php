<?php

namespace App\Console\Commands;

use App\Services\TruncateStoredMoney;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;

class TruncateMoney extends Command
{
    protected $signature = 'area3:truncate-money {--apply : Terapkan satu kali dengan audit; jalankan saat maintenance}';

    protected $description = 'Rencana baca-saja pemotongan desimal nominal tersimpan; --apply eksplisit setelah audit tersedia.';

    public function handle(): int
    {
        try {
            $result = app(TruncateStoredMoney::class)->run((bool) $this->option('apply'));
            $this->info(json_encode($result, JSON_THROW_ON_ERROR));
            if (! $this->option('apply')) {
                $this->line('Pemeriksaan baca saja; tidak ada nilai yang diubah.');
            }

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error($e instanceof QueryException ? 'Periksa koneksi its dan kelengkapan schema sebelumnya. Tidak melanjutkan konversi.' : $e->getMessage());

            return self::FAILURE;
        }
    }
}
