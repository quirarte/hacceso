<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, User-Agent, X-API-Key');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    json_response(200, ['ok' => true]);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_response(405, [
        'ok' => false,
        'error' => [
            'code' => 'METHOD_NOT_ALLOWED',
            'message' => 'Metodo no permitido. Usa POST.',
        ],
    ]);
}

$body = request_json_body();
if ($body === []) {
    $body = $_POST;
}

$deviceId = trim((string)($body['device_id'] ?? $body['device'] ?? ''));
$apiKey = request_header('X-API-Key') ?? trim((string)($body['api_key'] ?? ''));

if ($deviceId === '') {
    json_response(422, [
        'ok' => false,
        'error' => [
            'code' => 'VALIDATION_ERROR',
            'message' => 'device_id/device es obligatorio.',
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

    if (!is_array($device) || (int)$device['is_enabled'] !== 1) {
        json_response(401, [
            'ok' => false,
            'error' => [
                'code' => 'UNAUTHORIZED',
                'message' => 'Dispositivo no autorizado.',
            ],
        ]);
    }

    $apiKeyHash = trim((string)($device['api_key_hash'] ?? ''));
    if ($apiKeyHash !== '' && ($apiKey === '' || !password_verify($apiKey, $apiKeyHash))) {
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

    $now = new DateTimeImmutable('now');
    $nowSql = $now->format('Y-m-d H:i:s');
    $dedupFromSql = $now->modify('-5 seconds')->format('Y-m-d H:i:s');

    $recentStmt = $pdo->prepare(
        "SELECT id, expires_at
         FROM monitor_alerts
         WHERE device_id = :device_id
           AND alert_type = 'MESSAGING'
           AND created_at >= :dedup_from
         ORDER BY created_at DESC
         LIMIT 1"
    );
    $recentStmt->execute([
        'device_id' => $deviceId,
        'dedup_from' => $dedupFromSql,
    ]);
    $recentAlert = $recentStmt->fetch();

    if (is_array($recentAlert)) {
        json_response(200, [
            'ok' => true,
            'deduplicated' => true,
            'alert_id' => (string)$recentAlert['id'],
            'alert_type' => 'MESSAGING',
            'expires_at' => (new DateTimeImmutable((string)$recentAlert['expires_at']))->format(DateTimeInterface::ATOM),
        ]);
    }

    $expiresAt = $now->modify('+30 seconds');
    $alertId = uuid_v4();
    $alertStmt = $pdo->prepare(
        'INSERT INTO monitor_alerts (id, device_id, alert_type, created_at, expires_at)
         VALUES (:id, :device_id, :alert_type, :created_at, :expires_at)'
    );
    $alertStmt->execute([
        'id' => $alertId,
        'device_id' => $deviceId,
        'alert_type' => 'MESSAGING',
        'created_at' => $nowSql,
        'expires_at' => $expiresAt->format('Y-m-d H:i:s'),
    ]);

    json_response(201, [
        'ok' => true,
        'alert_id' => $alertId,
        'alert_type' => 'MESSAGING',
        'expires_at' => $expiresAt->format(DateTimeInterface::ATOM),
    ]);
} catch (Throwable $exception) {
    json_response(500, [
        'ok' => false,
        'error' => [
            'code' => 'INTERNAL_ERROR',
            'message' => 'No se pudo registrar el aviso de Mensajeria.',
        ],
    ]);
}
