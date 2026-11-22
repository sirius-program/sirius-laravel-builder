<?php

declare(strict_types=1);

namespace Sirius\LaravelBuilder\Definitions;

final readonly class ColumnDefinition
{
    /** @param list<string>|null $enumValues */
    public function __construct(
        public string $name,
        public string $type,
        public string $nativeType,
        public bool $nullable,
        public mixed $default,
        public ?int $length,
        public ?int $precision,
        public ?int $scale,
        public bool $unsigned,
        public bool $autoIncrement,
        public bool $generated,
        public ?string $generationExpression,
        public ?array $enumValues,
    ) {}
}
