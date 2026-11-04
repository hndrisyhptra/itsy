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
        Schema::create('approval_steps', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->char('id_approval_step', 36)->primary();
            $table->char('run_id', 36);
            $table->tinyInteger('step_no')->unsigned();
            $table->string('expected_role', 20);
            $table->bigInteger('assigned_user_id')->unsigned();
            $table->string('assigned_name_snapshot', 150);
            $table->unique(['run_id', 'step_no'], 'a3_uq_024');
            $table->foreign(['run_id'], 'a3_fk_046')->references(['id_approval_run'])->on('approval_runs');
            $table->foreign(['assigned_user_id'], 'a3_fk_047')->references(['id_user'])->on('users');
            $table->index(['assigned_user_id', 'run_id'], 'a3_ix_034');
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE `approval_steps` ADD CONSTRAINT `two_approval_steps` CHECK ((step_no = 1 AND expected_role = \'PM_KONSTRUKSI\') OR (step_no = 2 AND expected_role = \'MANAGER_KONSTRUKSI\'))');
        }
    }

    public function down(): void
    {
        Area3MigrationTarget::assertAllowed();
        Schema::dropIfExists('approval_steps');
    }
};
