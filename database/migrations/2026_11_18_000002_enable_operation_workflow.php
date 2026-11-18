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
        $old = require __DIR__.'/2026_11_15_000001_add_finance_settlement_approval.php';
        $roles = ['OFFICER_OPS1', 'MGR_BRANCH_OPS', 'OFFICER_SS1', 'MGR_SS', 'GM_OPS', 'MGR_FINANCE_SETTLEMENT', 'FINANCE'];
        $parts = [];
        foreach ($roles as $i => $role) {
            $parts[] = '(step_no = '.($i + 1)." AND expected_role = '$role')";
        }

        return '('.$old->checkCondition().') OR (approval_order_version = 4 AND workflow_version = 3 AND ('.implode(' OR ', $parts).'))';
    }

    private function replaceCheck(string $condition): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $drop = str_contains(strtolower(DB::selectOne('SELECT VERSION() AS version')->version), 'mariadb') ? 'DROP CONSTRAINT' : 'DROP CHECK';
            DB::statement('ALTER TABLE approval_steps '.$drop.' two_approval_steps, ADD CONSTRAINT two_approval_steps CHECK ('.$condition.')');
        }
    }

    private function alter(string $table, Closure $change): void
    {
        $triggers = DB::getDriverName() === 'sqlite' ? DB::table('sqlite_master')->where('type', 'trigger')->where('tbl_name', $table)->get(['name', 'sql']) : collect();
        Schema::table($table, $change);
        foreach ($triggers as $trigger) {
            if (! DB::table('sqlite_master')->where('type', 'trigger')->where('name', $trigger->name)->exists()) {
                DB::unprepared($trigger->sql);
            }
        }
    }

    public function up(): void
    {
        Area3MigrationTarget::assertAllowed();
        // Validate saved document units before DDL; never infer each PP unit from its shared Project row.
        DB::table('project_plans')->select(['id_pp', 'document_data'])->orderBy('id_pp')->chunk(500, function ($rows) {
            foreach ($rows as $row) {
                $p = json_decode($row->document_data, true, 512, JSON_THROW_ON_ERROR);
                if (($p['workflowVersion'] ?? 1) >= 3 && ! in_array($p['unit'] ?? '', ['Konstruksi', 'Operation'], true)) {
                    throw new RuntimeException('Unit PP tersimpan tidak valid: '.$row->id_pp.'. Periksa sebelum migration.');
                }
            }
        });
        foreach (['KONSTRUKSI' => 'Konstruksi', 'OPERATION' => 'Operation'] as $code => $name) {
            if (! DB::table('work_units')->where('code', $code)->exists()) {
                DB::table('work_units')->insert(['id_work_unit' => ((int) DB::table('work_units')->max('id_work_unit')) + 1, 'code' => $code, 'name' => $name]);
            }
        }
        foreach (['OFFICER_SS1' => 'Officer 1 SS', 'MGR_SS' => 'Manager SS'] as $code => $name) {
            if (! DB::table('roles')->where('code', $code)->exists()) {
                $id = max((int) DB::table('roles')->max('id_role'), (int) DB::table('master_data_changes')->where('entity', 'role')->max('record_id')) + 1;
                DB::table('roles')->insert(['id_role' => $id, 'code' => $code, 'name' => $name]);
            }
        }
        if (! Schema::hasColumn('users', 'work_unit_id')) {
            $this->alter('users', function (Blueprint $t) {
                $t->unsignedSmallInteger('work_unit_id')->nullable();
                $t->string('access_scope', 10)->default('BRANCH');
                $t->foreign('work_unit_id')->references('id_work_unit')->on('work_units');
            });
        }
        if (! Schema::hasColumn('project_plans', 'work_unit_id')) {
            $this->alter('project_plans', function (Blueprint $t) {
                $t->unsignedSmallInteger('work_unit_id')->nullable();
                $t->foreign('work_unit_id')->references('id_work_unit')->on('work_units');
                $t->index(['workspace_id', 'work_unit_id', 'branch_id'], 'a3_pp_unit_branch');
            });
        }
        $units = DB::table('work_units')->pluck('id_work_unit', 'code');
        DB::table('project_plans')->whereNull('work_unit_id')->select(['id_pp', 'document_data'])->orderBy('id_pp')->chunk(500, function ($rows) use ($units) {
            foreach ($rows as $row) {
                $p = json_decode($row->document_data, true, 512, JSON_THROW_ON_ERROR);
                DB::table('project_plans')->where('id_pp', $row->id_pp)->update(['work_unit_id' => $units[$p['unit'] === 'Operation' ? 'OPERATION' : 'KONSTRUKSI']]);
            }
        });
        $this->replaceCheck($this->checkCondition());
    }

    public function down(): void
    {
        Area3MigrationTarget::assertAllowed();
        if (DB::table('approval_steps')->where('approval_order_version', 4)->exists() || DB::table('users')->whereNotNull('work_unit_id')->orWhere('access_scope', 'REGION')->exists()) {
            throw new RuntimeException('Scope/approval Operation sudah digunakan; rollback ditolak untuk menjaga akses dan riwayat.');
        }
        $ids = DB::table('roles')->whereIn('code', ['OFFICER_SS1', 'MGR_SS'])->pluck('id_role');
        if (DB::table('user_assignments')->whereIn('role_id', $ids)->exists() || DB::table('users')->whereIn('role', ['OFFICER_SS1', 'MGR_SS'])->exists()) {
            throw new RuntimeException('Role SS telah dipakai akun; rollback ditolak.');
        }
        $previous = require __DIR__.'/2026_11_15_000001_add_finance_settlement_approval.php';
        $this->replaceCheck($previous->checkCondition());
        $this->alter('project_plans', function (Blueprint $t) {
            $t->dropForeign(['work_unit_id']);
            $t->dropIndex('a3_pp_unit_branch');
            $t->dropColumn('work_unit_id');
        });
        $this->alter('users', function (Blueprint $t) {
            $t->dropForeign(['work_unit_id']);
            $t->dropColumn(['work_unit_id', 'access_scope']);
        });
        // Keep catalogue identities, including pre-existing user-created SS roles.
    }
};
