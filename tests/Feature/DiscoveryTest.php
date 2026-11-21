<?php

declare(strict_types=1);

use Composer\Autoload\ClassLoader;
use Illuminate\Support\Facades\DB;
use Sirius\LaravelBuilder\Discovery\DiscoveryException;
use Sirius\LaravelBuilder\Discovery\EnumDiscovery;
use Sirius\LaravelBuilder\Discovery\ModelDiscovery;
use Tests\Fixtures\Discovery\Enums\Admin\Status;
use Tests\Fixtures\Discovery\Models\Admin\Category;

it('discovers concrete nested models deterministically without instantiating models or querying records', function (): void {
    DB::connection()->enableQueryLog();
    $models = $this->app->make(ModelDiscovery::class)->discover(__DIR__ . '/../Fixtures/Discovery/Models', 'Tests\\Fixtures\\Discovery\\Models');

    expect(array_column($models, 'relativeName'))->toBe(['Admin\\Category', 'ArchivedRecord', 'Client\\Category', 'Client\\Product', 'ConstructorGuard', 'User']);
    expect($models[0]->className)->toBe(Category::class);
    expect($models[1]->softDeletes)->toBeTrue();
    expect($models[0]->softDeletes)->toBeFalse();
    expect(DB::getQueryLog())->toBe([]);
});

it('discovers pure and backed enums while retaining duplicate basenames', function (): void {
    $enums = $this->app->make(EnumDiscovery::class)->discover(__DIR__ . '/../Fixtures/Discovery/Enums', 'Tests\\Fixtures\\Discovery\\Enums');

    expect(array_column($enums, 'relativeName'))->toBe(['Admin\\Status', 'Mode', 'Status']);
    expect(array_column($enums, 'backingType'))->toBe(['int', null, 'string']);
    expect($enums[0]->className)->toBe(Status::class);
});

it('returns an empty list for empty model directories and absent optional enums', function (): void {
    mkdir(base_path('empty-models'));
    file_put_contents(base_path('empty-models/README.md'), 'Not a PHP class.');
    expect($this->app->make(ModelDiscovery::class)->discover(base_path('empty-models')))->toBe([]);
    expect($this->app->make(EnumDiscovery::class)->discover(base_path('absent-enums')))->toBe([]);
});

it('rejects a namespace that cannot identify PSR-4 classes', function (): void {
    $this->app->make(ModelDiscovery::class)->discover(__DIR__ . '/../Fixtures/Discovery/Models', 'Invalid/Namespace');
})->throws(DiscoveryException::class, 'PSR-4 namespace');

it('rejects an autoload mapping that resolves to another source file', function (): void {
    mkdir(base_path('copied-models'));
    copy(__DIR__ . '/../Fixtures/Discovery/Models/User.php', base_path('copied-models/User.php'));
    $this->app->make(ModelDiscovery::class)->discover(base_path('copied-models'), 'Tests\\Fixtures\\Discovery\\Models');
})->throws(DiscoveryException::class, 'does not match');

it('reports a missing required model directory', function (): void {
    $this->app->make(ModelDiscovery::class)->discover(base_path('missing'));
})->throws(DiscoveryException::class, 'missing or unreadable');

it('reports an enum path that is not a directory', function (): void {
    $this->app->make(EnumDiscovery::class)->discover(base_path('routes/web.php'));
})->throws(DiscoveryException::class, 'missing or unreadable');

it('reports malformed or unautoloadable source files with actionable diagnostics', function (string $source, string $message): void {
    $namespace = 'DiscoverySandbox' . bin2hex(random_bytes(8));
    $directory = base_path('discovery');
    mkdir($directory);
    file_put_contents($directory . '/Broken.php', str_replace('TEST_NAMESPACE', $namespace, $source));
    $loader = new ClassLoader;
    $loader->addPsr4($namespace . '\\', $directory);
    $loader->register();

    try {
        expect(fn () => $this->app->make(ModelDiscovery::class)->discover($directory, $namespace))->toThrow(DiscoveryException::class, $message);
    } finally {
        $loader->unregister();
    }
})->with([
    ['<?php namespace TEST_NAMESPACE; class Broken extends MissingParent {}', 'valid autoloadable PHP'],
    ['<?php namespace TEST_NAMESPACE; class Broken {', 'valid autoloadable PHP'],
    ['<?php namespace TEST_NAMESPACE;', 'Check its declaration'],
    ['<?php namespace TEST_NAMESPACE; class broken {}', 'casing'],
]);
