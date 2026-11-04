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
        Schema::create('service_areas', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->bigIncrements('id_service_area');
            $table->string('workzone', 20);
            $table->string('name', 100);
            $table->bigInteger('branch_id')->unsigned();
            $table->bigInteger('region_id')->unsigned()->nullable()->default(null);
            $table->tinyInteger('is_active')->default(1);
            $table->bigInteger('created_by')->unsigned()->nullable()->default(null);
            $table->bigInteger('updated_by')->unsigned()->nullable()->default(null);
            $table->timestamp('created_at')->nullable()->default(null);
            $table->timestamp('updated_at')->nullable()->default(null);
            $table->timestamp('deleted_at')->nullable()->default(null);
            $table->unique(['workzone'], 'a3_uq_004');
            $table->index(['region_id'], 'a3_ix_013');
            $table->index(['created_by'], 'a3_ix_014');
            $table->index(['updated_by'], 'a3_ix_015');
            $table->index(['branch_id', 'is_active'], 'a3_ix_016');
            $table->index(['is_active'], 'a3_ix_017');
            $table->foreign(['branch_id'], 'a3_fk_003')->references(['id_branch'])->on('branches');
            $table->foreign(['region_id'], 'a3_fk_004')->references(['id_region'])->on('regions')->onDelete('set null');
            $table->unique(['branch_id', 'id_service_area'], 'a3_uq_005');
        });
    }

    public function down(): void
    {
        Area3MigrationTarget::assertAllowed();
        Schema::dropIfExists('service_areas');
    }
};
