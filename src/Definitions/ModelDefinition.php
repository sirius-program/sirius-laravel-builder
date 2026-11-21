<?php

declare(strict_types=1);

namespace Sirius\LaravelBuilder\Definitions;

final readonly class ModelDefinition
{
    /** @param class-string $className */
    public function __construct(public string $className, public string $relativeName, public bool $softDeletes) {}
}
