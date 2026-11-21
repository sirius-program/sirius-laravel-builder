<?php

declare(strict_types=1);

namespace Sirius\LaravelBuilder\Schema;

use Illuminate\Database\DatabaseManager;
use Throwable;

final readonly class ConnectionCheck
{
    public function __construct(private DatabaseManager $database) {}

    public function available(): bool
    {
        try {
            $this->database->connection()->getPdo();

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
