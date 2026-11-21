<?php

declare(strict_types=1);

namespace Sirius\LaravelBuilder\Discovery;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Sirius\LaravelBuilder\Definitions\ModelDefinition;

final readonly class ModelDiscovery
{
    public function __construct(private ClassDiscovery $classes) {}

    /** @return list<ModelDefinition> */
    public function discover(string $directory, string $namespace = 'App\\Models'): array
    {
        $models = [];
        foreach ($this->classes->discover($directory, $namespace) as $relative => $reflection) {
            if ($reflection->isAbstract() || !$reflection->isSubclassOf(Model::class)) {
                continue;
            }
            $models[] = new ModelDefinition($reflection->getName(), $relative, in_array(SoftDeletes::class, class_uses_recursive($reflection->getName()), true));
        }

        return $models;
    }
}
