<?php

declare(strict_types=1);

namespace Tests\Fixtures\Discovery\Models;

use Illuminate\Database\Eloquent\Model;
use Tests\Fixtures\Discovery\Concerns\Archived;

abstract class AbstractRecord extends Model
{
    use Archived;
}
