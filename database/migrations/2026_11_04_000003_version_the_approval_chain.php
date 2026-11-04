<?php

use App\Support\Area3MigrationTarget;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private function engineCheck(bool $expanded): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }
        $version = DB::selectOne('SELECT VERSION() AS version')->version;
        $drop = str_contains(strtolower($version), 'mariadb') ? 'DROP CONSTRAINT' : 'DROP CHECK';
        DB::statement('ALTER TABLE approval_steps '.$drop.' two_approval_steps');
        $legacy = "(step_no = 1 AND expected_role = 'PM_KONSTRUKSI') OR (step_no = 2 AND expected_role = 'MANAGER_KONSTRUKSI')";
        $new = "(step_no = 1 AND expected_role = 'PM_KONSTRUKSI') OR (step_no = 2 AND expected_role = 'ADMIN_AREA') OR (step_no = 3 AND expected_role = 'MGR_DEPLOYMENT') OR (step_no = 4 AND expected_role = 'MGR_BUSSINESS_SUPPORT') OR (step_no = 5 AND expected_role = 'HEAD_OF_PMO') OR (step_no = 6 AND expected_role = 'FINANCE')";
        $condition = $expanded ? '(workflow_version = 2 AND ('.$legacy.')) OR (workflow_version = 3 AND ('.$new.'))' : $legacy;
        DB::statement('ALTER TABLE approval_steps ADD CONSTRAINT two_approval_steps CHECK ('.$condition.')');
    }

    public function up(): void
    {
        Area3MigrationTarget::assertAllowed();
        Schema::table('approval_runs', fn (Blueprint $table) => $table->unsignedTinyInteger('workflow_version')->default(2));
        Schema::table('approval_steps', function (Blueprint $table) {
            $table->unsignedTinyInteger('workflow_version')->default(2);
            $table->string('expected_role', 40)->change();
        });
        $this->engineCheck(true);
    }

    public function down(): void
    {
        Area3MigrationTarget::assertAllowed();
        if (DB::table('approval_steps')->where('workflow_version', 3)->exists() || DB::table('approval_runs')->where('workflow_version', 3)->exists()) {
            throw new RuntimeException('Riwayat approval versi 3 tersedia; rollback ditolak agar tidak mengubah riwayat.');
        }
        $this->engineCheck(false);
        Schema::table('approval_steps', function (Blueprint $table) {
            $table->dropColumn('workflow_version');
            $table->string('expected_role', 20)->change();
        });
        Schema::table('approval_runs', fn (Blueprint $table) => $table->dropColumn('workflow_version'));
    }
};
