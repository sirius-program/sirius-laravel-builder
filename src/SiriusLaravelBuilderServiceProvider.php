<?php

declare(strict_types=1);

namespace Sirius\LaravelBuilder;

use Illuminate\Support\ServiceProvider;
use Sirius\LaravelBuilder\Commands\MakeCrudCommand;

final class SiriusLaravelBuilderServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/sirius-builder.php', 'sirius-builder');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([MakeCrudCommand::class]);
            $this->publishes([
                __DIR__ . '/../config/sirius-builder.php' => config_path('sirius-builder.php'),
            ], 'sirius-builder-config');
        }
    }
}
