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
        Schema::create('project_plan_team', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->bigIncrements('id_pp_team');
            $table->char('pp_id', 36);
            $table->bigInteger('user_id')->unsigned()->nullable();
            $table->string('name_snapshot', 150);
            $table->string('job_snapshot', 150);
            $table->foreign(['pp_id'], 'a3_fk_028')->references(['id_pp'])->on('project_plans');
            $table->foreign(['user_id'], 'a3_fk_029')->references(['id_user'])->on('users');
        });
    }

    public function down(): void
    {
        Area3MigrationTarget::assertAllowed();
        Schema::dropIfExists('project_plan_team');
    }
};
