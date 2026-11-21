<?php

declare(strict_types=1);

namespace Tests\Fixtures\Discovery\Models;

use Illuminate\Database\Eloquent\Model;

final class ConstructorGuard extends Model
{
    public function __construct()
    {
        throw new \RuntimeException('Discovery must not instantiate or boot models.');
    }
}
