<?php

declare(strict_types=1);

namespace Sirius\LaravelBuilder\Definitions;

final readonly class RouteDefinition
{
    /** @param list<string> $middleware */
    public function __construct(public string $urlPrefix, public string $namePrefix, public array $middleware = ['auth']) {}
}
