<?php

declare(strict_types=1);

namespace Sirius\LaravelBuilder\Definitions;

use InvalidArgumentException;

final readonly class ArtifactDefinition
{
    public function __construct(public ArtifactKind $kind, public string $path, public ?string $className = null)
    {
        if ($path === '' || str_contains($path, "\0") || preg_match('~(^[/\\\\]|:|(^|[/\\\\])\.\.([/\\\\]|$))~', $path)) {
            throw new InvalidArgumentException('Artifact paths must be relative and must not traverse outside the application.');
        }
        foreach (explode('/', str_replace('\\', '/', $path)) as $segment) {
            if ($segment === '' || $segment === '.') {
                throw new InvalidArgumentException('Artifact paths must use canonical nonempty segments.');
            }
        }
    }
}
