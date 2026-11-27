<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Sirius\LaravelBuilder\Schema\ConnectionCheck;
use Sirius\LaravelBuilder\SiriusLaravelBuilderServiceProvider;
use Sirius\LaravelBuilder\Support\Preflight;
use Symfony\Component\Console\Output\BufferedOutput;

it('checks prerequisites without writing files or executing record queries', function (): void {
    $before = hash_file('sha256', base_path('routes/web.php'));
    DB::connection()->enableQueryLog();

    $this->artisan('make:crud --check')->expectsOutputToContain('prerequisites are ready')->assertSuccessful();

    expect(hash_file('sha256', base_path('routes/web.php')))->toBe($before);
    expect(DB::getQueryLog())->toBe([]);
});

it('loads package defaults and respects application configuration overrides', function (): void {
    expect(config('sirius-builder.paths.models'))->toBe('app/Models')
        ->and(config('sirius-builder.paths.stubs'))->toBe('stubs/sirius-builder')
        ->and(config('sirius-builder.routes.middleware'))->toBe(['auth']);

    config()->set('sirius-builder.routes', ['prefix' => 'admin', 'name_prefix' => 'admin.', 'middleware' => ['auth', 'verified']]);
    (new SiriusLaravelBuilderServiceProvider($this->app))->register();

    expect(config('sirius-builder.routes.prefix'))->toBe('admin')
        ->and(config('sirius-builder.routes.middleware'))->toBe(['auth', 'verified'])
        ->and(config('sirius-builder.paths.models'))->toBe('app/Models');
});

it('rejects unattended wizard runs', function (): void {
    $this->artisan('make:crud --no-interaction')->expectsOutputToContain('interactive terminal')->assertFailed();
});

it('renders plain command messages without ANSI escapes or leaking markup', function (): void {
    config()->set('sirius-builder.paths.models', 'missing-<b>models</b>');
    $output = new BufferedOutput;

    $status = Artisan::call('make:crud', ['--check' => true, '--no-ansi' => true], $output);

    expect($status)->toBe(1)
        ->and($output->fetch())->toContain('missing-<b>models</b>')->not->toContain("\033[");
});

it('respects quiet command output', function (): void {
    $output = new BufferedOutput;

    $status = Artisan::call('make:crud', ['--check' => true, '--quiet' => true], $output);

    expect($status)->toBe(0)
        ->and($output->fetch())->toBe('');
});

it('reports invalid application paths', function (string $key, mixed $value, string $message): void {
    config()->set('sirius-builder.paths.' . $key, $value);

    $this->artisan('make:crud --check')->expectsOutputToContain($message)->assertFailed();
})->with([
    ['models', 'missing-models', 'Models directory not found'],
    ['routes', 'routes/missing.php', 'Route file must exist'],
    ['enums', '../outside', 'without parent traversal'],
    ['stubs', null, 'Invalid sirius-builder.paths.stubs'],
    ['models', 'C:\\outside', 'without parent traversal'],
]);

it('honors configured discovery and route paths', function (): void {
    config()->set('sirius-builder.paths.models', 'app/Actions');
    config()->set('sirius-builder.paths.routes', 'routes/web.php');

    $this->artisan('make:crud --check')->assertSuccessful();
});

it('reports unavailable database connections without exposing credentials', function (): void {
    config()->set('database.default', 'missing-secret-connection');

    $this->artisan('make:crud --check')
        ->expectsOutputToContain('Database connection unavailable')
        ->doesntExpectOutputToContain('missing-secret-connection')
        ->assertFailed();
});

it('reports missing dependencies', function (): void {
    $this->app->bind(Preflight::class, fn ($app): Preflight => new class($app['config'], $app, $app['files'], $app->make(ConnectionCheck::class)) extends Preflight
    {
        protected function dependencyAvailable(string $class): bool
        {
            return false;
        }
    });

    $this->artisan('make:crud --check')->expectsOutputToContain('Missing dependency')->assertFailed();
});

it('publishes configuration and preserves an existing customized file', function (): void {
    $this->artisan('vendor:publish', ['--tag' => 'sirius-builder-config'])->assertSuccessful();
    $path = config_path('sirius-builder.php');
    expect(is_file($path))->toBeTrue();
    file_put_contents($path, '<?php return ["customized" => true];');

    $this->artisan('vendor:publish', ['--tag' => 'sirius-builder-config'])->assertSuccessful();

    expect(file_get_contents($path))->toContain('customized');
});
