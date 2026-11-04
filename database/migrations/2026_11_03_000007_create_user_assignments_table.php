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
        Schema::create('user_assignments', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->bigIncrements('id_user_assignment');
            $table->bigInteger('user_id')->unsigned();
            $table->smallInteger('role_id')->unsigned();
            $table->bigInteger('branch_id')->unsigned();
            $table->boolean('active')->default(true);
            $table->unique(['user_id', 'role_id', 'branch_id'], 'a3_uq_006');
            $table->foreign(['user_id'], 'a3_fk_005')->references(['id_user'])->on('users');
            $table->foreign(['role_id'], 'a3_fk_006')->references(['id_role'])->on('roles');
            $table->foreign(['branch_id'], 'a3_fk_007')->references(['id_branch'])->on('branches');
        });
    }

    public function down(): void
    {
        Area3MigrationTarget::assertAllowed();
        Schema::dropIfExists('user_assignments');
    }
};
