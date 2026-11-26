<?php

use App\Support\Area3MigrationTarget;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Area3MigrationTarget::assertAllowed();
        // Never merge identities, delete files or rewrite referenced snapshots automatically.
        if (DB::table('mandors')->select('workspace_id', 'nik_hash')->groupBy('workspace_id', 'nik_hash')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('Master Mandor memiliki NIK ganda lintas Branch/unit. Migration dihentikan tanpa menghapus/menggabungkan data. Periksa dan rekonsiliasi master terlebih dahulu; snapshot SPK/kasbon tetap.');
        }
        // Historical origins remain as nullable provenance; they never control master access.
        $this->origins(true);
        if (! Schema::hasIndex('mandors', 'mandor_workspace_nik')) {
            Schema::table('mandors', fn (Blueprint $t) => $t->unique(['workspace_id', 'nik_hash'], 'mandor_workspace_nik'));
        }
        if (! Schema::hasIndex('mandors', 'mandor_workspace_search')) {
            Schema::table('mandors', fn (Blueprint $t) => $t->index(['workspace_id', 'name'], 'mandor_workspace_search'));
        }
    }

    public function down(): void
    {
        Area3MigrationTarget::assertAllowed();
        if (DB::table('mandors')->whereNull('branch_id')->orWhereNull('unit')->exists()) {
            throw new RuntimeException('Mandor bersama sudah tersimpan; rollback pembatasan Branch/unit ditolak agar data tetap dapat digunakan.');
        }
        Schema::table('mandors', function (Blueprint $t) {
            $t->dropUnique('mandor_workspace_nik');
            $t->dropIndex('mandor_workspace_search');
        });
        $this->origins(false);
    }

    private function origins(bool $nullable): void
    {
        $sqlite = DB::getDriverName() === 'sqlite';
        $deferred = $sqlite ? (int) DB::selectOne('PRAGMA defer_foreign_keys')->defer_foreign_keys : 0;
        if ($sqlite) {
            // SQLite rebuilds parent tables for CHANGE. Keep FK enforcement enabled,
            // defer only the transient rebuild, then check every FK and restore immediacy.
            DB::statement('PRAGMA defer_foreign_keys = ON');
        }
        try {
            Schema::table('mandors', function (Blueprint $t) use ($nullable) {
                $t->unsignedBigInteger('branch_id')->nullable($nullable)->change();
                $t->string('unit', 20)->nullable($nullable)->change();
            });
            if ($sqlite && DB::select('PRAGMA foreign_key_check')) {
                throw new RuntimeException('Relasi Master Mandor tidak utuh; perubahan schema ditolak.');
            }
        } finally {
            if ($sqlite) {
                DB::statement('PRAGMA defer_foreign_keys = '.($deferred ? 'ON' : 'OFF'));
            }
        }
    }
};
