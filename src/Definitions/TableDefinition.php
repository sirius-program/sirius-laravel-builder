<?php

declare(strict_types=1);

namespace Sirius\LaravelBuilder\Definitions;

final readonly class TableDefinition
{
    /**
     * @param  list<ColumnDefinition>  $columns
     * @param  list<IndexDefinition>  $indexes
     * @param  list<ForeignKeyDefinition>  $foreignKeys
     * @param  list<string>  $warnings
     */
    public function __construct(public string $connection, public string $driver, public string $table, public string $key, public array $columns, public array $indexes, public array $foreignKeys, public array $warnings) {}
}
