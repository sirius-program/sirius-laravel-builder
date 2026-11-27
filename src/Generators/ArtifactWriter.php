<?php

declare(strict_types=1);

namespace Sirius\LaravelBuilder\Generators;

use RuntimeException;
use Throwable;

class ArtifactWriter
{
    /** @param list<string> $approved */
    public function apply(ArtifactManifest $manifest, array $approved = []): void
    {
        if ($manifest->blockers || array_diff($manifest->overwrites(), $approved) || array_diff($approved, $manifest->overwrites())) {
            throw new RuntimeException('Resolve all conflicts and approve the exact overwrite targets before generation.');
        }

        $temps = $backups = $written = $directories = [];
        $lock = fopen(sys_get_temp_dir() . '/sirius-builder-' . hash('sha256', (string) realpath($manifest->root)) . '.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            throw new RuntimeException('Another generator is active or the generation lock is unavailable.');
        }

        try {
            foreach ($manifest->files as $relative => $entry) {
                $this->check($manifest, $relative, $entry['before']);
                if ($entry['before'] === $entry['after']) {
                    continue;
                }

                $target = $manifest->path($relative);
                $dir = dirname($target);
                $missing = [];
                while (!is_dir($dir)) {
                    if (file_exists($dir)) {
                        throw new RuntimeException('Target parent is not a directory: ' . $dir);
                    }
                    $missing[] = $dir;
                    $dir = dirname($dir);
                }

                foreach (array_reverse($missing) as $dir) {
                    if (!mkdir($dir)) {
                        throw new RuntimeException('Cannot create ' . $dir);
                    }
                    $directories[] = $dir;
                }

                $mode = $entry['before'] === null ? (0666 & ~umask()) : (fileperms($target) & 0777);
                $temps[$relative] = $this->stage($target, $entry['after'], $mode);
                if ($entry['before'] !== null) {
                    $backups[$relative] = $this->stage($target, $entry['before'], $mode);
                }
            }

            foreach ($manifest->files as $relative => $entry) {
                $this->check($manifest, $relative, $entry['before']);
            }

            foreach ($temps as $relative => $temp) {
                $this->check($manifest, $relative, $manifest->files[$relative]['before']);
                $this->install($temp, $manifest->path($relative));
                $written[] = $relative;
            }
        } catch (Throwable $failure) {
            $issues = [];
            foreach (array_reverse($written) as $relative) {
                try {
                    $this->check($manifest, $relative, $manifest->files[$relative]['after']);
                    $target = $manifest->path($relative);
                    if (isset($backups[$relative])) {
                        $this->install($backups[$relative], $target);
                    } elseif (!unlink($target)) {
                        throw new RuntimeException('Cannot remove ' . $relative, $failure->getCode(), $failure);
                    }
                } catch (Throwable) {
                    $issues[] = $relative . ' (backup: ' . ($backups[$relative] ?? 'new file') . ')';
                    unset($backups[$relative]);
                }
            }
            throw new RuntimeException($failure->getMessage() . ($issues !== [] ? ' Recovery required: ' . implode(', ', $issues) : ' No generated changes retained.'), $failure->getCode(), previous: $failure);
        } finally {
            try {
                foreach ([...array_values($temps), ...array_values($backups)] as $temp) {
                    if (is_file($temp)) {
                        unlink($temp);
                    }
                }

                foreach (array_reverse($directories) as $dir) {
                    if (is_dir($dir) && scandir($dir) === ['.', '..']) {
                        rmdir($dir);
                    }
                }
            } finally {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    protected function install(string $temp, string $target): void
    {
        if (!rename($temp, $target)) {
            throw new RuntimeException('Cannot replace ' . $target);
        }
    }

    private function check(ArtifactManifest $manifest, string $path, ?string $expected): void
    {
        if ($manifest->read($path) !== $expected) {
            throw new RuntimeException('File changed since review: ' . $path);
        }
    }

    private function stage(string $target, string $content, int $mode): string
    {
        $path = tempnam(dirname($target), '.sirius-');
        if ($path === false) {
            throw new RuntimeException('Cannot stage ' . $target);
        }
        try {
            if (dirname($path) !== dirname($target) || file_put_contents($path, $content) !== strlen($content) || !chmod($path, $mode)) {
                throw new RuntimeException('Cannot stage ' . $target);
            }

            return $path;
        } catch (Throwable $error) {
            unlink($path);
            throw $error;
        }
    }
}
