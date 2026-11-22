<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;

final class DatabaseConnections
{
    public static function selectedDriver(): string
    {
        $driver = getenv('BUILDER_TEST_DRIVER') ?: 'sqlite';
        if (!in_array($driver, ['sqlite', 'mysql', 'mariadb', 'pgsql'], true)) {
            throw new RuntimeException('BUILDER_TEST_DRIVER must be sqlite, mysql, mariadb, or pgsql.');
        }

        return $driver;
    }

    /** @return list<string> */
    public static function schemaDrivers(): array
    {
        return getenv('BUILDER_TEST_DRIVER') === false
            ? ['testing', 'mysql', 'mariadb', 'pgsql']
            : [self::selectedDriver() === 'sqlite' ? 'testing' : self::selectedDriver()];
    }

    /** @return array<string, mixed> */
    public static function configuration(string $driver): array
    {
        $prefix = 'BUILDER_TEST_' . strtoupper($driver) . '_';
        $values = [];
        foreach (['HOST', 'PORT', 'DATABASE', 'USERNAME', 'PASSWORD'] as $key) {
            $value = $_ENV[$prefix . $key] ?? getenv($prefix . $key);
            if (!is_string($value) || ($value === '' && $key !== 'PASSWORD')) {
                throw new RuntimeException('Configure ' . $prefix . $key . ' in the owner-managed .env.database or CI environment.');
            }
            $values[$key] = $value;
        }
        if (!in_array($values['HOST'], ['127.0.0.1', 'localhost', '::1'], true) || in_array(strtolower($values['DATABASE']), ['mysql', 'information_schema', 'performance_schema', 'sys', 'postgres', 'template0', 'template1'], true)) {
            throw new RuntimeException('Engine tests require a loopback host and an explicitly configured disposable, non-system database.');
        }
        if (!ctype_digit($values['PORT']) || (int) $values['PORT'] < 1 || (int) $values['PORT'] > 65535) {
            throw new RuntimeException('Configure a valid test database port.');
        }

        return [
            'driver'    => $driver, 'host' => $values['HOST'], 'port' => $values['PORT'],
            'database'  => $values['DATABASE'], 'username' => $values['USERNAME'], 'password' => $values['PASSWORD'],
            'prefix'    => '', 'charset' => $driver === 'pgsql' ? 'utf8' : 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci', 'search_path' => 'public', 'sslmode' => 'prefer',
        ];
    }
}
