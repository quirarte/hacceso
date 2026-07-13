<?php

declare(strict_types=1);

require __DIR__ . '/../../src/bootstrap.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: User-Agent, X-API-Key');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    json_response(200, ['ok' => true]);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    json_response(405, [
        'ok' => false,
        'error' => [
            'code' => 'METHOD_NOT_ALLOWED',
            'message' => 'Metodo no permitido. Usa GET.',
        ],
    ]);
}

$deviceId = trim((string)($_GET['device_id'] ?? ''));
$afterId = trim((string)($_GET['after_id'] ?? ''));
$apiKey = request_header('X-API-Key');

if ($deviceId === '' || $apiKey === null || $apiKey === '') {
    json_response(401, [
        'ok' => false,
        'error' => [
            'code' => 'UNAUTHORIZED',
            'message' => 'device_id y X-API-Key son obligatorios.',
        ],
    ]);
}

try {
    $pdo = db_pdo($config);
    $deviceStmt = $pdo->prepare(
        'SELECT device_id, api_key_hash, is_enabled
         FROM devices
         WHERE device_id = :device_id
         LIMIT 1'
    );
    $deviceStmt->execute(['device_id' => $deviceId]);
    $device = $deviceStmt->fetch();

    $apiAuthorized = is_array($device)
        && (int)$device['is_enabled'] === 1
        && password_verify($apiKey, (string)$device['api_key_hash']);

    if (!$apiAuthorized) {
        json_response(401, [
            'ok' => false,
            'error' => [
                'code' => 'UNAUTHORIZED',
                'message' => 'Credenciales de dispositivo invalidas.',
            ],
        ]);
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS monitor_alerts (
            id CHAR(36) NOT NULL,
            device_id VARCHAR(64) NOT NULL,
            alert_type VARCHAR(32) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            expires_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY idx_monitor_alerts_created_at (created_at),
            KEY idx_monitor_alerts_expires_at (expires_at),
            KEY idx_monitor_alerts_device_created_at (device_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $sql =
        "SELECT id, alert_type, created_at, expires_at
         FROM monitor_alerts
         WHERE device_id = :device_id
           AND alert_type = 'DESCENDING'
           AND expires_at > NOW()";
    $params = ['device_id' => $deviceId];

    if ($afterId !== '') {
        $sql .= ' AND id <> :after_id';
        $params['after_id'] = $afterId;
    }

    $sql .= ' ORDER BY created_at DESC LIMIT 1';
    $commandStmt = $pdo->prepare($sql);
    $commandStmt->execute($params);
    $command = $commandStmt->fetch();

    if (!is_array($command)) {
        json_response(200, ['ok' => true, 'command' => null]);
    }

    json_response(200, [
        'ok' => true,
        'command' => 'GOING_DOWN',
        'alert_id' => (string)$command['id'],
        'alert_type' => (string)$command['alert_type'],
        'message' => 'Vamos bajando',
        'created_at' => (new DateTimeImmutable((string)$command['created_at']))->format(DateTimeInterface::ATOM),
        'expires_at' => (new DateTimeImmutable((string)$command['expires_at']))->format(DateTimeInterface::ATOM),
    ]);
} catch (Throwable $exception) {
    json_response(500, [
        'ok' => false,
        'error' => [
            'code' => 'INTERNAL_ERROR',
            'message' => 'No se pudieron consultar las ordenes del monitor.',
        ],
    ]);
}
