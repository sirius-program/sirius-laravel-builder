<?php

declare(strict_types=1);

namespace Sirius\LaravelBuilder\Discovery;

use Illuminate\Filesystem\Filesystem;
use InvalidArgumentException;
use Sirius\LaravelBuilder\Definitions\ArtifactDefinition;
use Sirius\LaravelBuilder\Definitions\ResourceIdentity;

final readonly class ResourceCollisionDetector
{
    public function __construct(private Filesystem $files) {}

    /**
     * @param  list<ResourceIdentity>  $reserved
     * @return list<string>
     */
    public function identities(ResourceIdentity $candidate, array $reserved): array
    {
        $conflicts = [];
        foreach ($reserved as $existing) {
            foreach (['livewireNamespace', 'actionNamespace', 'viewPath', 'testPath'] as $property) {
                if (strcasecmp($candidate->{$property}, $existing->{$property}) === 0) {
                    $conflicts[] = 'Resource collision: ' . $candidate->{$property};
                }
            }
            if (strcasecmp($candidate->routes->urlPrefix, $existing->routes->urlPrefix) === 0 || strcasecmp($candidate->routes->namePrefix, $existing->routes->namePrefix) === 0) {
                $conflicts[] = 'Route identity collision: ' . $candidate->routes->namePrefix;
            }
        }

        return array_values(array_unique($conflicts));
    }

    /**
     * @param  list<ArtifactDefinition>  $artifacts
     * @return list<string>
     */
    public function artifacts(string $applicationRoot, array $artifacts): array
    {
        if (!$this->files->isDirectory($applicationRoot)) {
            throw new InvalidArgumentException('Application root must be an existing directory.');
        }
        $paths = [];
        $classes = [];
        $conflicts = [];
        foreach ($artifacts as $artifact) {
            $normalized = strtolower(str_replace('\\', '/', $artifact->path));
            foreach ($paths as $planned) {
                if ($normalized === $planned || str_starts_with($normalized, $planned . '/') || str_starts_with($planned, $normalized . '/')) {
                    $conflicts[] = 'Duplicate or overlapping artifact path: ' . $artifact->path;
                }
            }
            $paths[] = $normalized;
            if ($artifact->className !== null) {
                $class = strtolower(ltrim($artifact->className, '\\'));
                if (in_array($class, $classes, true) || class_exists($artifact->className) || interface_exists($artifact->className) || trait_exists($artifact->className)) {
                    $conflicts[] = 'Class already exists or is planned twice: ' . $artifact->className;
                }
                $classes[] = $class;
            }
            $current = $applicationRoot;
            $segments = explode('/', str_replace('\\', '/', $artifact->path));
            foreach ($segments as $index => $segment) {
                if ($segment === '' || $segment === '.') {
                    throw new InvalidArgumentException('Artifact paths must use canonical nonempty segments.');
                }
                $entries = scandir($current);
                if ($entries === false) {
                    throw new InvalidArgumentException('Cannot inspect artifact parent directory.');
                }
                $matches = array_values(array_filter($entries, static fn (string $entry): bool => strcasecmp($entry, $segment) === 0));
                if ($matches === []) {
                    break;
                }
                $current .= '/' . $matches[0];
                if (is_link($current) || !$this->files->isDirectory($current) || $index === array_key_last($segments)) {
                    $conflicts[] = 'Artifact target or parent already exists or is unsafe: ' . $artifact->path;
                    break;
                }
            }
        }

        return array_values(array_unique($conflicts));
    }
}
