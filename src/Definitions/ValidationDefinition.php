<?php

declare(strict_types=1);

namespace Sirius\LaravelBuilder\Definitions;

final readonly class ValidationDefinition
{
    /**
     * @param  list<string>  $shared
     * @param  list<string>  $create
     * @param  list<string>  $update
     * @param  list<ValidationConstraint>  $constraints
     */
    public function __construct(public array $shared, public array $create, public array $update, public array $constraints = []) {}

    /** @return list<string> */
    public function rules(bool $updating): array
    {
        return array_values(array_unique([...$this->shared, ...($updating ? $this->update : $this->create)]));
    }
}
