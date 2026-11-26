<?php

declare(strict_types=1);

namespace Sirius\LaravelBuilder\Discovery;

use BackedEnum;
use ReflectionEnum;
use Sirius\LaravelBuilder\Definitions\EnumDefinition;

final class EnumCaseDiscovery
{
    /** @return list<array{name: string, value: string|int}> */
    public function discover(EnumDefinition $enum): array
    {
        $cases = [];
        foreach ((new ReflectionEnum($enum->className))->getCases() as $case) {
            $value = $case->getValue();
            $cases[] = ['name' => $value->name, 'value' => $value instanceof BackedEnum ? $value->value : $value->name];
        }

        return $cases;
    }
}
