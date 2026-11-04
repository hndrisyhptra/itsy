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
        Schema::create('regions', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->bigIncrements('id_region');
            $table->string('code', 255);
            $table->bigInteger('area_id')->unsigned()->nullable()->default(null);
            $table->string('name', 255);
            $table->tinyInteger('is_active')->default(1);
            $table->bigInteger('created_by')->unsigned()->nullable()->default(null);
            $table->bigInteger('updated_by')->unsigned()->nullable()->default(null);
            $table->timestamp('created_at')->nullable()->default(null);
            $table->timestamp('updated_at')->nullable()->default(null);
            $table->timestamp('deleted_at')->nullable()->default(null);
            $table->unique(['code'], 'a3_uq_002');
            $table->index(['created_by'], 'a3_ix_004');
            $table->index(['updated_by'], 'a3_ix_005');
            $table->index(['is_active'], 'a3_ix_006');
            $table->index(['area_id'], 'a3_ix_007');
            $table->foreign(['area_id'], 'a3_fk_001')->references(['id_area'])->on('areas')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Area3MigrationTarget::assertAllowed();
        Schema::dropIfExists('regions');
    }
};
