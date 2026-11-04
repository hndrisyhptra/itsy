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
        Schema::create('designator_package_prices', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->bigIncrements('id_designator_package_price');
            $table->bigInteger('designator_id')->unsigned();
            $table->bigInteger('package_id')->unsigned();
            $table->decimal('price', 15, 2);
            $table->bigInteger('created_by')->unsigned()->nullable()->default(null);
            $table->bigInteger('updated_by')->unsigned()->nullable()->default(null);
            $table->timestamp('created_at')->nullable()->default(null);
            $table->timestamp('updated_at')->nullable()->default(null);
            $table->timestamp('deleted_at')->nullable()->default(null);
            $table->unique(['designator_id', 'package_id'], 'a3_uq_010');
            $table->index(['package_id'], 'a3_ix_027');
            $table->index(['created_by'], 'a3_ix_028');
            $table->index(['updated_by'], 'a3_ix_029');
            $table->foreign(['designator_id'], 'a3_fk_009')->references(['id_designator'])->on('designators')->onDelete('cascade');
            $table->foreign(['package_id'], 'a3_fk_010')->references(['id_package'])->on('packages')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Area3MigrationTarget::assertAllowed();
        Schema::dropIfExists('designator_package_prices');
    }
};
