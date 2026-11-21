<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\PackageManifest;
use Illuminate\Support\Facades\Http;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected $loadEnvironmentVariables = false;

    protected string $sandbox;

    protected function resolveApplication(): Application
    {
        $this->sandbox = __DIR__ . '/../.phpunit.cache/apps/' . bin2hex(random_bytes(12));
        $files = new Filesystem;
        $files->ensureDirectoryExists($this->sandbox . '/bootstrap/cache');
        $files->ensureDirectoryExists($this->sandbox . '/storage/framework/views');
        $files->copyDirectory(__DIR__ . '/Fixtures/Application', $this->sandbox);

        $app = parent::resolveApplication();
        $app->setBasePath($this->sandbox);
        $app->useAppPath($this->sandbox . '/app');
        $app->useBootstrapPath($this->sandbox . '/bootstrap');
        $app->useStoragePath($this->sandbox . '/storage');

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } finally {
            if (isset($this->sandbox)) {
                $root = realpath(__DIR__ . '/../.phpunit.cache/apps');
                $target = realpath($this->sandbox);
                if ($root !== false && $target !== false && str_starts_with($target, $root . DIRECTORY_SEPARATOR)) {
                    (new Filesystem)->deleteDirectory($target);
                }
            }
        }
    }

    /** @return list<class-string> */
    protected function getPackageProviders($app): array
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists($this->sandbox . '/vendor/composer');
        $metadata = json_decode($files->get(__DIR__ . '/../composer.json'), true, flags: JSON_THROW_ON_ERROR);
        $files->put($this->sandbox . '/vendor/composer/installed.json', json_encode(['packages' => [$metadata]], JSON_THROW_ON_ERROR));
        $manifest = new PackageManifest($files, $this->sandbox, $this->sandbox . '/bootstrap/cache/packages.php');

        return [LivewireServiceProvider::class, ...$manifest->providers()];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.env', 'testing');
        $app['config']->set('app.key', 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=');
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections', [
            'testing' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true],
        ]);
        $app['config']->set('cache.default', 'array');
        $app['config']->set('session.driver', 'array');
        $app['config']->set('view.paths', [$this->sandbox . '/resources/views']);
        $app['config']->set('view.compiled', $this->sandbox . '/storage/framework/views');
    }
}
