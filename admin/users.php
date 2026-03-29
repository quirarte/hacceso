<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

$error = null;
$success = null;
$generatedIssuerKey = null;

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
        } else {
            throw new RuntimeException('Acción no soportada.');
        }
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
    }
}

$employees = [];
$issuerKeys = [];

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
    <title>Hacceso Admin - Usuarios</title>
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
    <h1>Panel Admin: Usuarios y llaves de emisor</h1>

    <?php if ($success !== null): ?>
        <div class="ok">
            <strong>Éxito:</strong> <?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?>
            <?php if ($generatedIssuerKey !== null): ?>
                <div style="margin-top:8px;">
                    Llave en claro (copiar y guardar):
                    <div><code><?= htmlspecialchars($generatedIssuerKey, ENT_QUOTES, 'UTF-8') ?></code></div>
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
</body>
</html>
