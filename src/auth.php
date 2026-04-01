<?php

declare(strict_types=1);

const AUTH_ROLE_ADMIN = 'ADMIN';
const AUTH_ROLE_EMPLOYEE = 'EMPLOYEE';

function auth_request_is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') {
        return true;
    }

    $forwardedProto = strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));

    return $forwardedProto === 'https';
}

function auth_session_start(array $config): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $sessionName = trim((string)($config['auth']['session_name'] ?? 'HACCESOSESSID'));

    if ($sessionName !== '') {
        session_name($sessionName);
    }

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => auth_request_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

function auth_ensure_schema(PDO $pdo): void
{
    static $schemaReady = false;

    if ($schemaReady) {
        return;
    }

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS app_users (
            id CHAR(36) NOT NULL,
            username VARCHAR(64) NOT NULL,
            display_name VARCHAR(255) NULL,
            password_hash VARCHAR(255) NOT NULL,
            role ENUM(\'ADMIN\',\'EMPLOYEE\') NOT NULL,
            employee_uid VARCHAR(64) NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            last_login_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_app_users_username (username),
            UNIQUE KEY uq_app_users_employee_uid (employee_uid),
            KEY idx_app_users_role_active (role, is_active),
            CONSTRAINT fk_app_users_employee
                FOREIGN KEY (employee_uid) REFERENCES employees(uid)
                ON UPDATE CASCADE ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $schemaReady = true;
}

function auth_has_users(PDO $pdo): bool
{
    auth_ensure_schema($pdo);

    return (int)$pdo->query('SELECT COUNT(*) FROM app_users')->fetchColumn() > 0;
}

function auth_bootstrap_admin_if_configured(PDO $pdo, array $config): bool
{
    auth_ensure_schema($pdo);

    if (auth_has_users($pdo)) {
        return false;
    }

    $username = trim((string)($config['auth']['bootstrap_admin_username'] ?? ''));
    $password = (string)($config['auth']['bootstrap_admin_password'] ?? '');

    if ($username === '' || $password === '') {
        return false;
    }

    $displayName = normalize_optional_string($config['auth']['bootstrap_admin_name'] ?? null) ?? 'Administrador inicial';
    $now = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');

    $stmt = $pdo->prepare(
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
            NULL,
            1,
            NULL,
            :created_at,
            :updated_at
        )'
    );
    $stmt->execute([
        'id' => uuid_v4(),
        'username' => $username,
        'display_name' => $displayName,
        'password_hash' => password_hash($password, PASSWORD_BCRYPT),
        'role' => AUTH_ROLE_ADMIN,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return true;
}

function auth_role_options(): array
{
    return [AUTH_ROLE_ADMIN, AUTH_ROLE_EMPLOYEE];
}

function auth_is_valid_role(string $role): bool
{
    return in_array($role, auth_role_options(), true);
}

function auth_fetch_user_record_by_id(PDO $pdo, string $id): ?array
{
    auth_ensure_schema($pdo);

    $stmt = $pdo->prepare(
        'SELECT
            app_users.id,
            app_users.username,
            app_users.display_name,
            app_users.password_hash,
            app_users.role,
            app_users.employee_uid,
            app_users.is_active,
            app_users.last_login_at,
            app_users.created_at,
            app_users.updated_at,
            employees.display_name AS employee_display_name,
            employees.is_active AS employee_is_active
         FROM app_users
         LEFT JOIN employees
            ON employees.uid = app_users.employee_uid
         WHERE app_users.id = :id
         LIMIT 1'
    );
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch();

    return is_array($row) ? $row : null;
}

function auth_fetch_user_record_by_username(PDO $pdo, string $username): ?array
{
    auth_ensure_schema($pdo);

    $stmt = $pdo->prepare(
        'SELECT
            app_users.id,
            app_users.username,
            app_users.display_name,
            app_users.password_hash,
            app_users.role,
            app_users.employee_uid,
            app_users.is_active,
            app_users.last_login_at,
            app_users.created_at,
            app_users.updated_at,
            employees.display_name AS employee_display_name,
            employees.is_active AS employee_is_active
         FROM app_users
         LEFT JOIN employees
            ON employees.uid = app_users.employee_uid
         WHERE app_users.username = :username
         LIMIT 1'
    );
    $stmt->execute(['username' => $username]);
    $row = $stmt->fetch();

    return is_array($row) ? $row : null;
}

function auth_fetch_user_record_by_employee_uid(PDO $pdo, string $employeeUid): ?array
{
    auth_ensure_schema($pdo);

    $stmt = $pdo->prepare(
        'SELECT
            app_users.id,
            app_users.username,
            app_users.display_name,
            app_users.password_hash,
            app_users.role,
            app_users.employee_uid,
            app_users.is_active,
            app_users.last_login_at,
            app_users.created_at,
            app_users.updated_at,
            employees.display_name AS employee_display_name,
            employees.is_active AS employee_is_active
         FROM app_users
         LEFT JOIN employees
            ON employees.uid = app_users.employee_uid
         WHERE app_users.employee_uid = :employee_uid
         LIMIT 1'
    );
    $stmt->execute(['employee_uid' => $employeeUid]);
    $row = $stmt->fetch();

    return is_array($row) ? $row : null;
}

function auth_normalize_user_row(array $row): ?array
{
    $role = strtoupper(trim((string)($row['role'] ?? '')));

    if (!auth_is_valid_role($role)) {
        return null;
    }

    if ((int)($row['is_active'] ?? 0) !== 1) {
        return null;
    }

    $employeeUid = normalize_optional_string($row['employee_uid'] ?? null);
    $employeeDisplayName = normalize_optional_string($row['employee_display_name'] ?? null);
    $employeeIsActive = array_key_exists('employee_is_active', $row) && $row['employee_is_active'] !== null
        ? (int)$row['employee_is_active']
        : null;

    if ($role === AUTH_ROLE_EMPLOYEE) {
        if ($employeeUid === null || $employeeIsActive !== 1) {
            return null;
        }
    }

    $displayName = normalize_optional_string($row['display_name'] ?? null);
    $resolvedDisplayName = $role === AUTH_ROLE_EMPLOYEE
        ? ($employeeDisplayName ?? $displayName ?? (string)$row['username'])
        : ($displayName ?? (string)$row['username']);

    return [
        'id' => (string)$row['id'],
        'username' => (string)$row['username'],
        'display_name' => $displayName,
        'resolved_display_name' => $resolvedDisplayName,
        'role' => $role,
        'employee_uid' => $employeeUid,
        'last_login_at' => normalize_optional_string($row['last_login_at'] ?? null),
        'created_at' => normalize_optional_string($row['created_at'] ?? null),
        'updated_at' => normalize_optional_string($row['updated_at'] ?? null),
    ];
}

function auth_authenticate(PDO $pdo, string $username, string $password): ?array
{
    $username = trim($username);

    if ($username === '' || $password === '') {
        return null;
    }

    $row = auth_fetch_user_record_by_username($pdo, $username);

    if (!is_array($row)) {
        return null;
    }

    if (!password_verify($password, (string)$row['password_hash'])) {
        return null;
    }

    if (password_needs_rehash((string)$row['password_hash'], PASSWORD_BCRYPT)) {
        $rehashStmt = $pdo->prepare(
            'UPDATE app_users
             SET password_hash = :password_hash, updated_at = :updated_at
             WHERE id = :id'
        );
        $rehashStmt->execute([
            'password_hash' => password_hash($password, PASSWORD_BCRYPT),
            'updated_at' => (new DateTimeImmutable('now'))->format('Y-m-d H:i:s'),
            'id' => (string)$row['id'],
        ]);
        $row = auth_fetch_user_record_by_id($pdo, (string)$row['id']) ?? $row;
    }

    $user = auth_normalize_user_row($row);

    if ($user === null) {
        return null;
    }

    $lastLoginAt = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
    $updateStmt = $pdo->prepare(
        'UPDATE app_users
         SET last_login_at = :last_login_at
         WHERE id = :id'
    );
    $updateStmt->execute([
        'last_login_at' => $lastLoginAt,
        'id' => $user['id'],
    ]);

    return auth_fetch_current_user_by_id($pdo, $user['id']);
}

function auth_fetch_current_user_by_id(PDO $pdo, string $userId): ?array
{
    $row = auth_fetch_user_record_by_id($pdo, $userId);

    if (!is_array($row)) {
        return null;
    }

    return auth_normalize_user_row($row);
}

function auth_login_user(array $config, array $user): void
{
    auth_session_start($config);
    session_regenerate_id(true);
    $_SESSION['auth_user_id'] = $user['id'];
}

function auth_logout_user(array $config): void
{
    auth_session_start($config);
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], (bool)$params['secure'], (bool)$params['httponly']);
    }

    session_destroy();
}

