<?php

declare(strict_types=1);

use Sirius\LaravelBuilder\Definitions\ArtifactDefinition;
use Sirius\LaravelBuilder\Definitions\ArtifactKind;
use Sirius\LaravelBuilder\Definitions\FieldDefinition;
use Sirius\LaravelBuilder\Definitions\FieldType;
use Sirius\LaravelBuilder\Definitions\Layout;
use Sirius\LaravelBuilder\Definitions\ModelDefinition;
use Sirius\LaravelBuilder\Definitions\Operation;
use Sirius\LaravelBuilder\Definitions\OperationSelection;
use Sirius\LaravelBuilder\Definitions\ResourceDefinition;
use Sirius\LaravelBuilder\Support\ResourceNamer;
use Tests\Fixtures\Discovery\Models\Admin\Category;

it('derives nested identity while keeping the table suggestion tied to the model', function (): void {
    $model = new ModelDefinition(Category::class, 'Admin\\Category', false);
    $namer = new ResourceNamer;
    $identity = $namer->identify($model, 'categories');

    expect($identity->name)->toBe('Admin\\Category');
    expect($identity->livewireNamespace)->toBe('App\\Livewire\\Admin\\Category');
    expect($identity->actionNamespace)->toBe('App\\Actions\\Admin\\Category');
    expect($identity->viewPath)->toBe('resources/views/livewire/admin/category');
    expect($identity->viewName)->toBe('livewire.admin.category');
    expect($identity->testPath)->toBe('tests/Feature/Crud/Admin/Category');
    expect($identity->routes->urlPrefix)->toBe('/admin/categories');
    expect($identity->routes->namePrefix)->toBe('admin.categories.');
    expect($identity->routes->middleware)->toBe(['auth']);
    $renamed = $namer->identify($model, 'categories', 'Catalog\\ProductGroup', 'Company\\Application');
    expect($renamed->suggestedTable)->toBe('categories');
    expect($renamed->viewPath)->toBe('resources/views/livewire/catalog/product-group');
    expect($renamed->routes->urlPrefix)->toBe('/catalog/product-groups');
    expect($renamed->actionNamespace)->toBe('Company\\Application\\Actions\\Catalog\\ProductGroup');
});

it('rejects invalid or reserved resource names before deriving output', function (string $name): void {
    (new ResourceNamer)->identify(new ModelDefinition(Category::class, 'Admin\\Category', false), 'categories', $name);
})->with(['', '../Category', '/Category', 'Admin/Category', 'Admin\\', '\\Category', 'Admin\\\\Category', 'Blog Post', '1Category', 'Class', 'Invalid\\Object', 'Int', "Name\0", 'Admin\\Trait', '_', 'CON'])->throws(InvalidArgumentException::class);

it('offers only optional mutations and enables soft delete operations when supported', function (): void {
    expect((new OperationSelection(false))->selected)->toBe([Operation::Create, Operation::Update, Operation::Delete]);
    expect((new OperationSelection(true))->selected)->toBe(Operation::cases());
    expect((new OperationSelection(false, []))->selected)->toBe([]);
    expect((new OperationSelection(false, [Operation::Delete, Operation::Create, Operation::Create]))->selected)->toBe([Operation::Create, Operation::Delete]);
    expect((new OperationSelection(false))->includes(Operation::Update))->toBeTrue();
});

it('rejects restore and permanent deletion for ordinary models', function (Operation $operation): void {
    new OperationSelection(false, [$operation]);
})->with([Operation::Restore, Operation::PermanentDelete])->throws(InvalidArgumentException::class);

it('holds a typed resource plan without executing generation', function (): void {
    $model = new ModelDefinition(Category::class, 'Admin\\Category', false);
    $field = new FieldDefinition('name', 'Name', FieldType::Text, ['required', 'string']);
    $resource = new ResourceDefinition($model, (new ResourceNamer)->identify($model, 'categories'), new OperationSelection(false, []), Layout::Dialogs, fields: [$field]);

    expect($resource->layout)->toBe(Layout::Dialogs);
    expect($resource->policy->enforce)->toBeTrue();
    expect($resource->fields[0]->rules)->toBe(['required', 'string']);
    expect($resource->artifacts)->toBe([]);
});

it('rejects duplicate field columns', function (): void {
    $model = new ModelDefinition(Category::class, 'Admin\\Category', false);
    $field = new FieldDefinition('name', 'Name', FieldType::Text);
    new ResourceDefinition($model, (new ResourceNamer)->identify($model, 'categories'), new OperationSelection(false), fields: [$field, $field]);
})->throws(InvalidArgumentException::class, 'unique column');

it('rejects select-only sources on other fields', function (): void {
    new FieldDefinition('name', 'Name', FieldType::Text, endpoint: '/options');
})->throws(InvalidArgumentException::class);

it('rejects unsafe artifact paths', function (string $path): void {
    new ArtifactDefinition(ArtifactKind::View, $path);
})->with(['', '../escape.php', '/absolute.php', 'C:\\escape.php', 'views/../../escape.php', "bad\0path", 'new//index.php', 'new/./index.php'])->throws(InvalidArgumentException::class);

it('rejects an empty resolved model table', function (): void {
    (new ResourceNamer)->identify(new ModelDefinition(Category::class, 'Admin\\Category', false), ' ');
})->throws(InvalidArgumentException::class, 'table must not be empty');
