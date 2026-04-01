<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

$pdo = db_pdo($config);
$currentUser = auth_require_roles($pdo, $config, [AUTH_ROLE_ADMIN]);
$currentAdminLabel = (string)$currentUser['resolved_display_name'];

$error = null;
$success = null;
$generatedIssuerKey = null;
$generatedPassCodeId = null;

$selectedInviteId = trim((string)($_GET['invite_id'] ?? ''));
$inviteStatusFilter = strtoupper(trim((string)($_GET['invite_status'] ?? 'ALL')));
$validInviteFilters = ['ALL', 'ACTIVE', 'USED', 'REVOKED', 'EXPIRED'];

if (!in_array($inviteStatusFilter, $validInviteFilters, true)) {
    $inviteStatusFilter = 'ALL';
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = trim((string)($_POST['action'] ?? ''));

    try {
        auth_require_csrf_token($config);

        if ($action === 'create_employee') {
            $uid = trim((string)($_POST['uid'] ?? ''));
            $displayName = trim((string)($_POST['display_name'] ?? ''));
            $isActive = (int)(($_POST['is_active'] ?? '1') === '1');

            if ($uid === '' || $displayName === '') {
                throw new RuntimeException('UID y nombre son obligatorios para crear el empleado.');
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

            $success = 'Empleado guardado correctamente.';
        } elseif ($action === 'save_app_user') {
            $username = trim((string)($_POST['username'] ?? ''));
            $displayName = normalize_optional_string($_POST['app_display_name'] ?? null);
            $role = strtoupper(trim((string)($_POST['role'] ?? AUTH_ROLE_EMPLOYEE)));
            $employeeUid = normalize_optional_string($_POST['app_user_employee_uid'] ?? null);
            $password = (string)($_POST['password'] ?? '');
            $isActive = (int)(($_POST['app_user_is_active'] ?? '1') === '1');

            if ($username === '') {
                throw new RuntimeException('username es obligatorio.');
            }

            if (!auth_is_valid_role($role)) {
                throw new RuntimeException('role no es valido.');
            }

            $existingUser = auth_fetch_user_record_by_username($pdo, $username);

            if ($role === AUTH_ROLE_EMPLOYEE) {
                if ($employeeUid === null) {
                    throw new RuntimeException('Los usuarios EMPLOYEE deben apuntar a un employee_uid activo.');
                }

                $employeeStmt = $pdo->prepare(
                    'SELECT uid, display_name
                     FROM employees
                     WHERE uid = :uid AND is_active = 1
                     LIMIT 1'
                );
                $employeeStmt->execute(['uid' => $employeeUid]);
                $employeeRow = $employeeStmt->fetch();

                if (!is_array($employeeRow)) {
                    throw new RuntimeException('El employee_uid para el usuario web no existe o esta inactivo.');
                }

                $userForEmployee = auth_fetch_user_record_by_employee_uid($pdo, $employeeUid);
                if (is_array($userForEmployee) && (!is_array($existingUser) || (string)$userForEmployee['id'] !== (string)$existingUser['id'])) {
                    throw new RuntimeException('Ese employee_uid ya esta ligado a otro usuario web.');
                }

                if ($displayName === null) {
                    $displayName = (string)$employeeRow['display_name'];
                }
            } else {
                $employeeUid = null;
                if ($displayName === null) {
                    $displayName = $username;
                }
            }

            if ($existingUser === null && trim($password) === '') {
                throw new RuntimeException('La password es obligatoria al crear un usuario web nuevo.');
            }

            $nowSql = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');

            if ($existingUser === null) {
                $insertStmt = $pdo->prepare(
                    'INSERT INTO app_users (
                        id,
                        username,
                        display_name,
                        password_hash,
                        role,
                        employee_uid,
                        is_active,
                        last_login_at,
                        created_at,
                        updated_at
                    ) VALUES (
                        :id,
                        :username,
                        :display_name,
                        :password_hash,
                        :role,
                        :employee_uid,
                        :is_active,
                        NULL,
                        :created_at,
                        :updated_at
                    )'
                );
                $insertStmt->execute([
                    'id' => uuid_v4(),
                    'username' => $username,
                    'display_name' => $displayName,
                    'password_hash' => password_hash($password, PASSWORD_BCRYPT),
                    'role' => $role,
                    'employee_uid' => $employeeUid,
                    'is_active' => $isActive,
                    'created_at' => $nowSql,
                    'updated_at' => $nowSql,
                ]);

                $success = 'Usuario web creado correctamente.';
            } else {
                $params = [
                    'id' => (string)$existingUser['id'],
                    'display_name' => $displayName,
                    'role' => $role,
                    'employee_uid' => $employeeUid,
                    'is_active' => $isActive,
                    'updated_at' => $nowSql,
                ];

                $passwordSql = '';
                if (trim($password) !== '') {
                    $passwordSql = ', password_hash = :password_hash';
                    $params['password_hash'] = password_hash($password, PASSWORD_BCRYPT);
                }

                $updateStmt = $pdo->prepare(
                    'UPDATE app_users
                     SET display_name = :display_name,
                         role = :role,
                         employee_uid = :employee_uid,
                         is_active = :is_active,
                         updated_at = :updated_at' . $passwordSql . '
                     WHERE id = :id'
                );
                $updateStmt->execute($params);

                $success = trim($password) !== ''
                    ? 'Usuario web actualizado y password renovada.'
                    : 'Usuario web actualizado.';
            }
        } elseif ($action === 'create_issuer_key') {
            $employeeUid = trim((string)($_POST['employee_uid'] ?? ''));
            $isEnabled = (int)(($_POST['is_enabled'] ?? '1') === '1');

            if ($employeeUid === '') {
                throw new RuntimeException('employee_uid es obligatorio para generar la llave.');
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
            $success = 'Llave de emisor creada. Copiala ahora; no se volvera a mostrar.';
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
                throw new RuntimeException('valid_from y valid_to deben tener formato de fecha valido.');
            }

            if ($validTo <= $validFrom) {
                throw new RuntimeException('valid_to debe ser mayor que valid_from.');
            }

            $employeeStmt = $pdo->prepare('SELECT uid FROM employees WHERE uid = :uid AND is_active = 1 LIMIT 1');
            $employeeStmt->execute(['uid' => $issuedByEmployeeUid]);
            $employeeRow = $employeeStmt->fetch();

            if (!is_array($employeeRow)) {
                throw new RuntimeException('El emisor no existe o esta inactivo.');
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
            $selectedInviteId = $inviteId;
            $success = 'Pase de acceso creado correctamente.';
        } elseif ($action === 'revoke_invite') {
            $inviteIdToRevoke = trim((string)($_POST['invite_id'] ?? ''));

            if ($inviteIdToRevoke === '') {
                throw new RuntimeException('invite_id es obligatorio para revocar.');
            }

            $selectedInviteId = $inviteIdToRevoke;

            $pdo->beginTransaction();

            $inviteStmt = $pdo->prepare(
                'SELECT id, status
                 FROM invites
                 WHERE id = :id
                 LIMIT 1
                 FOR UPDATE'
            );
            $inviteStmt->execute(['id' => $inviteIdToRevoke]);
            $inviteRow = $inviteStmt->fetch();

            if (!is_array($inviteRow)) {
                $pdo->rollBack();
                throw new RuntimeException('No existe el pase a revocar.');
            }

            if ((string)$inviteRow['status'] !== 'REVOKED') {
                $updateStmt = $pdo->prepare(
                    'UPDATE invites
                     SET status = "REVOKED", updated_at = :updated_at
                     WHERE id = :id'
                );
                $updateStmt->execute([
                    'updated_at' => (new DateTimeImmutable('now'))->format('Y-m-d H:i:s'),
                    'id' => $inviteIdToRevoke,
                ]);
                $success = 'Pase revocado correctamente.';
            } else {
                $success = 'El pase ya estaba revocado.';
            }

            $pdo->commit();
        } else {
            throw new RuntimeException('Accion no soportada.');
        }
    } catch (Throwable $exception) {
        if ($pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $exception->getMessage();
    }
}

$activeEmployees = [];
$employees = [];
$appUsers = [];
$issuerKeys = [];
$invites = [];
$selectedInvite = null;
$selectedInviteEvents = [];

try {
    $activeEmployeesStmt = $pdo->query(
        'SELECT uid, display_name
         FROM employees
         WHERE is_active = 1
         ORDER BY display_name ASC'
    );
    $activeEmployees = $activeEmployeesStmt->fetchAll();

    $employeesStmt = $pdo->query(
        'SELECT uid, display_name, is_active, created_at, updated_at
         FROM employees
         ORDER BY created_at DESC'
    );
    $employees = $employeesStmt->fetchAll();

    $appUsers = auth_fetch_app_users($pdo);

    $issuerKeysStmt = $pdo->query(
        'SELECT issuer_key_id, employee_uid, is_enabled, created_at, updated_at
         FROM issuer_keys
         ORDER BY created_at DESC
         LIMIT 50'
    );
    $issuerKeys = $issuerKeysStmt->fetchAll();

    $inviteConditions = [];
    $inviteParams = [];

    if ($inviteStatusFilter === 'ACTIVE') {
        $inviteConditions[] = 'status = "ACTIVE" AND valid_to >= :now_filter';
        $inviteParams['now_filter'] = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
    } elseif ($inviteStatusFilter === 'USED') {
        $inviteConditions[] = 'status = "USED"';
    } elseif ($inviteStatusFilter === 'REVOKED') {
        $inviteConditions[] = 'status = "REVOKED"';
    } elseif ($inviteStatusFilter === 'EXPIRED') {
        $inviteConditions[] = 'status = "ACTIVE" AND valid_to < :now_filter';
        $inviteParams['now_filter'] = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
    }

    $invitesSql =
        'SELECT id, code_id, visitor_name, companions_expected, valid_from, valid_to, status, issued_by_employee_uid, issued_at, used_at, updated_at
         FROM invites';

    if ($inviteConditions !== []) {
        $invitesSql .= ' WHERE ' . implode(' AND ', $inviteConditions);
    }

    $invitesSql .= ' ORDER BY issued_at DESC LIMIT 100';

    $invitesStmt = $pdo->prepare($invitesSql);
    $invitesStmt->execute($inviteParams);
    $invites = $invitesStmt->fetchAll();

    if ($selectedInviteId !== '') {
        $selectedInviteStmt = $pdo->prepare(
            'SELECT id, code_id, visitor_name, visitor_phone, visitor_email, companions_expected, valid_from, valid_to, status, issued_by_employee_uid, issued_at, used_at, redisplay_until, created_at, updated_at
             FROM invites
             WHERE id = :id
             LIMIT 1'
        );
        $selectedInviteStmt->execute(['id' => $selectedInviteId]);
        $selectedInviteRow = $selectedInviteStmt->fetch();

        if (is_array($selectedInviteRow)) {
            $selectedInvite = $selectedInviteRow;

            try {
                $eventsStmt = $pdo->prepare(
                    'SELECT id, code_id, device_id, scanned_at, result, visitor_name_snapshot, latency_ms, error_detail
                     FROM scan_events
                     WHERE code_id = :code_id
                     ORDER BY scanned_at DESC
                     LIMIT 100'
                );
                $eventsStmt->execute(['code_id' => (string)$selectedInvite['code_id']]);
                $selectedInviteEvents = $eventsStmt->fetchAll();
            } catch (Throwable $eventsException) {
                $selectedInviteEvents = [];
                if ($error === null) {
                    $error = 'No se pudo cargar scan_events para el detalle del pase: ' . $eventsException->getMessage();
                }
            }
        }
    }
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
        :root {
            color-scheme: light;
            --bg: #f7f7f8;
            --card: #ffffff;
            --line: #d1d5db;
            --text: #222222;
            --muted: #666666;
            --primary: #1f6feb;
            --ok-bg: #e9fbe9;
            --ok-border: #64b464;
            --err-bg: #fdecec;
            --err-border: #d87c7c;
        }

        * { box-sizing: border-box; }

        body {
            font-family: Arial, sans-serif;
            margin: 24px;
            background: var(--bg);
            color: var(--text);
        }

        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 16px;
        }

        .topbar h1 {
            margin: 0;
        }

        .topbar-actions {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }

        .card {
            background: var(--card);
            border: 1px solid var(--line);
            border-radius: 10px;
            padding: 16px;
            margin-bottom: 16px;
        }

        h2, h3 { margin-top: 0; }

        label {
            display: block;
            margin-top: 8px;
            font-weight: 600;
        }

        input,
        select,
        button {
            width: 100%;
            max-width: 440px;
            padding: 8px;
            margin-top: 4px;
            border-radius: 8px;
            border: 1px solid var(--line);
            font: inherit;
        }

        button {
            width: auto;
            margin-top: 12px;
            cursor: pointer;
            background: var(--primary);
            color: #fff;
            border-color: var(--primary);
            font-weight: 700;
        }

        button.secondary {
            background: #fff;
            color: var(--text);
            border-color: var(--line);
        }

        table {
            border-collapse: collapse;
            width: 100%;
            font-size: 14px;
        }

        th,
        td {
            border: 1px solid var(--line);
            padding: 8px;
            text-align: left;
            vertical-align: top;
        }

        th { background: #f0f0f2; }

        .ok,
        .err {
            border-radius: 8px;
            padding: 10px;
            margin-bottom: 12px;
        }

        .ok { background: var(--ok-bg); border: 1px solid var(--ok-border); }
        .err { background: var(--err-bg); border: 1px solid var(--err-border); }

        .muted { color: var(--muted); font-size: 13px; }
        .qr-wrap { margin-top: 10px; }
        .inline-form { display: inline-block; margin: 0; }
        .inline-btn { margin-top: 0; padding: 6px 10px; font-size: 13px; }
        .actions-cell { min-width: 220px; }
        .actions-cell .inline-btn { margin-left: 6px; }
        .pill { display: inline-block; border-radius: 999px; padding: 2px 8px; font-size: 12px; font-weight: 700; }
        .pill.active { background: #e8f4ff; color: #0a56a6; }
        .pill.used { background: #f0ecff; color: #4a2e99; }
        .pill.revoked { background: #feecec; color: #9a1e1e; }
        .pill.expired { background: #fff3e6; color: #9b5c00; }
        code { background: #f2f2f2; padding: 2px 4px; border-radius: 4px; }
    </style>
</head>
<body>
    <div class="topbar">
        <div>
            <h1>Panel Admin: usuarios, roles y pases</h1>
            <div class="muted">Administra empleados, usuarios web, llaves de emisor y pases temporales.</div>
        </div>
        <div class="topbar-actions">
            <div class="muted">Sesion: <strong><?= htmlspecialchars($currentAdminLabel, ENT_QUOTES, 'UTF-8') ?></strong> (ADMIN)</div>
            <form method="post" action="/logout.php" class="inline-form">
                <?= auth_csrf_input($config) ?>
                <button type="submit" class="secondary">Cerrar sesion</button>
            </form>
        </div>
    </div>

    <?php if ($success !== null): ?>
        <div class="ok">
            <strong>Exito:</strong> <?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?>
            <?php if ($generatedIssuerKey !== null): ?>
                <div style="margin-top:8px;">
                    Llave en claro (copiar y guardar):
                    <div><code><?= htmlspecialchars($generatedIssuerKey, ENT_QUOTES, 'UTF-8') ?></code></div>
                </div>
            <?php endif; ?>
            <?php if ($generatedPassCodeId !== null): ?>
                <div style="margin-top:8px;">
                    Code ID del pase (contenido para QR):
                    <div><code id="new-pass-code-id"><?= htmlspecialchars($generatedPassCodeId, ENT_QUOTES, 'UTF-8') ?></code></div>
                </div>
                <div class="qr-wrap">
                    <button type="button" id="btn-generate-qr">Generar QR</button>
                    <button type="button" id="btn-download-qr" class="secondary" style="display:none;">Descargar PNG</button>
                    <div id="qr-container" style="margin-top:10px;"></div>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ($error !== null): ?>
        <div class="err"><strong>Error:</strong> <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <section class="card">
        <h2>Crear o actualizar usuario web</h2>
        <p class="muted">Usa el mismo username para actualizar un usuario existente. Si dejas la password vacia durante una actualizacion, se conserva la actual.</p>
        <form method="post">
            <?= auth_csrf_input($config) ?>
            <input type="hidden" name="action" value="save_app_user">

            <label for="username">Username</label>
            <input id="username" name="username" type="text" required placeholder="emp_admin_01">

            <label for="app_display_name">Nombre visible</label>
            <input id="app_display_name" name="app_display_name" type="text" placeholder="Admin Hacedores">

            <label for="role">Rol</label>
            <select id="role" name="role">
                <option value="EMPLOYEE">EMPLOYEE</option>
                <option value="ADMIN">ADMIN</option>
            </select>

            <label for="app_user_employee_uid">Employee UID (solo para EMPLOYEE)</label>
            <select id="app_user_employee_uid" name="app_user_employee_uid">
                <option value="">Sin employee_uid</option>
                <?php foreach ($activeEmployees as $employee): ?>
                    <option value="<?= htmlspecialchars((string)$employee['uid'], ENT_QUOTES, 'UTF-8') ?>">
                        <?= htmlspecialchars((string)$employee['display_name'], ENT_QUOTES, 'UTF-8') ?> (<?= htmlspecialchars((string)$employee['uid'], ENT_QUOTES, 'UTF-8') ?>)
                    </option>
                <?php endforeach; ?>
            </select>

            <label for="password">Password</label>
            <input id="password" name="password" type="password" placeholder="Nueva password o password inicial">

            <label for="app_user_is_active">Estado del usuario web</label>
            <select id="app_user_is_active" name="app_user_is_active">
                <option value="1">Activo</option>
                <option value="0">Inactivo</option>
            </select>

            <button type="submit">Guardar usuario web</button>
        </form>
    </section>

    <section class="card">
        <h2>Usuarios web registrados</h2>
        <table>
            <thead>
                <tr>
                    <th>Username</th>
                    <th>Rol</th>
                    <th>Employee UID</th>
                    <th>Nombre visible</th>
                    <th>Activo</th>
                    <th>Ultimo login</th>
                    <th>Actualizado</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($appUsers as $row): ?>
                    <tr>
                        <td><?= htmlspecialchars((string)$row['username'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars((string)$row['role'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars((string)($row['employee_uid'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars((string)($row['employee_display_name'] ?? $row['display_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= (int)$row['is_active'] === 1 ? 'Si' : 'No' ?></td>
                        <td><?= htmlspecialchars((string)($row['last_login_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars((string)$row['updated_at'], ENT_QUOTES, 'UTF-8') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </section>

    <section class="card">
        <h2>Crear o actualizar empleado</h2>
        <form method="post">
            <?= auth_csrf_input($config) ?>
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

            <button type="submit">Guardar empleado</button>
        </form>
    </section>

    <section class="card">
        <h2>Generar llave de emisor (X-Issuer-Key)</h2>
        <form method="post">
            <?= auth_csrf_input($config) ?>
            <input type="hidden" name="action" value="create_issuer_key">

            <label for="employee_uid">Employee UID</label>
            <select id="employee_uid" name="employee_uid" required>
                <option value="">Selecciona un empleado</option>
                <?php foreach ($activeEmployees as $employee): ?>
                    <option value="<?= htmlspecialchars((string)$employee['uid'], ENT_QUOTES, 'UTF-8') ?>">
                        <?= htmlspecialchars((string)$employee['display_name'], ENT_QUOTES, 'UTF-8') ?> (<?= htmlspecialchars((string)$employee['uid'], ENT_QUOTES, 'UTF-8') ?>)
                    </option>
                <?php endforeach; ?>
            </select>

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
            <?= auth_csrf_input($config) ?>
            <input type="hidden" name="action" value="create_invite">

            <label for="visitor_name">Nombre del visitante</label>
            <input id="visitor_name" name="visitor_name" type="text" required placeholder="Juan Perez">

            <label for="visitor_phone">Telefono (opcional)</label>
            <input id="visitor_phone" name="visitor_phone" type="text" placeholder="+52 55 1234 5678">

            <label for="visitor_email">Email (opcional)</label>
            <input id="visitor_email" name="visitor_email" type="email" placeholder="visitante@email.com">

            <label for="companions_expected">Acompanantes esperados</label>
            <input id="companions_expected" name="companions_expected" type="number" min="0" value="0" required>

            <label for="valid_from">Valido desde</label>
            <input id="valid_from" name="valid_from" type="datetime-local" required>

            <label for="valid_to">Valido hasta</label>
            <input id="valid_to" name="valid_to" type="datetime-local" required>

            <label for="issued_by_employee_uid">Emitido por (employee UID activo)</label>
            <select id="issued_by_employee_uid" name="issued_by_employee_uid" required>
                <option value="">Selecciona un empleado activo</option>
                <?php foreach ($activeEmployees as $employee): ?>
                    <option value="<?= htmlspecialchars((string)$employee['uid'], ENT_QUOTES, 'UTF-8') ?>">
                        <?= htmlspecialchars((string)$employee['display_name'], ENT_QUOTES, 'UTF-8') ?> (<?= htmlspecialchars((string)$employee['uid'], ENT_QUOTES, 'UTF-8') ?>)
                    </option>
                <?php endforeach; ?>
            </select>

            <button type="submit">Crear pase</button>
        </form>
    </section>

    <section class="card">
        <h2>Empleados registrados</h2>
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
                        <td><?= (int)$row['is_active'] === 1 ? 'Si' : 'No' ?></td>
                        <td><?= htmlspecialchars((string)$row['created_at'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars((string)$row['updated_at'], ENT_QUOTES, 'UTF-8') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </section>

    <section class="card">
        <h2>Llaves de emisor (ultimas 50)</h2>
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
                        <td><?= (int)$row['is_enabled'] === 1 ? 'Si' : 'No' ?></td>
                        <td><?= htmlspecialchars((string)$row['created_at'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars((string)$row['updated_at'], ENT_QUOTES, 'UTF-8') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </section>

    <section class="card">
        <h2>Pases recientes (maximo 100)</h2>
        <form method="get" style="margin-bottom: 10px;">
            <label for="invite_status" style="display:inline-block; margin-right:8px;">Filtrar estado:</label>
            <select id="invite_status" name="invite_status" style="width:auto; min-width:180px; display:inline-block;">
                <option value="ALL" <?= $inviteStatusFilter === 'ALL' ? 'selected' : '' ?>>Todos</option>
                <option value="ACTIVE" <?= $inviteStatusFilter === 'ACTIVE' ? 'selected' : '' ?>>ACTIVE (vigentes)</option>
                <option value="USED" <?= $inviteStatusFilter === 'USED' ? 'selected' : '' ?>>USED</option>
                <option value="REVOKED" <?= $inviteStatusFilter === 'REVOKED' ? 'selected' : '' ?>>REVOKED</option>
                <option value="EXPIRED" <?= $inviteStatusFilter === 'EXPIRED' ? 'selected' : '' ?>>Expirados</option>
            </select>
            <?php if ($selectedInviteId !== ''): ?>
                <input type="hidden" name="invite_id" value="<?= htmlspecialchars($selectedInviteId, ENT_QUOTES, 'UTF-8') ?>">
            <?php endif; ?>
            <button type="submit" class="inline-btn">Aplicar filtro</button>
        </form>

        <table>
            <thead>
                <tr>
                    <th>Invite ID</th>
                    <th>Code ID (QR)</th>
                    <th>Visitante</th>
                    <th>Acompanantes</th>
                    <th>Valido desde</th>
                    <th>Valido hasta</th>
                    <th>Estado</th>
                    <th>Emitido por</th>
                    <th>Emitido en</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($invites as $row): ?>
                    <?php
                        $status = (string)$row['status'];
                        $validTo = new DateTimeImmutable((string)$row['valid_to']);
                        $computedNow = new DateTimeImmutable('now');
                        $isExpiredActive = $status === 'ACTIVE' && $validTo < $computedNow;
                        $statusClass = $status === 'USED'
                            ? 'used'
                            : ($status === 'REVOKED' ? 'revoked' : ($isExpiredActive ? 'expired' : 'active'));
                        $statusLabel = $isExpiredActive ? 'EXPIRED' : $status;
                    ?>
                    <tr>
                        <td><?= htmlspecialchars((string)$row['id'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><code><?= htmlspecialchars((string)$row['code_id'], ENT_QUOTES, 'UTF-8') ?></code></td>
                        <td><?= htmlspecialchars((string)$row['visitor_name'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars((string)$row['companions_expected'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars((string)$row['valid_from'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars((string)$row['valid_to'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><span class="pill <?= htmlspecialchars($statusClass, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8') ?></span></td>
                        <td><?= htmlspecialchars((string)$row['issued_by_employee_uid'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars((string)$row['issued_at'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td class="actions-cell">
                            <a href="?invite_status=<?= urlencode($inviteStatusFilter) ?>&invite_id=<?= urlencode((string)$row['id']) ?>">Detalle</a>
                            <button type="button" class="inline-btn js-generate-table-qr" data-code-id="<?= htmlspecialchars((string)$row['code_id'], ENT_QUOTES, 'UTF-8') ?>">Generar QR</button>
                            <?php if ((string)$row['status'] !== 'REVOKED'): ?>
                                <form method="post" class="inline-form" onsubmit="return confirm('Seguro que deseas revocar este pase?');">
                                    <?= auth_csrf_input($config) ?>
                                    <input type="hidden" name="action" value="revoke_invite">
                                    <input type="hidden" name="invite_id" value="<?= htmlspecialchars((string)$row['id'], ENT_QUOTES, 'UTF-8') ?>">
                                    <button type="submit" class="inline-btn">Revocar</button>
                                </form>
                            <?php else: ?>
                                <span class="muted">Ya revocado</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div class="qr-wrap">
            <button type="button" id="btn-download-table-qr" class="secondary" style="display:none;">Descargar QR seleccionado</button>
            <div id="table-qr-container" style="margin-top:10px;"></div>
        </div>
    </section>

    <?php if ($selectedInvite !== null): ?>
        <section class="card">
            <h2>Detalle del pase seleccionado</h2>
            <p class="muted">Invite ID: <code><?= htmlspecialchars((string)$selectedInvite['id'], ENT_QUOTES, 'UTF-8') ?></code></p>
            <table>
                <tbody>
                    <tr><th>Code ID</th><td><code><?= htmlspecialchars((string)$selectedInvite['code_id'], ENT_QUOTES, 'UTF-8') ?></code></td></tr>
                    <tr><th>Visitante</th><td><?= htmlspecialchars((string)$selectedInvite['visitor_name'], ENT_QUOTES, 'UTF-8') ?></td></tr>
                    <tr><th>Telefono</th><td><?= htmlspecialchars((string)($selectedInvite['visitor_phone'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td></tr>
                    <tr><th>Email</th><td><?= htmlspecialchars((string)($selectedInvite['visitor_email'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td></tr>
                    <tr><th>Acompanantes</th><td><?= htmlspecialchars((string)$selectedInvite['companions_expected'], ENT_QUOTES, 'UTF-8') ?></td></tr>
                    <tr><th>Estado</th><td><?= htmlspecialchars((string)$selectedInvite['status'], ENT_QUOTES, 'UTF-8') ?></td></tr>
                    <tr><th>Valido desde</th><td><?= htmlspecialchars((string)$selectedInvite['valid_from'], ENT_QUOTES, 'UTF-8') ?></td></tr>
                    <tr><th>Valido hasta</th><td><?= htmlspecialchars((string)$selectedInvite['valid_to'], ENT_QUOTES, 'UTF-8') ?></td></tr>
                    <tr><th>Emitido por</th><td><?= htmlspecialchars((string)$selectedInvite['issued_by_employee_uid'], ENT_QUOTES, 'UTF-8') ?></td></tr>
                    <tr><th>Emitido en</th><td><?= htmlspecialchars((string)$selectedInvite['issued_at'], ENT_QUOTES, 'UTF-8') ?></td></tr>
                    <tr><th>Usado en</th><td><?= htmlspecialchars((string)($selectedInvite['used_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td></tr>
                    <tr><th>Redisplay hasta</th><td><?= htmlspecialchars((string)($selectedInvite['redisplay_until'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td></tr>
                    <tr><th>Actualizado</th><td><?= htmlspecialchars((string)$selectedInvite['updated_at'], ENT_QUOTES, 'UTF-8') ?></td></tr>
                </tbody>
            </table>
        </section>

        <section class="card">
            <h3>Historial de escaneos (scan_events)</h3>
            <?php if ($selectedInviteEvents === []): ?>
                <p class="muted">Sin eventos registrados para este code_id o la tabla scan_events aun no existe.</p>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Scanned at</th>
                            <th>Resultado</th>
                            <th>Dispositivo</th>
                            <th>Visitor snapshot</th>
                            <th>Latencia (ms)</th>
                            <th>Error detail</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($selectedInviteEvents as $event): ?>
                            <tr>
                                <td><?= htmlspecialchars((string)$event['scanned_at'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars((string)$event['result'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars((string)$event['device_id'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars((string)($event['visitor_name_snapshot'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars((string)($event['latency_ms'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars((string)($event['error_detail'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <script src="/admin/assets/js/qrcode.min.js"></script>
    <script>
        (() => {
            const codeNode = document.getElementById('new-pass-code-id');
            const generateBtn = document.getElementById('btn-generate-qr');
            const downloadBtn = document.getElementById('btn-download-qr');
            const container = document.getElementById('qr-container');
            const tableQrContainer = document.getElementById('table-qr-container');
            const tableDownloadBtn = document.getElementById('btn-download-table-qr');
            const tableQrButtons = document.querySelectorAll('.js-generate-table-qr');

            let latestCodeId = '';
            let latestQrWrap = null;
            let tableLatestCodeId = '';
            let tableLatestQrWrap = null;

            function renderQr(targetContainer, codeId) {
                if (!targetContainer || !codeId || typeof QRCode === 'undefined') {
                    return null;
                }

                targetContainer.innerHTML = '';
                const qrWrap = document.createElement('div');
                targetContainer.appendChild(qrWrap);

                new QRCode(qrWrap, {
                    text: codeId,
                    width: 320,
                    height: 320,
                });

                return qrWrap;
            }

            function downloadFromWrap(qrWrap, codeId) {
                if (!qrWrap || !codeId) {
                    return;
                }

                const canvas = qrWrap.querySelector('canvas');
                const img = qrWrap.querySelector('img');
                let url = '';

                if (canvas) {
                    url = canvas.toDataURL('image/png');
                } else if (img) {
                    url = img.src;
                }

                if (!url) {
                    return;
                }

                const link = document.createElement('a');
                link.href = url;
                link.download = `pase-${codeId}.png`;
                link.click();
            }

            if (generateBtn && downloadBtn && container && codeNode && typeof QRCode !== 'undefined') {
                generateBtn.addEventListener('click', () => {
                    latestCodeId = codeNode.textContent.trim();
                    latestQrWrap = renderQr(container, latestCodeId);
                    downloadBtn.style.display = latestQrWrap ? 'inline-block' : 'none';
                });

                downloadBtn.addEventListener('click', () => {
                    downloadFromWrap(latestQrWrap, latestCodeId);
                });
            }

            tableQrButtons.forEach((button) => {
                button.addEventListener('click', () => {
                    tableLatestCodeId = button.getAttribute('data-code-id') || '';
                    tableLatestQrWrap = renderQr(tableQrContainer, tableLatestCodeId);
                    if (tableDownloadBtn) {
                        tableDownloadBtn.style.display = tableLatestQrWrap ? 'inline-block' : 'none';
                    }
                });
            });

            if (tableDownloadBtn) {
                tableDownloadBtn.addEventListener('click', () => {
                    downloadFromWrap(tableLatestQrWrap, tableLatestCodeId);
                });
            }
        })();
    </script>
</body>
</html>
