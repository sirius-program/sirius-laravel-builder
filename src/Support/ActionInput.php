<?php

declare(strict_types=1);

namespace Sirius\LaravelBuilder\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use LogicException;
use Sirius\LaravelBuilder\Definitions\FieldPlan;
use Sirius\LaravelBuilder\Definitions\FieldType;
use Sirius\LaravelBuilder\Inference\ValueNormalizer;

final class ActionInput
{
    /**
     * @param  list<FieldPlan>  $plans
     * @param  array<string,mixed>  $input
     */
    public static function assign(Model $record, array $plans, array $input): void
    {
        $validation = new FieldValidation;
        $timezone = config('app.timezone');
        $input = $validation->input($plans, $input, $record->exists);
        $rules = [];

        foreach ($plans as $plan) {
            $column = $plan->field->column;
            if ($plan->field->type === FieldType::Password) {
                $record->makeHidden($column);
                if ($record->exists && !array_key_exists($column, $input)) {
                    continue;
                }
            }

            $rules[$column] = $validation->rules($plan, $timezone, $record->exists, $record, connection: $record->getConnection()->getName());
            if ($plan->field->type === FieldType::Password && isset($input[$column])) {
                $rules[$column][] = 'confirmed';
            }
        }

        $validated = Validator::make($input, $rules)->validate();
        foreach ($plans as $plan) {
            $column = $plan->field->column;
            if (!$plan->field->writable || !array_key_exists($column, $validated)) {
                continue;
            }

            $value = (new ValueNormalizer)->normalize($plan, $validated[$column], $timezone);
            if ($plan->field->type === FieldType::Password && $value !== null) {
                $handlesHash = $record->hasCast($column, 'hashed') || $record->hasSetMutator($column) || $record->hasAttributeSetMutator($column);
                $record->setAttribute($column, $handlesHash ? $value : Hash::make($value));
                if (!Hash::check($value, $record->getAttributes()[$column] ?? null)) {
                    throw new LogicException('The password mutator must store a compatible hash.');
                }

                continue;
            }

            if ($value !== null && $plan->usesJson() && in_array($plan->cast, ['array', 'json', 'collection'], true)) {
                // Preserve JSON objects (including {}) when Eloquent encodes the attribute.
                $value = json_decode($validated[$column], flags: JSON_THROW_ON_ERROR);
            }

            if ($plan->field->type === FieldType::Richtext && is_string($value)) {
                $value = RichText::clean($value);
            }

            $record->setAttribute($column, $value);
        }
    }
}
