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
        Schema::create('projects', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->char('id_project', 36)->primary();
            $table->string('project_code', 100)->unique();
            $table->bigInteger('branch_id')->unsigned();
            $table->bigInteger('service_area_id')->unsigned()->nullable();
            $table->smallInteger('work_unit_id')->unsigned();
            $table->string('name', 250);
            $table->foreign(['branch_id'], 'a3_fk_016')->references(['id_branch'])->on('branches');
            $table->foreign(['branch_id', 'service_area_id'], 'a3_fk_017')->references(['branch_id', 'id_service_area'])->on('service_areas');
            $table->foreign(['work_unit_id'], 'a3_fk_018')->references(['id_work_unit'])->on('work_units');
        });
    }

    public function down(): void
    {
        Area3MigrationTarget::assertAllowed();
        Schema::dropIfExists('projects');
    }
};
