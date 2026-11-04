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
        Schema::create('term_actuals', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->bigIncrements('id_term_actual');
            $table->char('term_id', 36);
            $table->char('contract_component_id', 36);
            $table->decimal('actual_quantity', 18, 3);
            $table->unique(['term_id', 'contract_component_id'], 'a3_uq_021');
            $table->foreign(['term_id'], 'a3_fk_041')->references(['id_payment_term'])->on('payment_terms');
            $table->foreign(['contract_component_id'], 'a3_fk_042')->references(['id_contract_component'])->on('contract_components');
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE `term_actuals` ADD CONSTRAINT `actual_nonnegative` CHECK (actual_quantity >= 0)');
        }
    }

    public function down(): void
    {
        Area3MigrationTarget::assertAllowed();
        Schema::dropIfExists('term_actuals');
    }
};
