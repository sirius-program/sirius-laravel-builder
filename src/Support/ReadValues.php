<?php

declare(strict_types=1);

namespace Sirius\LaravelBuilder\Support;

use Illuminate\Database\Eloquent\Model;
use Sirius\LaravelBuilder\Definitions\FieldPlan;
use Sirius\LaravelBuilder\Definitions\FieldType;
use Sirius\LaravelBuilder\Inference\ValueNormalizer;

final class ReadValues
{
    /**
     * @param  list<FieldPlan>  $plans
     * @return array<string, mixed>
     */
    public static function from(Model $record, array $plans): array
    {
        $values = [];
        $visible = $record->getVisible();

        foreach ($plans as $plan) {
            $column = $plan->field->column;

            if ($plan->field->type === FieldType::Password || $record->hasCast($column, 'hashed') || in_array($column, $record->getHidden(), true) || ($visible !== [] && !in_array($column, $visible, true))) {
                continue;
            }

            $value = $record->getAttribute($column);
            $values[$column] = $plan->field->type === FieldType::Richtext && is_string($value)
                ? RichText::clean($value)
                : (new ValueNormalizer)->forEditing($plan, $value, config('app.timezone'));
        }

        return $values;
    }
}
