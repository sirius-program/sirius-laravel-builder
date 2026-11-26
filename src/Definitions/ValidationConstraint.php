<?php

declare(strict_types=1);

namespace Sirius\LaravelBuilder\Definitions;

use InvalidArgumentException;

final readonly class ValidationConstraint
{
    /** @param list<string> $values */
    public function __construct(public string $kind, public array $values = [], public ?string $connection = null, public ?string $table = null, public ?string $column = null, public ?string $key = null)
    {
        if (!in_array($kind, ['in', 'unique', 'exists'], true)) {
            throw new InvalidArgumentException('Unsupported structured constraint.');
        }
        if ($kind !== 'in' && ($connection === null || $table === null || $column === null)) {
            throw new InvalidArgumentException('Database constraints require explicit connection, table, and column.');
        }
    }
}
