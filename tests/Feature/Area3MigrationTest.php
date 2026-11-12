<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Area3MasterData;
use App\Support\Area3MigrationTarget;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class Area3MigrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'area3_migration_test', 'database.connections.area3_migration_test' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true,
        ]]);
    }

    public function test_user_extension_backfills_username_and_role_without_changing_password_or_guessing_placement(): void
    {
        $this->migrate();
        $steps = DB::table('migrations')->where('migration', '>=', '2026_11_04_000002_extend_users_identity_and_placement')->count();
        $this->artisan('migrate:rollback', ['--step' => $steps, '--force' => true])->assertSuccessful();
        $password = Hash::make('ChosenPassword123');
        DB::table('users')->insert(['id_user' => 88, 'name' => 'Legacy fixture', 'email' => 'legacy@example.test', 'password' => $password]);
        DB::table('roles')->insert(['id_role' => 91, 'code' => 'SUPERADMIN', 'name' => 'Superadmin']);
        DB::table('user_assignments')->insert(['user_id' => 88, 'role_id' => 91, 'branch_id' => DB::table('branches')->value('id_branch'), 'active' => true]);
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        $user = User::findOrFail(88);
        $this->assertSame('user-88', $user->username);
        $this->assertSame('SUPERADMIN', $user->role);
        $this->assertSame($password, $user->getRawOriginal('password'));
        foreach (['nik', 'id_regions', 'id_branch', 'id_service_areas'] as $field) {
            $this->assertNull($user->$field);
        }
        $this->post('/login', ['username' => 'USER-88', 'password' => 'ChosenPassword123'])->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
        $this->post('/logout');
        $this->post('/login', ['email' => $user->email, 'password' => 'ChosenPassword123'])->assertSessionHasErrors('username');
        $this->assertGuest();
    }

    private function migrate(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
    }

    public function test_all_named_keys_and_foreign_keys_migrate_without_a_second_users_table(): void
    {
        $this->migrate();
        $manifest = json_decode(file_get_contents(database_path('mysql/master-source-manifest.json')), true);
        $foreignKeys = 0;
        foreach (($manifest['schema']['primary_keys'] + ['finance_private_files' => 'id_finance_file', 'mandor_payee_profiles' => 'id_payee', 'finance_kasbons' => 'id_kasbon']) as $table => $key) {
            $this->assertTrue(Schema::hasColumn($table, $key), $table.'.'.$key);
            $primary = collect(Schema::getIndexes($table))->firstWhere('primary', true);
            $this->assertSame([$key], $primary['columns'], $table);
            $foreignKeys += count(Schema::getForeignKeys($table));
        }
        $this->assertSame(107, $foreignKeys);
        $this->assertFalse(Schema::hasTable('area3_users'));
        $this->assertFalse(Schema::hasColumn('users', 'id'));
        $this->assertTrue(Schema::hasColumn('boq_lines', 'source_import_id'));
        $this->assertSame(0, DB::table('users')->count());
    }

    public function test_supplied_master_rows_are_loaded_exactly_and_migrate_is_repeatable(): void
    {
        $this->migrate();
        $snapshot = json_decode(file_get_contents(database_path('data/area3-master-data.json')), true);
        foreach ($snapshot as $table => $expected) {
            $key = array_key_first($expected[0]);
            usort($expected, fn ($a, $b) => $a[$key] <=> $b[$key]);
            $actual = DB::table($table)->orderBy($key)->get()->map(function ($row) {
                $row = (array) $row;
                if (isset($row['price'])) {
                    $row['price'] = sprintf('%.2F', $row['price']);
                }

                return $row;
            })->all();
            // SQL returns schema column order; compare field/value mappings independently.
            foreach ($actual as &$row) {
                ksort($row);
            }
            unset($row);
            foreach ($expected as &$row) {
                ksort($row);
            }
            unset($row);
            $this->assertSame($expected, $actual, $table);
        }
        $this->assertSame(7, DB::table('designator_package_prices')->where('price', 0)->count());
        $this->assertSame('R424', DB::table('branches')->where('name', 'PEKALONGAN')->value('kode_branch'));
        $this->assertSame(1, DB::table('branches')->where('name', 'DENPASAR')->count());
        $this->migrate();
        $this->assertSame(1030, DB::table('designator_package_prices')->count());
        $this->assertSame(67, DB::table('migrations')->count());
    }

    public function test_users_alignment_preserves_existing_ids_and_passwords_and_the_model_uses_id_user(): void
    {
        $base = require database_path('migrations/0001_01_01_000000_create_users_table.php');
        $base->up();
        DB::table('users')->insert(['id' => 87, 'name' => 'Preserved fixture', 'email' => 'migration-fixture@example.test', 'password' => 'existing-hash-fixture']);
        $migration = require database_path('migrations/2026_11_03_000001_align_users_for_area3.php');
        $migration->up();
        $user = User::findOrFail(87);
        $this->assertSame('id_user', $user->getKeyName());
        $this->assertSame(87, $user->getKey());
        $this->assertSame('existing-hash-fixture', $user->getRawOriginal('password'));
        $this->assertTrue($user->active);
        $migration->down();
        $this->assertSame(87, DB::table('users')->value('id'));
        $this->assertSame('existing-hash-fixture', DB::table('users')->value('password'));
    }

    public function test_existing_master_data_blocks_import_without_any_partial_inserts(): void
    {
        $paths = array_values(array_filter(glob(database_path('migrations/*.php')), fn ($p) => ! str_contains($p, '000048_import_supplied')));
        $this->artisan('migrate', ['--force' => true, '--path' => $paths, '--realpath' => true])->assertSuccessful();
        DB::table('designator_types')->insert(['id_designator_type' => 99, 'code' => 'EXISTING', 'name' => 'Existing master']);
        try {
            Area3MasterData::install();
            $this->fail('An occupied master table must block import');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('sudah berisi data', $error->getMessage());
        }
        $this->assertSame(0, DB::table('areas')->count());
        $this->assertSame(0, DB::table('branches')->count());
        $this->assertSame('EXISTING', DB::table('designator_types')->value('code'));
    }

    public function test_full_rollback_and_reapplication_keep_dependency_order_valid(): void
    {
        $this->migrate();
        $this->artisan('migrate:rollback', ['--force' => true])->assertSuccessful();
        $this->assertFalse(Schema::hasTable('users'));
        $this->assertFalse(Schema::hasTable('boq_import_rows'));
        $this->assertSame(0, DB::table('migrations')->count());
        $this->migrate();
        $this->assertSame(392, DB::table('service_areas')->count());
    }

    public function test_wrong_database_is_rejected_before_connecting(): void
    {
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('getDriverName')->once()->andReturn('mysql');
        $connection->shouldReceive('getDatabaseName')->once()->andReturn('unrelated_database');
        $connection->shouldNotReceive('getPdo');
        DB::shouldReceive('connection')->once()->andReturn($connection);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('database its');
        Area3MigrationTarget::assertAllowed();
    }
}
