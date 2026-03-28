<?php

declare(strict_types=1);

$config = require __DIR__ . '/config.php';

date_default_timezone_set($config['app_timezone']);

function json_response(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function request_json_body(): array
{
    $raw = file_get_contents('php://input');

    if ($raw === false || $raw === '') {
        return [];
    }

    $data = json_decode($raw, true);

    return is_array($data) ? $data : [];
}

function request_header(string $name): ?string
{
    $normalized = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    $value = $_SERVER[$normalized] ?? null;

    return is_string($value) && $value !== '' ? $value : null;
}

function normalize_optional_string(mixed $value): ?string
{
    if (!is_string($value)) {
        return null;
    }

    $normalized = trim($value);

    return $normalized === '' ? null : $normalized;
}

function db_pdo(array $config): PDO
{
    $db = $config['db'];

    if ($db['name'] === '' || $db['user'] === '') {
        throw new RuntimeException('DB_NAME y DB_USER son obligatorios.');
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=%s',
        $db['host'],
        $db['port'],
        $db['name'],
        $db['charset']
    );

    $pdo = new PDO($dsn, $db['user'], $db['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    $pdo->exec($config['db_timezone_sql']);

    return $pdo;
}

function uuid_v4(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
}

function random_token(int $byteLength = 24): string
{
    return rtrim(strtr(base64_encode(random_bytes($byteLength)), '+/', '-_'), '=');
}
