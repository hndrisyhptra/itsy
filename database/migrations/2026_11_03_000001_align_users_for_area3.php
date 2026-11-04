<?php

use App\Support\Area3MigrationTarget;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Area3MigrationTarget::assertAllowed();

        if (! Schema::hasColumn('users', 'id') || Schema::hasColumn('users', 'id_user') || Schema::hasColumn('users', 'active')) {
            throw new RuntimeException('Struktur users berbeda dari migration Laravel bawaan. Periksa tabel existing sebelum melanjutkan; tidak ada kolom pengguna yang ditimpa.');
        }

        Schema::table('users', function (Blueprint $table) {
            $table->renameColumn('id', 'id_user');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('active')->default(true);
        });
    }

    public function down(): void
    {
        Area3MigrationTarget::assertAllowed();
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('active');
            $table->renameColumn('id_user', 'id');
        });
    }
};
