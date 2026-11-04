<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        $app = parent::createApplication();

        // Stop before RefreshDatabase can migrate any persistent database.
        if (! $app->environment('testing')
            || $app['config']->get('database.default') !== 'sqlite'
            || $app['config']->get('database.connections.sqlite.database') !== ':memory:'
            || $app['config']->get('database.connections.sqlite.url')) {
            throw new RuntimeException('Tests require the testing environment and isolated SQLite :memory: database.');
        }

        return $app;
    }
}