function auth_current_user(PDO $pdo, array $config): ?array
{
    auth_session_start($config);

    $userId = $_SESSION['auth_user_id'] ?? null;

    if (!is_string($userId) || $userId === '') {
        return null;
    }

    $user = auth_fetch_current_user_by_id($pdo, $userId);

    if ($user === null) {
        auth_logout_user($config);
    }

    return $user;
}

function auth_current_request_path(): string
{
    $requestUri = (string)($_SERVER['REQUEST_URI'] ?? '/');

    if ($requestUri === '') {
        return '/';
    }

    return $requestUri[0] === '/' ? $requestUri : '/' . $requestUri;
}

function auth_redirect(string $path): void
{
    header('Location: ' . $path);
    exit;
}

function auth_dashboard_path(array $user): string
{
    return $user['role'] === AUTH_ROLE_ADMIN ? '/admin/users.php' : '/employee/passes.php';
}

function auth_normalize_redirect(?string $redirect, ?array $user = null): string
{
    $redirect = trim((string)$redirect);

    if ($redirect === '' || $redirect[0] !== '/' || substr($redirect, 0, 2) === '//') {
        return $user !== null ? auth_dashboard_path($user) : '/login.php';
    }

    $path = parse_url($redirect, PHP_URL_PATH);

    if (!is_string($path) || $path === '') {
        return $user !== null ? auth_dashboard_path($user) : '/login.php';
    }

    if ($path === '/login.php' || $path === '/logout.php') {
        return $user !== null ? auth_dashboard_path($user) : '/login.php';
    }

    return $redirect;
}

