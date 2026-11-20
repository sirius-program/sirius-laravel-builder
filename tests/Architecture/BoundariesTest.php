<?php

declare(strict_types=1);

use App\Actions\Category\CreateCategory;
use App\Livewire\Category\Create;
use Illuminate\Filesystem\Filesystem;
use Illuminate\View\Compilers\BladeCompiler;
use Livewire\Component;
use Tests\Support\ArchitectureRules;

arch('package and representative classes use strict types')
    ->expect(['Sirius\\LaravelBuilder', 'Tests\\Fixtures\\Package', 'App'])
    ->toUseStrictTypes();

arch('actions expose a handle entry point and do not depend on Livewire')
    ->expect('App\\Actions')
    ->toHaveMethod('handle')
    ->not->toUse('Livewire');

arch('generated components extend Livewire')
    ->expect('App\\Livewire')
    ->toExtend(Component::class);

it('checks real production and representative source files against layer boundaries', function (): void {
    $files = new Filesystem;
    $roots = [dirname(__DIR__, 2) . '/src', __DIR__ . '/../Fixtures/Package', __DIR__ . '/../Fixtures/Application/app'];
    $checked = 0;

    foreach ($roots as $root) {
        foreach ($files->allFiles($root) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $relative = str_replace('\\', '/', $file->getRelativePathname());
            $layer = explode('/', $relative)[0];
            $violations = ArchitectureRules::violations($file->getContents(), $layer, !str_ends_with($root, '/app'));
            expect($violations)->toBe([]);
            $checked++;
        }
    }

    expect($checked)->toBeGreaterThanOrEqual(6);
    expect(class_exists(CreateCategory::class))->toBeTrue();
    expect(class_exists(Create::class))->toBeTrue();
});

it('checks compiled Blade so directives cannot hide database access', function (): void {
    $files = new Filesystem;
    $compiler = new BladeCompiler($files, sys_get_temp_dir());
    $views = $files->allFiles(__DIR__ . '/../Fixtures/Application/resources/views');

    expect($views)->not->toBeEmpty();

    foreach ($views as $view) {
        expect(ArchitectureRules::violations($compiler->compileString($view->getContents()), 'Views'))->toBe([]);
    }
});
