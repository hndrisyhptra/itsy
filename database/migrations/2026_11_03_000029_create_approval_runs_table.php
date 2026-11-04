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
        Schema::create('approval_runs', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->char('id_approval_run', 36)->primary();
            $table->char('pp_id', 36)->nullable();
            $table->char('term_id', 36)->nullable();
            $table->integer('cycle_no')->unsigned();
            $table->string('status', 20)->default('PENDING');
            $table->bigInteger('submitted_by')->unsigned();
            $table->dateTime('submitted_at', 6);
            $table->unique(['pp_id', 'cycle_no'], 'a3_uq_022');
            $table->unique(['term_id', 'cycle_no'], 'a3_uq_023');
            $table->foreign(['pp_id'], 'a3_fk_043')->references(['id_pp'])->on('project_plans');
            $table->foreign(['term_id'], 'a3_fk_044')->references(['id_payment_term'])->on('payment_terms');
            $table->foreign(['submitted_by'], 'a3_fk_045')->references(['id_user'])->on('users');
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE `approval_runs` ADD CONSTRAINT `approval_one_subject` CHECK ((pp_id IS NOT NULL AND term_id IS NULL) OR (pp_id IS NULL AND term_id IS NOT NULL))');
            DB::statement('ALTER TABLE `approval_runs` ADD CONSTRAINT `approval_run_status` CHECK (status IN (\'PENDING\',\'APPROVED\',\'RETURNED\',\'REJECTED\'))');
        }
    }

    public function down(): void
    {
        Area3MigrationTarget::assertAllowed();
        Schema::dropIfExists('approval_runs');
    }
};
