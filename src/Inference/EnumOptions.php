<?php

declare(strict_types=1);

namespace Sirius\LaravelBuilder\Inference;

use InvalidArgumentException;
use Sirius\LaravelBuilder\Definitions\ColumnDefinition;

final class EnumOptions
{
    /**
     * @param  list<array{name: string, value: string|int}>  $cases
     * @return list<array{value: string, label: string}>
     */
    public function map(array $cases, ColumnDefinition $column): array
    {
        $options = [];
        $ids = [];
        foreach ($cases as $case) {
            $id = (string) $case['value'];
            if (trim($id) === '' || preg_match('/[\x00-\x1F]/', $id) || in_array($id, $ids, true) || ($column->enumValues !== null && !in_array($id, $column->enumValues, true))) {
                throw new InvalidArgumentException('Enum option IDs must be nonempty, unique, and compatible with the database values.');
            }
            if ($column->type === 'integer' && !preg_match('/^-?(0|[1-9][0-9]*)$/D', $id)) {
                throw new InvalidArgumentException('Enum values must match the integer column.');
            }
            if ($column->length !== null && mb_strlen($id) > $column->length) {
                throw new InvalidArgumentException('Enum value exceeds the column length.');
            }
            $ids[] = $id;
            $options[] = ['value' => $id, 'label' => FieldInference::label($case['name'])];
        }
        if ($options === []) {
            throw new InvalidArgumentException('The selected enum has no selectable cases.');
        }

        return $options;
    }
}
