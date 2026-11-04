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
        Schema::create('volume_balances', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->bigIncrements('id_volume_balance');
            $table->char('boq_component_id', 36)->unique();
            $table->decimal('original_quantity', 18, 3);
            $table->decimal('available', 18, 3);
            $table->decimal('reserved', 18, 3);
            $table->decimal('committed', 18, 3);
            $table->decimal('accepted', 18, 3);
            $table->bigInteger('revision')->unsigned()->default(0);
            $table->foreign(['boq_component_id'], 'a3_fk_080')->references(['id_boq_component'])->on('boq_components');
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE `volume_balances` ADD CONSTRAINT `balance_nonnegative` CHECK (original_quantity >= 0 AND available >= 0 AND reserved >= 0 AND committed >= 0 AND accepted >= 0)');
            DB::statement('ALTER TABLE `volume_balances` ADD CONSTRAINT `volume_conservation` CHECK (original_quantity = available + reserved + committed + accepted)');
        }
    }

    public function down(): void
    {
        Area3MigrationTarget::assertAllowed();
        Schema::dropIfExists('volume_balances');
    }
};
