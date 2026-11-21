<?php

declare(strict_types=1);

namespace Tests\Fixtures\Discovery\Concerns;

use Illuminate\Database\Eloquent\SoftDeletes;

trait Archived
{
    use SoftDeletes;
}
