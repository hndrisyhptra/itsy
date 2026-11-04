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
        Schema::create('project_plan_costs', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->bigIncrements('id_pp_cost');
            $table->char('pp_id', 36);
            $table->string('cost_type', 20);
            $table->decimal('amount', 20, 2);
            $table->unique(['pp_id', 'cost_type'], 'a3_uq_016');
            $table->foreign(['pp_id'], 'a3_fk_031')->references(['id_pp'])->on('project_plans');
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE `project_plan_costs` ADD CONSTRAINT `pp_cost_type` CHECK (cost_type IN (\'MANDOR\',\'NONSTOCK\',\'BOP\',\'PERMIT\'))');
            DB::statement('ALTER TABLE `project_plan_costs` ADD CONSTRAINT `pp_cost_nonnegative` CHECK (amount >= 0)');
        }
    }

    public function down(): void
    {
        Area3MigrationTarget::assertAllowed();
        Schema::dropIfExists('project_plan_costs');
    }
};
