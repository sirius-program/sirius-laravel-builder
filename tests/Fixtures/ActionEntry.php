<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

final class ActionEntry extends Model
{
    use HasUuids;
    use SoftDeletes;

    public $timestamps = false;

    protected $connection = 'testing';

    protected $primaryKey = 'entry_key';

    protected $casts = ['amount' => 'decimal:2', 'settings' => 'array', 'secret' => 'hashed'];
}
