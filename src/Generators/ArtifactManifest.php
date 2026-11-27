<?php

declare(strict_types=1);

namespace Sirius\LaravelBuilder\Generators;

use Illuminate\Support\Facades\Blade;
use RuntimeException;

final class ArtifactManifest
{
    /** @var array<string,array{before:?string,after:string,managed:bool}> */
    public array $files = [];

    /** @var list<string> */
    public array $routes = [];

    /** @var list<string> */
    public array $blockers = [];

    public function __construct(public readonly string $root) {}

    public function path(string $relative): string
    {
        $path = realpath($this->root);
        if ($path === false || !str_ends_with($relative, '.php') || preg_match('~^(vendor|storage|bootstrap/cache)/~i', $relative)) {
            throw new RuntimeException('Invalid generation target: ' . $relative);
        }

        foreach (explode('/', $relative) as $part) {
            if (!preg_match('/^[\pL\pN_-][\pL\pN_. -]*$/uD', $part) || rtrim($part, '. ') !== $part || preg_match('/^(con|prn|aux|nul|com[1-9]|lpt[1-9])(?:\.|$)/i', $part)) {
                throw new RuntimeException('Unsafe generation path: ' . $relative);
            }

            $path .= DIRECTORY_SEPARATOR . $part;
            clearstatcache(true, $path);
            if (is_link($path) || (file_exists($path) && realpath($path) !== $path)) {
                throw new RuntimeException('Unsafe alias or link: ' . $relative);
            }
        }

        return $path;
    }

    public function read(string $relative): ?string
    {
        $path = $this->path($relative);
        if (!file_exists($path)) {
            return null;
        }
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException('Unreadable file: ' . $relative);
        }

        $data = file_get_contents($path);
        if ($data === false) {
            throw new RuntimeException('Could not read ' . $relative);
        }

        return $data;
    }

    public function add(string $path, string $content, ?string $before, bool $managed = false): void
    {
        if (in_array(strtolower($path), array_map(strtolower(...), array_keys($this->files)), true)) {
            throw new RuntimeException('Duplicate target: ' . $path);
        }

        try {
            $php = str_ends_with($path, '.blade.php') ? Blade::compileString($content) : $content;
            $tokens = token_get_all($php, TOKEN_PARSE);
            if (!str_ends_with($path, '.blade.php') && ($tokens[0][0] ?? null) !== T_OPEN_TAG) {
                throw new RuntimeException('PHP files must start with a PHP opening tag.');
            }
        } catch (\Throwable $error) {
            throw new RuntimeException('Invalid generated source in ' . $path . ': ' . $error->getMessage(), $error->getCode(), previous: $error);
        }

        $this->files[$path] = ['before' => $before, 'after' => $content, 'managed' => $managed];
    }

    /** @return list<string> */
    public function overwrites(): array
    {
        return array_keys(
            array_filter(
                $this->files,
                static fn (array $entry): bool => !$entry['managed'] && $entry['before'] !== null && $entry['before'] !== $entry['after']
            )
        );
    }
}
