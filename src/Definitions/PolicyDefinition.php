<?php

declare(strict_types=1);

namespace Sirius\LaravelBuilder\Definitions;

final readonly class PolicyDefinition
{
    public function __construct(public bool $enforce = true, public ?string $className = null) {}
}
