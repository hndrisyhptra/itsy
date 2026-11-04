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
        Schema::table('boq_lines', function (Blueprint $table) {
            $table->char('source_import_id', 36)->nullable();
            $table->unsignedInteger('source_row_no')->nullable();
            $table->unique(['source_import_id', 'source_row_no'], 'a3_boq_source_unique');
            $table->foreign(['source_import_id', 'source_row_no'], 'a3_boq_source_foreign')->references(['batch_id', 'row_no'])->on('boq_import_rows');
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE `boq_lines` ADD CONSTRAINT `boq_line_source_pair` CHECK ((source_import_id IS NULL AND source_row_no IS NULL) OR (source_import_id IS NOT NULL AND source_row_no IS NOT NULL))');
        }
    }

    public function down(): void
    {
        Area3MigrationTarget::assertAllowed();
        if (DB::getDriverName() === 'mysql' && ! DB::connection()->isMaria()) {
            DB::statement('ALTER TABLE `boq_lines` DROP CHECK `boq_line_source_pair`');
        } elseif (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE `boq_lines` DROP CONSTRAINT `boq_line_source_pair`');
        }
        Schema::table('boq_lines', function (Blueprint $table) {
            $table->dropForeign(DB::getDriverName() === 'sqlite' ? ['source_import_id', 'source_row_no'] : 'a3_boq_source_foreign');
            $table->dropUnique('a3_boq_source_unique');
            $table->dropColumn(['source_import_id', 'source_row_no']);
        });
    }
};
