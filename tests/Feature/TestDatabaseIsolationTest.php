<?php

namespace Tests\Feature;

use DOMDocument;
use PDO;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class TestDatabaseIsolationTest extends TestCase
{
    public function test_persistent_sqlite_is_rejected_before_migrations_with_and_without_cached_config(): void
    {
        $directory = sys_get_temp_dir().'/area3-test-isolation-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $databasePath = $directory.'/sentinel.sqlite';
        $database = new PDO('sqlite:'.$databasePath);
        $database->exec("CREATE TABLE sentinel (value TEXT); INSERT INTO sentinel VALUES ('preserved')");

        try {
            foreach ([false, true] as $cached) {
                $cachePath = $directory.'/config.php';
                if ($cached) {
                    $config = $this->app['config']->all();
                    $config['database']['connections']['sqlite']['database'] = $databasePath;
                    file_put_contents($cachePath, '<?php return '.var_export($config, true).';');
                }

                $xml = new DOMDocument;
                $xml->load(base_path('phpunit.xml'));
                $xml->documentElement->setAttribute('bootstrap', base_path('vendor/autoload.php'));
                $xml->documentElement->setAttribute('cacheResult', 'false');
                foreach ($xml->getElementsByTagName('env') as $environment) {
                    if ($environment->getAttribute('name') === 'DB_DATABASE') {
                        $environment->setAttribute('value', $databasePath);
                    }
                    if ($environment->getAttribute('name') === 'APP_CONFIG_CACHE') {
                        $environment->setAttribute('value', $cachePath);
                    }
                }
                $suites = $xml->getElementsByTagName('testsuites')->item(0);
                while ($suites->firstChild) {
                    $suites->removeChild($suites->firstChild);
                }
                $suite = $xml->createElement('testsuite');
                $suite->setAttribute('name', 'Isolation probe');
                $suite->appendChild($xml->createElement('file', base_path('tests/Feature/PrototypeApplicationTest.php')));
                $suites->appendChild($suite);
                $source = $xml->getElementsByTagName('source')->item(0);
                $source->parentNode->removeChild($source);
                $xml->save($directory.'/phpunit.xml');

                $process = new Process([
                    PHP_BINARY, base_path('vendor/phpunit/phpunit/phpunit'),
                    '--configuration', $directory.'/phpunit.xml',
                    '--filter', 'test_workspace_is_shared_between_accounts_and_legacy_session_data_is_preserved',
                    '--no-progress', '--colors=never',
                ], base_path(), [
                    'APP_ENV' => 'testing',
                    'APP_CONFIG_CACHE' => $cachePath,
                    'DB_DATABASE' => $databasePath,
                ]);
                $process->setTimeout(15)->run();

                $this->assertSame(2, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
                $this->assertStringContainsString('Tests require the testing environment and isolated SQLite :memory: database.', $process->getOutput());
                $this->assertSame('preserved', $database->query('SELECT value FROM sentinel')->fetchColumn());
            }
        } finally {
            $database = null;
            foreach (glob($directory.'/*') as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }
}
