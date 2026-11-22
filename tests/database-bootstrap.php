<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

try {
    Dotenv\Dotenv::createImmutable(dirname(__DIR__), '.env.database')->safeLoad();
} catch (Throwable) {
    fwrite(STDERR, "Cannot load .env.database; ask the owner to check its syntax and file permissions.\n");
    exit(1);
}
define('BUILDER_DATABASE_TESTS', true);
