<?php

namespace Tests\Feature;

use App\Support\BudgetPidMigrationSchema;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/** MySQL metadata/DDL contracts only: no PDO/server connection or real DDL. */
class BudgetPidMigrationMysqlMetadataTest extends TestCase
{
    private array $sql = [];

    private function definition(): array
    {
        return ['budget_pid_imports' => function (Blueprint $t) {
            $t->uuid('id_budget_import')->primary();
            $t->unsignedBigInteger('actor_user_id');
            $t->foreign('actor_user_id')->references('id_user')->on('users')->restrictOnDelete();
            $t->string('unit', 20);
            $t->json('summary');
            $t->timestamp('created_at');
            $t->timestamp('updated_at');
            $t->index(['unit']);
        }];
    }

    private function metadata(string $driver = 'mysql', bool $signedUser = false, bool $wrongFk = false, string $engine = 'InnoDB', ?string $createdDefault = null, ?string $updatedDefault = null, ?string $unitDefault = null): void
    {
        $connection = new MySqlConnection(null, 'its', '', ['driver' => $driver]);
        DB::shouldReceive('connection')->andReturn($connection);
        DB::shouldReceive('getDriverName')->andReturn($driver);
        $column = fn ($name, $type, $base) => ['name' => $name, 'type' => $type, 'type_name' => $base, 'nullable' => false, 'default' => $driver === 'mariadb' ? 'NULL' : null, 'generation' => null, 'auto_increment' => false];
        $columns = [$column('id_budget_import', 'char(36)', 'char'), $column('actor_user_id', 'bigint(20) unsigned', 'bigint'), $column('unit', 'varchar(20)', 'varchar'), $column('summary', $driver === 'mariadb' ? 'longtext' : 'json', $driver === 'mariadb' ? 'longtext' : 'json')];
        $columns[2]['default'] = $unitDefault;
        $columns[] = array_replace($column('created_at', 'timestamp', 'timestamp'), ['default' => $createdDefault]);
        $columns[] = array_replace($column('updated_at', 'timestamp', 'timestamp'), ['default' => $updatedDefault]);
        Schema::shouldReceive('hasTable')->with('budget_pid_imports')->andReturn(true);
        Schema::shouldReceive('getTables')->andReturn([['name' => 'budget_pid_imports', 'engine' => $engine]]);
        Schema::shouldReceive('getColumns')->with('budget_pid_imports')->andReturn($columns);
        Schema::shouldReceive('getColumns')->with('users')->andReturn([$column('id_user', $signedUser ? 'bigint(20)' : 'bigint(20) unsigned', 'bigint')]);
        $indexes = [['name' => 'primary', 'columns' => ['id_budget_import'], 'primary' => true, 'unique' => true]];
        $fk = ['name' => 'budget_pid_imports_actor_user_id_foreign', 'columns' => ['actor_user_id'], 'foreign_schema' => $wrongFk ? 'other_database' : 'its', 'foreign_table' => 'users', 'foreign_columns' => ['id_user'], 'on_delete' => 'restrict', 'on_update' => 'restrict'];
        $foreign = $wrongFk ? [$fk] : [];
        Schema::shouldReceive('getIndexes')->with('budget_pid_imports')->andReturnUsing(function () use (&$indexes) {
            return $indexes;
        });
        Schema::shouldReceive('getForeignKeys')->with('budget_pid_imports')->andReturnUsing(function () use (&$foreign) {
            return $foreign;
        });
        Schema::shouldReceive('table')->with('budget_pid_imports', \Mockery::type(\Closure::class))->andReturnUsing(function ($table, $callback) use ($connection, &$indexes, &$foreign, $fk) {
            $blueprint = new Blueprint($connection, $table, $callback);
            $this->sql = array_merge($this->sql, $blueprint->toSql());
            foreach ($blueprint->getCommands() as $c) {
                if ($c->name === 'foreign') {
                    $foreign[] = $fk;
                } elseif ($c->name === 'index') {
                    $indexes[] = ['name' => $c->index, 'columns' => $c->columns, 'primary' => false, 'unique' => false];
                }
            }
        });
        Schema::shouldReceive('create')->never();
    }

