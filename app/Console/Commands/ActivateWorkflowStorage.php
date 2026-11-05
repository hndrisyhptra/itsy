<?php

namespace App\Console\Commands;

use App\Models\PrototypeWorkspace;
use App\Services\RelationalWorkflowStore;
use App\Support\Area3MigrationTarget;
use Illuminate\Console\Command;

class ActivateWorkflowStorage extends Command
{
    protected $signature = 'area3:activate-storage';

    protected $description = 'Pindahkan workspace akun ke tabel transaksi secara atomik tanpa mengubah arsip, nomor atau keputusan';

    public function handle(RelationalWorkflowStore $store): int
    {
        Area3MigrationTarget::assertAllowed();
        $workspace = PrototypeWorkspace::where('owner_key', hash('sha256', 'area3-authenticated-workflow-v1'))->first();
        if (! $workspace) {
            $this->info('Belum ada workspace akun; tabel transaksi akan digunakan saat login pertama.');

            return self::SUCCESS;
        }
        $changed = $store->activate($workspace);
        $state = $store->read($workspace->fresh());
        $this->info($changed ? 'Penyimpanan transaksi aktif; verifikasi baca ulang identik dengan arsip berhasil.' : 'Penyimpanan transaksi sudah aktif; arsip tidak diimpor ulang.');
        $this->line('PP: '.count($state['projects']).'; BA KH: '.count($state['deals']).'; SPK: '.count($state['spks']).'; Pembayaran: '.count($state['terms']));

        return self::SUCCESS;
    }
}
