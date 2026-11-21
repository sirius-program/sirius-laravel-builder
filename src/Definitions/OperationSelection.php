<?php

declare(strict_types=1);

namespace Sirius\LaravelBuilder\Definitions;

use InvalidArgumentException;

final readonly class OperationSelection
{
    /** @var list<Operation> */
    public array $selected;

    /** @param list<Operation>|null $selected */
    public function __construct(bool $softDeletes, ?array $selected = null)
    {
        $available = self::available($softDeletes);
        $selected ??= $available;
        foreach ($selected as $operation) {
            if (!in_array($operation, $available, true)) {
                throw new InvalidArgumentException('Restore and permanent delete require SoftDeletes.');
            }
        }
        $this->selected = array_values(array_filter($available, static fn (Operation $operation): bool => in_array($operation, $selected, true)));
    }

    /** @return list<Operation> */
    public static function available(bool $softDeletes): array
    {
        return $softDeletes ? Operation::cases() : [Operation::Create, Operation::Update, Operation::Delete];
    }

    public function includes(Operation $operation): bool
    {
        return in_array($operation, $this->selected, true);
    }
}
