<?php

declare(strict_types=1);

namespace Sirius\LaravelBuilder\Definitions;

use InvalidArgumentException;

final readonly class FieldDefinition
{
    /** @param list<string> $rules */
    public function __construct(
        public string $column,
        public string $label,
        public FieldType $type,
        public array $rules = [],
        public bool $writable = true,
        public ?string $endpoint = null,
        public ?EnumDefinition $enum = null,
    ) {
        if ($column === '' || $label === '') {
            throw new InvalidArgumentException('Field column and label must not be empty.');
        }
        if (($endpoint !== null || $enum instanceof EnumDefinition) && $type !== FieldType::Select) {
            throw new InvalidArgumentException('Only select fields can declare an endpoint or enum.');
        }
        if ($endpoint === '' || ($endpoint !== null && $enum instanceof EnumDefinition)) {
            throw new InvalidArgumentException('Use null for an absent endpoint; choose either endpoint or enum.');
        }
    }
}
