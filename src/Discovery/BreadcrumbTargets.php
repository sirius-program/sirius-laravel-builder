<?php

declare(strict_types=1);

namespace Sirius\LaravelBuilder\Discovery;

use InvalidArgumentException;

final class BreadcrumbTargets
{
    /** @return list<string> */
    public function discover(string $root, mixed $files): array
    {
        if (!$files) {
            return [];
        }

        $root = realpath($root);
        $targets = [];
        foreach ((array) $files as $file) {
            $path = is_string($file) ? realpath($file) : false;
            if (!$root || !$path || !str_starts_with($path, $root . DIRECTORY_SEPARATOR) || !is_file($path) || strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'php' || !is_readable($path) || !is_writable($path)) {
                throw new InvalidArgumentException('Configured breadcrumb files must be existing writable PHP files inside the application.');
            }
            $targets[] = str_replace(DIRECTORY_SEPARATOR, '/', substr($path, strlen($root) + 1));
        }

        return array_values(array_unique($targets));
    }
}
