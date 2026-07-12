<?php

declare(strict_types=1);

require __DIR__ . '/../../src/bootstrap.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

try {
    $pdo = db_pdo($config);
    auth_require_roles($pdo, $config, [AUTH_ROLE_ADMIN, AUTH_ROLE_EMPLOYEE]);

    try {
        $eventsStmt = $pdo->query(
            "SELECT id, scanned_at, visitor_name_snapshot, result
             FROM scan_events
             WHERE result IN ('OK_FIRST', 'OK_REDISPLAY')
               AND visitor_name_snapshot IS NOT NULL
               AND visitor_name_snapshot <> ''
             ORDER BY scanned_at DESC
             LIMIT 10"
        );
        $events = $eventsStmt->fetchAll();
    } catch (Throwable $exception) {
        $events = [];
    }

    $recentAccesses = [];
    $highlightedAccess = null;
    $now = new DateTimeImmutable('now');

    foreach ($events as $index => $event) {
        $scannedAt = new DateTimeImmutable((string)$event['scanned_at']);
        $highlightUntil = $scannedAt->modify('+2 minutes');
        $normalizedEvent = [
            'event_id' => (string)$event['id'],
            'visitor_name' => (string)$event['visitor_name_snapshot'],
            'result' => (string)$event['result'],
            'scanned_at' => $scannedAt->format(DateTimeInterface::ATOM),
            'scanned_at_label' => $scannedAt->format('Y-m-d H:i:s'),
            'highlight_until_iso' => $highlightUntil->format(DateTimeInterface::ATOM),
        ];

        $recentAccesses[] = $normalizedEvent;

        if ($index === 0 && $highlightUntil > $now) {
            $highlightedAccess = $normalizedEvent;
        }
    }

    json_response(200, [
        'ok' => true,
        'server_time' => $now->format(DateTimeInterface::ATOM),
        'recent_accesses' => $recentAccesses,
        'highlighted_access' => $highlightedAccess,
    ]);
} catch (Throwable $exception) {
    json_response(500, [
        'ok' => false,
        'error' => [
            'code' => 'ACCESS_MONITOR_UNAVAILABLE',
            'message' => 'No se pudo cargar el monitor de accesos.',
        ],
    ]);
}
