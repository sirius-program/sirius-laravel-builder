<?php

declare(strict_types=1);
use Tests\Support\DatabaseConnections;

require __DIR__ . '/../vendor/autoload.php';

if (DatabaseConnections::selectedDriver() !== 'sqlite') {
    try {
        Dotenv\Dotenv::createImmutable(dirname(__DIR__), '.env.database')->safeLoad();
    } catch (Throwable) {
        fwrite(STDERR, "Cannot load the owner-managed .env.database. Check its syntax and permissions.\n");
        exit(1);
    }
}
