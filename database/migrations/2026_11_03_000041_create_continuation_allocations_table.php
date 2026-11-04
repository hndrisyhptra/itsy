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
        Schema::create('continuation_allocations', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->bigIncrements('id_continuation_allocation');
            $table->char('continuation_pp_id', 36);
            $table->char('source_release_id', 36);
            $table->char('boq_component_id', 36);
            $table->decimal('quantity', 18, 3);
            $table->unique(['continuation_pp_id', 'source_release_id', 'boq_component_id'], 'a3_uq_031');
            $table->foreign(['continuation_pp_id'], 'a3_fk_077')->references(['id_pp'])->on('project_plans');
            $table->foreign(['source_release_id', 'boq_component_id'], 'a3_fk_078')->references(['id_volume_release', 'boq_component_id'])->on('volume_releases');
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE `continuation_allocations` ADD CONSTRAINT `allocation_positive` CHECK (quantity > 0)');
        }
    }

    public function down(): void
    {
        Area3MigrationTarget::assertAllowed();
        Schema::dropIfExists('continuation_allocations');
    }
};
