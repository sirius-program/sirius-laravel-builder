<?php

declare(strict_types=1);

namespace Tests\Fixtures\Package\Inference;

use Tests\Fixtures\Package\Definitions\ResourceDefinition;

final class ResourceLabel
{
    public function forResource(ResourceDefinition $resource): string
    {
        return $resource->name;
    }
}
