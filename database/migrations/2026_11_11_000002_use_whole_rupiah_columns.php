<?php

use App\Support\Area3MigrationTarget;
use App\Support\WholeRupiah;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Area3MigrationTarget::assertAllowed();
        WholeRupiah::assertIntegralStorage();
        $triggers = $this->triggers();
        foreach (WholeRupiah::COLUMNS as $table => $columns) {
            Schema::table($table, function (Blueprint $t) use ($columns) {
                foreach ($columns as $column => $nullable) {
                    $t->bigInteger($column)->nullable($nullable)->change();
                }
            });
        }
        $this->restoreTriggers($triggers);
    }

    public function down(): void
    {
        Area3MigrationTarget::assertAllowed();
        $triggers = $this->triggers();
        foreach (WholeRupiah::COLUMNS as $table => $columns) {
            Schema::table($table, function (Blueprint $t) use ($table, $columns) {
                foreach ($columns as $column => $nullable) {
                    $t->decimal($column, $table === 'designator_package_prices' ? 15 : 20, 2)->nullable($nullable)->change();
                }
            });
        }
        $this->restoreTriggers($triggers);
    }

    private function triggers(): array
    {
        return DB::getDriverName() === 'sqlite'
            ? DB::table('sqlite_master')->where('type', 'trigger')->whereIn('tbl_name', array_keys(WholeRupiah::COLUMNS))->pluck('sql', 'name')->all() : [];
    }

    private function restoreTriggers(array $triggers): void
    {
        if (! $triggers) {
            return;
        }
        $existing = DB::table('sqlite_master')->where('type', 'trigger')->pluck('name')->all();
        foreach ($triggers as $name => $sql) {
            if (! in_array($name, $existing, true)) {
                DB::statement($sql);
            }
        }
    }
};
