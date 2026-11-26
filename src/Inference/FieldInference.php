<?php

declare(strict_types=1);

namespace Sirius\LaravelBuilder\Inference;

use Sirius\LaravelBuilder\Definitions\ColumnDefinition;
use Sirius\LaravelBuilder\Definitions\FieldSuggestion;
use Sirius\LaravelBuilder\Definitions\FieldType;

final class FieldInference
{
    public function suggest(ColumnDefinition $column): FieldSuggestion
    {
        $types = match ($column->type) {
            'enum'                  => [FieldType::Select],
            'timestamp', 'datetime' => [FieldType::Datetime],
            'date'                  => [FieldType::Date],
            'time'                  => [FieldType::Time],
            'boolean'               => [FieldType::Boolean],
            'json'                  => [FieldType::Textarea],
            'uuid', 'ulid'          => [FieldType::Text],
            'integer'               => [FieldType::Number, FieldType::Boolean, FieldType::Currency],
            'decimal'               => [FieldType::Number, FieldType::Currency],
            'float'                 => [FieldType::Number],
            'string'                => [FieldType::Text, FieldType::Email, FieldType::Password, FieldType::Url, FieldType::Phone],
            'text'                  => [FieldType::Textarea, FieldType::Richtext],
            default                 => [],
        };
        if (str_ends_with($column->name, '_id') && in_array($column->type, ['integer', 'string', 'uuid', 'ulid'], true)) {
            $types = in_array($column->type, ['uuid', 'ulid'], true) ? [FieldType::Select, FieldType::Text] : [FieldType::Select, FieldType::Number, FieldType::Text];
        }

        return new FieldSuggestion(
            included: $column->name !== 'id' && !str_ends_with($column->name, '_at') && $column->type !== 'timestamp',
            label: self::label($column->name), types: $types,
            writable: !$column->generated && !$column->autoIncrement,
        );
    }

    public static function label(string $name): string
    {
        $words = preg_replace('/([a-z0-9])([A-Z])/', '$1 $2', $name);

        return mb_convert_case(str_replace(['_', '-'], ' ', $words), MB_CASE_TITLE, 'UTF-8');
    }
}
