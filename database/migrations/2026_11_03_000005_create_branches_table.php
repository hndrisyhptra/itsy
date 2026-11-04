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
        Schema::create('branches', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->bigIncrements('id_branch');
            $table->string('code', 255);
            $table->string('kode_branch', 30)->unique()->comment('PP numbering suffix, e.g. R424; code retains PKL etc.');
            $table->string('name', 255);
            $table->string('region', 50)->nullable()->default(null);
            $table->bigInteger('region_id')->unsigned()->nullable()->default(null);
            $table->tinyInteger('is_active')->default(1);
            $table->timestamp('created_at')->nullable()->default(null);
            $table->timestamp('updated_at')->nullable()->default(null);
            $table->bigInteger('created_by')->unsigned()->nullable()->default(null);
            $table->bigInteger('updated_by')->unsigned()->nullable()->default(null);
            $table->timestamp('deleted_at')->nullable()->default(null);
            $table->unique(['code'], 'a3_uq_003');
            $table->index(['region', 'name'], 'a3_ix_008');
            $table->index(['region_id'], 'a3_ix_009');
            $table->index(['created_by'], 'a3_ix_010');
            $table->index(['updated_by'], 'a3_ix_011');
            $table->index(['is_active'], 'a3_ix_012');
            $table->foreign(['region_id'], 'a3_fk_002')->references(['id_region'])->on('regions')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Area3MigrationTarget::assertAllowed();
        Schema::dropIfExists('branches');
    }
};
