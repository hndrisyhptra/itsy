<?php

namespace App\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/** Resume only the two PID migrations after non-transactional MySQL DDL interruptions. */
final class BudgetPidMigrationSchema
{
    public static function ensure(array $tables, array $extensions = []): void
    {
        Area3MigrationTarget::assertAllowed();
        $definitions = [];
        $storage = DB::getDriverName() === 'sqlite' ? [] : collect(Schema::getTables())->keyBy('name')->all();
        // Validate all existing structures and external key types before any DDL.
        foreach ($tables + $extensions as $table => $callback) {
            $blueprint = new Blueprint(DB::connection(), $table, $callback);
            $exists = Schema::hasTable($table);
            if (isset($extensions[$table]) && ! $exists) {
                self::fail($table, 'tabel induk belum tersedia');
            }
            if ($exists) {
                if ($storage && strtolower($storage[$table]['engine'] ?? '') !== 'innodb') {
                    self::fail($table, 'engine harus InnoDB');
                }
                self::columns($table, $blueprint, isset($extensions[$table]));
                self::keys($table, $blueprint, false);
            }
            foreach ($blueprint->getCommands() as $command) {
                if ($command->name !== 'foreign' || isset($tables[$command->on])) {
                    continue;
                }
                $foreign = collect(Schema::getColumns($command->on))->keyBy('name');
                $references = (array) $command->references;
                foreach ($command->columns as $i => $column) {
                    $expected = collect($blueprint->getColumns())->firstWhere('name', $column);
                    $actual = $foreign[$references[$i]] ?? null;
                    if (! $actual || ! self::sameType($expected->getAttributes(), $actual)) {
                        self::fail($table.'.'.$column, 'tipe FK tidak sesuai '.$command->on.'.'.$references[$i]);
                    }
                }
            }
            $definitions[$table] = $blueprint;
        }
        foreach ($tables as $table => $callback) {
            if (! Schema::hasTable($table)) {
                Schema::create($table, $callback);
            }
            self::keys($table, $definitions[$table], true);
        }
        foreach ($extensions as $table => $callback) {
            $columns = Schema::getColumnListing($table);
            $missing = array_filter($definitions[$table]->getColumns(), fn ($c) => ! in_array($c->name, $columns, true));
            if ($missing) {
                Schema::table($table, function (Blueprint $t) use ($missing) {
                    foreach ($missing as $column) {
                        $t->addColumn($column->type, $column->name, $column->getAttributes());
                    }
                });
            }
            self::keys($table, $definitions[$table], true);
        }
    }

    private static function columns(string $table, Blueprint $blueprint, bool $extension): void
    {
        $actual = collect(Schema::getColumns($table))->keyBy('name');
        if (! $extension) {
            $names = array_map(fn ($c) => $c->name, $blueprint->getColumns());
            foreach ($actual as $name => $column) {
                if (! in_array($name, $names, true) && ! $column['nullable'] && $column['default'] === null && ! $column['auto_increment']) {
                    self::fail($table.'.'.$name, 'kolom wajib tambahan tidak dikenal');
                }
            }
        }
        foreach ($blueprint->getColumns() as $column) {
            $found = $actual[$column->name] ?? null;
            if (! $found && $extension) {
                continue;
            }
            if (! $found || ! self::sameType($column->getAttributes(), $found)
                || (bool) $found['nullable'] !== (bool) $column->nullable
                || ($found['generation'] ?? null)
                || (DB::getDriverName() !== 'sqlite' && $found['auto_increment'])) {
                self::fail($table.'.'.$column->name, 'kolom/tipe/nullability berbeda');
            }
            if (! self::defaultMatches($column->getAttributes(), $found['default'])) {
                self::fail($table.'.'.$column->name, 'default berbeda');
            }
        }
    }

