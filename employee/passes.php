<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

function invite_effective_status(string $storedStatus, DateTimeImmutable $validTo, DateTimeImmutable $now): string
{
    if ($storedStatus === 'ACTIVE' && $validTo < $now) {
        return 'EXPIRED';
    }

    return $storedStatus;
}

function invite_status_badge_class(string $effectiveStatus): string
{
    return match ($effectiveStatus) {
        'USED' => 'info',
        'REVOKED' => 'danger',
        'EXPIRED' => 'warning',
        default => 'success',
    };
}

function format_spanish_datetime_label(DateTimeImmutable $dateTime): string
{
    $weekdayLabels = [
        1 => 'Lunes',
        2 => 'Martes',
        3 => 'Miercoles',
        4 => 'Jueves',
        5 => 'Viernes',
        6 => 'Sabado',
        7 => 'Domingo',
    ];
    $monthLabels = [
        1 => 'enero',
        2 => 'febrero',
        3 => 'marzo',
        4 => 'abril',
        5 => 'mayo',
        6 => 'junio',
        7 => 'julio',
        8 => 'agosto',
        9 => 'septiembre',
        10 => 'octubre',
        11 => 'noviembre',
        12 => 'diciembre',
    ];

    return sprintf(
        '%s %s %s, %s',
        $weekdayLabels[(int)$dateTime->format('N')] ?? $dateTime->format('l'),
        $dateTime->format('j'),
        $monthLabels[(int)$dateTime->format('n')] ?? $dateTime->format('F'),
        $dateTime->format('H:i')
    );
}

function format_spanish_qr_expiration_label(DateTimeImmutable $dateTime): string
{
    $monthLabels = [
        1 => 'enero',
        2 => 'febrero',
        3 => 'marzo',
        4 => 'abril',
        5 => 'mayo',
        6 => 'junio',
        7 => 'julio',
        8 => 'agosto',
        9 => 'septiembre',
        10 => 'octubre',
        11 => 'noviembre',
        12 => 'diciembre',
    ];

    return sprintf(
        '%s de %s, %s, %s',
        $dateTime->format('j'),
        $monthLabels[(int)$dateTime->format('n')] ?? $dateTime->format('F'),
        $dateTime->format('Y'),
        $dateTime->format('H:i')
    );
}

$pdo = db_pdo($config);
$currentUser = auth_require_roles($pdo, $config, [AUTH_ROLE_EMPLOYEE]);
$currentEmployeeUid = (string)$currentUser['employee_uid'];
$currentDisplayName = (string)$currentUser['resolved_display_name'];

$error = null;
$success = null;
$generatedPassCodeId = null;
$generatedPassValidToLabel = null;

