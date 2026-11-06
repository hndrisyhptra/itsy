<?php

use App\Support\Area3MigrationTarget;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function checkCondition(): string
    {
        $previous = require __DIR__.'/2026_11_06_000002_allow_project_support_approval_steps.php';
        $current = "(step_no = 1 AND expected_role = 'PM_KONSTRUKSI') OR (step_no = 2 AND expected_role = 'ADMIN_AREA') OR (step_no = 3 AND expected_role = 'MGR_PROJECT_SUPPORT') OR (step_no = 4 AND expected_role = 'MGR_DEPLOYMENT') OR (step_no = 5 AND expected_role = 'HEAD_OF_PMO') OR (step_no = 6 AND expected_role = 'FINANCE')";

        return '(approval_order_version = 1 AND ('.$previous->checkCondition(true).')) OR (approval_order_version = 2 AND workflow_version = 3 AND ('.$current.'))';
    }

    private function replaceCheck(string $condition, bool $removeColumn = false): void
    {
        $version = DB::selectOne('SELECT VERSION() AS version')->version;
        $drop = str_contains(strtolower($version), 'mariadb') ? 'DROP CONSTRAINT' : 'DROP CHECK';
        DB::statement('ALTER TABLE approval_steps '.$drop.' two_approval_steps, ADD CONSTRAINT two_approval_steps CHECK ('.$condition.')'.($removeColumn ? ', DROP COLUMN approval_order_version' : ''));
    }

    public function up(): void
    {
        Area3MigrationTarget::assertAllowed();
        // Retrying after an interrupted MySQL DDL must not duplicate the column.
        if (! Schema::hasColumn('approval_steps', 'approval_order_version')) {
            Schema::table('approval_steps', fn (Blueprint $t) => $t->unsignedTinyInteger('approval_order_version')->default(1));
        }
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->replaceCheck($this->checkCondition());
        }
    }

    public function down(): void
    {
        Area3MigrationTarget::assertAllowed();
        if (DB::table('approval_steps')->where('approval_order_version', 2)->exists()) {
            throw new RuntimeException('Urutan approval baru telah digunakan; rollback ditolak untuk menjaga riwayat.');
        }
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $previous = require __DIR__.'/2026_11_06_000002_allow_project_support_approval_steps.php';
            $this->replaceCheck($previous->checkCondition(true), true);
        } else {
            Schema::table('approval_steps', fn (Blueprint $t) => $t->dropColumn('approval_order_version'));
        }
    }
};
