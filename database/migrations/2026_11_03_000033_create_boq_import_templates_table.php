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
        Schema::create('boq_import_templates', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->bigIncrements('id_boq_import_template');
            $table->string('code', 60);
            $table->integer('version_no')->unsigned();
            $table->char('file_id', 36);
            $table->string('worksheet_name', 100);
            $table->integer('header_row')->unsigned();
            $table->json('column_mapping');
            $table->boolean('active')->default(true);
            $table->bigInteger('created_by')->unsigned();
            $table->dateTime('created_at', 6);
            $table->unique(['code', 'version_no'], 'a3_uq_025');
            $table->foreign(['file_id'], 'a3_fk_051')->references(['id_document_file'])->on('document_files');
            $table->foreign(['created_by'], 'a3_fk_052')->references(['id_user'])->on('users');
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE `boq_import_templates` ADD CONSTRAINT `boq_template_version` CHECK (version_no > 0 AND header_row > 0)');
        }
    }

    public function down(): void
    {
        Area3MigrationTarget::assertAllowed();
        Schema::dropIfExists('boq_import_templates');
    }
};
