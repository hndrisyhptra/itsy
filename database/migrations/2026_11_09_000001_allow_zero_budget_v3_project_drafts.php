<?php

use App\Support\Area3MigrationTarget;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function checkCondition(): string
    {
        return "budget > 0 OR (budget = 0 AND status = 'DRAFT' AND workflow_version = 3)";
    }

    private function replaceCheck(string $condition): void
    {
        $version = DB::selectOne('SELECT VERSION() AS version')->version;
        $drop = str_contains(strtolower($version), 'mariadb') ? 'DROP CONSTRAINT' : 'DROP CHECK';
        DB::statement('ALTER TABLE project_plans '.$drop.' pp_budget_positive, ADD CONSTRAINT pp_budget_positive CHECK ('.$condition.')');
    }

    public function up(): void
    {
        Area3MigrationTarget::assertAllowed();
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->replaceCheck($this->checkCondition());
        }
    }

    public function down(): void
    {
        Area3MigrationTarget::assertAllowed();
        if (DB::table('project_plans')->where('budget', '<=', 0)->exists()) {
            throw new RuntimeException('Draf PP tanpa BOQ masih bernilai nol; rollback ditolak agar data draf tetap utuh.');
        }
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->replaceCheck('budget > 0');
        }
    }
};
