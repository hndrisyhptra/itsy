<?php

use App\Support\Area3MigrationTarget;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const ROLES = [
        'ADMIN_OPS' => 'Admin Operation',
        'ADMIN_OPS_AREA' => 'Admin Operation Area',
        'OFFICER_OPS1' => 'Officer 1 Operation',
        'OFFICER_OPS2' => 'Officer 2 Operation',
        'OFFICER_OPS3' => 'Officer 3 Operation',
        'MGR_BRANCH_OPS' => 'Manager Branch Operation',
        'GM_OPS' => 'General Manager Operation',
    ];

    public function up(): void
    {
        Area3MigrationTarget::assertAllowed();

        foreach (self::ROLES as $code => $name) {
            if (! DB::table('roles')->where('code', $code)->exists()) {
                DB::table('roles')->insert([
                    'id_role' => ((int) DB::table('roles')->max('id_role')) + 1,
                    'code' => $code,
                    'name' => $name,
                ]);
            }
        }
    }

    public function down(): void
    {
        Area3MigrationTarget::assertAllowed();

        $codes = array_keys(self::ROLES);
        $roleIds = DB::table('roles')->whereIn('code', $codes)->pluck('id_role');
        if (DB::table('user_assignments')->whereIn('role_id', $roleIds)->exists()
            || DB::table('users')->whereIn('role', $codes)->exists()) {
            throw new RuntimeException('Operation roles are assigned to accounts; refusing to remove their identities.');
        }

        DB::table('roles')->whereIn('code', $codes)->delete();
    }
};
