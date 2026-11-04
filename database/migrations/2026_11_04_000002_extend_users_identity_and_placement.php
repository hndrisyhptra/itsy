<?php

use App\Support\Area3MigrationTarget;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // SQLite rebuilds the users table for foreign keys; its PRAGMA must run outside a transaction.
    public $withinTransaction = false;

    public function up(): void
    {
        Area3MigrationTarget::assertAllowed();
        Schema::table('users', function (Blueprint $table) {
            $table->string('username', 50)->nullable()->unique();
            $table->string('nik', 50)->nullable()->unique();
            $table->unsignedBigInteger('id_regions')->nullable();
            $table->unsignedBigInteger('id_branch')->nullable();
            $table->unsignedBigInteger('id_service_areas')->nullable();
            $table->string('role', 40)->nullable()->index();
            $table->foreign('id_regions', 'a3_users_region_fk')->references('id_region')->on('regions');
            $table->foreign('id_branch', 'a3_users_branch_fk')->references('id_branch')->on('branches');
            $table->foreign('id_service_areas', 'a3_users_service_area_fk')->references('id_service_area')->on('service_areas');
        });
        // Preserve passwords/identity. Never guess an employee NIK or a primary placement.
        DB::table('users')->orderBy('id_user')->chunkById(100, function ($users) {
            foreach ($users as $user) {
                $roles = DB::table('user_assignments as a')->join('roles as r', 'r.id_role', '=', 'a.role_id')
                    ->where('a.user_id', $user->id_user)->where('a.active', true)->distinct()->pluck('r.code');
                DB::table('users')->where('id_user', $user->id_user)->update([
                    'username' => 'user-'.$user->id_user,
                    'role' => $roles->count() === 1 ? $roles->first() : null,
                ]);
            }
        }, 'id_user');
    }

    public function down(): void
    {
        Area3MigrationTarget::assertAllowed();
        Schema::table('users', function (Blueprint $table) {
            $sqlite = DB::getDriverName() === 'sqlite';
            $table->dropForeign($sqlite ? ['id_regions'] : 'a3_users_region_fk');
            $table->dropForeign($sqlite ? ['id_branch'] : 'a3_users_branch_fk');
            $table->dropForeign($sqlite ? ['id_service_areas'] : 'a3_users_service_area_fk');
            $table->dropUnique(['username']);
            $table->dropUnique(['nik']);
            $table->dropIndex(['role']);
            $table->dropColumn(['username', 'nik', 'id_regions', 'id_branch', 'id_service_areas', 'role']);
        });
    }
};
