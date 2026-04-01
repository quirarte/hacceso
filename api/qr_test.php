<?php
header('Content-Type: application/json; charset=utf-8');

// Permitir pruebas simples (opcional)
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Responder preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    echo json_encode(['ok' => true, 'message' => 'preflight']);
    exit;
}

date_default_timezone_set('UTC');

$method = $_SERVER['REQUEST_METHOD'];
$raw = file_get_contents('php://input');

// Si viene JSON (como lo envía el ESP32)
$data = json_decode($raw, true);

// Si no vino JSON válido, intenta POST normal
if (!is_array($data)) {
    $data = $_POST;
}

$qr = isset($data['qr']) ? trim((string)$data['qr']) : '';
$device = isset($data['device']) ? trim((string)$data['device']) : 'esp32-devkitc-v4';

// Log simple para verificar recepción
$logDir = __DIR__ . '/logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0775, true);
}
$logFile = $logDir . '/qr_test.log';

$line = sprintf(
    "[%s] ip=%s device=%s qr=%s\n",
    gmdate('Y-m-d H:i:s'),
    $_SERVER['REMOTE_ADDR'] ?? 'unknown',
    $device,
    $qr
);
@file_put_contents($logFile, $line, FILE_APPEND);

// Validación mínima
if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'ok' => false,
        'error' => 'Metodo no permitido, usa POST'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($qr === '') {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => 'Campo qr vacio o faltante'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Respuesta OK
http_response_code(200);
echo json_encode([
    'ok' => true,
    'message' => 'QR recibido correctamente',
    'qr' => $qr,
    'qr_length' => strlen($qr),
    'device' => $device,
    'server_time_utc' => gmdate('c')
], JSON_UNESCAPED_UNICODE);