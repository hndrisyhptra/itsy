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
        Schema::create('volume_releases', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->char('id_volume_release', 36)->primary();
            $table->char('completion_id', 36);
            $table->char('contract_component_id', 36);
            $table->char('boq_component_id', 36);
            $table->decimal('quantity', 18, 3);
            $table->bigInteger('authorized_by')->unsigned();
            $table->dateTime('authorized_at', 6);
            $table->unique(['id_volume_release', 'boq_component_id'], 'a3_uq_030');
            $table->foreign(['completion_id'], 'a3_fk_073')->references(['id_ba_pp'])->on('ba_completions');
            $table->foreign(['contract_component_id'], 'a3_fk_074')->references(['id_contract_component'])->on('contract_components');
            $table->foreign(['boq_component_id'], 'a3_fk_075')->references(['id_boq_component'])->on('boq_components');
            $table->foreign(['authorized_by'], 'a3_fk_076')->references(['id_user'])->on('users');
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE `volume_releases` ADD CONSTRAINT `release_positive` CHECK (quantity > 0)');
        }
    }

    public function down(): void
    {
        Area3MigrationTarget::assertAllowed();
        Schema::dropIfExists('volume_releases');
    }
};
