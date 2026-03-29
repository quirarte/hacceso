<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

$error = null;
$success = null;
$generatedIssuerKey = null;
$generatedPassCodeId = null;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = trim((string)($_POST['action'] ?? ''));

    try {
        $pdo = db_pdo($config);

        if ($action === 'create_employee') {
            $uid = trim((string)($_POST['uid'] ?? ''));
            $displayName = trim((string)($_POST['display_name'] ?? ''));
            $isActive = (int)(($_POST['is_active'] ?? '1') === '1');

            if ($uid === '' || $displayName === '') {
                throw new RuntimeException('UID y nombre son obligatorios para crear usuario.');
            }

            $stmt = $pdo->prepare(
                'INSERT INTO employees (uid, display_name, is_active)
                 VALUES (:uid, :display_name, :is_active)
                 ON DUPLICATE KEY UPDATE
                    display_name = VALUES(display_name),
                    is_active = VALUES(is_active)'
            );
            $stmt->execute([
                'uid' => $uid,
                'display_name' => $displayName,
                'is_active' => $isActive,
            ]);

            $success = 'Usuario guardado correctamente.';
        } elseif ($action === 'create_issuer_key') {
            $employeeUid = trim((string)($_POST['employee_uid'] ?? ''));
            $isEnabled = (int)(($_POST['is_enabled'] ?? '1') === '1');

            if ($employeeUid === '') {
                throw new RuntimeException('employee_uid es obligatorio para generar llave.');
            }

            $employeeStmt = $pdo->prepare('SELECT uid FROM employees WHERE uid = :uid LIMIT 1');
            $employeeStmt->execute(['uid' => $employeeUid]);
            $employeeRow = $employeeStmt->fetch();

            if (!is_array($employeeRow)) {
                throw new RuntimeException('El employee_uid no existe en employees.');
            }

            $plainIssuerKey = random_token(32);
            $issuerKeyHash = password_hash($plainIssuerKey, PASSWORD_BCRYPT);

            $stmt = $pdo->prepare(
                'INSERT INTO issuer_keys (issuer_key_id, employee_uid, api_key_hash, is_enabled)
                 VALUES (:issuer_key_id, :employee_uid, :api_key_hash, :is_enabled)'
            );
            $stmt->execute([
                'issuer_key_id' => uuid_v4(),
                'employee_uid' => $employeeUid,
                'api_key_hash' => $issuerKeyHash,
                'is_enabled' => $isEnabled,
            ]);

            $generatedIssuerKey = $plainIssuerKey;
            $success = 'Llave de emisor creada. Copia la llave en claro ahora, no se volverá a mostrar.';
        } elseif ($action === 'create_invite') {
            $visitorName = trim((string)($_POST['visitor_name'] ?? ''));
            $validFromRaw = trim((string)($_POST['valid_from'] ?? ''));
            $validToRaw = trim((string)($_POST['valid_to'] ?? ''));
            $companionsExpected = (int)($_POST['companions_expected'] ?? 0);
            $visitorPhone = normalize_optional_string($_POST['visitor_phone'] ?? null);
            $visitorEmail = normalize_optional_string($_POST['visitor_email'] ?? null);
            $issuedByEmployeeUid = trim((string)($_POST['issued_by_employee_uid'] ?? ''));

            if ($visitorName === '' || $validFromRaw === '' || $validToRaw === '' || $issuedByEmployeeUid === '') {
                throw new RuntimeException('visitor_name, valid_from, valid_to y issued_by_employee_uid son obligatorios.');
            }

            if ($companionsExpected < 0) {
                throw new RuntimeException('companions_expected no puede ser negativo.');
            }

            try {
                $validFrom = new DateTimeImmutable($validFromRaw);
                $validTo = new DateTimeImmutable($validToRaw);
            } catch (Throwable $exception) {
                throw new RuntimeException('valid_from y valid_to deben tener formato de fecha válido.');
            }

            if ($validTo <= $validFrom) {
                throw new RuntimeException('valid_to debe ser mayor que valid_from.');
            }

            $employeeStmt = $pdo->prepare('SELECT uid FROM employees WHERE uid = :uid AND is_active = 1 LIMIT 1');
            $employeeStmt->execute(['uid' => $issuedByEmployeeUid]);
            $employeeRow = $employeeStmt->fetch();

            if (!is_array($employeeRow)) {
                throw new RuntimeException('El emisor no existe o está inactivo.');
            }

            $now = new DateTimeImmutable('now');
            $inviteId = uuid_v4();
            $codeId = random_token(24);

            $stmt = $pdo->prepare(
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
            $stmt->execute([
                'id' => $inviteId,
                'code_id' => $codeId,
                'visitor_name' => $visitorName,
                'visitor_phone' => $visitorPhone,
                'visitor_email' => $visitorEmail,
                'companions_expected' => $companionsExpected,
                'valid_from' => $validFrom->format('Y-m-d H:i:s'),
                'valid_to' => $validTo->format('Y-m-d H:i:s'),
                'issued_by_employee_uid' => $issuedByEmployeeUid,
                'issued_at' => $now->format('Y-m-d H:i:s'),
                'created_at' => $now->format('Y-m-d H:i:s'),
                'updated_at' => $now->format('Y-m-d H:i:s'),
            ]);

            $generatedPassCodeId = $codeId;
            $success = 'Pase de acceso creado correctamente.';
        } else {
            throw new RuntimeException('Acción no soportada.');
        }
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
    }
}