function auth_require_roles(PDO $pdo, array $config, array $roles): array
{
    $user = auth_current_user($pdo, $config);

    if ($user === null) {
        auth_redirect('/login.php?redirect=' . urlencode(auth_current_request_path()));
    }

    if (!in_array($user['role'], $roles, true)) {
        http_response_code(403);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html lang="es"><head><meta charset="utf-8"><title>Acceso denegado</title></head><body style="font-family: Arial, sans-serif; margin: 32px;">';
        echo '<h1>Acceso denegado</h1>';
        echo '<p>Tu usuario no tiene permisos para abrir esta pagina.</p>';
        echo '<p><a href="' . htmlspecialchars(auth_dashboard_path($user), ENT_QUOTES, 'UTF-8') . '">Ir a tu panel</a></p>';
        echo '</body></html>';
        exit;
    }

    return $user;
}

function auth_csrf_token(array $config): string
{
    auth_session_start($config);

    $token = $_SESSION['csrf_token'] ?? null;

    if (!is_string($token) || $token === '') {
        $token = bin2hex(random_bytes(32));
        $_SESSION['csrf_token'] = $token;
    }

    return $token;
}

function auth_csrf_input(array $config): string
{
    return '<input type="hidden" name="_csrf" value="' . htmlspecialchars(auth_csrf_token($config), ENT_QUOTES, 'UTF-8') . '">';
}

function auth_require_csrf_token(array $config): void
{
    auth_session_start($config);

    $submittedToken = trim((string)($_POST['_csrf'] ?? ''));
    $sessionToken = $_SESSION['csrf_token'] ?? null;

    if (!is_string($sessionToken) || $sessionToken === '' || $submittedToken === '' || !hash_equals($sessionToken, $submittedToken)) {
        throw new RuntimeException('La sesion expiro o el formulario no es valido. Recarga la pagina e intenta de nuevo.');
    }
}

function auth_fetch_app_users(PDO $pdo): array
{
    auth_ensure_schema($pdo);

    $stmt = $pdo->query(
        'SELECT
            app_users.id,
            app_users.username,
            app_users.display_name,
            app_users.role,
            app_users.employee_uid,
            app_users.is_active,
            app_users.last_login_at,
            app_users.created_at,
            app_users.updated_at,
            employees.display_name AS employee_display_name,
            employees.is_active AS employee_is_active
         FROM app_users
         LEFT JOIN employees
            ON employees.uid = app_users.employee_uid
         ORDER BY app_users.role ASC, app_users.username ASC'
    );

    return $stmt->fetchAll();
}
