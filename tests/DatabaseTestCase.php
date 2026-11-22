<?php

declare(strict_types=1);

namespace Tests;

use RuntimeException;
use Tests\Support\DatabaseConnections;

abstract class DatabaseTestCase extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        if (!defined('BUILDER_DATABASE_TESTS')) {
            throw new RuntimeException('Use composer test:database to opt into disposable engine tests.');
        }
        foreach (array_diff(DatabaseConnections::schemaDrivers(), ['testing']) as $driver) {
            $app['config']->set('database.connections.' . $driver, DatabaseConnections::configuration($driver));
        }
    }
}
