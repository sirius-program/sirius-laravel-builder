<?php

declare(strict_types=1);

namespace Sirius\LaravelBuilder\Support;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Filesystem\Filesystem;
use Livewire\Component;
use Sirius\LaravelBuilder\Schema\ConnectionCheck;
use Sirius\Ui\SiriusUiServiceProvider;

class Preflight
{
    public function __construct(
        private readonly Repository $config,
        private readonly Application $app,
        private readonly Filesystem $files,
        private readonly ConnectionCheck $connection,
    ) {}

    /** @return list<string> */
    public function errors(): array
    {
        $errors = [];
        foreach ([Component::class => 'livewire/livewire:^4.0', SiriusUiServiceProvider::class => 'sirius/ui:^0.1.0'] as $class => $package) {
            if (!$this->dependencyAvailable($class)) {
                $errors[] = 'Missing dependency: install ' . $package . ' with Composer.';
            }
        }
        foreach (['models', 'enums', 'stubs', 'routes'] as $key) {
            $relative = $this->config->get('sirius-builder.paths.' . $key);
            if (!is_string($relative) || $relative === '' || preg_match('~(^[/\\\\]|^[A-Za-z]:|(^|[/\\\\])\.\.([/\\\\]|$))~', $relative)) {
                $errors[] = 'Invalid sirius-builder.paths.' . $key . ': use a non-empty path relative to the application root without parent traversal.';

                continue;
            }
            $path = $this->app->basePath($relative);
            if ($key === 'models' && !$this->files->isDirectory($path)) {
                $errors[] = 'Models directory not found: ' . $relative . '. Create it or update sirius-builder.paths.models.';
            }
            if ($key === 'routes' && (!$this->files->isFile($path) || !is_readable($path) || !is_writable($path))) {
                $errors[] = 'Route file must exist and be readable/writable: ' . $relative . '.';
            }
            if (in_array($key, ['enums', 'stubs'], true) && $this->files->exists($path) && !$this->files->isDirectory($path)) {
                $errors[] = ucfirst($key) . ' path must be a directory: ' . $relative . '.';
            }
        }
        if (!$this->connection->available()) {
            $errors[] = 'Database connection unavailable. Check the default connection configuration and database service. Connection details are not displayed.';
        }

        return $errors;
    }

    protected function dependencyAvailable(string $class): bool
    {
        return class_exists($class);
    }
}
