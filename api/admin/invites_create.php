<?php

declare(strict_types=1);

require __DIR__ . '/../../src/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_response(405, ['error' => ['code' => 'METHOD_NOT_ALLOWED', 'message' => 'Método no permitido']]);
}

$issuerKey = request_header('X-Issuer-Key');

if ($issuerKey === null || $issuerKey === '') {
    json_response(401, ['error' => ['code' => 'UNAUTHORIZED', 'message' => 'X-Issuer-Key es requerido']]);
}

$body = request_json_body();
$visitorName = trim((string)($body['visitor_name'] ?? ''));
$validFromRaw = trim((string)($body['valid_from'] ?? ''));
$validToRaw = trim((string)($body['valid_to'] ?? ''));
$companionsExpected = (int)($body['companions_expected'] ?? 0);
$visitorPhone = normalize_optional_string($body['visitor_phone'] ?? null);
$visitorEmail = normalize_optional_string($body['visitor_email'] ?? null);

if ($visitorName === '' || $validFromRaw === '' || $validToRaw === '') {
    json_response(422, ['error' => ['code' => 'VALIDATION_ERROR', 'message' => 'visitor_name, valid_from y valid_to son obligatorios']]);
}

if ($companionsExpected < 0) {
    json_response(422, ['error' => ['code' => 'VALIDATION_ERROR', 'message' => 'companions_expected no puede ser negativo']]);
}

try {
    $validFrom = new DateTimeImmutable($validFromRaw);
    $validTo = new DateTimeImmutable($validToRaw);
} catch (Throwable $exception) {
    json_response(422, ['error' => ['code' => 'VALIDATION_ERROR', 'message' => 'valid_from y valid_to deben ser fechas válidas']]);
}

if ($validTo <= $validFrom) {
    json_response(422, ['error' => ['code' => 'VALIDATION_ERROR', 'message' => 'valid_to debe ser mayor que valid_from']]);
}

try {
    $pdo = db_pdo($config);

    $issuerStmt = $pdo->prepare(
        'SELECT employee_uid, api_key_hash, is_enabled
         FROM issuer_keys
         WHERE is_enabled = 1'
    );
    $issuerStmt->execute();
    $issuerRows = $issuerStmt->fetchAll();

    $issuerEmployeeUid = null;

    foreach ($issuerRows as $issuerRow) {
        if (password_verify($issuerKey, (string)$issuerRow['api_key_hash'])) {
            $issuerEmployeeUid = (string)$issuerRow['employee_uid'];
            break;
        }
    }

    if ($issuerEmployeeUid === null) {
        json_response(401, ['error' => ['code' => 'UNAUTHORIZED', 'message' => 'Credenciales de emisor inválidas']]);
    }

    $now = new DateTimeImmutable('now');
    $nowSql = $now->format('Y-m-d H:i:s');
    $validFromSql = $validFrom->format('Y-m-d H:i:s');
    $validToSql = $validTo->format('Y-m-d H:i:s');

    $inviteId = uuid_v4();
    $codeId = random_token(24);

    $insertStmt = $pdo->prepare(
        'INSERT INTO invites (
            id,
            code_id,
            visitor_name,
            visitor_phone,
            visitor_email,
            companions_expected,
            valid_from,
            valid_to,
            issued_by_employee_uid,
            issued_at,
            status,
            used_at,
            redisplay_until,
            created_at,
            updated_at
        ) VALUES (
            :id,
            :code_id,
            :visitor_name,
            :visitor_phone,
            :visitor_email,
            :companions_expected,
            :valid_from,
            :valid_to,
            :issued_by_employee_uid,
            :issued_at,
            "ACTIVE",
            NULL,
            NULL,
            :created_at,
            :updated_at
        )'
    );

    $insertStmt->execute([
        'id' => $inviteId,
        'code_id' => $codeId,
        'visitor_name' => $visitorName,
        'visitor_phone' => $visitorPhone,
        'visitor_email' => $visitorEmail,
        'companions_expected' => $companionsExpected,
        'valid_from' => $validFromSql,
        'valid_to' => $validToSql,
        'issued_by_employee_uid' => $issuerEmployeeUid,
        'issued_at' => $nowSql,
        'created_at' => $nowSql,
        'updated_at' => $nowSql,
    ]);

    json_response(201, [
        'id' => $inviteId,
        'code_id' => $codeId,
        'status' => 'ACTIVE',
        'visitor_name' => $visitorName,
        'companions_expected' => $companionsExpected,
        'valid_from' => $validFrom->format(DateTimeInterface::ATOM),
        'valid_to' => $validTo->format(DateTimeInterface::ATOM),
        'issued_by_employee_uid' => $issuerEmployeeUid,
        'issued_at' => $now->format(DateTimeInterface::ATOM),
    ]);
} catch (Throwable $exception) {
    json_response(500, [
        'error' => [
            'code' => 'INTERNAL_ERROR',
            'message' => 'No se pudo crear el pase',
            'details' => $exception->getMessage(),
        ],
    ]);
}
