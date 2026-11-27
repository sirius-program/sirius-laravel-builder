<?php

declare(strict_types=1);

namespace Sirius\LaravelBuilder\Generators;

use InvalidArgumentException;
use Sirius\LaravelBuilder\Definitions\EnumDefinition;
use Sirius\LaravelBuilder\Definitions\FieldDefinition;
use Sirius\LaravelBuilder\Definitions\FieldPlan;
use Sirius\LaravelBuilder\Definitions\FieldType;

final readonly class FieldRenderer
{
    public function __construct(private StubRenderer $stubs = new StubRenderer) {}

    public function render(FieldPlan $plan, string $scope, string $property = 'form'): string
    {
        if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_-]*$/D', $scope) || !preg_match('/^[a-zA-Z_]\w*$/D', $property)) {
            throw new InvalidArgumentException('Use a stable HTML identifier and a Livewire property name.');
        }
        $result = $this->control($plan, $plan->field, $scope, $property);
        if ($plan->confirmation instanceof FieldDefinition) {
            $result .= "\n" . $this->control($plan, $plan->confirmation, $scope, $property);
        }

        return $result;
    }

    private function control(FieldPlan $plan, FieldDefinition $field, string $scope, string $property): string
    {
        if (!preg_match('/^[a-zA-Z_]\w*$/D', $field->column)) {
            throw new InvalidArgumentException('Column cannot be represented as a Livewire field: ' . $field->column);
        }
        $namespace = config('sirius-ui.namespace.blade', 'sirius');
        if (!is_string($namespace) || !preg_match('/^[a-z][a-z0-9-]*$/D', $namespace)) {
            throw new InvalidArgumentException('Invalid Sirius UI Blade namespace.');
        }
        $type = $field->type;
        $component = match ($type) {
            FieldType::Text,FieldType::Email,FieldType::Password,FieldType::Url,FieldType::Number => 'input',
            FieldType::Boolean                                                                    => 'switch',
            FieldType::Time,FieldType::Date,FieldType::Datetime                                   => 'datetime-picker',
            default                                                                               => $type->value,
        };
        $attributes = [];
        if ($component === 'input' || $component === 'datetime-picker') {
            if (in_array($type, [FieldType::Email, FieldType::Url], true)) {
                $attributes[] = 'type="text" inputmode="' . $type->value . '"';
            } else {
                $attributes[] = 'type="' . $type->value . '"';
            }
        }
        if ($type === FieldType::Number && in_array($plan->column->type, ['float', 'decimal'], true)) {
            $attributes[] = 'step="any"';
        }
        if ($type === FieldType::Password) {
            $attributes[] = 'autocomplete="new-password"';
        }
        if (!$plan->field->writable) {
            $attributes[] = ':disabled="true"';
        }
        if ($type === FieldType::Currency) {
            $precision = $plan->column->scale ?? ($plan->column->type === 'integer' ? 0 : 2);
            if ($precision < 0 || $precision > 20) {
                throw new InvalidArgumentException('Currency precision must be between 0 and 20.');
            }
            $attributes[] = ':precision="' . $precision . '"';
            $attributes[] = ':allow-negative="' . ($plan->column->unsigned ? 'false' : 'true') . '"';
        }
        if ($type === FieldType::Select && $field->enum instanceof EnumDefinition) {
            $records = [];
            foreach ($plan->options as $option) {
                $records[] = "['value' => " . $this->literal($option['value']) . ", 'label' => " . $this->literal($option['label']) . ']';
            }
            $attributes[] = ':options="[' . implode(', ', $records) . ']"';
        } elseif ($field->endpoint !== null) {
            $attributes[] = ':search-url="' . $this->literal($field->endpoint) . '"';
        }

        return $this->stubs->render('field', [
            'namespace' => $namespace, 'component' => $component, 'binding' => $property . '.' . $field->column,
            'id'        => $scope . '-' . $field->column, 'label' => $this->literal($field->label), 'attributes' => implode("\n    ", $attributes),
        ]);
    }

    private function literal(string $value): string
    {
        // Dynamic Blade attributes pass through both the Blade and PHP parsers.
        return preg_match('~^[\pL\pN /_.:-]*$~uD', $value) ? var_export($value, true) : "base64_decode('" . base64_encode($value) . "')";
    }
}
