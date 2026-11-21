<?php

declare(strict_types=1);

namespace Sirius\LaravelBuilder\Definitions;

enum FieldType: string
{
    case Text = 'text';
    case Email = 'email';
    case Password = 'password';
    case Url = 'url';
    case Phone = 'phone';
    case Select = 'select';
    case Number = 'number';
    case Boolean = 'boolean';
    case Currency = 'currency';
    case Textarea = 'textarea';
    case Richtext = 'richtext';
    case Datetime = 'datetime';
    case Date = 'date';
    case Time = 'time';
}
