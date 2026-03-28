<?php

declare(strict_types=1);

require __DIR__ . '/../../src/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    json_response(405, ['error' => ['code' => 'METHOD_NOT_ALLOWED', 'message' => 'Método no permitido']]);
}

try {
    $pdo = db_pdo($config);
    $pdo->query('SELECT 1');
} catch (Throwable $exception) {
    json_response(503, [
        'ok' => false,
        'error' => [
            'code' => 'SERVICE_UNAVAILABLE',
            'message' => 'Servicio no disponible',
        ],
    ]);
}

json_response(200, ['ok' => true]);
