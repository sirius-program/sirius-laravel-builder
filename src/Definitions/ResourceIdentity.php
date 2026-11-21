<?php

declare(strict_types=1);

namespace Sirius\LaravelBuilder\Definitions;

final readonly class ResourceIdentity
{
    public function __construct(
        public string $name,
        public string $suggestedTable,
        public string $livewireNamespace,
        public string $actionNamespace,
        public string $viewPath,
        public string $viewName,
        public string $testPath,
        public RouteDefinition $routes,
    ) {}
}
