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

$pdo = db_pdo($config);
$currentUser = auth_require_roles($pdo, $config, [AUTH_ROLE_EMPLOYEE]);
$currentEmployeeUid = (string)$currentUser['employee_uid'];
$currentDisplayName = (string)$currentUser['resolved_display_name'];

$error = null;
$success = null;
$generatedPassCodeId = null;

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
$expiringSoonCount = 0;
$expiredPendingCount = 0;
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
    $soonLimit = $requestNow->modify('+24 hours');

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

        if ($rowEffectiveStatus === 'ACTIVE' && $rowValidTo <= $soonLimit) {
            $expiringSoonCount++;
        }

        if ($rowEffectiveStatus === 'EXPIRED') {
            $expiredPendingCount++;
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
            --sidebar-width: 290px;
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

        .app-shell {
            display: grid;
            grid-template-columns: var(--sidebar-width) 1fr;
            min-height: 100vh;
        }

        .sidebar {
            position: relative;
            overflow: hidden;
            padding: 28px 22px;
            color: #eef8f3;
            background:
                radial-gradient(circle at top right, rgba(108, 213, 170, 0.22), transparent 34%),
                linear-gradient(180deg, var(--surface-dark) 0%, #112721 100%);
        }

        .sidebar::after {
            content: "";
            position: absolute;
            right: -56px;
            bottom: -56px;
            width: 180px;
            height: 180px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.05);
        }

        .brand,
        .sidebar-card,
        .nav-group,
        .helper {
            position: relative;
            z-index: 1;
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 14px;
            margin-bottom: 28px;
        }

        .brand-mark {
            width: 46px;
            height: 46px;
            display: grid;
            place-items: center;
            border-radius: 15px;
            font-size: 22px;
            font-weight: 800;
            color: #103126;
            background: linear-gradient(135deg, #9ce8c8 0%, #f2db8d 100%);
        }

        .brand h1 {
            margin: 0;
            font-size: 22px;
            letter-spacing: 0.02em;
        }

        .sidebar-card,
        .nav-group {
            border: 1px solid rgba(255, 255, 255, 0.08);
            background: rgba(255, 255, 255, 0.05);
            backdrop-filter: blur(12px);
            border-radius: 22px;
        }

        .sidebar-card {
            padding: 18px;
            margin-bottom: 18px;
        }

        .sidebar-card small {
            display: block;
            color: rgba(238, 248, 243, 0.72);
            text-transform: uppercase;
            letter-spacing: 0.10em;
            font-size: 11px;
            margin-bottom: 10px;
        }

        .sidebar-card strong {
            display: block;
            font-size: 20px;
            margin-bottom: 6px;
        }

        .sidebar-card span {
            color: rgba(238, 248, 243, 0.76);
            font-size: 14px;
        }

        .nav-group {
            padding: 12px;
        }

        .nav-link {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 14px;
            border-radius: 16px;
            color: #f4fbf8;
            text-decoration: none;
            font-weight: 700;
        }

        .nav-link + .nav-link {
            margin-top: 6px;
        }

        .nav-link.active {
            color: #103126;
            background: linear-gradient(135deg, #a6efcf 0%, #f4e1a8 100%);
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.34);
        }

        .nav-pill {
            min-width: 28px;
            padding: 4px 8px;
            border-radius: 999px;
            font-size: 12px;
            text-align: center;
            color: rgba(244, 251, 248, 0.84);
            background: rgba(255, 255, 255, 0.10);
        }

        .nav-link.active .nav-pill {
            color: #103126;
            background: rgba(16, 49, 38, 0.12);
        }

        .helper {
            margin-top: 18px;
            padding: 18px;
            border-radius: 22px;
            background: rgba(11, 23, 19, 0.26);
        }

        .helper-link {
            display: block;
            width: 100%;
            margin-bottom: 12px;
            padding: 12px 16px;
            border-radius: 14px;
            border: 1px solid rgba(255, 255, 255, 0.12);
            color: #eef8f3;
            text-align: center;
            text-decoration: none;
            font-weight: 700;
            background: rgba(255, 255, 255, 0.06);
        }

        .helper button {
            width: 100%;
            border: 0;
            border-radius: 14px;
            padding: 12px 16px;
            font: inherit;
            font-weight: 800;
            color: #123227;
            background: #f1e1a9;
            cursor: pointer;
        }

        .content {
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
            border: 0;
            border-radius: 16px;
            padding: 13px 18px;
            font-weight: 800;
            cursor: pointer;
            color: #fff;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-strong) 100%);
            box-shadow: 0 14px 28px rgba(29, 143, 107, 0.24);
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
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
            .app-shell {
                grid-template-columns: 1fr;
            }

            .content {
                padding: 18px;
            }

            .sidebar {
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
<div class="app-shell">
    <aside class="sidebar">
        <div class="brand">
            <div class="brand-mark">H</div>
            <div>
                <h1>Hacceso</h1>
            </div>
        </div>

        <div class="sidebar-card">
            <small>Sesion</small>
            <strong><?= htmlspecialchars($currentDisplayName, ENT_QUOTES, 'UTF-8') ?></strong>
            <span>Empleado emisor: <?= htmlspecialchars($currentEmployeeUid, ENT_QUOTES, 'UTF-8') ?></span>
        </div>

        <nav class="nav-group" aria-label="Navegacion principal">
            <a class="nav-link" href="#inicio">
                <span>Inicio</span>
                <span class="nav-pill"><?= htmlspecialchars((string)$activeInvitesCount, ENT_QUOTES, 'UTF-8') ?></span>
            </a>
            <a class="nav-link active" href="#generar">
                <span>Generar pase</span>
                <span class="nav-pill">+</span>
            </a>
            <a class="nav-link" href="#emitidos">
                <span>Pases emitidos</span>
                <span class="nav-pill"><?= htmlspecialchars((string)count($invites), ENT_QUOTES, 'UTF-8') ?></span>
            </a>
            <a class="nav-link" href="#detalle">
                <span>Detalle y QR</span>
                <span class="nav-pill"><?= $selectedInvite !== null ? '1' : '0' ?></span>
            </a>
        </nav>

        <div class="helper">
            <a class="helper-link" href="/admin/access-monitor.php">Abrir monitoreo</a>
            <form method="post" action="/logout.php" class="inline-form">
                <?= auth_csrf_input($config) ?>
                <button type="submit">Cerrar sesion</button>
            </form>
        </div>
    </aside>

    <main class="content">
        <header class="topbar" id="inicio">
            <div class="title-block">
                <h2>Panel de pases</h2>
                <p>Genera pases temporales, consulta su vigencia y recupera su QR para compartirlo con el visitante.</p>
            </div>
            <div class="top-actions">
                <div class="ghost-chip">Sesion activa: <strong><?= htmlspecialchars($currentDisplayName, ENT_QUOTES, 'UTF-8') ?></strong></div>
                <button type="button" onclick="document.getElementById('visitor_name').focus();">Nuevo pase</button>
            </div>
        </header>

        <?php if ($success !== null): ?>
            <div class="ok">
                <strong>Exito:</strong> <?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?>
                <?php if ($generatedPassCodeId !== null): ?>
                    <div style="margin-top:10px;">Code ID generado: <code id="new-pass-code-id"><?= htmlspecialchars($generatedPassCodeId, ENT_QUOTES, 'UTF-8') ?></code></div>
                    <div class="qr-box">
                        <button type="button" id="btn-generate-qr">Generar QR</button>
                        <button type="button" id="btn-download-qr" style="display:none;">Descargar PNG</button>
                        <div id="qr-container" style="margin-top:12px;"></div>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($error !== null): ?>
            <div class="err"><strong>Error:</strong> <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <section class="stats-grid">
            <article class="stat-card">
                <small>Activos</small>
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
                <small>Por vencer</small>
                <div class="stat-row">
                    <div class="stat-value"><?= htmlspecialchars((string)$expiringSoonCount, ENT_QUOTES, 'UTF-8') ?></div>
                    <span class="badge warning">24h</span>
                </div>
            </article>
            <article class="stat-card">
                <small>Expirados</small>
                <div class="stat-row">
                    <div class="stat-value"><?= htmlspecialchars((string)$expiredPendingCount, ENT_QUOTES, 'UTF-8') ?></div>
                    <span class="badge danger">pendientes</span>
                </div>
            </article>
        </section>

        <section class="content-grid">
            <section class="panel" id="generar">
                <div class="section-kicker">Nuevo acceso</div>
                <h3>Generar pase temporal</h3>
                <p class="section-copy">El pase quedara asociado a tu usuario y podra escanearse durante la ventana de vigencia que definas.</p>

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
                        <p>Se generara un QR unico listo para compartir con el visitante.</p>
                        <div class="cta-actions">
                            <button type="submit" class="primary">Crear pase</button>
                        </div>
                    </div>
                </form>
            </section>

            <aside class="detail-card" id="detalle">
                <div class="detail-top">
                    <div>
                        <div class="section-kicker">Detalle</div>
                        <h3>Pase seleccionado</h3>
                        <p>Consulta datos del pase y genera de nuevo su QR cuando lo necesites.</p>
                    </div>
                </div>

                <?php if ($selectedInvite === null): ?>
                    <p class="muted">Selecciona un pase de la tabla para ver su detalle aqui.</p>
                <?php else: ?>
                    <?php
                        $selectedValidTo = new DateTimeImmutable((string)$selectedInvite['valid_to']);
                        $selectedEffectiveStatus = invite_effective_status((string)$selectedInvite['status'], $selectedValidTo, $requestNow);
                    ?>
                    <div class="detail-grid">
                        <div class="detail-item">
                            <small>Visitante</small>
                            <strong><?= htmlspecialchars((string)$selectedInvite['visitor_name'], ENT_QUOTES, 'UTF-8') ?></strong>
                        </div>
                        <div class="detail-item">
                            <small>Estado</small>
                            <strong class="badge <?= htmlspecialchars(invite_status_badge_class($selectedEffectiveStatus), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($selectedEffectiveStatus, ENT_QUOTES, 'UTF-8') ?></strong>
                        </div>
                        <div class="detail-item">
                            <small>Code ID</small>
                            <strong><code id="detail-code-id"><?= htmlspecialchars((string)$selectedInvite['code_id'], ENT_QUOTES, 'UTF-8') ?></code></strong>
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
                        <button type="button" class="js-generate-detail-qr" data-code-id="<?= htmlspecialchars((string)$selectedInvite['code_id'], ENT_QUOTES, 'UTF-8') ?>">Generar QR del detalle</button>
                        <button type="button" id="btn-download-detail-qr" style="display:none;">Descargar PNG</button>
                        <div id="detail-qr-container" style="margin-top:12px;"></div>
                    </div>
                <?php endif; ?>
            </aside>
        </section>

        <section class="table-card" id="emitidos">
            <div class="table-head">
                <div>
                    <div class="section-kicker">Historico</div>
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
                            <th>Code ID</th>
                            <th>Vigencia</th>
                            <th>Estado</th>
                            <th>Emitido</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($invites as $row): ?>
                            <?php
                                $rowValidTo = new DateTimeImmutable((string)$row['valid_to']);
                                $rowEffectiveStatus = invite_effective_status((string)$row['status'], $rowValidTo, $requestNow);
                                $rowBadgeClass = invite_status_badge_class($rowEffectiveStatus);
                            ?>
                            <tr>
                                <td>
                                    <strong><?= htmlspecialchars((string)$row['visitor_name'], ENT_QUOTES, 'UTF-8') ?></strong>
                                    <div class="row-subtle"><?= htmlspecialchars((string)($row['visitor_phone'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                                </td>
                                <td><code><?= htmlspecialchars((string)$row['code_id'], ENT_QUOTES, 'UTF-8') ?></code></td>
                                <td>
                                    <div><?= htmlspecialchars((string)$row['valid_from'], ENT_QUOTES, 'UTF-8') ?></div>
                                    <div class="row-subtle">hasta <?= htmlspecialchars((string)$row['valid_to'], ENT_QUOTES, 'UTF-8') ?></div>
                                </td>
                                <td><span class="badge <?= htmlspecialchars($rowBadgeClass, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($rowEffectiveStatus, ENT_QUOTES, 'UTF-8') ?></span></td>
                                <td><?= htmlspecialchars((string)$row['issued_at'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td>
                                    <div class="table-actions">
                                        <a href="?invite_status=<?= urlencode($inviteStatusFilter) ?>&invite_id=<?= urlencode((string)$row['id']) ?>#detalle">Detalle</a>
                                        <button type="button" class="js-generate-table-qr" data-code-id="<?= htmlspecialchars((string)$row['code_id'], ENT_QUOTES, 'UTF-8') ?>">Generar QR</button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <div class="qr-box">
                    <button type="button" id="btn-download-table-qr" style="display:none;">Descargar QR seleccionado</button>
                    <div id="table-qr-container" style="margin-top:12px;"></div>
                </div>
            <?php endif; ?>
        </section>
    </main>
</div>

<script src="/admin/assets/js/qrcode.min.js"></script>
<script>
(() => {
    const generateBtn = document.getElementById('btn-generate-qr');
    const downloadBtn = document.getElementById('btn-download-qr');
    const codeNode = document.getElementById('new-pass-code-id');
    const qrContainer = document.getElementById('qr-container');
    const tableQrContainer = document.getElementById('table-qr-container');
    const tableDownloadBtn = document.getElementById('btn-download-table-qr');
    const detailQrContainer = document.getElementById('detail-qr-container');
    const detailDownloadBtn = document.getElementById('btn-download-detail-qr');

    let latestQrWrap = null;
    let latestCodeId = '';
    let latestTableQrWrap = null;
    let latestTableCodeId = '';
    let latestDetailQrWrap = null;
    let latestDetailCodeId = '';

    function renderQr(targetContainer, codeId) {
        if (!targetContainer || !codeId || typeof QRCode === 'undefined') {
            return null;
        }

        targetContainer.innerHTML = '';
        const qrWrap = document.createElement('div');
        targetContainer.appendChild(qrWrap);

        new QRCode(qrWrap, {
            text: codeId,
            width: 240,
            height: 240
        });

        return qrWrap;
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
            latestQrWrap = renderQr(qrContainer, latestCodeId);
            downloadBtn.style.display = latestQrWrap ? 'inline-block' : 'none';
        });

        downloadBtn.addEventListener('click', () => {
            downloadQr(latestQrWrap, latestCodeId);
        });
    }

    document.querySelectorAll('.js-generate-table-qr').forEach((button) => {
        button.addEventListener('click', () => {
            latestTableCodeId = button.getAttribute('data-code-id') || '';
            latestTableQrWrap = renderQr(tableQrContainer, latestTableCodeId);
            if (tableDownloadBtn) {
                tableDownloadBtn.style.display = latestTableQrWrap ? 'inline-block' : 'none';
            }
            const detailSection = document.getElementById('emitidos');
            if (detailSection) {
                detailSection.scrollIntoView({behavior: 'smooth', block: 'start'});
            }
        });
    });

    if (tableDownloadBtn) {
        tableDownloadBtn.addEventListener('click', () => {
            downloadQr(latestTableQrWrap, latestTableCodeId);
        });
    }

    document.querySelectorAll('.js-generate-detail-qr').forEach((button) => {
        button.addEventListener('click', () => {
            latestDetailCodeId = button.getAttribute('data-code-id') || '';
            latestDetailQrWrap = renderQr(detailQrContainer, latestDetailCodeId);
            if (detailDownloadBtn) {
                detailDownloadBtn.style.display = latestDetailQrWrap ? 'inline-block' : 'none';
            }
        });
    });

    if (detailDownloadBtn) {
        detailDownloadBtn.addEventListener('click', () => {
            downloadQr(latestDetailQrWrap, latestDetailCodeId);
        });
    }
})();
</script>
</body>
</html>
