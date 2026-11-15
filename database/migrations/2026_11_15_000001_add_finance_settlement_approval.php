<?php

use App\Support\Area3MigrationTarget;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function checkCondition(): string
    {
        $previous = require __DIR__.'/2026_11_06_000003_version_support_first_approval_order.php';
        $roles = ['PM_KONSTRUKSI', 'ADMIN_AREA', 'MGR_PROJECT_SUPPORT', 'MGR_DEPLOYMENT', 'HEAD_OF_PMO', 'MGR_FINANCE_SETTLEMENT', 'FINANCE'];
        $steps = [];
        foreach ($roles as $i => $role) {
            $steps[] = '(step_no = '.($i + 1)." AND expected_role = '$role')";
        }

        return '('.$previous->checkCondition().') OR (approval_order_version = 3 AND workflow_version = 3 AND ('.implode(' OR ', $steps).'))';
    }

    private function replaceCheck(string $condition): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $version = DB::selectOne('SELECT VERSION() AS version')->version;
            $drop = str_contains(strtolower($version), 'mariadb') ? 'DROP CONSTRAINT' : 'DROP CHECK';
            DB::statement('ALTER TABLE approval_steps '.$drop.' two_approval_steps, ADD CONSTRAINT two_approval_steps CHECK ('.$condition.')');
        }
    }

    public function up(): void
    {
        Area3MigrationTarget::assertAllowed();
        if (! DB::table('roles')->where('code', 'MGR_FINANCE_SETTLEMENT')->exists()) {
            DB::table('roles')->insert(['id_role' => ((int) DB::table('roles')->max('id_role')) + 1, 'code' => 'MGR_FINANCE_SETTLEMENT', 'name' => 'Mgr Finance & Settlement']);
        }
        $this->replaceCheck($this->checkCondition());
    }

    public function down(): void
    {
        Area3MigrationTarget::assertAllowed();
        $role = DB::table('roles')->where('code', 'MGR_FINANCE_SETTLEMENT')->value('id_role');
        if (DB::table('approval_steps')->where('approval_order_version', 3)->exists() || DB::table('user_assignments')->where('role_id', $role)->exists() || DB::table('users')->where('role', 'MGR_FINANCE_SETTLEMENT')->exists()) {
            throw new RuntimeException('Peran/approval Settlement sudah digunakan; rollback ditolak untuk menjaga riwayat.');
        }
        $previous = require __DIR__.'/2026_11_06_000003_version_support_first_approval_order.php';
        $this->replaceCheck($previous->checkCondition());
        DB::table('roles')->where('code', 'MGR_FINANCE_SETTLEMENT')->delete();
    }
};
