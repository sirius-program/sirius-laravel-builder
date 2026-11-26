<?php

declare(strict_types=1);

namespace Sirius\LaravelBuilder\Definitions;

final readonly class IndexDefinition
{
    /** @param list<string> $columns */
    public function __construct(public string $name, public array $columns, public bool $unique, public bool $primary, public ?string $type, public bool $unconditional = false) {}
}
