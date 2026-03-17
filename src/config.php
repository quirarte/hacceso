<?php

declare(strict_types=1);

return [
    'app_timezone' => getenv('APP_TIMEZONE') ?: 'America/Mexico_City',
    'db_timezone_sql' => getenv('DB_TIMEZONE_SQL') ?: "SET time_zone = '-06:00'",
    'db' => [
        'host' => getenv('DB_HOST') ?: '127.0.0.1',
        'port' => getenv('DB_PORT') ?: '3306',
        'name' => getenv('DB_NAME') ?: '',
        'user' => getenv('DB_USER') ?: '',
        'pass' => getenv('DB_PASS') ?: '',
        'charset' => 'utf8mb4',
    ],
];