$employees = [];
$issuerKeys = [];
$invites = [];

try {
    $pdo = db_pdo($config);

    $employeesStmt = $pdo->query(
        'SELECT uid, display_name, is_active, created_at, updated_at
         FROM employees
         ORDER BY created_at DESC'
    );
    $employees = $employeesStmt->fetchAll();

    $issuerKeysStmt = $pdo->query(
        'SELECT issuer_key_id, employee_uid, is_enabled, created_at, updated_at
         FROM issuer_keys
         ORDER BY created_at DESC
         LIMIT 50'
    );
    $issuerKeys = $issuerKeysStmt->fetchAll();

    $invitesStmt = $pdo->query(
        'SELECT id, code_id, visitor_name, companions_expected, valid_from, valid_to, status, issued_by_employee_uid, issued_at
         FROM invites
         ORDER BY issued_at DESC
         LIMIT 50'
    );
    $invites = $invitesStmt->fetchAll();
} catch (Throwable $exception) {
    if ($error === null) {
        $error = 'No se pudieron cargar datos: ' . $exception->getMessage();
    }
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Hacceso Admin - Usuarios y pases</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 24px; background: #f7f7f8; color: #222; }
        .card { background: #fff; border: 1px solid #ddd; border-radius: 8px; padding: 16px; margin-bottom: 16px; }
        h1, h2 { margin-top: 0; }
        label { display: block; margin-top: 8px; font-weight: 600; }
        input, select { width: 100%; max-width: 440px; padding: 8px; margin-top: 4px; }
        button { margin-top: 12px; padding: 10px 14px; cursor: pointer; }
        table { border-collapse: collapse; width: 100%; font-size: 14px; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background: #f0f0f2; }
        .ok { background: #e9fbe9; border: 1px solid #64b464; padding: 10px; border-radius: 6px; }
        .err { background: #fdecec; border: 1px solid #d87c7c; padding: 10px; border-radius: 6px; }
        code { background: #f2f2f2; padding: 2px 4px; border-radius: 4px; }
    </style>
</head>
<body>
    <h1>Panel Admin: Usuarios, llaves de emisor y pases</h1>

    <?php if ($success !== null): ?>
        <div class="ok">
            <strong>Éxito:</strong> <?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?>
            <?php if ($generatedIssuerKey !== null): ?>
                <div style="margin-top:8px;">
                    Llave en claro (copiar y guardar):
                    <div><code><?= htmlspecialchars($generatedIssuerKey, ENT_QUOTES, 'UTF-8') ?></code></div>
                </div>
            <?php endif; ?>
            <?php if ($generatedPassCodeId !== null): ?>
                <div style="margin-top:8px;">
                    Code ID del pase (contenido para QR):
                    <div><code><?= htmlspecialchars($generatedPassCodeId, ENT_QUOTES, 'UTF-8') ?></code></div>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ($error !== null): ?>
        <div class="err"><strong>Error:</strong> <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <section class="card">
        <h2>Crear / actualizar usuario (employees)</h2>
        <form method="post">
            <input type="hidden" name="action" value="create_employee">

            <label for="uid">UID</label>
            <input id="uid" name="uid" type="text" required placeholder="emp_admin_01">

            <label for="display_name">Nombre</label>
            <input id="display_name" name="display_name" type="text" required placeholder="Admin Hacedores">

            <label for="is_active">Estado</label>
            <select id="is_active" name="is_active">
                <option value="1">Activo</option>
                <option value="0">Inactivo</option>
            </select>

            <button type="submit">Guardar usuario</button>
        </form>
    </section>

    <section class="card">
        <h2>Generar llave de emisor (X-Issuer-Key)</h2>
        <form method="post">
            <input type="hidden" name="action" value="create_issuer_key">

            <label for="employee_uid">Employee UID</label>
            <input id="employee_uid" name="employee_uid" type="text" required placeholder="emp_admin_01">

            <label for="is_enabled">Estado de llave</label>
            <select id="is_enabled" name="is_enabled">
                <option value="1">Habilitada</option>
                <option value="0">Deshabilitada</option>
            </select>

            <button type="submit">Generar llave</button>
        </form>
    </section>

    <section class="card">
        <h2>Generar pase de acceso</h2>
        <form method="post">
            <input type="hidden" name="action" value="create_invite">

            <label for="visitor_name">Nombre del visitante</label>
            <input id="visitor_name" name="visitor_name" type="text" required placeholder="Juan Pérez">

            <label for="visitor_phone">Teléfono (opcional)</label>
            <input id="visitor_phone" name="visitor_phone" type="text" placeholder="+52 55 1234 5678">

            <label for="visitor_email">Email (opcional)</label>
            <input id="visitor_email" name="visitor_email" type="email" placeholder="visitante@email.com">

            <label for="companions_expected">Acompañantes esperados</label>
            <input id="companions_expected" name="companions_expected" type="number" min="0" value="0" required>

            <label for="valid_from">Válido desde</label>
            <input id="valid_from" name="valid_from" type="datetime-local" required>

            <label for="valid_to">Válido hasta</label>
            <input id="valid_to" name="valid_to" type="datetime-local" required>

            <label for="issued_by_employee_uid">Emitido por (employee UID activo)</label>
            <input id="issued_by_employee_uid" name="issued_by_employee_uid" type="text" required placeholder="emp_admin_01">

            <button type="submit">Crear pase</button>
        </form>
    </section>

    <section class="card">
        <h2>Usuarios registrados</h2>
        <table>
            <thead>
                <tr>
                    <th>UID</th>
                    <th>Nombre</th>
                    <th>Activo</th>
                    <th>Creado</th>
                    <th>Actualizado</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($employees as $row): ?>
                    <tr>
                        <td><?= htmlspecialchars((string)$row['uid'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars((string)$row['display_name'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= (int)$row['is_active'] === 1 ? 'Sí' : 'No' ?></td>
                        <td><?= htmlspecialchars((string)$row['created_at'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars((string)$row['updated_at'], ENT_QUOTES, 'UTF-8') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </section>

    <section class="card">
        <h2>Llaves de emisor (últimas 50)</h2>
        <table>
            <thead>
                <tr>
                    <th>Issuer Key ID</th>
                    <th>Employee UID</th>
                    <th>Habilitada</th>
                    <th>Creada</th>
                    <th>Actualizada</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($issuerKeys as $row): ?>
                    <tr>
                        <td><?= htmlspecialchars((string)$row['issuer_key_id'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars((string)$row['employee_uid'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= (int)$row['is_enabled'] === 1 ? 'Sí' : 'No' ?></td>
                        <td><?= htmlspecialchars((string)$row['created_at'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars((string)$row['updated_at'], ENT_QUOTES, 'UTF-8') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </section>

    <section class="card">
        <h2>Pases recientes (últimos 50)</h2>
        <table>
            <thead>
                <tr>
                    <th>Invite ID</th>
                    <th>Code ID (QR)</th>
                    <th>Visitante</th>
                    <th>Acompañantes</th>
                    <th>Válido desde</th>
                    <th>Válido hasta</th>
                    <th>Estado</th>
                    <th>Emitido por</th>
                    <th>Emitido en</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($invites as $row): ?>
                    <tr>
                        <td><?= htmlspecialchars((string)$row['id'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><code><?= htmlspecialchars((string)$row['code_id'], ENT_QUOTES, 'UTF-8') ?></code></td>
                        <td><?= htmlspecialchars((string)$row['visitor_name'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars((string)$row['companions_expected'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars((string)$row['valid_from'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars((string)$row['valid_to'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars((string)$row['status'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars((string)$row['issued_by_employee_uid'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars((string)$row['issued_at'], ENT_QUOTES, 'UTF-8') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </section>
</body>
</html>
