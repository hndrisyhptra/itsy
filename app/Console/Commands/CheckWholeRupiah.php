<?php

namespace App\Console\Commands;

use App\Support\Area3MigrationTarget;
use App\Support\WholeRupiah;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;

class CheckWholeRupiah extends Command
{
    protected $signature = 'area3:check-whole-rupiah';

    protected $description = 'Periksa nominal pecahan sebelum migrasi rupiah bulat (baca saja, khusus its).';

    public function handle(): int
    {
        try {
            Area3MigrationTarget::assertAllowed();
            WholeRupiah::assertIntegralStorage();
        } catch (QueryException) {
            $this->error('Pemeriksaan belum dapat diselesaikan. Periksa koneksi its dan kelengkapan schema migrasi sebelumnya; tidak ada data yang diubah.');

            return self::FAILURE;
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $this->info('Lulus: nominal operasional dan tagihan berjalan dapat disimpan sebagai rupiah bulat. Pemeriksaan baca saja; migration belum dijalankan.');

        return self::SUCCESS;
    }
}
