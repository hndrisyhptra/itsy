<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use RuntimeException;

final class Area3MigrationTarget
{
    public static function assertAllowed(): void
    {
        $connection = DB::connection();
        $driver = $connection->getDriverName();
        $database = $connection->getDatabaseName();

        if ($driver === 'sqlite' && $database === ':memory:' && app()->environment('testing') && ! $connection->getConfig('url')) {
            return;
        }

        if (! in_array($driver, ['mysql', 'mariadb'], true) || $database !== 'its') {
            throw new RuntimeException('Migration Area 3 hanya untuk MySQL/MariaDB database its. Atur DB_CONNECTION=mysql dan DB_DATABASE=its, lalu jalankan php artisan config:clear. SQLite demo tidak diubah.');
        }
    }
}
