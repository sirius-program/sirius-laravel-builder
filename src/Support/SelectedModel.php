<?php

declare(strict_types=1);

namespace Sirius\LaravelBuilder\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Sirius\LaravelBuilder\Definitions\ModelDefinition;
use Sirius\LaravelBuilder\Definitions\TableDefinition;
use Sirius\LaravelBuilder\Schema\SchemaInspector;

final readonly class SelectedModel
{
    private Model $model;

    public function __construct(ModelDefinition $definition)
    {
        $model = app($definition->className);
        if (!$model instanceof Model) {
            throw new InvalidArgumentException('Select an Eloquent model.');
        }
        $this->model = $model;
    }

    public function table(): string
    {
        return $this->model->getTable();
    }

    public function routeKey(): string
    {
        return $this->model->getRouteKeyName();
    }

    public function inspect(string $table): TableDefinition
    {
        return app(SchemaInspector::class)->inspect($this->model, $table);
    }

    public function cast(string $column): ?string
    {
        return $this->model->getCasts()[$column] ?? null;
    }

    public function policy(): ?string
    {
        $policy = Gate::getPolicyFor($this->model);

        return $policy ? $policy::class : null;
    }
}