    private static function defaultMatches(array $column, mixed $actual): bool
    {
        $default = $actual === null || strtoupper((string) $actual) === 'NULL'
            ? null : trim((string) $actual, "'\"");
        $expected = $column['default'] ?? null;
        if ($default === ($expected === null ? null : (string) $expected)) {
            return true;
        }

        // With explicit_defaults_for_timestamp=OFF, MySQL/MariaDB supplies defaults
        // absent from Blueprint. All PID inserts/upserts explicitly provide these dates.
        // Branch-only updates must explicitly retain both timestamps (BudgetPidBranch).
        return $column['type'] === 'timestamp' && $expected === null
            && in_array($column['name'], ['created_at', 'updated_at'], true)
            && ((preg_match('/^current_timestamp(?:\((?:0)?\))?$/iD', trim((string) $actual)) === 1
                    && (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true) || ($column['useCurrent'] ?? false)))
                || (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)
                    && $column['name'] === 'updated_at' && $default === '0000-00-00 00:00:00'));
    }

    private static function sameType(array $expected, array $actual): bool
    {
        $sqlite = DB::getDriverName() === 'sqlite';
        $type = strtolower($actual['type']);
        $base = strtolower($actual['type_name']);

        return match ($expected['type']) {
            'tinyInteger', 'bigInteger', 'integer' => $sqlite ? $base === 'integer'
                : $base === ['tinyInteger' => 'tinyint', 'bigInteger' => 'bigint', 'integer' => 'int'][$expected['type']]
                    && str_contains($type, 'unsigned') === (bool) ($expected['unsigned'] ?? false),
            'uuid' => $sqlite ? $base === 'varchar' : $type === 'char(36)',
            'string' => $sqlite ? $base === 'varchar' : $type === 'varchar('.$expected['length'].')',
            'char' => $sqlite ? $base === 'varchar' : $type === 'char('.$expected['length'].')',
            // MariaDB exposes its JSON alias as longtext; application JSON encoding is unchanged.
            'json' => $sqlite ? $base === 'text' : in_array($base, ['json', 'longtext'], true),
            'timestamp' => $sqlite ? $base === 'datetime' : $type === 'timestamp',
            default => false,
        };
    }

    private static function commands(Blueprint $blueprint): array
    {
        $commands = array_map(fn ($c) => $c->getAttributes(), $blueprint->getCommands());
        foreach ($commands as &$command) {
            if ($command['name'] === 'foreign') {
                $command['references'] = (array) $command['references'];
            }
        }
        unset($command);
        foreach ($blueprint->getColumns() as $column) {
            foreach (['primary', 'unique', 'index'] as $type) {
                if ($column->$type) {
                    $commands[] = ['name' => $type, 'columns' => [$column->name], 'index' => is_string($column->$type) ? $column->$type : null];
                }
            }
        }

        return $commands;
    }

    private static function keys(string $table, Blueprint $blueprint, bool $repair): void
    {
        $indexes = Schema::getIndexes($table);
        $foreign = Schema::getForeignKeys($table);
        foreach (self::commands($blueprint) as $c) {
            if (in_array($c['name'], ['primary', 'unique', 'index'], true)) {
                $matches = fn ($i) => $i['columns'] === $c['columns'] && $i['primary'] === ($c['name'] === 'primary') && $i['unique'] === ($c['name'] !== 'index');
                if (array_filter($indexes, $matches)) {
                    continue;
                }
                if ($c['name'] === 'primary' || array_filter($indexes, fn ($i) => $i['name'] === ($c['index'] ?? strtolower($table.'_'.implode('_', $c['columns']).'_'.$c['name'])))) {
                    self::fail($table, 'primary key atau indeks berbeda');
                }
                if ($repair) {
                    Schema::table($table, fn (Blueprint $t) => $t->{$c['name']}($c['columns'], $c['index'] ?? null));
                }
            } elseif ($c['name'] === 'foreign') {
                $found = array_values(array_filter($foreign, fn ($f) => $f['columns'] === $c['columns']));
                $valid = $found && $found[0]['foreign_table'] === $c['on'] && $found[0]['foreign_columns'] === $c['references']
                    && in_array($found[0]['on_delete'], [$c['onDelete'] ?? 'no action'], true)
                    && in_array($found[0]['on_update'], ['no action', 'restrict'], true)
                    && (DB::getDriverName() === 'sqlite' || $found[0]['foreign_schema'] === DB::connection()->getDatabaseName());
                if ($found && ! $valid) {
                    self::fail($table, 'foreign key berbeda');
                }
                if (! $found && $repair) {
                    Schema::table($table, function (Blueprint $t) use ($c) {
                        $fk = $t->foreign($c['columns'], $c['index'])->references($c['references'])->on($c['on']);
                        if (isset($c['onDelete'])) {
                            $fk->onDelete($c['onDelete']);
                        }
                    });
                    // MyISAM can silently ignore foreign keys: never mark such a table complete.
                    $created = array_filter(Schema::getForeignKeys($table), fn ($f) => $f['columns'] === $c['columns'] && $f['foreign_table'] === $c['on'] && $f['foreign_columns'] === $c['references']);
                    if (! $created) {
                        self::fail($table, 'database tidak menerapkan foreign key; periksa engine InnoDB');
                    }
                }
            }
        }
    }

    private static function fail(string $target, string $reason): never
    {
        throw new RuntimeException("Struktur Budgeting PID tidak sesuai pada {$target}: {$reason}. Data tidak dihapus/reset. Periksa SHOW CREATE TABLE sebelum melanjutkan; jangan tandai migration selesai secara manual.");
    }
}
