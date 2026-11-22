<?php

declare(strict_types=1);

namespace Sirius\LaravelBuilder\Definitions;

final readonly class ForeignKeyDefinition
{
    /**
     * @param  list<string>  $columns
     * @param  list<string>  $foreignColumns
     */
    public function __construct(public ?string $name, public array $columns, public ?string $foreignSchema, public string $foreignTable, public array $foreignColumns, public string $onUpdate, public string $onDelete) {}
}
