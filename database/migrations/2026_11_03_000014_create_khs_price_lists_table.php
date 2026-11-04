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
        Schema::create('khs_price_lists', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->bigIncrements('id_khs_price_list');
            $table->bigInteger('package_id')->unsigned();
            $table->bigInteger('branch_id')->unsigned()->nullable();
            $table->string('reference_no', 150);
            $table->integer('version_no')->unsigned();
            $table->date('valid_from');
            $table->date('valid_until')->nullable();
            $table->string('status', 20)->default('DRAFT');
            $table->unique(['reference_no', 'version_no'], 'a3_uq_011');
            $table->foreign(['package_id'], 'a3_fk_011')->references(['id_package'])->on('packages');
            $table->foreign(['branch_id'], 'a3_fk_012')->references(['id_branch'])->on('branches');
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE `khs_price_lists` ADD CONSTRAINT `khs_period` CHECK (valid_until IS NULL OR valid_until >= valid_from)');
            DB::statement('ALTER TABLE `khs_price_lists` ADD CONSTRAINT `khs_status` CHECK (status IN (\'DRAFT\',\'ACTIVE\',\'RETIRED\'))');
        }
    }

    public function down(): void
    {
        Area3MigrationTarget::assertAllowed();
        Schema::dropIfExists('khs_price_lists');
    }
};
