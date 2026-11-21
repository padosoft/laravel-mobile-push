<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Tests;

use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase as Orchestra;
use Padosoft\MobilePush\Laravel\MobilePushServiceProvider;

abstract class TestCase extends Orchestra
{
    /**
     * For a test that needs the application BUILT on its own configuration (which modules are on, which profile):
     * set this, then call $this->refreshApplication(). It runs after the configuration is loaded and before the
     * package's service provider registers, so the modules see it from their first line. Reset after every test.
     *
     * @var (\Closure(Application): void)|null
     */
    public static ?\Closure $environment = null;

    protected function getPackageProviders($app): array
    {
        return [MobilePushServiceProvider::class];
    }

    /**
     * With MOBILE_PUSH_TEST_STRICT_CACHE=1 the suite runs on what a freshly created Laravel 13 application has: a
     * default cache store that really serialises its values (the `array` store of the test environment keeps PHP
     * values as they are) and refuses to rebuild objects (`cache.serializable_classes = false`). Anything the package
     * caches as an object comes back as an incomplete class there; CI runs the Laravel tests once in this mode.
     */
    protected function defineEnvironment($app): void
    {
        if (getenv('MOBILE_PUSH_TEST_STRICT_CACHE') === '1') {
            $dir = sys_get_temp_dir().'/mobile-push-strict-cache-'.getmypid();
            $app['config']->set('cache.serializable_classes', false);
            $app['config']->set('cache.stores.strict-file', ['driver' => 'file', 'path' => $dir, 'lock_path' => $dir]);
            $app['config']->set('cache.default', 'strict-file');
        }

        if (self::$environment !== null) {
            (self::$environment)($app);
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('MOBILE_PUSH_TEST_STRICT_CACHE') === '1') {
            $this->app->make('cache')->store('strict-file')->flush();
        }

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
        self::$environment = null;
    }

    private function onServerDatabase(): bool
    {
        $default = $this->app->make('config')->get('database.default');

        return in_array($this->app->make('config')->get("database.connections.{$default}.driver"), ['mysql', 'mariadb', 'pgsql', 'sqlsrv'], true);
    }
}
