<?php

declare(strict_types=1);

require __DIR__ . '/../../../src/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    json_response(405, ['error' => ['code' => 'METHOD_NOT_ALLOWED', 'message' => 'Método no permitido']]);
}

json_response(200, ['ok' => true]);
