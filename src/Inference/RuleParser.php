<?php

declare(strict_types=1);

namespace Sirius\LaravelBuilder\Inference;

use InvalidArgumentException;
use Sirius\LaravelBuilder\Definitions\ValidationDefinition;

final class RuleParser
{
    /** @return list<string> */
    public function parse(string $input): array
    {
        if (preg_match('/[\x00-\x1F]/', $input) || str_contains($input, '::')) {
            throw new InvalidArgumentException('Use rule text only; control characters and PHP expressions are not supported.');
        }
        if (trim($input) === '') {
            return [];
        }
        $rules = array_map(trim(...), explode('|', $input));
        foreach ($rules as $rule) {
            if (!preg_match('/^[a-z][a-z0-9_]*(?::[^\x00-\x1F]+)?$/iD', $rule)) {
                throw new InvalidArgumentException('Malformed pipe rules: use nonempty Laravel rule names and parameters.');
            }
            $name = strtolower(explode(':', $rule, 2)[0]);
            if (in_array($name, ['regex', 'not_regex'], true)) {
                throw new InvalidArgumentException('Regex rules require structured configuration; delimiters and pipes cannot be safely edited here.');
            }
            if (in_array($name, ['unique', 'exists', 'in', 'enum'], true)) {
                throw new InvalidArgumentException('Membership and database rules must remain structured, not pipe strings.');
            }
        }
        if (in_array('required', $rules, true) && in_array('nullable', $rules, true)) {
            throw new InvalidArgumentException('Choose required or nullable, not both.');
        }

        return array_values(array_unique($rules));
    }

    public function edit(ValidationDefinition $baseline, ?string $shared = null, ?string $create = null, ?string $update = null): ValidationDefinition
    {
        $definition = new ValidationDefinition(
            $shared === null ? $baseline->shared : $this->parse($shared),
            $create === null ? $baseline->create : $this->parse($create),
            $update === null ? $baseline->update : $this->parse($update),
            $baseline->constraints,
        );
        foreach ([false, true] as $updating) {
            $rules = $definition->rules($updating);
            if (in_array('required', $rules, true) && in_array('nullable', $rules, true)) {
                throw new InvalidArgumentException('Shared and operation rules conflict: required and nullable.');
            }
        }

        return $definition;
    }
}
