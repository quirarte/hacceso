<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

$error = null;
$success = null;
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
        $pdo = db_pdo($config);

        if ($action === 'create_invite') {
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
            $selectedInviteId = $inviteId;
            $success = 'Pase de acceso creado correctamente.';
        } else {
            throw new RuntimeException('Acción no soportada.');
        }
    } catch (Throwable $exception) {
        if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $exception->getMessage();
    }
}

$employees = [];
$invites = [];
$selectedInvite = null;

try {
    $pdo = db_pdo($config);

    $employeesStmt = $pdo->query(
        'SELECT uid, display_name
         FROM employees
         WHERE is_active = 1
         ORDER BY display_name ASC'
    );
    $employees = $employeesStmt->fetchAll();

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
        'SELECT id, code_id, visitor_name, companions_expected, valid_from, valid_to, status, issued_by_employee_uid, issued_at
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
            'SELECT id, code_id, visitor_name, visitor_phone, visitor_email, companions_expected, valid_from, valid_to, status, issued_by_employee_uid, issued_at, used_at, updated_at
             FROM invites
             WHERE id = :id
             LIMIT 1'
        );
        $selectedInviteStmt->execute(['id' => $selectedInviteId]);
        $selectedInviteRow = $selectedInviteStmt->fetch();

        if (is_array($selectedInviteRow)) {
            $selectedInvite = $selectedInviteRow;
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
    <title>Hacceso Empleados - Generar pases</title>
    <style>
        :root {
            color-scheme: light;
            --bg: #f4f6fb;
            --card: #ffffff;
            --text: #1f2937;
            --subtle: #6b7280;
            --line: #d1d5db;
            --primary: #1f6feb;
            --primary-soft: #e8f0ff;
            --ok-bg: #ebfbee;
            --ok-border: #80c98d;
            --err-bg: #fff1f1;
            --err-border: #e19797;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            font-family: Inter, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: var(--bg);
            color: var(--text);
            line-height: 1.4;
        }

        .layout {
            max-width: 1100px;
            margin: 0 auto;
            padding: 24px 16px 48px;
        }

        .topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 16px;
            gap: 12px;
            flex-wrap: wrap;
        }

        .topbar h1 {
            margin: 0;
            font-size: 24px;
        }

        .muted {
            color: var(--subtle);
        }

        .grid {
            display: grid;
            gap: 16px;
        }

        .card {
            background: var(--card);
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 16px;
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.03);
        }

        .card h2,
        .card h3 {
            margin-top: 0;
            margin-bottom: 12px;
        }

        label {
            display: block;
            margin-bottom: 6px;
            font-weight: 600;
            font-size: 14px;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 12px;
        }

        input,
        select,
        button {
            width: 100%;
            border-radius: 8px;
            border: 1px solid var(--line);
            padding: 10px;
            font: inherit;
        }

        button {
            width: auto;
            cursor: pointer;
            background: var(--primary);
            color: #fff;
            border-color: var(--primary);
            font-weight: 600;
        }

        button.secondary {
            background: #fff;
            color: var(--text);
            border-color: var(--line);
        }

        .stack-sm > * + * {
            margin-top: 8px;
        }

        .ok,
        .err {
            border-radius: 10px;
            padding: 12px;
            margin-bottom: 12px;
        }

        .ok { background: var(--ok-bg); border: 1px solid var(--ok-border); }
        .err { background: var(--err-bg); border: 1px solid var(--err-border); }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }

        th,
        td {
            border: 1px solid var(--line);
            padding: 8px;
            text-align: left;
            vertical-align: top;
        }

        th { background: #f8fafc; }

        .pill {
            display: inline-block;
            border-radius: 999px;
            padding: 2px 8px;
            font-size: 12px;
            font-weight: 700;
        }

        .pill.active { background: #e8f4ff; color: #0a56a6; }
        .pill.used { background: #f0ecff; color: #4a2e99; }
        .pill.revoked { background: #feecec; color: #9a1e1e; }
        .pill.expired { background: #fff3e6; color: #9b5c00; }

        .actions {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
        }

        a { color: var(--primary); }
        code { background: #f2f4f8; padding: 2px 4px; border-radius: 4px; }
    </style>
</head>
<body>
<div class="layout">
    <div class="topbar">
        <div>
            <h1>Panel de empleados: generación de pases</h1>
            <div class="muted">Emite pases y consulta los pases generados por el equipo.</div>
        </div>
        <div><a href="/admin/users.php">Ir al panel admin</a></div>
    </div>

    <?php if ($success !== null): ?>
        <div class="ok">
            <strong>Éxito:</strong> <?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?>
            <?php if ($generatedPassCodeId !== null): ?>
                <div style="margin-top:8px;">Code ID generado para QR: <code id="new-pass-code-id"><?= htmlspecialchars($generatedPassCodeId, ENT_QUOTES, 'UTF-8') ?></code></div>
                <div style="margin-top:8px;" class="actions">
                    <button type="button" id="btn-generate-qr">Generar QR</button>
                    <button type="button" id="btn-download-qr" class="secondary" style="display:none;">Descargar PNG</button>
                </div>
                <div id="qr-container" style="margin-top:10px;"></div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ($error !== null): ?>
        <div class="err"><strong>Error:</strong> <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <section class="card">
        <h2>Generar pase</h2>
        <form method="post" class="stack-sm">
            <input type="hidden" name="action" value="create_invite">
            <div class="form-grid">
                <div>
                    <label for="visitor_name">Nombre del visitante</label>
                    <input id="visitor_name" name="visitor_name" type="text" required placeholder="Juan Pérez">
                </div>
                <div>
                    <label for="visitor_phone">Teléfono (opcional)</label>
                    <input id="visitor_phone" name="visitor_phone" type="text" placeholder="+52 55 1234 5678">
                </div>
                <div>
                    <label for="visitor_email">Email (opcional)</label>
                    <input id="visitor_email" name="visitor_email" type="email" placeholder="visitante@email.com">
                </div>
                <div>
                    <label for="companions_expected">Acompañantes esperados</label>
                    <input id="companions_expected" name="companions_expected" type="number" min="0" value="0" required>
                </div>
                <div>
                    <label for="valid_from">Válido desde</label>
                    <input id="valid_from" name="valid_from" type="datetime-local" required>
                </div>
                <div>
                    <label for="valid_to">Válido hasta</label>
                    <input id="valid_to" name="valid_to" type="datetime-local" required>
                </div>
                <div>
                    <label for="issued_by_employee_uid">Empleado emisor</label>
                    <select id="issued_by_employee_uid" name="issued_by_employee_uid" required>
                        <option value="">Selecciona un empleado activo</option>
                        <?php foreach ($employees as $employee): ?>
                            <option value="<?= htmlspecialchars((string)$employee['uid'], ENT_QUOTES, 'UTF-8') ?>">
                                <?= htmlspecialchars((string)$employee['display_name'], ENT_QUOTES, 'UTF-8') ?> (<?= htmlspecialchars((string)$employee['uid'], ENT_QUOTES, 'UTF-8') ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <button type="submit">Crear pase</button>
        </form>
    </section>

    <section class="card">
        <h2>Pases recientes</h2>
        <form method="get" class="actions" style="margin-bottom: 10px;">
            <div>
                <label for="invite_status">Filtrar estado</label>
                <select id="invite_status" name="invite_status" style="min-width:190px;">
                    <option value="ALL" <?= $inviteStatusFilter === 'ALL' ? 'selected' : '' ?>>Todos</option>
                    <option value="ACTIVE" <?= $inviteStatusFilter === 'ACTIVE' ? 'selected' : '' ?>>ACTIVE (vigentes)</option>
                    <option value="USED" <?= $inviteStatusFilter === 'USED' ? 'selected' : '' ?>>USED</option>
                    <option value="REVOKED" <?= $inviteStatusFilter === 'REVOKED' ? 'selected' : '' ?>>REVOKED</option>
                    <option value="EXPIRED" <?= $inviteStatusFilter === 'EXPIRED' ? 'selected' : '' ?>>Expirados</option>
                </select>
            </div>
            <div style="display:flex; align-items:flex-end;">
                <?php if ($selectedInviteId !== ''): ?>
                    <input type="hidden" name="invite_id" value="<?= htmlspecialchars($selectedInviteId, ENT_QUOTES, 'UTF-8') ?>">
                <?php endif; ?>
                <button type="submit">Aplicar filtro</button>
            </div>
        </form>

        <table>
            <thead>
            <tr>
                <th>Invite ID</th>
                <th>Code ID (QR)</th>
                <th>Visitante</th>
                <th>Acomp.</th>
                <th>Válido hasta</th>
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
                    <td><?= htmlspecialchars((string)$row['valid_to'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><span class="pill <?= htmlspecialchars($statusClass, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8') ?></span></td>
                    <td><?= htmlspecialchars((string)$row['issued_by_employee_uid'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars((string)$row['issued_at'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td>
                        <div class="actions">
                            <a href="?invite_status=<?= urlencode($inviteStatusFilter) ?>&invite_id=<?= urlencode((string)$row['id']) ?>">Detalle</a>
                            <button type="button" class="secondary js-generate-table-qr" data-code-id="<?= htmlspecialchars((string)$row['code_id'], ENT_QUOTES, 'UTF-8') ?>">Generar QR</button>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <div style="margin-top:10px;" class="actions">
            <button type="button" id="btn-download-table-qr" class="secondary" style="display:none;">Descargar QR seleccionado</button>
        </div>
        <div id="table-qr-container" style="margin-top:10px;"></div>
    </section>

    <?php if ($selectedInvite !== null): ?>
        <section class="card">
            <h3>Detalle del pase seleccionado</h3>
            <table>
                <tbody>
                <tr><th>Invite ID</th><td><code><?= htmlspecialchars((string)$selectedInvite['id'], ENT_QUOTES, 'UTF-8') ?></code></td></tr>
                <tr><th>Code ID</th><td><code><?= htmlspecialchars((string)$selectedInvite['code_id'], ENT_QUOTES, 'UTF-8') ?></code></td></tr>
                <tr><th>Visitante</th><td><?= htmlspecialchars((string)$selectedInvite['visitor_name'], ENT_QUOTES, 'UTF-8') ?></td></tr>
                <tr><th>Teléfono</th><td><?= htmlspecialchars((string)($selectedInvite['visitor_phone'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td></tr>
                <tr><th>Email</th><td><?= htmlspecialchars((string)($selectedInvite['visitor_email'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td></tr>
                <tr><th>Acompañantes</th><td><?= htmlspecialchars((string)$selectedInvite['companions_expected'], ENT_QUOTES, 'UTF-8') ?></td></tr>
                <tr><th>Estado</th><td><?= htmlspecialchars((string)$selectedInvite['status'], ENT_QUOTES, 'UTF-8') ?></td></tr>
                <tr><th>Válido desde</th><td><?= htmlspecialchars((string)$selectedInvite['valid_from'], ENT_QUOTES, 'UTF-8') ?></td></tr>
                <tr><th>Válido hasta</th><td><?= htmlspecialchars((string)$selectedInvite['valid_to'], ENT_QUOTES, 'UTF-8') ?></td></tr>
                <tr><th>Emitido por</th><td><?= htmlspecialchars((string)$selectedInvite['issued_by_employee_uid'], ENT_QUOTES, 'UTF-8') ?></td></tr>
                <tr><th>Emitido en</th><td><?= htmlspecialchars((string)$selectedInvite['issued_at'], ENT_QUOTES, 'UTF-8') ?></td></tr>
                <tr><th>Usado en</th><td><?= htmlspecialchars((string)($selectedInvite['used_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td></tr>
                <tr><th>Actualizado</th><td><?= htmlspecialchars((string)$selectedInvite['updated_at'], ENT_QUOTES, 'UTF-8') ?></td></tr>
                </tbody>
            </table>
        </section>
    <?php endif; ?>
</div>

<script src="/admin/assets/js/qrcode.min.js"></script>
<script>
(() => {
    function setupQrButton({buttonId, sourceCodeIdId, containerId, downloadButtonId}) {
        const btnGenerate = document.getElementById(buttonId);
        const codeElement = document.getElementById(sourceCodeIdId);
        const container = document.getElementById(containerId);
        const btnDownload = document.getElementById(downloadButtonId);

        if (!btnGenerate || !codeElement || !container || !btnDownload) {
            return;
        }

        btnGenerate.addEventListener('click', () => {
            const codeId = codeElement.textContent.trim();
            if (!codeId) {
                return;
            }

            container.innerHTML = '';
            const qrWrap = document.createElement('div');
            container.appendChild(qrWrap);

            new QRCode(qrWrap, {
                text: codeId,
                width: 220,
                height: 220,
            });

            setTimeout(() => {
                const canvas = qrWrap.querySelector('canvas');
                const img = qrWrap.querySelector('img');
                btnDownload.style.display = canvas || img ? 'inline-block' : 'none';
            }, 0);

            btnDownload.onclick = () => {
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

                const a = document.createElement('a');
                a.href = url;
                a.download = `hacceso-pass-${codeId}.png`;
                a.click();
            };
        });
    }

    setupQrButton({
        buttonId: 'btn-generate-qr',
        sourceCodeIdId: 'new-pass-code-id',
        containerId: 'qr-container',
        downloadButtonId: 'btn-download-qr',
    });

    const tableQrContainer = document.getElementById('table-qr-container');
    const tableQrDownloadBtn = document.getElementById('btn-download-table-qr');
    let lastTableQrWrap = null;
    let lastTableCodeId = '';

    document.querySelectorAll('.js-generate-table-qr').forEach((btn) => {
        btn.addEventListener('click', () => {
            if (!tableQrContainer || !tableQrDownloadBtn) {
                return;
            }

            const codeId = btn.getAttribute('data-code-id') || '';
            if (!codeId) {
                return;
            }

            tableQrContainer.innerHTML = '';
            lastTableQrWrap = document.createElement('div');
            lastTableCodeId = codeId;
            tableQrContainer.appendChild(lastTableQrWrap);

            new QRCode(lastTableQrWrap, {
                text: codeId,
                width: 220,
                height: 220,
            });

            setTimeout(() => {
                const canvas = lastTableQrWrap.querySelector('canvas');
                const img = lastTableQrWrap.querySelector('img');
                tableQrDownloadBtn.style.display = canvas || img ? 'inline-block' : 'none';
            }, 0);
        });
    });

    if (tableQrDownloadBtn) {
        tableQrDownloadBtn.addEventListener('click', () => {
            if (!lastTableQrWrap || !lastTableCodeId) {
                return;
            }

            const canvas = lastTableQrWrap.querySelector('canvas');
            const img = lastTableQrWrap.querySelector('img');
            let url = '';

            if (canvas) {
                url = canvas.toDataURL('image/png');
            } else if (img) {
                url = img.src;
            }

            if (!url) {
                return;
            }

            const a = document.createElement('a');
            a.href = url;
            a.download = `hacceso-pass-${lastTableCodeId}.png`;
            a.click();
        });
    }
})();
</script>
</body>
</html>
