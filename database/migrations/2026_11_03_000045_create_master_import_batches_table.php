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
        Schema::create('master_import_batches', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->char('id_master_import', 36)->primary();
            $table->char('file_id', 36);
            $table->string('master_type', 20);
            $table->string('status', 20)->default('STAGED');
            $table->bigInteger('imported_by')->unsigned();
            $table->dateTime('created_at', 6);
            $table->foreign(['file_id'], 'a3_fk_083')->references(['id_document_file'])->on('document_files');
            $table->foreign(['imported_by'], 'a3_fk_084')->references(['id_user'])->on('users');
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE `master_import_batches` ADD CONSTRAINT `import_master_type` CHECK (master_type IN (\'AREA\',\'REGION\',\'BRANCH\',\'SERVICE_AREA\',\'DESIGNATOR_TYPE\',\'DESIGNATOR\',\'PACKAGE\',\'KHS\'))');
            DB::statement('ALTER TABLE `master_import_batches` ADD CONSTRAINT `import_status` CHECK (status IN (\'STAGED\',\'VALIDATED\',\'APPLIED\',\'REJECTED\'))');
        }
    }

    public function down(): void
    {
        Area3MigrationTarget::assertAllowed();
        Schema::dropIfExists('master_import_batches');
    }
};
