<?php

declare(strict_types=1);

namespace Sirius\LaravelBuilder\Discovery;

use ReflectionEnum;
use Sirius\LaravelBuilder\Definitions\EnumDefinition;

final readonly class EnumDiscovery
{
    public function __construct(private ClassDiscovery $classes) {}

    /** @return list<EnumDefinition> */
    public function discover(string $directory, string $namespace = 'App\\Enums'): array
    {
        $enums = [];
        foreach ($this->classes->discover($directory, $namespace, optional: true) as $relative => $reflection) {
            if (!$reflection->isEnum()) {
                continue;
            }
            $enum = new ReflectionEnum($reflection->getName());
            $enums[] = new EnumDefinition($enum->getName(), $relative, $enum->getBackingType()?->getName());
        }

        return $enums;
    }
}
