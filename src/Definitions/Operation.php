<?php

declare(strict_types=1);

namespace Sirius\LaravelBuilder\Definitions;

enum Operation: string
{
    case Create = 'create';
    case Update = 'update';
    case Delete = 'delete';
    case Restore = 'restore';
    case PermanentDelete = 'force-delete';
}
