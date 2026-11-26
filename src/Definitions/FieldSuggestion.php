<?php

declare(strict_types=1);

namespace Sirius\LaravelBuilder\Definitions;

final readonly class FieldSuggestion
{
    /** @param list<FieldType> $types */
    public function __construct(public bool $included, public string $label, public array $types, public bool $writable) {}
}
