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
        Schema::create('khs_prices', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->bigIncrements('id_khs_price');
            $table->bigInteger('price_list_id')->unsigned();
            $table->bigInteger('designator_id')->unsigned();
            $table->bigInteger('source_price_id')->unsigned()->nullable();
            $table->decimal('material_price', 20, 2)->nullable();
            $table->decimal('service_price', 20, 2)->nullable();
            $table->unique(['price_list_id', 'designator_id'], 'a3_uq_012');
            $table->foreign(['price_list_id'], 'a3_fk_013')->references(['id_khs_price_list'])->on('khs_price_lists');
            $table->foreign(['designator_id'], 'a3_fk_014')->references(['id_designator'])->on('designators');
            $table->foreign(['source_price_id'], 'a3_fk_015')->references(['id_designator_package_price'])->on('designator_package_prices');
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE `khs_prices` ADD CONSTRAINT `khs_components` CHECK (material_price IS NOT NULL OR service_price IS NOT NULL)');
            DB::statement('ALTER TABLE `khs_prices` ADD CONSTRAINT `khs_material_nonnegative` CHECK (material_price IS NULL OR material_price >= 0)');
            DB::statement('ALTER TABLE `khs_prices` ADD CONSTRAINT `khs_service_nonnegative` CHECK (service_price IS NULL OR service_price >= 0)');
        }
    }

    public function down(): void
    {
        Area3MigrationTarget::assertAllowed();
        Schema::dropIfExists('khs_prices');
    }
};
