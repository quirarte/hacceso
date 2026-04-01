<?php

declare(strict_types=1);

return [
    'app_timezone' => getenv('APP_TIMEZONE') ?: 'America/Mexico_City',
    'db_timezone_sql' => getenv('DB_TIMEZONE_SQL') ?: "SET time_zone = '-06:00'",
    'auth' => [
        'session_name' => getenv('AUTH_SESSION_NAME') ?: 'HACCESOSESSID',
        'bootstrap_admin_username' => getenv('AUTH_BOOTSTRAP_ADMIN_USERNAME') ?: '',
        'bootstrap_admin_password' => getenv('AUTH_BOOTSTRAP_ADMIN_PASSWORD') ?: '',
        'bootstrap_admin_name' => getenv('AUTH_BOOTSTRAP_ADMIN_NAME') ?: 'Administrador inicial',
    ],
    'db' => [
        'host' => getenv('DB_HOST') ?: '127.0.0.1',
        'port' => getenv('DB_PORT') ?: '3306',
        'name' => getenv('DB_NAME') ?: '',
        'user' => getenv('DB_USER') ?: '',
        'pass' => getenv('DB_PASS') ?: '',
        'charset' => 'utf8mb4',
    ],
];
