<?php

use App\Support\Area3MigrationTarget;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private function changeNullable(bool $nullable): void
    {
        $triggers = DB::getDriverName() === 'sqlite' ? DB::table('sqlite_master')->where('type', 'trigger')->where('tbl_name', 'apm_settlements')->pluck('sql', 'name')->all() : [];
        Schema::table('apm_settlements', function (Blueprint $t) use ($nullable) {
            $column = $t->string('apm_no', 60)->nullable($nullable);
            if (DB::getDriverName() === 'sqlite') {
                $column->collation('NOCASE');
            } else {
                $column->charset('ascii')->collation('ascii_general_ci');
            }
            $column->change();
        });
        foreach ($triggers as $name => $sql) {
            if (! DB::table('sqlite_master')->where('type', 'trigger')->where('name', $name)->exists()) {
                DB::statement($sql);
            }
        }
    }

    public function up(): void
    {
        Area3MigrationTarget::assertAllowed();
        $this->changeNullable(true);
        $condition = 'apm_no IS NOT NULL OR (kasbon_id IS NOT NULL AND transfer_file_id IS NOT NULL)';
        if (DB::getDriverName() === 'sqlite') {
            foreach (['insert', 'update'] as $event) {
                $expression = preg_replace('/\b(apm_no|kasbon_id|transfer_file_id)\b/', 'NEW.$1', $condition);
                DB::statement('CREATE TRIGGER settlement_apm_optional_'.$event.' BEFORE '.strtoupper($event).' ON apm_settlements WHEN NOT ('.$expression.") BEGIN SELECT RAISE(ABORT, 'settlement_apm_optional'); END");
            }
        } else {
            DB::statement('ALTER TABLE apm_settlements ADD CONSTRAINT settlement_apm_optional CHECK ('.$condition.')');
        }
    }

    public function down(): void
    {
        Area3MigrationTarget::assertAllowed();
        if (DB::table('apm_settlements')->whereNull('apm_no')->exists()) {
            throw new RuntimeException('Pembayaran tanpa APM masih tersedia; rollback ditolak.');
        }
        if (DB::getDriverName() === 'sqlite') {
            foreach (['insert', 'update'] as $event) {
                DB::statement('DROP TRIGGER IF EXISTS settlement_apm_optional_'.$event);
            }
        } else {
            $version = DB::selectOne('SELECT VERSION() AS version')->version;
            DB::statement('ALTER TABLE apm_settlements '.(str_contains(strtolower($version), 'mariadb') ? 'DROP CONSTRAINT' : 'DROP CHECK').' settlement_apm_optional');
        }
        $this->changeNullable(false);
    }
};
