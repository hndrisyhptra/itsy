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
        Schema::create('document_files', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->char('id_document_file', 36)->primary();
            $table->string('storage_disk', 40);
            $table->string('storage_key', 500)->unique();
            $table->string('original_name', 200);
            $table->string('mime_type', 80);
            $table->bigInteger('byte_size')->unsigned();
            $digest = $table->char('sha256', 64);
            if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
                $digest->charset('ascii')->collation('ascii_bin');
            } else {
                $digest->collation('BINARY');
            }
            $table->bigInteger('uploaded_by')->unsigned();
            $table->dateTime('uploaded_at', 6);
            $table->foreign(['uploaded_by'], 'a3_fk_050')->references(['id_user'])->on('users');
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE `document_files` ADD CONSTRAINT `file_bytes` CHECK (byte_size > 0)');
        }
    }

    public function down(): void
    {
        Area3MigrationTarget::assertAllowed();
        Schema::dropIfExists('document_files');
    }
};
