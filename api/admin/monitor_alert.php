<?php

declare(strict_types=1);

require __DIR__ . '/../../src/bootstrap.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_response(405, [
        'ok' => false,
        'error' => [
            'code' => 'METHOD_NOT_ALLOWED',
            'message' => 'Metodo no permitido. Usa POST.',
        ],
    ]);
}

try {
    $pdo = db_pdo($config);
    auth_require_roles($pdo, $config, [AUTH_ROLE_ADMIN, AUTH_ROLE_EMPLOYEE]);

    $body = request_json_body();
    $deviceId = trim((string)($body['device_id'] ?? 'recepcion-01'));
    $alertType = strtoupper(trim((string)($body['alert_type'] ?? '')));

    if ($deviceId === '' || $alertType !== 'DESCENDING') {
        json_response(422, [
            'ok' => false,
            'error' => [
                'code' => 'VALIDATION_ERROR',
                'message' => 'device_id y alert_type DESCENDING son obligatorios.',
            ],
        ]);
    }

    $deviceStmt = $pdo->prepare(
        'SELECT device_id FROM devices WHERE device_id = :device_id AND is_enabled = 1 LIMIT 1'
    );
    $deviceStmt->execute(['device_id' => $deviceId]);
    if (!is_array($deviceStmt->fetch())) {
        json_response(422, [
            'ok' => false,
            'error' => [
                'code' => 'DEVICE_UNAVAILABLE',
                'message' => 'El dispositivo no existe o esta deshabilitado.',
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
    $expiresAt = $now->modify('+30 seconds');
    $alertId = uuid_v4();
    $alertStmt = $pdo->prepare(
        'INSERT INTO monitor_alerts (id, device_id, alert_type, created_at, expires_at)
         VALUES (:id, :device_id, :alert_type, :created_at, :expires_at)'
    );
    $alertStmt->execute([
        'id' => $alertId,
        'device_id' => $deviceId,
        'alert_type' => $alertType,
        'created_at' => $now->format('Y-m-d H:i:s'),
        'expires_at' => $expiresAt->format('Y-m-d H:i:s'),
    ]);

    json_response(201, [
        'ok' => true,
        'alert_id' => $alertId,
        'alert_type' => $alertType,
        'message' => 'Vamos bajando',
        'expires_at' => $expiresAt->format(DateTimeInterface::ATOM),
    ]);
} catch (Throwable $exception) {
    json_response(500, [
        'ok' => false,
        'error' => [
            'code' => 'INTERNAL_ERROR',
            'message' => 'No se pudo registrar la orden del monitor.',
        ],
    ]);
}
