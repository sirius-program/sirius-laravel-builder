<?php

declare(strict_types=1);

namespace Sirius\LaravelBuilder\Definitions;

final readonly class FieldPlan
{
    /**
     * @param  list<array{value: string, label: string}>  $options
     * @param  list<string>  $warnings
     */
    public function __construct(public ColumnDefinition $column, public FieldDefinition $field, public ValidationDefinition $validation, public ?FieldDefinition $confirmation = null, public array $options = [], public ?string $cast = null, public array $warnings = [], public string $driver = 'unknown') {}

    public function withValidation(ValidationDefinition $validation): self
    {
        $field = new FieldDefinition($this->field->column, $this->field->label, $this->field->type, $validation->rules(false), $this->field->writable, $this->field->endpoint, $this->field->enum);

        return new self($this->column, $field, $validation, $this->confirmation, $this->options, $this->cast, $this->warnings, $this->driver);
    }
}
