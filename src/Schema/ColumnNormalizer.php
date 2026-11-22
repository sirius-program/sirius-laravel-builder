<?php

declare(strict_types=1);

namespace Sirius\LaravelBuilder\Schema;

use Sirius\LaravelBuilder\Definitions\ColumnDefinition;

final class ColumnNormalizer
{
    /**
     * @param  array<string, mixed>  $column
     * @param  list<string>|null  $enumValues
     */
    public function normalize(array $column, ?array $enumValues = null, bool $identity = false): ColumnDefinition
    {
        $native = (string) $column['type'];
        $name = strtolower(trim((string) $column['type_name']));
        $name = preg_replace('/\s+unsigned$/', '', $name);
        $type = match ($name) {
            'varchar', 'character varying', 'nvarchar', 'char', 'character', 'bpchar'                                     => 'string',
            'tinyint', 'smallint', 'mediumint', 'int', 'integer', 'bigint', 'int2', 'int4', 'int8', 'serial', 'bigserial' => 'integer',
            'numeric', 'decimal', 'dec'                                                                                   => 'decimal',
            'float', 'double', 'double precision', 'real', 'float4', 'float8'                                             => 'float',
            'bool', 'boolean'                                                                                             => 'boolean',
            'tinytext', 'mediumtext', 'longtext', 'text'                                                                  => 'text',
            'timestamp', 'timestamptz', 'timestamp with time zone', 'timestamp without time zone'                         => 'timestamp',
            'datetime'                                                                                                    => 'datetime',
            'time', 'timetz', 'time with time zone', 'time without time zone'                                             => 'time',
            'date'                                                                                                        => 'date',
            'json', 'jsonb'                                                                                               => 'json',
            'uuid'                                                                                                        => 'uuid',
            'blob', 'tinyblob', 'mediumblob', 'longblob', 'binary', 'varbinary', 'bytea'                                  => 'binary',
            'enum'                                                                                                        => 'enum',
            default                                                                                                       => 'unknown',
        };
        if ($enumValues !== null) {
            $type = 'enum';
        }
        if ($name === 'enum' && $enumValues === null) {
            $enumValues = $this->mysqlEnum($native);
        }
        preg_match('/\(\s*(\d+)(?:\s*,\s*(-?\d+))?\s*\)/', $native, $size);
        $length = $type === 'string' && isset($size[1]) ? (int) $size[1] : null;
        $precision = $type === 'decimal' && isset($size[1]) ? (int) $size[1] : null;
        $scale = $precision !== null ? (isset($size[2]) ? (int) $size[2] : 0) : null;

        return new ColumnDefinition(
            name: (string) $column['name'], type: $type, nativeType: $native,
            nullable: (bool) $column['nullable'], default: $column['default'],
            length: $length, precision: $precision, scale: $scale,
            unsigned: str_contains(strtolower($native), 'unsigned'),
            autoIncrement: $identity || (bool) $column['auto_increment'],
            generated: $identity || $column['generation'] !== null,
            generationExpression: $column['generation']['expression'] ?? null,
            enumValues: $enumValues,
        );
    }

    /** @return list<string> */
    private function mysqlEnum(string $type): array
    {
        $body = substr($type, 5, -1);
        $values = [];
        $offset = 0;
        while ($offset < strlen($body)) {
            if (!preg_match("/\G'((?:[^'\\\\]|\\\\.|'')*)'(?:,|$)/s", $body, $match, 0, $offset)) {
                throw new SchemaInspectionException('Cannot decode enum metadata; configure its allowed values explicitly.');
            }
            $values[] = str_replace("''", "'", preg_replace_callback('/\\\\(.)/s', static fn (array $escape): string => match ($escape[1]) {
                '0'     => "\0", 'n' => "\n", 'r' => "\r", 't' => "\t", 'b' => "\x08", 'Z' => "\x1a",
                default => $escape[1],
            }, $match[1]));
            $offset += strlen($match[0]);
        }

        return $values;
    }
}
