<?php

declare(strict_types=1);

namespace Sirius\LaravelBuilder\Inference;

use InvalidArgumentException;
use Sirius\LaravelBuilder\Definitions\ColumnDefinition;

final class NumberNormalizer
{
    public function normalize(mixed $value, ColumnDefinition $column, string $driver = 'unknown'): string
    {
        if (!is_int($value) && !is_string($value)) {
            throw new InvalidArgumentException('Use an exact numeric string, never floating-point currency.');
        }
        $value = (string) $value;
        if (!preg_match('/^(-?)([0-9]+)(?:\.([0-9]+))?$/D', $value, $parts)) {
            throw new InvalidArgumentException('Use canonical digits without grouping separators or exponent notation.');
        }
        $whole = ltrim($parts[2], '0') ?: '0';
        $fraction = $parts[3] ?? '';
        $negative = $parts[1] === '-' && ($whole !== '0' || trim($fraction, '0') !== '');
        if ($negative && $column->unsigned) {
            throw new InvalidArgumentException('Unsigned values cannot be negative.');
        }
        if ($column->type === 'integer') {
            if ($fraction !== '') {
                throw new InvalidArgumentException('An integer cannot contain fractional digits.');
            }
            $native = strtolower($column->nativeType);
            $bits = match (true) {
                $driver === 'sqlite'                                             => 64,
                str_contains($native, 'tinyint')                                 => 8,
                str_contains($native, 'smallint'), str_contains($native, 'int2') => 16,
                str_contains($native, 'mediumint')                               => 24,
                str_contains($native, 'bigint'), str_contains($native, 'int8')   => 64,
                default                                                          => 32,
            };
            $bounds = [8 => ['127', '128', '255'], 16 => ['32767', '32768', '65535'], 24 => ['8388607', '8388608', '16777215'], 32 => ['2147483647', '2147483648', '4294967295'], 64 => ['9223372036854775807', '9223372036854775808', '18446744073709551615']];
            $limit = $bounds[$bits][$column->unsigned ? 2 : ($negative ? 1 : 0)];
            if (strlen($whole) > strlen($limit) || (strlen($whole) === strlen($limit) && strcmp($whole, $limit) > 0)) {
                throw new InvalidArgumentException('Integer is outside the native column range.');
            }
        } elseif ($column->type === 'decimal') {
            if ($column->scale !== null && ($column->scale < 0 || ($column->precision !== null && $column->scale > $column->precision))) {
                throw new InvalidArgumentException('This decimal precision/scale requires an explicit custom mapping.');
            }
            if ($column->scale !== null && strlen($fraction) > $column->scale) {
                throw new InvalidArgumentException('Value exceeds decimal scale; rounding is not automatic.');
            }
            if ($column->precision !== null && $column->scale !== null && strlen(ltrim($whole, '0')) > $column->precision - $column->scale) {
                throw new InvalidArgumentException('Value exceeds decimal precision.');
            }
            if ($column->scale !== null) {
                $fraction = str_pad($fraction, $column->scale, '0');
            }
        }

        return ($negative ? '-' : '') . $whole . ($fraction === '' ? '' : '.' . $fraction);
    }
}
