<?php

declare(strict_types=1);

namespace Sirius\LaravelBuilder\Generators;

use InvalidArgumentException;
use RuntimeException;

final class StubRenderer
{
    private const array TOKENS = [
        'action'     => ['namespace', 'class', 'parameters', 'return_type', 'body'],
        'livewire'   => ['namespace', 'class', 'members', 'view'],
        'view'       => ['content'],
        'pest'       => ['description', 'body'],
        'breadcrumb' => ['name', 'parameters', 'body'],
        'field'      => ['namespace', 'component', 'id', 'binding', 'label', 'attributes'],
    ];

    /** @return array<string,string> Package source => consumer destination. */
    public function publishPaths(): array
    {
        $directory = config('sirius-builder.paths.stubs');
        if (!is_string($directory) || !preg_match('~^[\w-]+(?:[/\\\\][\w.-]+)*$~D', $directory) || in_array('..', explode('/', str_replace('\\', '/', $directory)), true)) {
            throw new InvalidArgumentException('Configure a relative sirius-builder.paths.stubs directory without traversal.');
        }

        $path = base_path();
        foreach (explode('/', str_replace('\\', '/', $directory)) as $segment) {
            $path .= DIRECTORY_SEPARATOR . $segment;
            if (is_link($path) || (file_exists($path) && (!is_dir($path) || !str_starts_with((string) realpath($path), realpath(base_path()) . DIRECTORY_SEPARATOR)))) {
                throw new InvalidArgumentException('Unsafe application stub directory.');
            }
        }

        $paths = [];
        foreach (array_keys(self::TOKENS) as $name) {
            $target = $path . DIRECTORY_SEPARATOR . $name . '.stub';
            if (is_link($target) || (file_exists($target) && !is_file($target))) {
                throw new InvalidArgumentException('Unsafe application stub: ' . $name);
            }
            $paths[$this->shipped($name)] = $target;
        }

        return $paths;
    }

    /** @param array<string,string> $values Trusted source fragments for the named template. */
    public function render(string $name, array $values): string
    {
        $required = self::TOKENS[$name] ?? throw new InvalidArgumentException('Unknown stub: ' . $name);
        $source = $this->shipped($name);
        $override = $this->publishPaths()[$source];
        $path = is_file($override) ? $override : $source;
        $stub = file_get_contents($path);

        if ($stub === false) {
            throw new RuntimeException('Cannot read ' . $name . ' stub.');
        }

        $stub = str_replace("\r\n", "\n", $stub);
        $pattern = '/%\{([a-z_]+)\}%/';
        preg_match_all($pattern, $stub, $matches);
        $remaining = preg_replace($pattern, '', $stub);

        if (str_contains((string) $remaining, '%{') || str_contains((string) $remaining, '}%') || array_diff($required, $matches[1]) || array_diff($matches[1], $required)) {
            throw new InvalidArgumentException('Invalid placeholders in ' . $name . ' stub. Required: ' . implode(', ', $required));
        }

        if (array_diff($required, array_keys($values)) || array_diff(array_keys($values), $required)) {
            throw new InvalidArgumentException('Incorrect render context for ' . $name . ' stub.');
        }

        $result = preg_replace_callback($pattern, static fn (array $match): string => $values[$match[1]], $stub);
        if (in_array($name, ['action', 'livewire', 'pest', 'breadcrumb'], true)) {
            try {
                $tokens = token_get_all(($name === 'breadcrumb' ? '<?php ' : '') . $result, TOKEN_PARSE);
                if (($tokens[0][0] ?? null) !== T_OPEN_TAG) {
                    throw new InvalidArgumentException('PHP stub must start with a PHP opening tag: ' . $name);
                }
            } catch (\ParseError $error) {
                throw new InvalidArgumentException('Invalid PHP rendered by ' . $name . ' stub: ' . $error->getMessage(), $error->getCode(), previous: $error);
            }
        }

        return (string) $result;
    }

    private function shipped(string $name): string
    {
        return dirname(__DIR__, 2) . '/stubs/' . $name . '.stub';
    }
}
