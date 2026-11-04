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
        Schema::create('contract_components', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->char('id_contract_component', 36)->primary();
            $table->char('contract_id', 36);
            $table->char('boq_component_id', 36);
            $table->decimal('quantity', 18, 3);
            $table->decimal('khs_ceiling_snapshot', 20, 2);
            $table->decimal('sph_price', 20, 2);
            $table->decimal('offered_price', 20, 2);
            $table->decimal('agreed_price', 20, 2);
            $table->unique(['contract_id', 'boq_component_id'], 'a3_uq_019');
            $table->foreign(['contract_id'], 'a3_fk_036')->references(['id_contract'])->on('contracts');
            $table->foreign(['boq_component_id'], 'a3_fk_037')->references(['id_boq_component'])->on('boq_components');
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE `contract_components` ADD CONSTRAINT `contract_component_numbers` CHECK (quantity > 0 AND khs_ceiling_snapshot >= 0 AND sph_price >= 0 AND offered_price >= 0 AND agreed_price >= 0 AND agreed_price <= khs_ceiling_snapshot)');
        }
    }

    public function down(): void
    {
        Area3MigrationTarget::assertAllowed();
        Schema::dropIfExists('contract_components');
    }
};
