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
        if (! DB::table('roles')->where('code', 'MGR_PROJECT_SUPPORT')->exists()) {
            DB::table('roles')->insert(['id_role' => ((int) DB::table('roles')->max('id_role')) + 1, 'code' => 'MGR_PROJECT_SUPPORT', 'name' => 'Mgr Project Support']);
        }
        Schema::create('master_data_changes', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->uuid('id_master_change')->primary();
            $table->string('entity', 20);
            $table->unsignedBigInteger('record_id');
            $table->string('action', 10);
            $table->json('before_data')->nullable();
            $table->json('after_data');
            $table->unsignedBigInteger('actor_user_id');
            $table->uuid('request_uuid')->unique();
            $table->timestamp('created_at');
            $table->foreign('actor_user_id')->references('id_user')->on('users');
            $table->index(['entity', 'record_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Area3MigrationTarget::assertAllowed();
        $role = DB::table('roles')->where('code', 'MGR_PROJECT_SUPPORT')->value('id_role');
        if (DB::table('master_data_changes')->exists() || DB::table('user_assignments')->where('role_id', $role)->exists() || DB::table('users')->where('role', 'MGR_PROJECT_SUPPORT')->exists()) {
            throw new RuntimeException('Role/master telah digunakan; rollback ditolak untuk menjaga riwayat.');
        }
        Schema::dropIfExists('master_data_changes');
        DB::table('roles')->where('code', 'MGR_PROJECT_SUPPORT')->delete();
    }
};
