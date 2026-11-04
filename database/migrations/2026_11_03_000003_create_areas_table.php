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
        Schema::create('areas', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->bigIncrements('id_area');
            $table->string('code', 10)->comment('Kode Area, mis. 3');
            $table->string('name', 100)->comment('Nama Area, mis. Area 3');
            $table->string('description', 500)->nullable()->default(null);
            $table->tinyInteger('is_active')->default(1);
            $table->bigInteger('created_by')->unsigned()->nullable()->default(null);
            $table->bigInteger('updated_by')->unsigned()->nullable()->default(null);
            $table->timestamp('created_at')->nullable()->default(null);
            $table->timestamp('updated_at')->nullable()->default(null);
            $table->timestamp('deleted_at')->nullable()->default(null);
            $table->unique(['code'], 'a3_uq_001');
            $table->index(['created_by'], 'a3_ix_001');
            $table->index(['updated_by'], 'a3_ix_002');
            $table->index(['is_active'], 'a3_ix_003');
        });
    }

    public function down(): void
    {
        Area3MigrationTarget::assertAllowed();
        Schema::dropIfExists('areas');
    }
};
