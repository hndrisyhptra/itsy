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
        Schema::create('boq_lines', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->char('id_boq', 36)->primary();
            $table->char('root_pp_id', 36);
            $table->bigInteger('khs_price_id')->unsigned();
            $table->string('designator_code_snapshot', 255);
            $table->text('description_snapshot');
            $table->string('unit_snapshot', 255);
            $table->unique(['root_pp_id', 'khs_price_id'], 'a3_uq_017');
            $table->foreign(['root_pp_id'], 'a3_fk_032')->references(['id_pp'])->on('project_plans');
            $table->foreign(['khs_price_id'], 'a3_fk_033')->references(['id_khs_price'])->on('khs_prices');
        });
    }

    public function down(): void
    {
        Area3MigrationTarget::assertAllowed();
        Schema::dropIfExists('boq_lines');
    }
};
