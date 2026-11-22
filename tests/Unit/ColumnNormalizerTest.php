<?php

declare(strict_types=1);

use Sirius\LaravelBuilder\Schema\ColumnNormalizer;
use Sirius\LaravelBuilder\Schema\SchemaInspectionException;

it('normalizes native type aliases without inventing unavailable constraints', function (string $native, string $typeName, string $type, ?int $length, ?int $precision, ?int $scale): void {
    $column = (new ColumnNormalizer)->normalize([
        'name'     => 'value', 'type' => $native, 'type_name' => $typeName,
        'nullable' => true, 'default' => 'CURRENT_TIMESTAMP', 'auto_increment' => false, 'generation' => null,
    ]);
    expect($column->type)->toBe($type);
    expect($column->length)->toBe($length);
    expect($column->precision)->toBe($precision);
    expect($column->scale)->toBe($scale);
    expect($column->default)->toBe('CURRENT_TIMESTAMP');
    expect($column->nullable)->toBeTrue();
})->with([
    ['varchar(80)', 'varchar', 'string', 80, null, null],
    ['character varying(80)', 'varchar', 'string', 80, null, null],
    ['int unsigned', 'int', 'integer', null, null, null],
    ['bigint', 'int8', 'integer', null, null, null],
    ['tinyint(1)', 'tinyint', 'integer', null, null, null],
    ['boolean', 'bool', 'boolean', null, null, null],
    ['numeric(12,2)', 'numeric', 'decimal', null, 12, 2],
    ['numeric(12,-2)', 'numeric', 'decimal', null, 12, -2],
    ['numeric', 'numeric', 'decimal', null, null, null],
    ['decimal(10)', 'decimal', 'decimal', null, 10, 0],
    ['timestamp(6) without time zone', 'timestamp', 'timestamp', null, null, null],
    ['time with time zone', 'timetz', 'time', null, null, null],
    ['datetime', 'datetime', 'datetime', null, null, null],
    ['date', 'date', 'date', null, null, null],
    ['jsonb', 'jsonb', 'json', null, null, null],
    ['uuid', 'uuid', 'uuid', null, null, null],
    ['bytea', 'bytea', 'binary', null, null, null],
    ['money', 'money', 'unknown', null, null, null],
]);

it('preserves enum escaping and generation expressions', function (): void {
    $column = (new ColumnNormalizer)->normalize([
        'name'       => 'status', 'type' => "enum('a,b','it''s','back\\\\slash','line\\nend','')", 'type_name' => 'enum',
        'nullable'   => false, 'default' => null, 'auto_increment' => false,
        'generation' => ['expression' => 'example'],
    ]);
    expect($column->enumValues)->toBe(['a,b', "it's", 'back\\slash', "line\nend", '']);
    expect($column->generated)->toBeTrue();
    expect($column->generationExpression)->toBe('example');
});

it('rejects undecodable native enums', function (): void {
    (new ColumnNormalizer)->normalize([
        'name'     => 'status', 'type' => 'enum(invalid)', 'type_name' => 'enum',
        'nullable' => false, 'default' => null, 'auto_increment' => false, 'generation' => null,
    ]);
})->throws(SchemaInspectionException::class, 'Cannot decode enum');
