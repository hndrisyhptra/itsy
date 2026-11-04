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
        Schema::create('master_import_rows', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->bigIncrements('id_master_import_row');
            $table->char('batch_id', 36);
            $table->integer('row_no')->unsigned();
            $table->json('raw_values');
            $table->json('validation_errors')->nullable();
            $table->unique(['batch_id', 'row_no'], 'a3_uq_033');
            $table->foreign(['batch_id'], 'a3_fk_085')->references(['id_master_import'])->on('master_import_batches');
        });
    }

    public function down(): void
    {
        Area3MigrationTarget::assertAllowed();
        Schema::dropIfExists('master_import_rows');
    }
};