auth_session_start($config);

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

        if ($action !== 'create_invite') {
            throw new RuntimeException('Accion no soportada.');
        }

        $visitorName = trim((string)($_POST['visitor_name'] ?? ''));
        $validFromRaw = trim((string)($_POST['valid_from'] ?? ''));
        $validToRaw = trim((string)($_POST['valid_to'] ?? ''));
        $companionsExpectedRaw = trim((string)($_POST['companions_expected'] ?? '1'));
        $visitorPhone = normalize_optional_string($_POST['visitor_phone'] ?? null);
        $visitorEmail = null;

        if ($visitorName === '' || $validFromRaw === '' || $validToRaw === '') {
            throw new RuntimeException('visitor_name, valid_from y valid_to son obligatorios.');
        }

        if ($companionsExpectedRaw === '' || !ctype_digit($companionsExpectedRaw)) {
            throw new RuntimeException('companions_expected debe ser un numero entero igual o mayor a 0.');
        }

        $companionsExpected = (int)$companionsExpectedRaw;

        try {
            $validFrom = new DateTimeImmutable($validFromRaw);
            $validTo = new DateTimeImmutable($validToRaw);
        } catch (Throwable $exception) {
            throw new RuntimeException('valid_from y valid_to deben tener formato de fecha valido.');
        }

        if ($validTo <= $validFrom) {
            throw new RuntimeException('valid_to debe ser mayor que valid_from.');
        }

        $employeeStmt = $pdo->prepare(
            'SELECT uid
             FROM employees
             WHERE uid = :uid AND is_active = 1
             LIMIT 1'
        );
        $employeeStmt->execute(['uid' => $currentEmployeeUid]);
        $employeeRow = $employeeStmt->fetch();

        if (!is_array($employeeRow)) {
            throw new RuntimeException('Tu usuario ya no tiene un employee_uid activo.');
        }

        $now = new DateTimeImmutable('now');
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
            'valid_from' => $validFrom->format('Y-m-d H:i:s'),
            'valid_to' => $validTo->format('Y-m-d H:i:s'),
            'issued_by_employee_uid' => $currentEmployeeUid,
            'issued_at' => $now->format('Y-m-d H:i:s'),
            'created_at' => $now->format('Y-m-d H:i:s'),
            'updated_at' => $now->format('Y-m-d H:i:s'),
        ]);

        $_SESSION['employee_pass_flash_success'] = 'Pase de acceso creado correctamente.';
        $_SESSION['employee_pass_flash_code_id'] = $codeId;
        $_SESSION['employee_pass_flash_valid_to_label'] = format_spanish_qr_expiration_label($validTo);

        $redirectQuery = [
            'invite_status' => $inviteStatusFilter,
            'invite_id' => $inviteId,
        ];

        auth_redirect('/employee/passes.php?' . http_build_query($redirectQuery) . '#detalle');
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        $error = $exception->getMessage();
    }
}

$invites = [];
$selectedInvite = null;
$defaultValidFromValue = (new DateTimeImmutable('now'))->format('Y-m-d\TH:i');
$defaultValidToValue = (new DateTimeImmutable('+24 hours'))->format('Y-m-d\TH:i');
$formVisitorName = trim((string)($_POST['visitor_name'] ?? ''));
$formVisitorPhone = trim((string)($_POST['visitor_phone'] ?? ''));
$formCompanionsExpected = trim((string)($_POST['companions_expected'] ?? '1'));
$formValidFrom = trim((string)($_POST['valid_from'] ?? $defaultValidFromValue));
$formValidTo = trim((string)($_POST['valid_to'] ?? $defaultValidToValue));
$activeInvitesCount = 0;
$issuedTodayCount = 0;
$usedTodayCount = 0;
$requestNow = new DateTimeImmutable('now');
$requestNowSql = $requestNow->format('Y-m-d H:i:s');

if (isset($_SESSION['employee_pass_flash_success']) && is_string($_SESSION['employee_pass_flash_success'])) {
    $success = $_SESSION['employee_pass_flash_success'];
    unset($_SESSION['employee_pass_flash_success']);
}

if (isset($_SESSION['employee_pass_flash_code_id']) && is_string($_SESSION['employee_pass_flash_code_id'])) {
    $generatedPassCodeId = $_SESSION['employee_pass_flash_code_id'];
    unset($_SESSION['employee_pass_flash_code_id']);
}

if (isset($_SESSION['employee_pass_flash_valid_to_label']) && is_string($_SESSION['employee_pass_flash_valid_to_label'])) {
    $generatedPassValidToLabel = $_SESSION['employee_pass_flash_valid_to_label'];
    unset($_SESSION['employee_pass_flash_valid_to_label']);
}

