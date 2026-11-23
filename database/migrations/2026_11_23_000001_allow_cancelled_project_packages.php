<?php

use App\Support\Area3MigrationTarget;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function budgetCondition(bool $cancelled = true): string
    {
        return $cancelled ? "budget > 0 OR (budget = 0 AND status IN ('DRAFT', 'CANCELLED') AND workflow_version = 3)" : "budget > 0 OR (budget = 0 AND status = 'DRAFT' AND workflow_version = 3)";
    }

    private function replaceChecks(bool $cancelled): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }
        $version = DB::selectOne('SELECT VERSION() AS version')->version;
        $drop = str_contains(strtolower($version), 'mariadb') ? 'DROP CONSTRAINT' : 'DROP CHECK';
        $extra = $cancelled ? ", 'CANCELLED'" : '';
        DB::statement("ALTER TABLE project_plans {$drop} pp_status, {$drop} pp_budget_positive, ADD CONSTRAINT pp_status CHECK (status IN ('DRAFT','SUBMITTED','RETURNED','REJECTED','APPROVED'{$extra})), ADD CONSTRAINT pp_budget_positive CHECK (".$this->budgetCondition($cancelled).')');
        DB::statement("ALTER TABLE approval_runs {$drop} approval_run_status, ADD CONSTRAINT approval_run_status CHECK (status IN ('PENDING','APPROVED','RETURNED','REJECTED'{$extra}))");
    }

    public function up(): void
    {
        Area3MigrationTarget::assertAllowed();
        $this->replaceChecks(true);
    }

    public function down(): void
    {
        Area3MigrationTarget::assertAllowed();
        if (DB::table('project_plans')->where('status', 'CANCELLED')->exists() || DB::table('approval_runs')->where('status', 'CANCELLED')->exists()) {
            throw new RuntimeException('Paket Cancel sudah tersimpan; rollback ditolak untuk menjaga data dan audit.');
        }
        $this->replaceChecks(false);
    }
};
