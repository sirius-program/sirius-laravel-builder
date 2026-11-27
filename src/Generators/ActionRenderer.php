<?php

declare(strict_types=1);

namespace Sirius\LaravelBuilder\Generators;

use Sirius\LaravelBuilder\Definitions\FieldPlan;
use Sirius\LaravelBuilder\Definitions\Operation;
use Sirius\LaravelBuilder\Definitions\ResourceDefinition;
use UnitEnum;

final readonly class ActionRenderer
{
    public function __construct(private StubRenderer $stubs) {}

    public function render(ResourceDefinition $resource, Operation $operation, string $class): string
    {
        $model = '\\' . $resource->model->className;
        $creating = $operation === Operation::Create;
        $writing = $creating || $operation === Operation::Update;
        $parameters = $creating ? 'array $input' : 'int|string $key' . ($writing ? ', array $input' : '');
        $capture = $creating ? '$input' : '$key' . ($writing ? ', $input' : '');
        $result = $writing ? $model : 'void';
        $body = '$model = new ' . $model . ';' . "\n        ";
        $body .= ($writing ? 'return ' : '') . '$model->getConnection()->transaction(static function () use ($model, ' . $capture . '): ' . $result . " {\n";
        $trashed = in_array($operation, [Operation::Restore, Operation::PermanentDelete], true);
        $lines = [$creating ? '$record = $model;' : '$record = $model->newQuery()->' . ($trashed ? 'onlyTrashed()->' : '') . 'lockForUpdate()->findOrFail($key);'];
        $ability = $operation === Operation::PermanentDelete ? 'forceDelete' : $operation->value;

        if ($resource->policy->enforce) {
            $lines[] = '\Illuminate\Support\Facades\Gate::authorize(' . var_export($ability, true) . ', ' . ($creating ? $model . '::class' : '$record') . ');';
        }

        if ($writing) {
            $plans = array_map(fn (FieldPlan $plan): string => $this->expression(new FieldPlan($plan->column, $plan->field, $plan->validation, cast: $plan->cast, driver: $plan->driver)), $resource->fieldPlans);
            $lines[] = '\Sirius\LaravelBuilder\Support\ActionInput::assign($record, [' . ($plans === [] ? '' : "\n                " . implode(",\n                ", $plans) . ",\n            ") . '], $input);';
        }

        $method = $writing ? 'save' : $ability;
        $lines[] = 'if (!$record->' . $method . '()) {';
        $lines[] = "    throw new \\RuntimeException('The model cancelled the operation.');";
        $lines[] = '}';

        if ($writing) {
            $lines[] = 'return $record;';
        }

        $body .= '            ' . implode("\n            ", $lines) . "\n        });";

        return $this->stubs->render('action', [
            'namespace'   => $resource->identity->actionNamespace,
            'class'       => $class,
            'parameters'  => $parameters,
            'return_type' => $result,
            'body'        => $body,
        ]);
    }

    private function expression(mixed $value, int $indent = 16): string
    {
        if ($value instanceof UnitEnum) {
            return '\\' . $value::class . '::' . $value->name;
        }

        if (is_array($value)) {
            $items = [];
            foreach ($value as $key => $item) {
                $items[] = (array_is_list($value) ? '' : var_export($key, true) . ' => ') . $this->expression($item, $indent);
            }

            return '[' . implode(', ', $items) . ']';
        }

        if (!is_object($value)) {
            return var_export($value, true);
        }

        $padding = str_repeat(' ', $indent);
        $arguments = [];
        foreach ((new \ReflectionMethod($value, '__construct'))->getParameters() as $parameter) {
            $name = $parameter->getName();
            $property = $value->{$name};
            if ($parameter->isDefaultValueAvailable() && $property === $parameter->getDefaultValue()) {
                continue;
            }
            $arguments[] = $padding . '    ' . $name . ': ' . $this->expression($property, $indent + 4);
        }

        return 'new \\' . $value::class . "(\n" . implode(",\n", $arguments) . ",\n" . $padding . ')';
    }
}
