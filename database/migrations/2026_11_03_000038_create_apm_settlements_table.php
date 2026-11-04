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
        Schema::create('apm_settlements', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->char('id_apm_settlement', 36)->primary();
            $table->char('term_id', 36)->unique();
            $table->char('verified_scan_id', 36);
            $apm = $table->string('apm_no', 60)->unique();
            if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
                $apm->charset('ascii')->collation('ascii_general_ci');
            } else {
                $apm->collation('NOCASE');
            }
            $table->decimal('amount', 20, 2);
            $table->bigInteger('recorded_by')->unsigned();
            $table->dateTime('recorded_at', 6);
            $table->foreign(['term_id', 'verified_scan_id'], 'a3_fk_068')->references(['term_id', 'id_term_scan'])->on('term_scans');
            $table->foreign(['recorded_by'], 'a3_fk_069')->references(['id_user'])->on('users');
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE `apm_settlements` ADD CONSTRAINT `settlement_positive` CHECK (amount > 0)');
        }
    }

    public function down(): void
    {
        Area3MigrationTarget::assertAllowed();
        Schema::dropIfExists('apm_settlements');
    }
};
