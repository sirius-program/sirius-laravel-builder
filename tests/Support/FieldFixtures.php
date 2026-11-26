<?php

declare(strict_types=1);

namespace Tests\Support;

use Sirius\LaravelBuilder\Definitions\ColumnDefinition;
use Sirius\LaravelBuilder\Definitions\TableDefinition;

final class FieldFixtures
{
    /** @param array<string, mixed> $overrides */
    public static function column(array $overrides = []): ColumnDefinition
    {
        return new ColumnDefinition(...array_replace([
            'name'                 => 'name',
            'type'                 => 'string',
            'nativeType'           => 'varchar(80)',
            'nullable'             => false,
            'default'              => null,
            'length'               => 80,
            'precision'            => null,
            'scale'                => null,
            'unsigned'             => false,
            'autoIncrement'        => false,
            'generated'            => false,
            'generationExpression' => null,
            'enumValues'           => null,
        ], $overrides));
    }

    /** @param list<ColumnDefinition> $columns */
    public static function table(array $columns): TableDefinition
    {
        return new TableDefinition('testing', 'mysql', 'categories', 'id', $columns, [], [], []);
    }
}
