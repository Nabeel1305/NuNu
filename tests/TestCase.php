<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * RefreshDatabase drops every table in the connected database. On a shared
     * server that must never be a database holding real data, so anything not
     * named *_test is refused. This runs when the application is built, before
     * any test trait touches the database.
     */
    public function createApplication()
    {
        $app = parent::createApplication();

        $config = $app['config']->get('database.connections.' . $app['config']->get('database.default'));
        $name = (string) ($config['database'] ?? '');

        if (($config['driver'] ?? '') !== 'sqlite' && ! str_ends_with($name, '_test')) {
            throw new \RuntimeException("Refusing to run tests against database '{$name}': its name must end in _test.");
        }

        return $app;
    }
}
