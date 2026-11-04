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
        Schema::create('project_plans', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->char('id_pp', 36)->primary();
            $table->char('project_id', 36);
            $table->string('pp_no', 150)->unique();
            $table->bigInteger('numbering_rule_id')->unsigned();
            $table->string('number_scope_key', 100);
            $table->bigInteger('number_sequence')->unsigned();
            $table->string('number_pattern_snapshot', 100);
            $table->string('kode_branch_snapshot', 30);
            $table->char('number_period', 7)->comment('MM-YYYY in server Asia/Jakarta time');
            $table->dateTime('numbered_at', 6);
            $table->char('root_pp_id', 36)->nullable();
            $table->char('parent_pp_id', 36)->nullable();
            $table->date('usage_date');
            $table->decimal('budget', 20, 2);
            $table->bigInteger('pm_user_id')->unsigned();
            $table->bigInteger('manager_user_id')->unsigned();
            $table->text('introduction');
            $table->text('risk_plan');
            $table->text('communication_plan');
            $table->text('quality_plan');
            $table->string('status', 20)->default('DRAFT');
            $table->bigInteger('revision')->unsigned()->default(0);
            $table->smallInteger('workflow_version')->unsigned()->default(2);
            $table->bigInteger('created_by')->unsigned();
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);
            $table->foreign(['project_id'], 'a3_fk_021')->references(['id_project'])->on('projects');
            $table->foreign(['numbering_rule_id', 'number_scope_key'], 'a3_fk_022')->references(['rule_id', 'scope_key'])->on('number_sequences');
            $table->foreign(['root_pp_id'], 'a3_fk_023')->references(['id_pp'])->on('project_plans');
            $table->foreign(['parent_pp_id'], 'a3_fk_024')->references(['id_pp'])->on('project_plans');
            $table->foreign(['pm_user_id'], 'a3_fk_025')->references(['id_user'])->on('users');
            $table->foreign(['manager_user_id'], 'a3_fk_026')->references(['id_user'])->on('users');
            $table->foreign(['created_by'], 'a3_fk_027')->references(['id_user'])->on('users');
            $table->index(['pm_user_id', 'status'], 'a3_ix_030');
            $table->index(['manager_user_id', 'status'], 'a3_ix_031');
            $table->unique(['numbering_rule_id', 'number_scope_key', 'number_sequence'], 'a3_uq_014');
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE `project_plans` ADD CONSTRAINT `pp_number_positive` CHECK (number_sequence > 0)');
            DB::statement('ALTER TABLE `project_plans` ADD CONSTRAINT `pp_budget_positive` CHECK (budget > 0)');
            DB::statement('ALTER TABLE `project_plans` ADD CONSTRAINT `pp_status` CHECK (status IN (\'DRAFT\',\'SUBMITTED\',\'RETURNED\',\'REJECTED\',\'APPROVED\'))');
        }
    }

    public function down(): void
    {
        Area3MigrationTarget::assertAllowed();
        Schema::dropIfExists('project_plans');
    }
};
