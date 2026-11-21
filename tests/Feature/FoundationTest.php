<?php

declare(strict_types=1);

use App\Actions\Category\CreateCategory;
use App\Livewire\Category\Create;
use App\Models\Category;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Sirius\LaravelBuilder\SiriusLaravelBuilderServiceProvider;

beforeEach(function (): void {
    Schema::create('categories', function (Blueprint $table): void {
        $table->id();
        $table->string('name', 80);
        $table->timestamps();
    });
});

it('boots the provider and registers the command without touching records', function (): void {
    expect(app()->getProvider(SiriusLaravelBuilderServiceProvider::class))->toBeInstanceOf(SiriusLaravelBuilderServiceProvider::class);
    expect(array_key_exists('make:crud', Artisan::all()))->toBeTrue();
    $this->assertDatabaseCount('categories', 0);
});

it('isolates database connections and application artifacts', function (): void {
    expect(DB::connection()->getDatabaseName())->toBe(':memory:');
    expect(base_path())->toBe($this->sandbox);
    expect(array_keys(config('database.connections')))->toBe(['testing']);
    expect(app_path())->toBe($this->sandbox . '/app');
    expect(is_file($this->sandbox . '/resources/views/livewire/category/create.blade.php'))->toBeTrue();
    $this->assertDatabaseCount('categories', 0);
});

it('persists only authorized validated attributes through the fixture action', function (): void {
    Gate::define('create', fn (?object $user, string $model): bool => $model === Category::class);

    $category = (new CreateCategory)->handle(['name' => 'Tools', 'id' => 999, 'unexpected' => 'ignored']);

    $this->assertModelExists($category);
    $this->assertDatabaseHas('categories', ['id' => 1, 'name' => 'Tools']);
});

it('rejects direct action calls when authorization fails', function (): void {
    Gate::define('create', fn (?object $user): bool => false);

    expect(fn (): Category => (new CreateCategory)->handle(['name' => 'Tools']))->toThrow(AuthorizationException::class);

    $this->assertDatabaseCount('categories', 0);
});

it('rejects invalid direct action calls without writing records', function (): void {
    Gate::define('create', fn (?object $user): bool => true);

    expect(fn (): Category => (new CreateCategory)->handle(['name' => '']))->toThrow(ValidationException::class);

    $this->assertDatabaseCount('categories', 0);
});

it('delegates Livewire submissions to the action and resets state', function (): void {
    Gate::define('create', fn (?object $user): bool => true);

    Livewire::test(Create::class)->set('name', 'Tools')->call('save')->assertHasNoErrors()->assertSet('name', '');

    $this->assertDatabaseHas('categories', ['name' => 'Tools']);
});

it('returns Livewire validation errors without writing records', function (): void {
    Gate::define('create', fn (?object $user): bool => true);

    Livewire::test(Create::class)->call('save')->assertHasErrors(['name' => 'required']);

    $this->assertDatabaseCount('categories', 0);
});
