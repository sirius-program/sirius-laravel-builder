<?php

declare(strict_types=1);

namespace Sirius\LaravelBuilder\Discovery;

use Illuminate\Filesystem\Filesystem;
use ReflectionClass;
use Throwable;

final readonly class ClassDiscovery
{
    public function __construct(private Filesystem $files) {}

    /** @return array<string, ReflectionClass<object>> Relative name => reflected class */
    public function discover(string $directory, string $namespace, bool $optional = false): array
    {
        if (!$this->files->exists($directory) && $optional) {
            return [];
        }
        if (!$this->files->isDirectory($directory) || !is_readable($directory)) {
            throw new DiscoveryException('Discovery directory is missing or unreadable: ' . $directory);
        }
        $namespace = trim($namespace, '\\');
        if ($namespace === '' || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)*$/D', $namespace)) {
            throw new DiscoveryException('Provide the PSR-4 namespace of the discovery directory.');
        }
        $discovered = [];
        try {
            foreach ($this->files->allFiles($directory) as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                $root = realpath($directory);
                $source = $file->getRealPath();
                if ($root === false || $source === false || !str_starts_with($source, $root . DIRECTORY_SEPARATOR)) {
                    throw new DiscoveryException('Discovery source resolves outside its configured directory.');
                }
                $relative = str_replace('/', '\\', str_replace('\\', '/', substr($file->getRelativePathname(), 0, -4)));
                $class = $namespace . '\\' . $relative;
                if (!class_exists($class) && !interface_exists($class) && !trait_exists($class)) {
                    throw new DiscoveryException('Cannot autoload ' . $class . '. Check its declaration and Composer PSR-4 mapping.');
                }
                $reflection = new ReflectionClass($class);
                if ($reflection->getFileName() === false || realpath($reflection->getFileName()) !== $source) {
                    throw new DiscoveryException('Autoloaded class does not match the scanned source: ' . $class);
                }
                if ($reflection->getName() !== $class) {
                    throw new DiscoveryException('Class name casing or alias does not match its path: ' . $class);
                }
                $discovered[$relative] = $reflection;
            }
        } catch (Throwable $exception) {
            if ($exception instanceof DiscoveryException) {
                throw $exception;
            }
            throw new DiscoveryException('Unable to discover classes in ' . $directory . ': ' . $exception::class . '. Check readable files and valid autoloadable PHP classes.', $exception->getCode(), previous: $exception);
        }
        ksort($discovered, SORT_STRING);

        return $discovered;
    }
}
