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
        Schema::create('boq_import_rows', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->bigIncrements('id_boq_import_row');
            $table->char('batch_id', 36);
            $table->integer('row_no')->unsigned();
            $table->json('raw_values');
            $table->string('designator_code', 255)->nullable();
            $table->string('unit_code', 255)->nullable();
            $table->decimal('quantity', 18, 3)->nullable();
            $table->decimal('excel_material_price', 20, 2)->nullable();
            $table->decimal('excel_service_price', 20, 2)->nullable();
            $table->bigInteger('matched_khs_price_id')->unsigned()->nullable();
            $table->boolean('price_mismatch')->default(false);
            $table->string('status', 20)->default('UNMATCHED');
            $table->json('validation_errors')->nullable();
            $table->bigInteger('reviewed_by')->unsigned()->nullable();
            $table->dateTime('reviewed_at', 6)->nullable();
            $table->text('review_note')->nullable();
            $table->unique(['batch_id', 'row_no'], 'a3_uq_026');
            $table->foreign(['batch_id'], 'a3_fk_059')->references(['id_boq_import'])->on('boq_import_batches');
            $table->foreign(['matched_khs_price_id'], 'a3_fk_060')->references(['id_khs_price'])->on('khs_prices');
            $table->foreign(['reviewed_by'], 'a3_fk_061')->references(['id_user'])->on('users');
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE `boq_import_rows` ADD CONSTRAINT `boq_import_row_positive` CHECK (row_no > 0)');
            DB::statement('ALTER TABLE `boq_import_rows` ADD CONSTRAINT `boq_import_row_status` CHECK (status IN (\'UNMATCHED\',\'MATCHED\',\'REVIEW\',\'INVALID\',\'VALIDATED\'))');
            DB::statement('ALTER TABLE `boq_import_rows` ADD CONSTRAINT `boq_import_row_valid` CHECK (status <> \'VALIDATED\' OR (matched_khs_price_id IS NOT NULL AND quantity IS NOT NULL AND quantity > 0 AND quantity <= 1000000000 AND validation_errors IS NULL))');
            DB::statement('ALTER TABLE `boq_import_rows` ADD CONSTRAINT `boq_import_row_review` CHECK (status <> \'VALIDATED\' OR price_mismatch = FALSE OR (reviewed_by IS NOT NULL AND reviewed_at IS NOT NULL AND review_note IS NOT NULL AND CHAR_LENGTH(TRIM(review_note)) > 0))');
        }
    }

    public function down(): void
    {
        Area3MigrationTarget::assertAllowed();
        Schema::dropIfExists('boq_import_rows');
    }
};
