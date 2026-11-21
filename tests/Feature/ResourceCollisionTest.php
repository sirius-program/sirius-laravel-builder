<?php

declare(strict_types=1);

use Sirius\LaravelBuilder\Definitions\ArtifactDefinition;
use Sirius\LaravelBuilder\Definitions\ArtifactKind;
use Sirius\LaravelBuilder\Definitions\ModelDefinition;
use Sirius\LaravelBuilder\Discovery\ResourceCollisionDetector;
use Sirius\LaravelBuilder\Support\ResourceNamer;
use Tests\Fixtures\Discovery\Models\Admin\Category;

it('detects normalized resource collisions and keeps distinct nested names separate', function (): void {
    $model = new ModelDefinition(Category::class, 'Admin\\Category', false);
    $namer = new ResourceNamer;
    $existing = $namer->identify($model, 'categories');
    $detector = $this->app->make(ResourceCollisionDetector::class);

    expect($detector->identities($namer->identify($model, 'categories', 'admin\\category'), [$existing]))->not->toBeEmpty();
    expect($detector->identities($namer->identify($model, 'categories', 'Client\\Category'), [$existing]))->toBe([]);
    expect($detector->identities($namer->identify($model, 'categories', 'Admin\\BlogPost'), [$namer->identify($model, 'categories', 'Admin\\Blog_Post')]))->not->toBeEmpty();
});

it('detects existing targets, case insensitive duplicates, class collisions and blocked parent paths without writes', function (): void {
    $detector = $this->app->make(ResourceCollisionDetector::class);
    file_put_contents(base_path('blocked'), 'keep me');
    $artifacts = [
        new ArtifactDefinition(ArtifactKind::View, 'routes/WEB.php'),
        new ArtifactDefinition(ArtifactKind::View, 'new/Index.php'),
        new ArtifactDefinition(ArtifactKind::View, 'NEW/index.php'),
        new ArtifactDefinition(ArtifactKind::Action, 'app/Actions/New.php', Category::class),
        new ArtifactDefinition(ArtifactKind::View, 'blocked/child.php'),
    ];
    $before = hash_file('sha256', base_path('routes/web.php'));

    $conflicts = $detector->artifacts(base_path(), $artifacts);

    expect($conflicts)->toHaveCount(4);
    expect(hash_file('sha256', base_path('routes/web.php')))->toBe($before);
    expect(file_get_contents(base_path('blocked')))->toBe('keep me');
    expect(is_dir(base_path('new')))->toBeFalse();
    expect($detector->artifacts(base_path(), [new ArtifactDefinition(ArtifactKind::View, 'resources/views/new/index.blade.php')]))->toBe([]);
});

it('detects parent-child conflicts in planned output before any files exist', function (): void {
    $conflicts = $this->app->make(ResourceCollisionDetector::class)->artifacts(base_path(), [
        new ArtifactDefinition(ArtifactKind::View, 'new/item'),
        new ArtifactDefinition(ArtifactKind::View, 'new/item/index.blade.php'),
    ]);
    expect($conflicts)->toHaveCount(1);
    expect(is_dir(base_path('new')))->toBeFalse();
});

it('uses the selected Eloquent table without changing it when the resource is renamed', function (): void {
    $model = new Category;
    $definition = new ModelDefinition(Category::class, 'Admin\\Category', false);
    $namer = new ResourceNamer;
    $connection = $model->getConnection();
    $connection->enableQueryLog();

    expect($namer->identify($definition, $model->getTable())->suggestedTable)->toBe('categories');
    $model->setTable('legacy_catalog');
    expect($namer->identify($definition, $model->getTable(), 'ProductGroup')->suggestedTable)->toBe('legacy_catalog');
    expect($connection->getQueryLog())->toBe([]);
});
