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
        Schema::create('project_plan_schedule', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->bigIncrements('id_pp_schedule');
            $table->char('pp_id', 36);
            $table->tinyInteger('stage_no')->unsigned();
            $table->string('stage_name', 100);
            $table->decimal('work_days', 8, 2);
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->unique(['pp_id', 'stage_no'], 'a3_uq_015');
            $table->foreign(['pp_id'], 'a3_fk_030')->references(['id_pp'])->on('project_plans');
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE `project_plan_schedule` ADD CONSTRAINT `pp_schedule_valid` CHECK (stage_no BETWEEN 1 AND 5 AND work_days >= 0 AND end_date >= start_date)');
        }
    }

    public function down(): void
    {
        Area3MigrationTarget::assertAllowed();
        Schema::dropIfExists('project_plan_schedule');
    }
};
