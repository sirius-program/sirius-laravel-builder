<?php

declare(strict_types=1);

namespace Sirius\LaravelBuilder\Definitions;

final readonly class EnumDefinition
{
    /** @param class-string<\UnitEnum> $className */
    public function __construct(public string $className, public string $relativeName, public ?string $backingType) {}
}
