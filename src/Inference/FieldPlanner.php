<?php

declare(strict_types=1);

namespace Sirius\LaravelBuilder\Inference;

use InvalidArgumentException;
use Sirius\LaravelBuilder\Definitions\ColumnDefinition;
use Sirius\LaravelBuilder\Definitions\EnumDefinition;
use Sirius\LaravelBuilder\Definitions\FieldDefinition;
use Sirius\LaravelBuilder\Definitions\FieldPlan;
use Sirius\LaravelBuilder\Definitions\FieldType;
use Sirius\LaravelBuilder\Definitions\TableDefinition;
use Sirius\LaravelBuilder\Definitions\ValidationConstraint;
use Sirius\LaravelBuilder\Definitions\ValidationDefinition;

final readonly class FieldPlanner
{
    public function __construct(private FieldInference $inference = new FieldInference) {}

    /** @param list<array{value: string, label: string}> $options */
    public function plan(TableDefinition $table, ColumnDefinition $column, FieldType $type, ?string $label = null, ?string $endpoint = null, ?EnumDefinition $enum = null, array $options = [], ?string $cast = null, ?string $confirmationLabel = null): FieldPlan
    {
        if ($confirmationLabel !== null && ($type !== FieldType::Password || trim($confirmationLabel) === '')) {
            throw new InvalidArgumentException('A nonempty confirmation label can only be configured for password fields.');
        }
        $suggestion = $this->inference->suggest($column);
        $enumSelect = $enum instanceof EnumDefinition && $type === FieldType::Select && in_array($column->type, ['string', 'integer', 'enum', 'uuid', 'ulid'], true);
        if (!in_array($type, $suggestion->types, true) && !$enumSelect) {
            throw new InvalidArgumentException('Unsupported field mapping for ' . $column->name . '; choose a suggested type or supply a custom implementation.');
        }
        if ($enum instanceof EnumDefinition && $options === []) {
            throw new InvalidArgumentException('Resolve and validate enum options before planning the field.');
        }
        if ($options !== [] && (!$enum instanceof EnumDefinition || $type !== FieldType::Select)) {
            throw new InvalidArgumentException('Resolved options require an enum-backed select.');
        }
        if ($enum instanceof EnumDefinition && $cast !== null && $cast !== $enum->className) {
            throw new InvalidArgumentException('The selected enum differs from the existing model cast; configure an explicit compatible mapping.');
        }
        $endpoint = $endpoint === null || trim($endpoint) === '' ? null : trim($endpoint);
        if ($endpoint !== null && (!str_starts_with($endpoint, '/') || str_starts_with($endpoint, '//') || preg_match('/[\\\\\x00-\x20]/', $endpoint))) {
            throw new InvalidArgumentException('Use a same-origin endpoint path beginning with one slash.');
        }
        if ($cast !== null && str_starts_with($cast, 'decimal:') && $column->scale !== null && $cast !== 'decimal:' . $column->scale) {
            throw new InvalidArgumentException('Decimal cast scale differs from the database; configure a safe explicit mapping.');
        }
        $warnings = [];
        $shared = match ($column->type) {
            'string', 'text'        => ['string'],
            'boolean'               => ['boolean'],
            'uuid'                  => ['uuid'],
            'ulid'                  => ['ulid'],
            'json'                  => ['json'],
            'timestamp', 'datetime' => ['date_format:Y-m-d H:i:s'],
            'date'                  => ['date_format:Y-m-d'],
            'time'                  => ['date_format:H:i'],
            'integer', 'float'      => ['numeric'],
            'decimal'               => $column->scale !== null && $column->scale >= 0 ? ['numeric', 'decimal:0,' . $column->scale] : ['numeric'],
            default                 => [],
        };
        if ($column->type === 'integer' && $type === FieldType::Boolean) {
            $shared = [];
        }
        if ($column->length !== null && in_array($column->type, ['string', 'text'], true)) {
            $shared[] = 'max:' . $column->length;
        }
        $shared = [...$shared, ...match ($type) {
            FieldType::Email    => ['email'],
            FieldType::Url      => ['url:http,https'],
            FieldType::Password => ['confirmed'],
            FieldType::Boolean  => ['boolean'],
            default             => [],
        }];
        if ($column->type === 'decimal' && ($column->scale !== null && ($column->scale < 0 || ($column->precision !== null && $column->scale > $column->precision)))) {
            throw new InvalidArgumentException('This decimal precision/scale requires a custom mapping.');
        }
        $presence = $column->nullable ? ['nullable'] : ($column->default !== null ? ['sometimes', 'required'] : ['required']);
        $update = $type === FieldType::Password ? ['sometimes', 'required'] : $presence;
        $constraints = [];
        $values = $enum instanceof EnumDefinition ? array_column($options, 'value') : $column->enumValues;
        if ($values !== null) {
            foreach ($values as $value) {
                if ($column->type === 'integer') {
                    (new NumberNormalizer)->normalize($value, $column, $table->driver);
                }
                if ($column->length !== null && mb_strlen($value) > $column->length) {
                    throw new InvalidArgumentException('Enum value exceeds column length.');
                }
                if ($column->enumValues !== null && !in_array($value, $column->enumValues, true)) {
                    throw new InvalidArgumentException('Enum option is incompatible with database values.');
                }
            }
            if (count(array_unique($values)) !== count($values) || ($enum instanceof EnumDefinition && in_array('', $values, true))) {
                throw new InvalidArgumentException('Enum IDs must be unique and nonempty.');
            }
            $constraints[] = new ValidationConstraint('in', $values);
        }
        foreach ($table->indexes as $index) {
            if ($index->unique && in_array($column->name, $index->columns, true)) {
                if ($index->unconditional && $index->columns === [$column->name]) {
                    $constraints[] = new ValidationConstraint('unique', connection: $table->connection, table: $table->table, column: $column->name, key: $table->key);
                } else {
                    $warnings[] = 'Unique index ' . $index->name . ' needs explicit composite/partial/expression validation configuration.';
                }
            }
        }
        foreach ($table->foreignKeys as $foreignKey) {
            if ($foreignKey->columns === [$column->name] && count($foreignKey->foreignColumns) === 1) {
                if ($table->prefix !== '' && !str_starts_with($foreignKey->foreignTable, $table->prefix)) {
                    $warnings[] = 'Foreign table lies outside the connection prefix; configure existence validation explicitly.';

                    continue;
                }
                $foreignTable = $table->prefix === '' ? $foreignKey->foreignTable : substr($foreignKey->foreignTable, strlen($table->prefix));
                $target = ($foreignKey->foreignSchema !== null ? $foreignKey->foreignSchema . '.' : '') . $foreignTable;
                $constraints[] = new ValidationConstraint('exists', connection: $table->connection, table: $target, column: $foreignKey->foreignColumns[0]);
                $warnings[] = 'Existence validation is not authorization; scope selectable records and persistence to the current user.';
            } elseif (in_array($column->name, $foreignKey->columns, true)) {
                $warnings[] = 'Composite foreign key requires explicit validation configuration.';
            }
        }
        if ($type === FieldType::Password) {
            if (in_array($column->name . '_confirmation', array_column($table->columns, 'name'), true)) {
                throw new InvalidArgumentException('Password confirmation name conflicts with an existing database column.');
            }
            $warnings[] = 'Never display/prefill password hashes; omit blank Update input; hash in the action unless a hashed cast or verified mutator already does so.';
        }
        if ($type === FieldType::Richtext) {
            $warnings[] = 'A maintained server-side HTML sanitizer and explicit allowlist are required before persistence and legacy HTML rendering; uploads are disabled.';
        }
        if ($type === FieldType::Phone) {
            $warnings[] = 'E.164 structural validation does not prove a telephone number is allocated or reachable.';
        }
        if ($cast !== null && !in_array($cast, ['string', 'integer', 'int', 'boolean', 'bool', 'array', 'json', 'object', 'collection', 'hashed', 'date', 'datetime', 'immutable_date', 'immutable_datetime', $enum?->className], true) && !str_starts_with($cast, 'decimal:')) {
            $warnings[] = 'Custom cast requires an explicit normalization adapter: ' . $cast;
        }
        $validation = $suggestion->writable ? new ValidationDefinition(array_values(array_unique($shared)), $presence, $update, $constraints) : new ValidationDefinition(['prohibited'], [], []);
        $field = new FieldDefinition($column->name, $label ?? $suggestion->label, $type, $validation->rules(false), $suggestion->writable, $endpoint, $enum);
        $confirmation = $type === FieldType::Password ? new FieldDefinition($column->name . '_confirmation', $confirmationLabel ?? 'Confirm ' . $field->label, FieldType::Password, writable: false) : null;

        return new FieldPlan($column, $field, $validation, $confirmation, $options, $cast, $warnings, $table->driver);
    }

    /**
     * @param  list<string>  $included
     * @param  list<string>  $suppliedByModel  Verified model defaults/hooks, not guessed by reflection.
     * @return list<string>
     */
    public function omittedRequired(TableDefinition $table, array $included, array $suppliedByModel = []): array
    {
        $missing = [];
        foreach ($table->columns as $column) {
            if (!in_array($column->name, [...$included, ...$suppliedByModel], true) && !$column->nullable && $column->default === null && !$column->generated && !$column->autoIncrement) {
                $missing[] = $column->name;
            }
        }

        return $missing;
    }
}