try {
    $inviteConditions = ['issued_by_employee_uid = :current_employee_uid'];
    $inviteParams = ['current_employee_uid' => $currentEmployeeUid];

    if ($inviteStatusFilter === 'ACTIVE') {
        $inviteConditions[] = 'status = "ACTIVE" AND valid_to >= :now_filter';
        $inviteParams['now_filter'] = $requestNowSql;
    } elseif ($inviteStatusFilter === 'USED') {
        $inviteConditions[] = 'status = "USED"';
    } elseif ($inviteStatusFilter === 'REVOKED') {
        $inviteConditions[] = 'status = "REVOKED"';
    } elseif ($inviteStatusFilter === 'EXPIRED') {
        $inviteConditions[] = 'status = "ACTIVE" AND valid_to < :now_filter';
        $inviteParams['now_filter'] = $requestNowSql;
    }

    $invitesSql =
        'SELECT id, code_id, visitor_name, visitor_phone, companions_expected, valid_from, valid_to, status, issued_at, used_at, updated_at
         FROM invites
         WHERE ' . implode(' AND ', $inviteConditions) . '
         ORDER BY issued_at DESC
         LIMIT 100';

    $invitesStmt = $pdo->prepare($invitesSql);
    $invitesStmt->execute($inviteParams);
    $invites = $invitesStmt->fetchAll();

    $todayStart = $requestNow->setTime(0, 0, 0);
    $tomorrowStart = $todayStart->modify('+1 day');

    foreach ($invites as $row) {
        $rowStatus = (string)$row['status'];
        $rowValidTo = new DateTimeImmutable((string)$row['valid_to']);
        $rowIssuedAt = new DateTimeImmutable((string)$row['issued_at']);
        $rowEffectiveStatus = invite_effective_status($rowStatus, $rowValidTo, $requestNow);

        if ($rowEffectiveStatus === 'ACTIVE') {
            $activeInvitesCount++;
        }

        if ($rowIssuedAt >= $todayStart && $rowIssuedAt < $tomorrowStart) {
            $issuedTodayCount++;
        }

        if ($rowStatus === 'USED' && !empty($row['used_at'])) {
            $rowUsedAt = new DateTimeImmutable((string)$row['used_at']);
            if ($rowUsedAt >= $todayStart && $rowUsedAt < $tomorrowStart) {
                $usedTodayCount++;
            }
        }
    }

    if ($selectedInviteId !== '') {
        $selectedInviteStmt = $pdo->prepare(
            'SELECT id, code_id, visitor_name, visitor_phone, companions_expected, valid_from, valid_to, status, issued_at, used_at, updated_at
             FROM invites
             WHERE id = :id
               AND issued_by_employee_uid = :current_employee_uid
             LIMIT 1'
        );
        $selectedInviteStmt->execute([
            'id' => $selectedInviteId,
            'current_employee_uid' => $currentEmployeeUid,
        ]);
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
            --bg: #eef3f0;
            --surface: rgba(255, 255, 255, 0.84);
            --surface-dark: #17352d;
            --text: #163028;
            --muted: #60756d;
            --line: rgba(22, 48, 40, 0.12);
            --primary: #1d8f6b;
            --primary-strong: #136b50;
            --primary-soft: #dbf4ea;
            --warning-soft: #fff1cf;
            --warning-text: #92610f;
            --danger-soft: #fde5e3;
            --danger-text: #a03832;
            --info-soft: #e6f2ff;
            --info-text: #1d5f97;
            --ok-bg: #e9f7f0;
            --ok-border: rgba(29, 143, 107, 0.28);
            --err-bg: #fdeceb;
            --err-border: rgba(160, 56, 50, 0.22);
            --shadow: 0 24px 60px rgba(18, 39, 33, 0.10);
            --radius-xl: 28px;
            --radius-lg: 22px;
            --radius-md: 16px;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            font-family: Bahnschrift, "Aptos", "Segoe UI Variable", "Segoe UI", sans-serif;
            color: var(--text);
            line-height: 1.45;
            background:
                radial-gradient(circle at top left, rgba(65, 155, 120, 0.22), transparent 30%),
                radial-gradient(circle at bottom right, rgba(247, 199, 96, 0.18), transparent 24%),
                linear-gradient(180deg, #f4f8f5 0%, var(--bg) 100%);
        }

        .helper-link {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 12px 16px;
            border-radius: 14px;
            border: 1px solid var(--line);
            color: var(--text);
            text-align: center;
            text-decoration: none;
            font-weight: 700;
            background: rgba(255, 255, 255, 0.70);
        }

        .topbar button,
        .topbar .helper-link {
            border: 0;
            border-radius: 14px;
            padding: 12px 16px;
            font: inherit;
            font-weight: 800;
            cursor: pointer;
        }

        .content {
            max-width: 1360px;
            margin: 0 auto;
            padding: 28px;
        }

        .topbar,
        .stat-row,
        .detail-top,
        .table-head,
        .cta-row,
        .top-actions {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
        }

        .topbar {
            margin-bottom: 24px;
        }

        .title-block h2 {
            margin: 0;
            font-size: 34px;
            line-height: 1.05;
        }

        .title-block p {
            margin: 8px 0 0;
            color: var(--muted);
            max-width: 700px;
        }

        .ghost-chip {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 14px;
            border-radius: 16px;
            border: 1px solid var(--line);
            background: rgba(255, 255, 255, 0.56);
            color: var(--text);
        }

        .top-actions button,
        .cta-actions button,
        .table-actions a,
        .table-actions button,
        .filter-submit {
            font: inherit;
        }

        .top-actions button,
        .cta-actions .primary {
            border-radius: 16px;
            padding: 13px 18px;
            font-weight: 800;
            cursor: pointer;
            color: #fff;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-strong) 100%);
            box-shadow: 0 14px 28px rgba(29, 143, 107, 0.24);
        }

        .top-actions form {
            margin: 0;
        }

        .top-actions .secondary {
            color: var(--text);
            background: rgba(255, 255, 255, 0.70);
            border: 1px solid var(--line);
            box-shadow: none;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }

        .stat-card,
        .panel,
        .detail-card,
        .table-card {
            border: 1px solid rgba(255, 255, 255, 0.65);
            background: var(--surface);
            backdrop-filter: blur(18px);
            box-shadow: var(--shadow);
        }

        .stat-card {
            padding: 18px 18px 16px;
            border-radius: var(--radius-lg);
        }

        .stat-card small {
            display: block;
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: 0.10em;
            font-size: 11px;
            margin-bottom: 12px;
        }

        .stat-value {
            font-size: 34px;
            font-weight: 800;
            line-height: 1;
        }

        .badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 7px 10px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 800;
            white-space: nowrap;
        }

        .badge.success {
            color: var(--primary-strong);
            background: var(--primary-soft);
        }

        .badge.warning {
            color: var(--warning-text);
            background: var(--warning-soft);
        }

        .badge.info {
            color: var(--info-text);
            background: var(--info-soft);
        }

        .badge.danger {
            color: var(--danger-text);
            background: var(--danger-soft);
        }

        .muted,
        .stat-note,
        .row-subtle {
            color: var(--muted);
        }

        .content-grid {
            display: flex;
            gap: 22px;
            align-items: flex-start;
            margin-bottom: 22px;
        }

        .content-grid > *:first-child {
            flex: 1.25;
        }

        .content-grid > *:last-child {
            flex: 0.75;
            min-width: 320px;
        }

        .panel,
        .detail-card,
        .table-card {
            padding: 24px;
            border-radius: var(--radius-xl);
        }

        .section-kicker {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            border-radius: 999px;
            background: var(--primary-soft);
            color: var(--primary-strong);
            font-size: 12px;
            font-weight: 800;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            margin-bottom: 14px;
        }

        .panel h3,
        .detail-card h3,
        .table-card h3 {
            margin: 0;
            font-size: 28px;
            line-height: 1.1;
        }

        .section-copy,
        .detail-top p,
        .table-head p {
            margin: 10px 0 22px;
            color: var(--muted);
            line-height: 1.55;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 16px;
        }

        .field.full {
            grid-column: 1 / -1;
        }

        .field label {
            display: block;
            margin-bottom: 8px;
            font-size: 13px;
            font-weight: 800;
            letter-spacing: 0.03em;
        }

        .field input,
        .filter-row select {
            width: 100%;
            font: inherit;
            border: 1px solid var(--line);
            background: rgba(255, 255, 255, 0.92);
            color: var(--text);
            border-radius: 16px;
            padding: 14px 15px;
            outline: none;
        }

        .field-hint {
            margin-top: 8px;
            color: var(--muted);
            font-size: 13px;
        }

        .cta-row {
            margin-top: 22px;
            padding-top: 18px;
            border-top: 1px solid var(--line);
        }

        .cta-row p {
            margin: 0;
            color: var(--muted);
        }

        .ok,
        .err {
            padding: 16px 18px;
            margin-bottom: 18px;
            border-radius: 18px;
        }

        .ok {
            background: var(--ok-bg);
            border: 1px solid var(--ok-border);
        }

        .err {
            background: var(--err-bg);
            border: 1px solid var(--err-border);
        }

        .ok code,
        .detail-card code,
        .table-card code {
            background: rgba(255, 255, 255, 0.7);
            border-radius: 8px;
            padding: 2px 6px;
        }

        .filter-row {
            display: flex;
            gap: 12px;
            align-items: end;
            flex-wrap: wrap;
            margin-bottom: 18px;
        }

        .filter-row > div {
            min-width: 220px;
        }

        .filter-submit {
            border-radius: 14px;
            padding: 12px 16px;
            cursor: pointer;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th,
        td {
            padding: 14px 10px;
            border-bottom: 1px solid var(--line);
            text-align: left;
            vertical-align: top;
            font-size: 14px;
        }

        th {
            color: var(--muted);
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.08em;
        }

        .table-actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .table-actions a,
        .table-actions button {
            border-radius: 12px;
            padding: 10px 12px;
            text-decoration: none;
            cursor: pointer;
            border: 1px solid var(--line);
            background: rgba(255, 255, 255, 0.70);
            color: var(--text);
        }

        .detail-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 14px 18px;
            margin-top: 20px;
        }

        .detail-item small {
            display: block;
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: 0.08em;
            font-size: 11px;
            margin-bottom: 6px;
        }

        .detail-item strong {
            font-size: 16px;
        }

        .qr-box {
            margin-top: 18px;
            padding-top: 18px;
            border-top: 1px solid var(--line);
        }

        .qr-box button {
            border: 1px solid var(--line);
            border-radius: 14px;
            padding: 12px 16px;
            background: rgba(255, 255, 255, 0.74);
            cursor: pointer;
        }

        @media (max-width: 1180px) {
            .stats-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .content-grid {
                flex-direction: column;
            }

            .content-grid > *:last-child {
                min-width: 0;
                width: 100%;
            }
        }

        @media (max-width: 920px) {
            .content {
                padding: 18px;
            }
        }

        @media (max-width: 640px) {
            .stats-grid,
            .form-grid,
            .detail-grid {
                grid-template-columns: 1fr;
            }

            .title-block h2 {
                font-size: 28px;
            }
        }
    </style>
</head>
<body>
    <main class="content">
        <header class="topbar" id="inicio">
            <div class="title-block">
                <h2>Panel de pases</h2>
            </div>
            <div class="top-actions">
                <div class="ghost-chip">Sesion activa: <strong><?= htmlspecialchars($currentDisplayName, ENT_QUOTES, 'UTF-8') ?></strong></div>
                <a class="helper-link" href="/admin/access-monitor.php" target="_blank" rel="noopener noreferrer">Monitor</a>
                <form method="post" action="/logout.php">
                    <?= auth_csrf_input($config) ?>
                    <button type="submit" class="secondary">Cerrar sesion</button>
                </form>
            </div>
        </header>

        <?php if ($error !== null): ?>
            <div class="err"><strong>Error:</strong> <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <section class="stats-grid">
            <article class="stat-card">
                <small>Vigentes</small>
                <div class="stat-row">
                    <div class="stat-value"><?= htmlspecialchars((string)$activeInvitesCount, ENT_QUOTES, 'UTF-8') ?></div>
                    <span class="badge success">vigentes</span>
                </div>
            </article>
            <article class="stat-card">
                <small>Emitidos hoy</small>
                <div class="stat-row">
                    <div class="stat-value"><?= htmlspecialchars((string)$issuedTodayCount, ENT_QUOTES, 'UTF-8') ?></div>
                    <span class="badge info">hoy</span>
                </div>
            </article>
            <article class="stat-card">
                <small>Usados hoy</small>
                <div class="stat-row">
                    <div class="stat-value"><?= htmlspecialchars((string)$usedTodayCount, ENT_QUOTES, 'UTF-8') ?></div>
                    <span class="badge info">hoy</span>
                </div>
            </article>
        </section>

        <section class="content-grid">
            <section class="panel" id="generar">
                <h3>Generar pase temporal</h3>

                <form method="post">
                    <?= auth_csrf_input($config) ?>
                    <input type="hidden" name="action" value="create_invite">

                    <div class="form-grid">
                        <div class="field full">
                            <label for="visitor_name">Nombre del visitante</label>
                            <input id="visitor_name" name="visitor_name" type="text" required value="<?= htmlspecialchars($formVisitorName, ENT_QUOTES, 'UTF-8') ?>" placeholder="Juan Perez">
                        </div>
                        <div class="field">
                            <label for="visitor_phone">Telefono (opcional)</label>
                            <input id="visitor_phone" name="visitor_phone" type="text" value="<?= htmlspecialchars($formVisitorPhone, ENT_QUOTES, 'UTF-8') ?>" placeholder="+52 55 1234 5678">
                        </div>
                        <div class="field">
                            <label for="companions_expected">Acompanantes esperados</label>
                            <input id="companions_expected" name="companions_expected" type="number" min="0" required value="<?= htmlspecialchars($formCompanionsExpected, ENT_QUOTES, 'UTF-8') ?>">
                        </div>
                        <div class="field">
                            <label for="valid_from">Valido desde</label>
                            <input id="valid_from" name="valid_from" type="datetime-local" required value="<?= htmlspecialchars($formValidFrom, ENT_QUOTES, 'UTF-8') ?>">
                        </div>
                        <div class="field">
                            <label for="valid_to">Valido hasta</label>
                            <input id="valid_to" name="valid_to" type="datetime-local" required value="<?= htmlspecialchars($formValidTo, ENT_QUOTES, 'UTF-8') ?>">
                        </div>
                    </div>

                    <div class="cta-row">
                        <div class="cta-actions">
                            <button type="submit" class="primary">Crear pase</button>
                        </div>
                    </div>
                </form>
            </section>

            <aside class="detail-card" id="detalle">
                <div class="detail-top">
                    <div>
                        <h3>Pase seleccionado</h3>
                    </div>
                </div>

                <?php if ($selectedInvite === null): ?>
                    <p class="muted">Selecciona un pase de la tabla para ver su detalle aqui.</p>
                <?php else: ?>
                    <?php
                        $selectedValidTo = new DateTimeImmutable((string)$selectedInvite['valid_to']);
                        $selectedEffectiveStatus = invite_effective_status((string)$selectedInvite['status'], $selectedValidTo, $requestNow);
                    ?>
                    <div style="margin-bottom:16px;">
                        <strong class="badge <?= htmlspecialchars(invite_status_badge_class($selectedEffectiveStatus), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($selectedEffectiveStatus, ENT_QUOTES, 'UTF-8') ?></strong>
                    </div>
                    <div class="detail-grid">
                        <div class="detail-item">
                            <small>Visitante</small>
                            <strong><?= htmlspecialchars((string)$selectedInvite['visitor_name'], ENT_QUOTES, 'UTF-8') ?></strong>
                        </div>
                        <div class="detail-item">
                            <small>Acompanantes</small>
                            <strong><?= htmlspecialchars((string)$selectedInvite['companions_expected'], ENT_QUOTES, 'UTF-8') ?></strong>
                        </div>
                        <div class="detail-item">
                            <small>Valido desde</small>
                            <strong><?= htmlspecialchars((string)$selectedInvite['valid_from'], ENT_QUOTES, 'UTF-8') ?></strong>
                        </div>
                        <div class="detail-item">
                            <small>Valido hasta</small>
                            <strong><?= htmlspecialchars((string)$selectedInvite['valid_to'], ENT_QUOTES, 'UTF-8') ?></strong>
                        </div>
                        <div class="detail-item">
                            <small>Telefono</small>
                            <strong><?= htmlspecialchars((string)($selectedInvite['visitor_phone'] ?? ''), ENT_QUOTES, 'UTF-8') ?></strong>
                        </div>
                        <div class="detail-item">
                            <small>Usado en</small>
                            <strong><?= htmlspecialchars((string)($selectedInvite['used_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?></strong>
                        </div>
                    </div>

                    <div class="qr-box">
                        <div
                            id="detail-qr-container"
                            data-code-id="<?= htmlspecialchars((string)$selectedInvite['code_id'], ENT_QUOTES, 'UTF-8') ?>"
                            data-valid-to-label="<?= htmlspecialchars(format_spanish_qr_expiration_label($selectedValidTo), ENT_QUOTES, 'UTF-8') ?>"
                            style="margin-top:12px;"
                        ></div>
                    </div>
                <?php endif; ?>
            </aside>
        </section>

        <section class="table-card" id="emitidos">
            <div class="table-head">
                <div>
                    <h3>Pases emitidos</h3>
                    <p>Lista de los ultimos 100 pases generados por tu usuario, con acceso rapido al detalle y al QR.</p>
                </div>
            </div>

            <form method="get" class="filter-row">
                <div>
                    <label for="invite_status">Filtrar estado</label>
                    <select id="invite_status" name="invite_status">
                        <option value="ALL" <?= $inviteStatusFilter === 'ALL' ? 'selected' : '' ?>>Todos</option>
                        <option value="ACTIVE" <?= $inviteStatusFilter === 'ACTIVE' ? 'selected' : '' ?>>ACTIVE</option>
                        <option value="USED" <?= $inviteStatusFilter === 'USED' ? 'selected' : '' ?>>USED</option>
                        <option value="REVOKED" <?= $inviteStatusFilter === 'REVOKED' ? 'selected' : '' ?>>REVOKED</option>
                        <option value="EXPIRED" <?= $inviteStatusFilter === 'EXPIRED' ? 'selected' : '' ?>>EXPIRED</option>
                    </select>
                </div>
                <?php if ($selectedInviteId !== ''): ?>
                    <input type="hidden" name="invite_id" value="<?= htmlspecialchars($selectedInviteId, ENT_QUOTES, 'UTF-8') ?>">
                <?php endif; ?>
                <button type="submit" class="filter-submit">Aplicar filtro</button>
            </form>

            <?php if ($invites === []): ?>
                <p class="muted">Todavia no tienes pases para este filtro.</p>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Visitante</th>
                            <th>Vigencia</th>
                            <th>Estado</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($invites as $row): ?>
                            <?php
                                $rowValidTo = new DateTimeImmutable((string)$row['valid_to']);
                                $rowEffectiveStatus = invite_effective_status((string)$row['status'], $rowValidTo, $requestNow);
                                $rowBadgeClass = invite_status_badge_class($rowEffectiveStatus);
                                $rowStatusLabel = $rowEffectiveStatus;
                                if ($rowEffectiveStatus === 'USED' && !empty($row['used_at'])) {
                                    $rowStatusLabel .= ' ' . format_spanish_datetime_label(new DateTimeImmutable((string)$row['used_at']));
                                }
                            ?>
                            <tr>
                                <td>
                                    <strong><?= htmlspecialchars((string)$row['visitor_name'], ENT_QUOTES, 'UTF-8') ?></strong>
                                    <div class="row-subtle"><?= htmlspecialchars((string)($row['visitor_phone'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                                </td>
                                <td>
                                    <div><?= htmlspecialchars((string)$row['valid_from'], ENT_QUOTES, 'UTF-8') ?></div>
                                    <div class="row-subtle"><?= htmlspecialchars((string)$row['valid_to'], ENT_QUOTES, 'UTF-8') ?></div>
                                </td>
                                <td><span class="badge <?= htmlspecialchars($rowBadgeClass, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($rowStatusLabel, ENT_QUOTES, 'UTF-8') ?></span></td>
                                <td>
                                    <div class="table-actions">
                                        <a href="?invite_status=<?= urlencode($inviteStatusFilter) ?>&invite_id=<?= urlencode((string)$row['id']) ?>#detalle">Detalle</a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

            <?php endif; ?>
        </section>
    </main>

<script src="/admin/assets/js/qrcode.min.js"></script>
<script>
(() => {
    const generateBtn = document.getElementById('btn-generate-qr');
    const downloadBtn = document.getElementById('btn-download-qr');
    const codeNode = document.getElementById('new-pass-code-id');
    const qrContainer = document.getElementById('qr-container');
    const detailQrContainer = document.getElementById('detail-qr-container');

    let latestQrWrap = null;
    let latestCodeId = '';

    function renderQr(targetContainer, codeId, validToLabel) {
        if (!targetContainer || !codeId || typeof QRCode === 'undefined') {
            return null;
        }

        targetContainer.innerHTML = '';
        const qrWrap = document.createElement('div');

        new QRCode(qrWrap, {
            text: codeId,
            width: 240,
            height: 240
        });

        const qrCanvas = qrWrap.querySelector('canvas');
        const qrImage = qrWrap.querySelector('img');

        if (!qrCanvas && !qrImage) {
            return qrWrap;
        }

        const finalCanvas = document.createElement('canvas');
        finalCanvas.width = 280;
        finalCanvas.height = 300;

        const context = finalCanvas.getContext('2d');
        if (!context) {
            targetContainer.appendChild(qrWrap);
            return qrWrap;
        }

        context.fillStyle = '#ffffff';
        context.fillRect(0, 0, finalCanvas.width, finalCanvas.height);

        const drawCaption = () => {
            context.fillStyle = '#111111';
            context.font = '12px Arial';
            context.textAlign = 'center';
            context.textBaseline = 'middle';
            context.fillText('Escanea en recepción de Hacedores', finalCanvas.width / 2, 270);
            context.fillText(`Vence el: ${validToLabel || ''}hrs`, finalCanvas.width / 2, 286);
            targetContainer.appendChild(finalCanvas);
        };

        if (qrCanvas) {
            context.drawImage(qrCanvas, 20, 20, 240, 240);
            drawCaption();
            return finalCanvas;
        }

        qrImage.addEventListener('load', () => {
            context.drawImage(qrImage, 20, 20, 240, 240);
            drawCaption();
        }, {once: true});

        if (qrImage.complete) {
            context.drawImage(qrImage, 20, 20, 240, 240);
            drawCaption();
        }

        return finalCanvas;
    }

    function downloadQr(qrWrap, codeId) {
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

        const anchor = document.createElement('a');
        anchor.href = url;
        anchor.download = `hacceso-pass-${codeId}.png`;
        anchor.click();
    }

    if (generateBtn && downloadBtn && codeNode && qrContainer) {
        generateBtn.addEventListener('click', () => {
            latestCodeId = codeNode.textContent.trim();
            latestQrWrap = renderQr(qrContainer, latestCodeId, codeNode.getAttribute('data-valid-to-label') || '');
            downloadBtn.style.display = latestQrWrap ? 'inline-block' : 'none';
        });

        downloadBtn.addEventListener('click', () => {
            downloadQr(latestQrWrap, latestCodeId);
        });
    }

    if (detailQrContainer) {
        const detailCodeId = detailQrContainer.getAttribute('data-code-id') || '';
        renderQr(detailQrContainer, detailCodeId, detailQrContainer.getAttribute('data-valid-to-label') || '');
    }
})();
</script>
</body>
</html>
