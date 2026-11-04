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
        Schema::create('volume_movements', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->char('id_volume_movement', 36)->primary();
            $table->char('receipt_id', 36);
            $table->char('boq_component_id', 36);
            $table->string('movement_type', 30);
            $table->string('source_type', 40);
            $table->char('source_id', 36);
            $table->decimal('available_delta', 18, 3);
            $table->decimal('reserved_delta', 18, 3);
            $table->decimal('committed_delta', 18, 3);
            $table->decimal('accepted_delta', 18, 3);
            $table->dateTime('created_at', 6);
            $table->unique(['receipt_id', 'boq_component_id'], 'a3_uq_032');
            $table->foreign(['receipt_id'], 'a3_fk_081')->references(['id_command_receipt'])->on('command_receipts');
            $table->foreign(['boq_component_id'], 'a3_fk_082')->references(['id_boq_component'])->on('boq_components');
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE `volume_movements` ADD CONSTRAINT `movement_balanced` CHECK (available_delta + reserved_delta + committed_delta + accepted_delta = 0)');
        }
    }

    public function down(): void
    {
        Area3MigrationTarget::assertAllowed();
        Schema::dropIfExists('volume_movements');
    }
};
