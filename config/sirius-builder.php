<?php

declare(strict_types=1);

return [
    'paths' => [
        'models' => 'app/Models',
        'enums'  => 'app/Enums',
        'stubs'  => 'stubs/sirius-builder',
        'routes' => 'routes/web.php',
    ],
    'routes' => [
        'prefix'      => '',
        'name_prefix' => '',
        'middleware'  => ['auth'],
    ],
];
