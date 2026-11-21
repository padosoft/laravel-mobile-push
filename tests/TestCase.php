<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Padosoft\MobilePush\Laravel\MobilePushServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [MobilePushServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();

        // The suite is written against SQLite ':memory:', where every test starts on an empty database. On a server
        // database (the MySQL / PostgreSQL CI jobs, DB_CONNECTION in the environment) the tables of the previous
        // test are still there: start each test from an empty schema.
        if ($this->onServerDatabase()) {
            $this->app->make('db')->connection()->getSchemaBuilder()->dropAllTables();
        }
    }

    protected function tearDown(): void
    {
        // One server connection per test would exhaust the server's connection limit over a few hundred tests.
        if ($this->app !== null && $this->onServerDatabase()) {
            $database = $this->app->make('db');
            foreach (array_keys($database->getConnections()) as $name) {
                $database->disconnect($name);
            }
        }

        parent::tearDown();
    }

    private function onServerDatabase(): bool
    {
        $default = $this->app->make('config')->get('database.default');

        return in_array($this->app->make('config')->get("database.connections.{$default}.driver"), ['mysql', 'mariadb', 'pgsql', 'sqlsrv'], true);
    }
}
