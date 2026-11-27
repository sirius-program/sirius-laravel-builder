<?php

declare(strict_types=1);

namespace Sirius\LaravelBuilder\Support;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Sirius\LaravelBuilder\Definitions\FieldPlan;
use Sirius\LaravelBuilder\Definitions\FieldType;
use Sirius\LaravelBuilder\Definitions\ValidationDefinition;
use Sirius\LaravelBuilder\Inference\ValueNormalizer;

final class FieldValidation
{
    /** @return list<mixed> */
    public function rules(FieldPlan $plan, string $timezone, bool $updating = false, ?Model $trustedRecord = null, ?ValidationDefinition $edited = null, ?string $connection = null): array
    {
        if (!$plan->field->writable) {
            return ['prohibited'];
        }

        $definition = $edited ?? $plan->validation;
        $rules = $definition->rules($updating);

        foreach ($definition->constraints as $constraint) {
            if ($constraint->kind === 'in') {
                $rules[] = Rule::in($constraint->values);

                continue;
            }

            $constraintConnection = $connection ?? $constraint->connection;
            $table = $constraintConnection . '.' . $constraint->table;

            if ($constraint->kind === 'exists') {
                $rules[] = Rule::exists($table, $constraint->column);

                continue;
            }

            $rule = Rule::unique($table, $constraint->column);
            if ($updating) {
                if (!$trustedRecord instanceof Model || !$trustedRecord->exists || $trustedRecord->getKey() === null || $trustedRecord->getTable() !== $constraint->table || $trustedRecord->getKeyName() !== $constraint->key || $trustedRecord->getConnection()->getName() !== $constraintConnection) {
                    throw new InvalidArgumentException('Update uniqueness requires the trusted, server-resolved existing model on the matching table and connection.');
                }
                $rule->ignore($trustedRecord);
            }

            $rules[] = $rule;
        }

        $rules[] = static function (string $attribute, mixed $value, Closure $fail) use ($plan, $timezone): void {
            try {
                (new ValueNormalizer)->normalize($plan, $value, $timezone);
            } catch (InvalidArgumentException $exception) {
                $fail->__invoke($exception->getMessage());
            }
        };

        return $rules;
    }

    /**
     * @param  list<FieldPlan>  $plans
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function input(array $plans, array $input, bool $updating): array
    {
        foreach ($plans as $plan) {
            if ($updating && $plan->field->type === FieldType::Password && in_array($input[$plan->field->column] ?? null, ['', null], true)) {
                unset($input[$plan->field->column], $input[$plan->field->column . '_confirmation']);
            }
        }

        return $input;
    }
}
