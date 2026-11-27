<?php

declare(strict_types=1);

namespace Sirius\LaravelBuilder\Commands;

use Illuminate\Console\View\Components\Factory;
use InvalidArgumentException;
use Sirius\LaravelBuilder\Definitions\FieldPlan;
use Sirius\LaravelBuilder\Definitions\FieldType;
use Sirius\LaravelBuilder\Definitions\TableDefinition;
use Sirius\LaravelBuilder\Discovery\EnumCaseDiscovery;
use Sirius\LaravelBuilder\Discovery\EnumDiscovery;
use Sirius\LaravelBuilder\Inference\EnumOptions;
use Sirius\LaravelBuilder\Inference\FieldInference;
use Sirius\LaravelBuilder\Inference\FieldPlanner;
use Sirius\LaravelBuilder\Inference\RuleParser;
use Sirius\LaravelBuilder\Support\SelectedModel;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\search;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

final readonly class WizardFields
{
    public function __construct(private Factory $messages) {}

    /** @param array<string,FieldPlan> $previous
     * @return array<string,FieldPlan>
     */
    public function collect(TableDefinition $table, SelectedModel $model, array $previous, bool $editing): array
    {
        $fields = [];
        foreach ($table->columns as $column) {
            $old = $previous[$column->name] ?? null;
            $suggestion = (new FieldInference)->suggest($column);
            if ($suggestion->types === []) {
                $this->messages->warn('Unsupported column: ' . $column->name);

                continue;
            }

            if (!confirm('Include ' . $column->name . '?', $editing ? $old !== null : $suggestion->included)) {
                continue;
            }

            $label = text('Label for ' . $column->name, default: $old?->field->label ?? $suggestion->label, required: true);
            $choices = array_column($suggestion->types, 'value');

            $enums = [];
            if ($column->type === 'enum') {
                foreach (app(EnumDiscovery::class)->discover(base_path(config('sirius-builder.paths.enums'))) as $enum) {
                    $enums[$enum->className] = $enum;
                    $choices[] = $enum->className;
                }
            }

            do {
                try {
                    $default = $old?->field->enum->className ?? $old?->field->type->value;
                    $question = 'Field type for ' . $column->name;
                    $choice = count($enums) > 5
                        ? search($question, static fn (?string $query): array => array_values(array_filter($choices, static fn (string $choice): bool => stripos($choice, $query ?? '') !== false)))
                        : select($question, $choices, default: $default);
                    $enum = $enums[$choice] ?? null;
                    $type = $enum ? FieldType::Select : FieldType::from($choice);
                    $endpoint = $type === FieldType::Select && !$enum ? text('Options endpoint (optional)', default: $old?->field->endpoint ?? '') : null;
                    $custom = $old?->confirmation?->label;
                    $confirmation = $type === FieldType::Password ? text('Password confirmation label', default: $custom !== null && $custom !== 'Confirm ' . $old->field->label ? $custom : 'Confirm ' . $label, required: true) : null;
                    $options = $enum ? (new EnumOptions)->map((new EnumCaseDiscovery)->discover($enum), $column) : [];
                    $plan = (new FieldPlanner)->plan($table, $column, $type, $label, $endpoint, $enum, $options, $model->cast($column->name), $confirmation);
                    break;
                } catch (InvalidArgumentException $exception) {
                    $this->messages->warn($exception->getMessage());
                    if (!confirm('Choose another mapping?', true)) {
                        continue 2;
                    }
                }
            } while (true);

            $baseline = $old && $old->field->type === $type && $old->field->enum == $enum ? $old->validation : $plan->validation;
            do {
                try {
                    $rules = [];
                    foreach (['shared', 'create', 'update'] as $group) {
                        $rules[] = text(ucfirst($group) . ' rules for ' . $column->name, default: implode('|', $baseline->{$group}));
                    }
                    $fields[$column->name] = $plan->withValidation((new RuleParser)->edit($plan->validation, ...$rules));

                    break;
                } catch (InvalidArgumentException $exception) {
                    $this->messages->warn($exception->getMessage());
                }
            } while (true);
        }

        return $fields;
    }
}
