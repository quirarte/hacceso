<?php

declare(strict_types=1);

require __DIR__ . '/../../../src/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_response(405, ['error' => ['code' => 'METHOD_NOT_ALLOWED', 'message' => 'Método no permitido']]);
}

$startedAt = microtime(true);
$body = request_json_body();
$deviceId = trim((string)($body['device_id'] ?? ''));
$codeId = trim((string)($body['code_id'] ?? ''));
$apiKey = request_header('X-API-Key');

if ($apiKey === null || $apiKey === '') {
    json_response(401, ['error' => ['code' => 'UNAUTHORIZED', 'message' => 'X-API-Key es requerido']]);
}

if ($deviceId === '' || $codeId === '') {
    json_response(422, ['error' => ['code' => 'VALIDATION_ERROR', 'message' => 'device_id y code_id son obligatorios']]);
}

try {
    $pdo = db_pdo($config);

    $deviceStmt = $pdo->prepare(
        'SELECT device_id, api_key_hash, is_enabled FROM devices WHERE device_id = :device_id LIMIT 1'
    );
    $deviceStmt->execute(['device_id' => $deviceId]);
    $device = $deviceStmt->fetch();

    $apiAuthorized = is_array($device)
        && (int)$device['is_enabled'] === 1
        && password_verify($apiKey, (string)$device['api_key_hash']);

    if (!$apiAuthorized) {
        json_response(401, ['error' => ['code' => 'UNAUTHORIZED', 'message' => 'Credenciales de dispositivo inválidas']]);
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

        if ($status === 'REVOKED') {
            $result = 'REVOKED';
        } elseif ($now < $validFrom || $now > $validTo) {
            $result = 'EXPIRED';
        } elseif ($status === 'ACTIVE') {
            $result = 'OK_FIRST';
            $visitorName = (string)$invite['visitor_name'];
            $companionsExpected = (int)$invite['companions_expected'];

            $updateStmt = $pdo->prepare(
                'UPDATE invites
                 SET status = \"USED\", used_at = :used_at, redisplay_until = DATE_ADD(:used_at, INTERVAL 5 MINUTE)
                 WHERE id = :id AND status = \"ACTIVE\"'
            );
            $updateStmt->execute([
                'used_at' => $nowSql,
                'id' => $invite['id'],
            ]);
        } elseif ($status === 'USED' && $redisplayUntil !== null && $now <= $redisplayUntil) {
            $result = 'OK_REDISPLAY';
            $visitorName = (string)$invite['visitor_name'];
            $companionsExpected = (int)$invite['companions_expected'];
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
        'error_detail' => null,
    ]);

    $pdo->commit();

    $response = ['result' => $result];

    if ($result === 'OK_FIRST' || $result === 'OK_REDISPLAY') {
        $response['visitor_name'] = $visitorName;
        $response['companions_expected'] = $companionsExpected;
    }

    json_response(200, $response);
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    json_response(500, [
        'error' => [
            'code' => 'INTERNAL_ERROR',
            'message' => 'No se pudo procesar la validación',
            'details' => $exception->getMessage(),
        ],
    ]);
}
