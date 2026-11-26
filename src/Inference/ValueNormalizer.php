<?php

declare(strict_types=1);

namespace Sirius\LaravelBuilder\Inference;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use JsonException;
use Sirius\LaravelBuilder\Definitions\FieldPlan;
use Sirius\LaravelBuilder\Definitions\FieldType;

final class ValueNormalizer
{
    public function normalize(FieldPlan $plan, mixed $value, string $timezone): mixed
    {
        if ($value === null) {
            if (!$plan->column->nullable) {
                throw new InvalidArgumentException('This column cannot store null.');
            }

            return null;
        }
        if ($plan->field->type === FieldType::Boolean) {
            return match ($value) {
                true, 1, '1'  => true,
                false, 0, '0' => false,
                default       => throw new InvalidArgumentException('Use true, false, 1, or 0 for booleans.'),
            };
        }
        if (in_array($plan->column->type, ['integer', 'decimal'], true)) {
            return (new NumberNormalizer)->normalize($value, $plan->column, $plan->driver);
        }
        if ($plan->column->type === 'float') {
            if ((!is_string($value) && !is_int($value) && !is_float($value)) || !is_numeric($value) || !is_finite((float) $value)) {
                throw new InvalidArgumentException('Use a finite numeric value.');
            }

            return $value;
        }
        if ($plan->column->type === 'json') {
            if (!is_string($value)) {
                throw new InvalidArgumentException('JSON input must be a string; serialize cast values for editing first.');
            }
            try {
                $decoded = json_decode($value, in_array($plan->cast, ['array', 'json', 'collection'], true), 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                throw new InvalidArgumentException('Enter valid JSON.');
            }
            if ($plan->cast === null || $plan->cast === 'string') {
                return $value;
            }
            if ($decoded === null && !$plan->column->nullable) {
                throw new InvalidArgumentException('JSON null would become SQL null through this cast, but the column is not nullable.');
            }
            preg_match_all('/"(?:\\\\.|[^"\\\\])*"(*SKIP)(*F)|-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?/', $value, $numbers);
            foreach ($numbers[0] as $number) {
                $numeric = json_decode($number);
                if (is_float($numeric) && (!is_finite($numeric) || !strpbrk($number, '.eE'))) {
                    throw new InvalidArgumentException('JSON cast would lose numeric precision; use quoted values or a custom adapter.');
                }
            }
            if (!in_array($plan->cast, ['array', 'json', 'object', 'collection'], true)) {
                throw new InvalidArgumentException('Supply an adapter for this JSON cast.');
            }
            if (in_array($plan->cast, ['array', 'collection'], true) && !is_array($decoded)) {
                throw new InvalidArgumentException('This JSON cast requires an object or array.');
            }
            if ($plan->cast === 'object' && !is_object($decoded)) {
                throw new InvalidArgumentException('This JSON cast requires an object.');
            }

            return $decoded;
        }
        if (!is_string($value)) {
            throw new InvalidArgumentException('Use a canonical string for this field.');
        }
        if ($plan->field->type === FieldType::Number && !preg_match('/^-?[0-9]+(?:\.[0-9]+)?$/D', $value)) {
            throw new InvalidArgumentException('Use canonical numeric text for this string column.');
        }
        if ($plan->field->type === FieldType::Phone && !preg_match('/^\+[1-9][0-9]{1,14}$/D', $value)) {
            throw new InvalidArgumentException('Enter a canonical E.164 phone number.');
        }
        $format = match ($plan->field->type) {
            FieldType::Date => 'Y-m-d', FieldType::Time => 'H:i', FieldType::Datetime => 'Y-m-d H:i:s',
            default         => null,
        };
        if ($format !== null) {
            $date = DateTimeImmutable::createFromFormat('!' . $format, $value, new DateTimeZone($timezone));
            if ($date === false || $date->format($format) !== $value) {
                throw new InvalidArgumentException('Use a valid local date/time in format ' . $format . '.');
            }

            return $plan->field->type === FieldType::Time ? $value . ':00' : $value;
        }

        return $value;
    }

    public function forEditing(FieldPlan $plan, mixed $value, ?string $timezone = null): mixed
    {
        if ($plan->field->type === FieldType::Password) {
            return '';
        }
        if ($value instanceof \DateTimeInterface) {
            if ($timezone === null) {
                throw new InvalidArgumentException('Specify the application/widget timezone for date casts.');
            }
            if ($value->format('u') !== '000000' || ($plan->field->type === FieldType::Time && $value->format('s') !== '00')) {
                throw new InvalidArgumentException('Widget precision cannot preserve this stored time; supply a custom mapping.');
            }
            $date = DateTimeImmutable::createFromInterface($value)->setTimezone(new DateTimeZone($timezone));

            return $date->format(match ($plan->field->type) {
                FieldType::Date => 'Y-m-d', FieldType::Time => 'H:i',
                default         => 'Y-m-d H:i:s',
            });
        }
        if ($value instanceof \BackedEnum) {
            return (string) $value->value;
        }
        if ($value instanceof \UnitEnum) {
            return $value->name;
        }
        if ($plan->column->type === 'json' && $plan->cast !== null && $plan->cast !== 'string' && $value !== null) {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        }
        if ($plan->field->type === FieldType::Select && (is_int($value) || is_string($value))) {
            return (string) $value;
        }
        if ($plan->field->type === FieldType::Time && is_string($value) && strlen($value) > 5) {
            if (!preg_match('/^\d{2}:\d{2}:00$/D', $value)) {
                throw new InvalidArgumentException('Minute-precision time widgets cannot preserve nonzero seconds.');
            }

            return substr($value, 0, 5);
        }

        return $value;
    }
}