    public function test_mysql_partial_table_repairs_constraints_using_real_mysql_grammar_and_preserves_rows(): void
    {
        $this->metadata();
        BudgetPidMigrationSchema::ensure($this->definition());
        $this->assertCount(2, $this->sql);
        $sql = implode("\n", $this->sql);
        $this->assertStringContainsString('foreign key (`actor_user_id`) references `users` (`id_user`) on delete restrict', $sql);
        $this->assertStringContainsString('add index `budget_pid_imports_unit_index`(`unit`)', $sql);
        $this->assertDoesNotMatchRegularExpression('/drop|truncate|insert|update|delete from/i', $sql);
    }

    public function test_mariadb_json_alias_and_integer_display_width_are_compatible(): void
    {
        $this->metadata('mariadb');
        BudgetPidMigrationSchema::ensure($this->definition());
        $this->assertCount(2, $this->sql);
    }

    public function test_signed_imported_user_key_is_diagnosed_before_any_ddl(): void
    {
        $this->metadata(signedUser: true);
        try {
            BudgetPidMigrationSchema::ensure($this->definition());
            $this->fail('Signed user key must not be silently adopted.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('tipe FK tidak sesuai users.id_user', $e->getMessage());
        }
        $this->assertSame([], $this->sql);
    }

    public function test_existing_cross_database_foreign_key_is_rejected_without_altering_it(): void
    {
        $this->metadata(wrongFk: true);
        try {
            BudgetPidMigrationSchema::ensure($this->definition());
            $this->fail('Cross-database FK must not be adopted.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('foreign key berbeda', $e->getMessage());
        }
        $this->assertSame([], $this->sql);
    }

    public function test_nontransactional_existing_table_is_not_silently_adopted(): void
    {
        $this->metadata(engine: 'MyISAM');
        try {
            BudgetPidMigrationSchema::ensure($this->definition());
            $this->fail('MyISAM must not be adopted.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('engine harus InnoDB', $e->getMessage());
        }
        $this->assertSame([], $this->sql);
    }

    public static function implicitTimestampDefaults(): array
    {
        return [
            'mysql-no-parentheses' => ['mysql', 'CURRENT_TIMESTAMP', null],
            'mysql-parentheses' => ['mysql', 'CURRENT_TIMESTAMP()', 'CURRENT_TIMESTAMP()'],
            'mariadb-confirmed-local' => ['mariadb', 'current_timestamp()', null],
            'mariadb-second-timestamp-zero' => ['mariadb', 'current_timestamp()', '0000-00-00 00:00:00'],
            'mysql-explicit-precision-zero' => ['mysql', 'CURRENT_TIMESTAMP(0)', null],
        ];
    }

    #[DataProvider('implicitTimestampDefaults')]
    public function test_implicit_timestamp_defaults_resume_without_altering_any_timestamp(string $driver, string $created, ?string $updated): void
    {
        $this->metadata($driver, createdDefault: $created, updatedDefault: $updated);
        BudgetPidMigrationSchema::ensure($this->definition());
        $this->assertCount(2, $this->sql);
        $sql = implode("\n", $this->sql);
        $this->assertStringNotContainsString('created_at', $sql);
        $this->assertStringNotContainsString('updated_at', $sql);
        $this->assertDoesNotMatchRegularExpression('/modify|drop|truncate|insert|update|delete from/i', $sql);
    }

    public function test_timestamp_compatibility_does_not_accept_arbitrary_default_or_change_other_columns(): void
    {
        $this->metadata(createdDefault: '2026-01-01 00:00:00');
        try {
            BudgetPidMigrationSchema::ensure($this->definition());
            $this->fail('Arbitrary timestamp default must not be adopted.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('budget_pid_imports.created_at: default berbeda', $e->getMessage());
        }
        $this->assertSame([], $this->sql);
    }

    public function test_current_timestamp_is_not_a_valid_implicit_default_for_non_timestamp_columns(): void
    {
        $this->metadata(unitDefault: 'current_timestamp()');
        try {
            BudgetPidMigrationSchema::ensure($this->definition());
            $this->fail('Non-timestamp default must still match exactly.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('budget_pid_imports.unit: default berbeda', $e->getMessage());
        }
        $this->assertSame([], $this->sql);
    }

    public function test_actual_fresh_migrations_emit_explicit_timestamp_defaults_without_on_update(): void
    {
        $connection = new MySqlConnection(null, 'its', '', ['driver' => 'mysql']);
        DB::shouldReceive('connection')->andReturn($connection);
        DB::shouldReceive('getDriverName')->andReturn('mysql');
        $control = \Mockery::mock();
        $control->shouldReceive('insert')->once()->with(['id_budget_control' => 1, 'version' => 0])->andReturn(true);
        DB::shouldReceive('table')->with('budget_pid_controls')->andReturn($control);
        $tables = $indexes = $foreign = [];
        Schema::shouldReceive('getTables')->andReturn([]);
        Schema::shouldReceive('hasTable')->andReturnUsing(function ($table) use (&$tables) {
            return isset($tables[$table]);
        });
        Schema::shouldReceive('getColumns')->andReturnUsing(function ($table) use (&$tables) {
            if (in_array($table, ['users', 'branches'], true)) {
                return [['name' => $table === 'users' ? 'id_user' : 'id_branch', 'type_name' => 'bigint', 'type' => 'bigint unsigned']];
            }

            return $tables[$table];
        });
        Schema::shouldReceive('getColumnListing')->andReturnUsing(function ($table) use (&$tables) {
            return array_column($tables[$table], 'name');
        });
        Schema::shouldReceive('getIndexes')->andReturnUsing(function ($table) use (&$indexes) {
            return $indexes[$table] ?? [];
        });
        Schema::shouldReceive('getForeignKeys')->andReturnUsing(function ($table) use (&$foreign) {
            return $foreign[$table] ?? [];
        });
        $compile = function ($table, $callback, bool $create) use ($connection, &$tables, &$indexes, &$foreign) {
            $blueprint = new Blueprint($connection, $table, $callback);
            if ($create) {
                $blueprint->create();
            }
            $this->sql = array_merge($this->sql, $blueprint->toSql());
            foreach ($blueprint->getColumns() as $c) {
                $tables[$table][] = ['name' => $c->name];
            }
            foreach ($blueprint->getCommands() as $c) {
                if (in_array($c->name, ['primary', 'unique', 'index'], true)) {
                    $indexes[$table][] = ['name' => $c->index ?? 'primary', 'columns' => $c->columns, 'primary' => $c->name === 'primary', 'unique' => $c->name !== 'index'];
                } elseif ($c->name === 'foreign') {
                    $foreign[$table][] = ['columns' => $c->columns, 'foreign_schema' => 'its', 'foreign_table' => $c->on, 'foreign_columns' => (array) $c->references, 'on_delete' => $c->onDelete, 'on_update' => 'restrict'];
                }
            }
        };
        Schema::shouldReceive('create')->andReturnUsing(fn ($table, $callback) => $compile($table, $callback, true));
        Schema::shouldReceive('table')->andReturnUsing(fn ($table, $callback) => $compile($table, $callback, false));
        foreach (['2026_11_24_000001_create_budgeting_pid_master.php', '2026_11_24_000002_map_budget_pid_branches.php'] as $file) {
            (require database_path('migrations/'.$file))->up();
        }
        $timestampCreates = array_values(array_filter($this->sql, fn ($sql) => str_starts_with($sql, 'create table') && str_contains($sql, '`created_at`')));
        $this->assertCount(3, $timestampCreates);
        foreach ($timestampCreates as $sql) {
            $this->assertStringContainsString('`created_at` timestamp not null default CURRENT_TIMESTAMP', $sql);
            $this->assertStringNotContainsString('on update', strtolower($sql));
            $this->assertStringNotContainsString('0000-00-00', $sql);
        }
        $this->assertStringContainsString('`updated_at` timestamp not null default CURRENT_TIMESTAMP', implode("\n", $timestampCreates));
    }
}
