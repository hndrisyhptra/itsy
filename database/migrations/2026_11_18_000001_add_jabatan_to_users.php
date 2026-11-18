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
        Schema::table('users', function (Blueprint $table) {
            $table->string('jabatan', 250)->nullable();
        });
    }

    public function down(): void
    {
        Area3MigrationTarget::assertAllowed();
        if (DB::table('users')->whereNotNull('jabatan')->where('jabatan', '<>', '')->exists()) {
            throw new RuntimeException('Jabatan pengguna telah diisi; rollback ditolak agar data tidak hilang.');
        }
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('jabatan'));
    }
};
