<?php

use App\Support\Area3MigrationTarget;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function checkCondition(bool $projectSupport): string
    {
        $legacy = "(step_no = 1 AND expected_role = 'PM_KONSTRUKSI') OR (step_no = 2 AND expected_role = 'MANAGER_KONSTRUKSI')";
        $support = $projectSupport ? "expected_role IN ('MGR_BUSSINESS_SUPPORT', 'MGR_PROJECT_SUPPORT')" : "expected_role = 'MGR_BUSSINESS_SUPPORT'";
        $current = "(step_no = 1 AND expected_role = 'PM_KONSTRUKSI') OR (step_no = 2 AND expected_role = 'ADMIN_AREA') OR (step_no = 3 AND expected_role = 'MGR_DEPLOYMENT') OR (step_no = 4 AND $support) OR (step_no = 5 AND expected_role = 'HEAD_OF_PMO') OR (step_no = 6 AND expected_role = 'FINANCE')";

        return '(workflow_version = 2 AND ('.$legacy.')) OR (workflow_version = 3 AND ('.$current.'))';
    }

    private function replaceCheck(bool $projectSupport): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }
        $version = DB::selectOne('SELECT VERSION() AS version')->version;
        $drop = str_contains(strtolower($version), 'mariadb') ? 'DROP CONSTRAINT' : 'DROP CHECK';
        // One ALTER retains the previous protection if the replacement fails.
        DB::statement('ALTER TABLE approval_steps '.$drop.' two_approval_steps, ADD CONSTRAINT two_approval_steps CHECK ('.$this->checkCondition($projectSupport).')');
    }

    public function up(): void
    {
        Area3MigrationTarget::assertAllowed();
        $this->replaceCheck(true);
    }

    public function down(): void
    {
        Area3MigrationTarget::assertAllowed();
        if (DB::table('approval_steps')->where('expected_role', 'MGR_PROJECT_SUPPORT')->exists()) {
            throw new RuntimeException('Approval Project Support telah digunakan; rollback ditolak untuk menjaga riwayat.');
        }
        $this->replaceCheck(false);
    }
};
