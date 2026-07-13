<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, User-Agent, X-API-Key');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    json_response(200, ['ok' => true]);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_response(405, [
        'ok' => false,
        'error' => [
            'code' => 'METHOD_NOT_ALLOWED',
            'message' => 'Método no permitido. Usa POST.',
        ],
    ]);
}

$startedAt = microtime(true);
$body = request_json_body();
if ($body === []) {
    $body = $_POST;
}

$deviceId = trim((string)($body['device_id'] ?? $body['device'] ?? 'esp32-devkitc-v4'));
$codeId = trim((string)($body['code_id'] ?? $body['qr'] ?? ''));
$apiKey = request_header('X-API-Key') ?? trim((string)($body['api_key'] ?? ''));
$userAgent = request_header('User-Agent') ?? '';
$ipAddress = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');

if ($codeId === '' || strlen($codeId) < 3 || strlen($codeId) > 300) {
    json_response(400, [
        'ok' => false,
        'error' => [
            'code' => 'INVALID_QR',
            'message' => 'QR inválido.',
        ],
    ]);
}

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
    $requiresApiKey = $apiKeyHash !== '';

    if ($requiresApiKey && $apiKey === '') {
        json_response(401, [
            'ok' => false,
            'error' => [
                'code' => 'UNAUTHORIZED',
                'message' => 'X-API-Key es requerido para este dispositivo.',
            ],
        ]);
    }

    if ($requiresApiKey && !password_verify($apiKey, $apiKeyHash)) {
        json_response(401, [
            'ok' => false,
            'error' => [
                'code' => 'UNAUTHORIZED',
                'message' => 'Credenciales de dispositivo inválidas.',
            ],
        ]);
    }

    $pdo->beginTransaction();

    $inviteStmt = $pdo->prepare(
        'SELECT id, status, visitor_name, companions_expected, valid_from, valid_to, redisplay_until
         FROM invites
         WHERE code_id = :code_id
         LIMIT 1
         FOR UPDATE'
    );
    $inviteStmt->execute(['code_id' => $codeId]);
    $invite = $inviteStmt->fetch();

    $now = new DateTimeImmutable('now');
    $nowSql = $now->format('Y-m-d H:i:s');

    // Some QR readers submit the same frame more than once. Keep that
    // transport retry from becoming a second monitor event.
    $dedupFromSql = $now->modify('-5 seconds')->format('Y-m-d H:i:s');
    $recentEventStmt = $pdo->prepare(
        'SELECT result, visitor_name_snapshot
         FROM scan_events
         WHERE code_id = :code_id
           AND device_id = :device_id
           AND scanned_at >= :dedup_from
           AND scanned_at <= :scanned_at
         ORDER BY scanned_at DESC, created_at DESC
         LIMIT 1'
    );
    $recentEventStmt->execute([
        'code_id' => $codeId,
        'device_id' => $deviceId,
        'dedup_from' => $dedupFromSql,
        'scanned_at' => $nowSql,
    ]);
    $recentEvent = $recentEventStmt->fetch();

    if (is_array($recentEvent)) {
        $pdo->commit();

        $deduplicatedResponse = [
            'ok' => true,
            'message' => 'QR duplicado ignorado',
            'result' => (string)$recentEvent['result'],
            'qr_length' => strlen($codeId),
            'device' => $deviceId,
            'scanned_at' => $now->format(DateTimeInterface::ATOM),
            'deduplicated' => true,
        ];

        if ($recentEvent['visitor_name_snapshot'] !== null) {
            $deduplicatedResponse['visitor_name'] = (string)$recentEvent['visitor_name_snapshot'];
        }

        json_response(200, $deduplicatedResponse);
    }

    $result = 'INEXISTENT';
    $visitorName = null;
    $companionsExpected = null;

    if (is_array($invite)) {
        $status = (string)$invite['status'];
        $validFrom = new DateTimeImmutable((string)$invite['valid_from']);
        $validTo = new DateTimeImmutable((string)$invite['valid_to']);
        $redisplayUntil = isset($invite['redisplay_until']) && $invite['redisplay_until'] !== null
            ? new DateTimeImmutable((string)$invite['redisplay_until'])
            : null;
        $visitorName = (string)$invite['visitor_name'];
        $companionsExpected = (int)$invite['companions_expected'];

        if ($status === 'REVOKED') {
            $result = 'REVOKED';
        } elseif ($now < $validFrom || $now > $validTo) {
            $result = 'EXPIRED';
        } elseif ($status === 'ACTIVE') {
            $result = 'OK_FIRST';

            $updateStmt = $pdo->prepare(
                "UPDATE invites
                 SET status = 'USED', used_at = :used_at, redisplay_until = DATE_ADD(:used_at, INTERVAL 5 MINUTE)
                 WHERE id = :id AND status = 'ACTIVE'"
            );
            $updateStmt->execute([
                'used_at' => $nowSql,
                'id' => $invite['id'],
            ]);
        } elseif ($status === 'USED' && $redisplayUntil !== null && $now <= $redisplayUntil) {
            $result = 'OK_REDISPLAY';
        } else {
            $result = 'USED';
        }
    }

    $latencyMs = (int)round((microtime(true) - $startedAt) * 1000);

    $eventStmt = $pdo->prepare(
        'INSERT INTO scan_events (id, code_id, device_id, scanned_at, result, visitor_name_snapshot, latency_ms, error_detail)
         VALUES (:id, :code_id, :device_id, :scanned_at, :result, :visitor_name_snapshot, :latency_ms, :error_detail)'
    );
    $eventStmt->execute([
        'id' => uuid_v4(),
        'code_id' => $codeId,
        'device_id' => $deviceId,
        'scanned_at' => $nowSql,
        'result' => $result,
        'visitor_name_snapshot' => $visitorName,
        'latency_ms' => $latencyMs,
        'error_detail' => sprintf('ip=%s ua=%s', $ipAddress, $userAgent),
    ]);

    $pdo->commit();

    $response = [
        'ok' => true,
        'message' => 'QR procesado',
        'result' => $result,
        'qr_length' => strlen($codeId),
        'device' => $deviceId,
        'scanned_at' => $now->format(DateTimeInterface::ATOM),
    ];

    if ($visitorName !== null) {
        $response['visitor_name'] = $visitorName;
        $response['companions_expected'] = $companionsExpected;
    }

    json_response(200, $response);
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    json_response(500, [
        'ok' => false,
        'error' => [
            'code' => 'INTERNAL_ERROR',
            'message' => 'No se pudo procesar el QR.',
        ],
    ]);
}
