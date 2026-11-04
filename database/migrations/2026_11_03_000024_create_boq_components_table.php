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
        Schema::create('boq_components', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->char('id_boq_component', 36)->primary();
            $table->char('boq_line_id', 36);
            $table->string('component', 1);
            $table->decimal('quantity', 18, 3);
            $table->decimal('official_unit_price', 20, 2);
            $table->unique(['boq_line_id', 'component'], 'a3_uq_018');
            $table->foreign(['boq_line_id'], 'a3_fk_034')->references(['id_boq'])->on('boq_lines');
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE `boq_components` ADD CONSTRAINT `boq_component_type` CHECK (component IN (\'M\',\'S\'))');
            DB::statement('ALTER TABLE `boq_components` ADD CONSTRAINT `boq_component_numbers` CHECK (quantity > 0 AND quantity <= 1000000000 AND official_unit_price >= 0)');
        }
    }

    public function down(): void
    {
        Area3MigrationTarget::assertAllowed();
        Schema::dropIfExists('boq_components');
    }
};
