<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use RuntimeException;

final class Area3MasterData
{
    private const SHA256 = '2e8017164eaf0b4cd81cb3ef91b1d4b23e32c3d625b025a0a3fdfb12af71e3b8';

    private const TABLES = [
        'areas' => ['id_area', 1],
        'regions' => ['id_region', 3],
        'branches' => ['id_branch', 16],
        'service_areas' => ['id_service_area', 392],
        'packages' => ['id_package', 2],
        'designator_types' => ['id_designator_type', 5],
        'designators' => ['id_designator', 724],
        'designator_package_prices' => ['id_designator_package_price', 1030],
    ];

    private static function snapshot(): array
    {
        $path = database_path('data/area3-master-data.json');
        if (! is_file($path) || ! hash_equals(self::SHA256, hash_file('sha256', $path))) {
            throw new RuntimeException('Snapshot master Area 3 hilang/berubah. Data tidak diimpor; pulihkan snapshot migration yang sesuai.');
        }
        $data = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (array_keys($data) !== array_keys(self::TABLES)) {
            throw new RuntimeException('Daftar tabel snapshot master tidak sesuai.');
        }
        foreach (self::TABLES as $table => [$key, $count]) {
            if (count($data[$table]) !== $count) {
                throw new RuntimeException('Jumlah master tidak sesuai: '.$table);
            }
        }

        return $data;
    }

    public static function install(): void
    {
        Area3MigrationTarget::assertAllowed();
        $data = self::snapshot();
        $driver = DB::getDriverName();
        $timezone = null;
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $timezone = DB::selectOne('SELECT @@session.time_zone AS source_timezone')->source_timezone;
            DB::statement("SET SESSION time_zone = '+00:00'");
        }

        try {
            DB::transaction(function () use ($data) {
                foreach (self::TABLES as $table => $definition) {
                    if (DB::table($table)->exists()) {
                        throw new RuntimeException('Master '.$table.' sudah berisi data. Migration berhenti tanpa menimpa data existing.');
                    }
                }
                foreach ($data as $table => $rows) {
                    foreach (array_chunk($rows, 50) as $chunk) {
                        DB::table($table)->insert($chunk);
                    }
                }
            });
        } finally {
            if ($timezone !== null) {
                DB::statement('SET SESSION time_zone = ?', [$timezone]);
            }
        }
    }

    public static function rollback(): void
    {
        Area3MigrationTarget::assertAllowed();
        $data = self::snapshot();
        DB::transaction(function () use ($data) {
            foreach (array_reverse(self::TABLES, true) as $table => [$key, $count]) {
                foreach (array_chunk(array_column($data[$table], $key), 50) as $ids) {
                    DB::table($table)->whereIn($key, $ids)->delete();
                }
            }
        });
    }
}
