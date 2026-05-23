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
        $employeeStmt->execute(['uid' => $currentEmployeeUid]);
        $employeeRow = $employeeStmt->fetch();

        if (!is_array($employeeRow)) {
            throw new RuntimeException('Tu usuario ya no tiene un employee_uid activo.');
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
            'issued_by_employee_uid' => $currentEmployeeUid,
            'issued_at' => $now->format('Y-m-d H:i:s'),
            'created_at' => $now->format('Y-m-d H:i:s'),
            'updated_at' => $now->format('Y-m-d H:i:s'),
        ]);

        $generatedPassCodeId = $codeId;
        $selectedInviteId = $inviteId;
        $_SESSION['employee_pass_flash_success'] = 'Pase de acceso creado correctamente.';
        $_SESSION['employee_pass_flash_code_id'] = $codeId;

        $redirectQuery = [
            'invite_status' => $inviteStatusFilter,
            'invite_id' => $selectedInviteId,
        ];
        auth_redirect('/employee/passes.php?' . http_build_query($redirectQuery) . '#detalle');
    } catch (Throwable $exception) {
        if ($pdo instanceof PDO && $pdo->inTransaction()) {
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
        'SELECT id, code_id, visitor_name, companions_expected, valid_from, valid_to, status, issued_at
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

        .brand p {
            margin: 4px 0 0;
            color: rgba(238, 248, 243, 0.72);
            font-size: 13px;
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

        .sidebar-card small,
        .helper small {
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
            line-height: 1.45;
        }

        .nav-group {
            padding: 12px;
        }

        .nav-link {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 14px 14px;
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

        .helper p {
            margin: 0 0 14px;
            color: rgba(238, 248, 243, 0.76);
            font-size: 14px;
            line-height: 1.5;
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

        .topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 18px;
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
            line-height: 1.5;
        }

        .top-actions {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
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
        .table-actions button {
            font: inherit;
        }

        .top-actions button {
            border: 0;
            border-radius: 16px;
            padding: 13px 18px;
            font-weight: 800;
            cursor: pointer;
        }

        .top-actions .primary,
        .cta-actions .primary {
            color: #fff;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-strong) 100%);
            box-shadow: 0 14px 28px rgba(29, 143, 107, 0.24);
        }

        .top-actions .secondary,
        .cta-actions .secondary,
        .table-actions a,
        .table-actions button,
        .filter-submit {
            color: var(--text);
            background: rgba(255, 255, 255, 0.7);
            border: 1px solid var(--line);
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }

        .title-block h2:only-child {
            margin-bottom: 0;
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

        .stat-row,
        .detail-top,
        .table-head,
        .cta-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
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

        .stat-note,
        .row-subtle,
        .muted {
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
        .filter-row select,
        .filter-submit {
            font: inherit;
        }

        .field input {
            width: 100%;
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
            line-height: 1.45;
        }

        .cta-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .cta-actions button {
            border-radius: 15px;
            padding: 13px 18px;
            font-weight: 800;
            cursor: pointer;
        }

        .detail-top {
            align-items: flex-start;
            margin-bottom: 18px;
        }

        .detail-status {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .qr-preview {
            display: grid;
            place-items: center;
            min-height: 238px;
            margin-bottom: 18px;
            border-radius: 24px;
            background: linear-gradient(180deg, rgba(219, 244, 234, 0.72) 0%, rgba(255, 255, 255, 0.92) 100%);
            border: 1px dashed rgba(29, 143, 107, 0.25);
        }

        .qr-preview img,
        .qr-preview canvas {
            width: min(220px, 100%);
            height: auto;
            background: #fff;
            padding: 14px;
            border-radius: 24px;
            box-shadow: 0 16px 30px rgba(22, 48, 40, 0.12);
        }

        .qr-preview-empty {
            color: var(--muted);
            text-align: center;
            max-width: 220px;
        }

        .detail-list {
            display: grid;
            gap: 12px;
        }

        .detail-item {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 16px;
            padding-bottom: 12px;
            border-bottom: 1px solid var(--line);
        }

        .detail-item:last-child {
            padding-bottom: 0;
            border-bottom: 0;
        }

        .detail-item small {
            display: block;
            margin-bottom: 6px;
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: 0.08em;
            font-size: 11px;
        }

        .detail-item strong {
            font-size: 15px;
        }

        .search,
        .filter-row select {
            border: 1px solid var(--line);
            background: rgba(255, 255, 255, 0.86);
            border-radius: 15px;
            padding: 12px 14px;
        }

        .filter-row {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 14px;
        }

        .search {
            min-width: 240px;
            flex: 1 1 240px;
        }

        .filter-submit {
            border-radius: 15px;
            padding: 12px 16px;
            cursor: pointer;
            font-weight: 700;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th,
        td {
            padding: 14px 12px;
            text-align: left;
            border-bottom: 1px solid var(--line);
            vertical-align: top;
        }

        th {
            color: var(--muted);
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.08em;
        }

        tbody tr:hover {
            background: rgba(255, 255, 255, 0.48);
        }

        .row-title {
            font-weight: 800;
            margin-bottom: 4px;
        }

        .table-actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .table-actions a,
        .table-actions button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 12px;
            padding: 9px 12px;
            font-size: 13px;
            font-weight: 700;
            text-decoration: none;
            cursor: pointer;
        }

        .ok,
        .err {
            border-radius: var(--radius-md);
            padding: 14px 16px;
            margin-bottom: 18px;
        }

        .ok {
            background: var(--ok-bg);
            border: 1px solid var(--ok-border);
        }

        .err {
            background: var(--err-bg);
            border: 1px solid var(--err-border);
        }

        .inline-form {
            margin: 0;
        }

        .sr-only {
            position: absolute;
            width: 1px;
            height: 1px;
            padding: 0;
            margin: -1px;
            overflow: hidden;
            clip: rect(0, 0, 0, 0);
            white-space: nowrap;
            border: 0;
        }

        @media (max-width: 1220px) {
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

        @media (max-width: 900px) {
            .app-shell {
                grid-template-columns: 1fr;
            }

            .content {
                padding: 20px;
            }

            .topbar {
                flex-direction: column;
                align-items: stretch;
            }
        }

        @media (max-width: 720px) {
            .stats-grid,
            .form-grid {
                grid-template-columns: 1fr;
            }

            .table-head,
            .cta-row {
                flex-direction: column;
                align-items: stretch;
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
            </div>
            <div class="top-actions">
                <div class="ghost-chip">Sesion activa: <strong><?= htmlspecialchars($currentDisplayName, ENT_QUOTES, 'UTF-8') ?></strong></div>
                <button type="button" class="primary" onclick="document.getElementById('visitor_name').focus();">Nuevo pase</button>
            </div>
        </header>

        <section class="stats-grid" aria-label="Resumen">
            <article class="stat-card">
                <small>Pases vigentes</small>
                <div class="stat-row">
                    <div class="stat-value"><?= htmlspecialchars((string)$activeInvitesCount, ENT_QUOTES, 'UTF-8') ?></div>
                    <span class="badge success">Activos</span>
                </div>
                <div class="stat-note">Accesos disponibles en este momento.</div>
            </article>

            <article class="stat-card">
                <small>Emitidos hoy</small>
                <div class="stat-row">
                    <div class="stat-value"><?= htmlspecialchars((string)$issuedTodayCount, ENT_QUOTES, 'UTF-8') ?></div>
                    <span class="badge info">Hoy</span>
                </div>
                <div class="stat-note">Volumen diario de pases generados.</div>
            </article>

            <article class="stat-card">
                <small>Por vencer</small>
                <div class="stat-row">
                    <div class="stat-value"><?= htmlspecialchars((string)$expiringSoonCount, ENT_QUOTES, 'UTF-8') ?></div>
                    <span class="badge warning">24 horas</span>
                </div>
                <div class="stat-note">Pases activos que vencen pronto.</div>
            </article>

            <article class="stat-card">
                <small>Vencidos</small>
                <div class="stat-row">
                    <div class="stat-value"><?= htmlspecialchars((string)$expiredPendingCount, ENT_QUOTES, 'UTF-8') ?></div>
                    <span class="badge danger">Revisar</span>
                </div>
                <div class="stat-note">Pases que ya expiraron y siguen en estado activo.</div>
            </article>
        </section>

        <?php if ($success !== null): ?>
            <div class="ok">
                <strong>Exito:</strong> <?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <?php if ($error !== null): ?>
            <div class="err">
                <strong>Error:</strong> <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <section class="content-grid">
            <article class="panel" id="generar">
                <div class="section-kicker">Seccion principal</div>
                <h3>Generar pase</h3>
                <p class="section-copy">Usa solo los datos necesarios. La vigencia se completa automaticamente a 24 horas desde el momento actual, pero puedes ajustarla si hace falta.</p>

                <form method="post">
                    <?= auth_csrf_input($config) ?>
                    <input type="hidden" name="action" value="create_invite">

                    <div class="form-grid">
                        <div class="field full">
                            <label for="visitor_name">Nombre del visitante</label>
                            <input id="visitor_name" name="visitor_name" type="text" required placeholder="Juan Perez" value="<?= htmlspecialchars($formVisitorName, ENT_QUOTES, 'UTF-8') ?>">
                        </div>

                        <div class="field">
                            <label for="visitor_phone">Telefono</label>
                            <input id="visitor_phone" name="visitor_phone" type="text" placeholder="+52 55 1234 5678" value="<?= htmlspecialchars($formVisitorPhone, ENT_QUOTES, 'UTF-8') ?>">
                        </div>

                        <div class="field">
                            <label for="companions_expected">Acompanantes</label>
                            <input id="companions_expected" name="companions_expected" type="text" inputmode="numeric" required value="<?= htmlspecialchars($formCompanionsExpected, ENT_QUOTES, 'UTF-8') ?>">
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
                        <p>Al confirmar, el sistema muestra el QR, permite descargarlo y deja el pase visible en la lista reciente para consulta inmediata.</p>
                        <div class="cta-actions">
                            <button type="button" class="secondary" id="btn-reset-default-dates">Restablecer vigencia</button>
                            <button type="submit" class="primary">Generar pase y mostrar QR</button>
                        </div>
                    </div>
                </form>
            </article>

            <aside class="detail-card" id="detalle">
                <?php
                    $detailInvite = $selectedInvite;
                    $detailStatusLabel = 'Listo';
                    $detailStatusClass = 'info';
                    $detailVisitorName = 'Selecciona un pase para ver el detalle';
                    $detailCompanions = '';
                    $detailWindow = 'Sin datos';
                    $detailWindowNote = 'El QR aparecera aqui';
                    $detailPhone = 'No disponible';
                    $detailUpdatedAt = 'No disponible';

                    if ($detailInvite !== null) {
                        $detailStatus = (string)$detailInvite['status'];
                        $detailValidTo = new DateTimeImmutable((string)$detailInvite['valid_to']);
                        $detailStatusLabel = invite_effective_status($detailStatus, $detailValidTo, $requestNow);
                        $detailStatusClass = invite_status_badge_class($detailStatusLabel);
                        $detailVisitorName = (string)$detailInvite['visitor_name'];
                        $detailCompanions = (string)$detailInvite['companions_expected'] . ' acompanantes';
                        $detailWindow =
                            (new DateTimeImmutable((string)$detailInvite['valid_from']))->format('d/m/Y H:i') .
                            ' - ' .
                            $detailValidTo->format('d/m/Y H:i');
                        $detailWindowNote = $detailStatusLabel === 'EXPIRED' ? 'Este pase ya vencio.' : 'Ventana de acceso vigente.';
                        $detailPhone = (string)($detailInvite['visitor_phone'] ?? '') !== ''
                            ? (string)$detailInvite['visitor_phone']
                            : 'No disponible';
                        $detailUpdatedAt = (new DateTimeImmutable((string)$detailInvite['updated_at']))->format('d/m/Y H:i');
                    }
                ?>
                <div class="detail-top">
                    <div>
                        <h3>Detalle y QR</h3>
                    </div>
                    <?php if ($generatedPassCodeId !== null || $selectedInvite !== null): ?>
                        <div class="detail-status">
                            <span class="badge <?= htmlspecialchars($detailStatusClass, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($detailStatusLabel, ENT_QUOTES, 'UTF-8') ?></span>
                            <span class="badge info">QR listo</span>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="qr-preview" id="detail-qr-container" data-code-id="<?= htmlspecialchars((string)($generatedPassCodeId ?? ($selectedInvite['code_id'] ?? '')), ENT_QUOTES, 'UTF-8') ?>">
                    <div class="qr-preview-empty" id="detail-qr-empty">Selecciona o genera un pase para visualizar el QR.</div>
                </div>

                <div class="detail-list">
                    <div class="detail-item">
                        <div>
                            <small>Visitante</small>
                            <strong><?= htmlspecialchars($detailVisitorName, ENT_QUOTES, 'UTF-8') ?></strong>
                        </div>
                        <div class="row-subtle"><?= htmlspecialchars($detailCompanions, ENT_QUOTES, 'UTF-8') ?></div>
                    </div>
                    <div class="detail-item">
                        <div>
                            <small>Ventana de acceso</small>
                            <strong><?= htmlspecialchars($detailWindow, ENT_QUOTES, 'UTF-8') ?></strong>
                        </div>
                        <div class="row-subtle"><?= htmlspecialchars($detailWindowNote, ENT_QUOTES, 'UTF-8') ?></div>
                    </div>
                    <div class="detail-item">
                        <div>
                            <small>Telefono</small>
                            <strong><?= htmlspecialchars($detailPhone, ENT_QUOTES, 'UTF-8') ?></strong>
                        </div>
                        <div class="row-subtle">Contacto visible para operacion</div>
                    </div>
                    <div class="detail-item">
                        <div>
                            <small>Actualizado</small>
                            <strong><?= htmlspecialchars($detailUpdatedAt, ENT_QUOTES, 'UTF-8') ?></strong>
                        </div>
                        <div class="row-subtle">Ultima referencia disponible</div>
                    </div>
                    <div class="detail-item">
                        <div>
                            <small>Acciones</small>
                            <strong>Descargar o ampliar QR</strong>
                        </div>
                        <div class="row-subtle">
                            <button type="button" id="btn-download-detail-qr" class="filter-submit" style="display:none;">Descargar PNG</button>
                        </div>
                    </div>
                </div>
            </aside>
        </section>

        <section class="table-card" id="emitidos">
            <div class="table-head">
                <div>
                    <h3>Pases emitidos</h3>
                </div>
                <div class="ghost-chip">Total cargados: <strong><?= htmlspecialchars((string)count($invites), ENT_QUOTES, 'UTF-8') ?></strong></div>
            </div>

            <form method="get">
                <div class="filter-row">
                    <label class="sr-only" for="invite_status">Filtrar estado</label>
                    <select id="invite_status" name="invite_status">
                        <option value="ALL" <?= $inviteStatusFilter === 'ALL' ? 'selected' : '' ?>>Todos</option>
                        <option value="ACTIVE" <?= $inviteStatusFilter === 'ACTIVE' ? 'selected' : '' ?>>Activos</option>
                        <option value="USED" <?= $inviteStatusFilter === 'USED' ? 'selected' : '' ?>>Usados</option>
                        <option value="REVOKED" <?= $inviteStatusFilter === 'REVOKED' ? 'selected' : '' ?>>Revocados</option>
                        <option value="EXPIRED" <?= $inviteStatusFilter === 'EXPIRED' ? 'selected' : '' ?>>Expirados</option>
                    </select>
                    <button type="submit" class="filter-submit">Aplicar filtro</button>
                </div>
            </form>

            <table>
                <thead>
                <tr>
                    <th>Visitante</th>
                    <th>Vigencia</th>
                    <th>Estado</th>
                    <th>Emitido en</th>
                    <th>Acciones</th>
                </tr>
                </thead>
                <tbody>
                    <?php foreach ($invites as $row): ?>
                    <?php
                        $status = (string)$row['status'];
                        $validTo = new DateTimeImmutable((string)$row['valid_to']);
                        $validFrom = new DateTimeImmutable((string)$row['valid_from']);
                        $issuedAt = new DateTimeImmutable((string)$row['issued_at']);
                        $statusLabel = invite_effective_status($status, $validTo, $requestNow);
                        $statusClass = invite_status_badge_class($statusLabel);
                    ?>
                    <tr>
                        <td>
                            <div class="row-title"><?= htmlspecialchars((string)$row['visitor_name'], ENT_QUOTES, 'UTF-8') ?></div>
                            <div class="row-subtle"><?= htmlspecialchars((string)$row['companions_expected'], ENT_QUOTES, 'UTF-8') ?> acompanantes</div>
                        </td>
                        <td>
                            <div class="row-title"><?= htmlspecialchars($validFrom->format('d/m/Y H:i'), ENT_QUOTES, 'UTF-8') ?> - <?= htmlspecialchars($validTo->format('d/m/Y H:i'), ENT_QUOTES, 'UTF-8') ?></div>
                            <div class="row-subtle"><?= $statusLabel === 'EXPIRED' ? 'Ya vencido' : 'Ventana configurada' ?></div>
                        </td>
                        <td><span class="badge <?= htmlspecialchars($statusClass, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8') ?></span></td>
                        <td>
                            <div class="row-title"><?= htmlspecialchars($issuedAt->format('d/m/Y H:i'), ENT_QUOTES, 'UTF-8') ?></div>
                            <div class="row-subtle">Generado por ti</div>
                        </td>
                        <td>
                            <div class="table-actions">
                                <a href="?invite_status=<?= urlencode($inviteStatusFilter) ?>&invite_id=<?= urlencode((string)$row['id']) ?>#detalle">Ver detalle</a>
                                <button type="button" class="js-generate-table-qr" data-code-id="<?= htmlspecialchars((string)$row['code_id'], ENT_QUOTES, 'UTF-8') ?>">Ver QR</button>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </section>
    </main>
</div>

<script src="/admin/assets/js/qrcode.min.js"></script>
<script>
(() => {
    const validFromInput = document.getElementById('valid_from');
    const validToInput = document.getElementById('valid_to');
    const resetDatesButton = document.getElementById('btn-reset-default-dates');
    const detailQrContainer = document.getElementById('detail-qr-container');
    const detailQrEmpty = document.getElementById('detail-qr-empty');
    const detailQrDownloadButton = document.getElementById('btn-download-detail-qr');

    function toDateTimeLocalValue(date) {
        const pad = (value) => String(value).padStart(2, '0');

        return [
            date.getFullYear(),
            pad(date.getMonth() + 1),
            pad(date.getDate()),
        ].join('-') + 'T' + [
            pad(date.getHours()),
            pad(date.getMinutes()),
        ].join(':');
    }

    function fillDefaultDates(force) {
        if (!validFromInput || !validToInput) {
            return;
        }

        const now = new Date();
        const plus24Hours = new Date(now.getTime() + (24 * 60 * 60 * 1000));

        if (force || !validFromInput.value) {
            validFromInput.value = toDateTimeLocalValue(now);
        }

        if (force || !validToInput.value) {
            validToInput.value = toDateTimeLocalValue(plus24Hours);
        }
    }

    function mountQr(container, codeId) {
        if (!container || !codeId || typeof QRCode === 'undefined') {
            return null;
        }

        container.innerHTML = '';
        const qrWrap = document.createElement('div');
        container.appendChild(qrWrap);

        new QRCode(qrWrap, {
            text: codeId,
            width: 220,
            height: 220,
        });

        return qrWrap;
    }

    function bindDownload(button, getWrap, getCodeId) {
        if (!button) {
            return;
        }

        button.addEventListener('click', () => {
            const qrWrap = getWrap();
            const codeId = getCodeId();

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
        });
    }

    let currentDetailQrWrap = null;
    let currentDetailCodeId = '';

    function renderDetailQr(codeId) {
        if (!detailQrContainer) {
            return;
        }

        if (!codeId) {
            detailQrContainer.innerHTML = '';
            if (detailQrEmpty) {
                detailQrContainer.appendChild(detailQrEmpty);
            }
            if (detailQrDownloadButton) {
                detailQrDownloadButton.style.display = 'none';
            }
            currentDetailQrWrap = null;
            currentDetailCodeId = '';
            return;
        }

        currentDetailCodeId = codeId;
        currentDetailQrWrap = mountQr(detailQrContainer, codeId);

        setTimeout(() => {
            if (!currentDetailQrWrap || !detailQrDownloadButton) {
                return;
            }

            const canvas = currentDetailQrWrap.querySelector('canvas');
            const img = currentDetailQrWrap.querySelector('img');
            detailQrDownloadButton.style.display = canvas || img ? 'inline-block' : 'none';
        }, 0);
    }

    fillDefaultDates(false);

    if (resetDatesButton) {
        resetDatesButton.addEventListener('click', () => {
            fillDefaultDates(true);
        });
    }

    bindDownload(
        detailQrDownloadButton,
        () => currentDetailQrWrap,
        () => currentDetailCodeId
    );

    document.querySelectorAll('.js-generate-table-qr').forEach((button) => {
        button.addEventListener('click', () => {
            const codeId = button.getAttribute('data-code-id') || '';
            renderDetailQr(codeId);
            const detailSection = document.getElementById('detalle');
            if (detailSection) {
                detailSection.scrollIntoView({behavior: 'smooth', block: 'start'});
            }
        });
    });

    if (detailQrContainer) {
        const initialCodeId = detailQrContainer.getAttribute('data-code-id') || '';
        renderDetailQr(initialCodeId);
    }
})();
</script>
</body>
</html>
