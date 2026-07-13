<?php

declare(strict_types=1);

require __DIR__ . '/../../src/bootstrap.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$weekdayLabels = [
    1 => 'Lunes',
    2 => 'Martes',
    3 => 'Miercoles',
    4 => 'Jueves',
    5 => 'Viernes',
    6 => 'Sabado',
    7 => 'Domingo',
];

try {
    $pdo = db_pdo($config);
    auth_require_roles($pdo, $config, [AUTH_ROLE_ADMIN, AUTH_ROLE_EMPLOYEE]);

    try {
        $eventsStmt = $pdo->query(
            "SELECT
                scan_events.id,
                scan_events.scanned_at,
                scan_events.visitor_name_snapshot,
                scan_events.result,
                invites.companions_expected,
                invites.issued_by_employee_uid,
                employees.display_name AS issuer_display_name
             FROM scan_events
             LEFT JOIN invites
                ON invites.code_id = scan_events.code_id
             LEFT JOIN employees
                ON employees.uid = invites.issued_by_employee_uid
             WHERE result IN ('OK_FIRST', 'OK_REDISPLAY', 'EXPIRED', 'USED')
               AND scan_events.visitor_name_snapshot IS NOT NULL
               AND scan_events.visitor_name_snapshot <> ''
             ORDER BY scan_events.scanned_at DESC
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
        $weekdayNumber = (int)$scannedAt->format('N');
        $weekdayLabel = $weekdayLabels[$weekdayNumber] ?? $scannedAt->format('l');
        $issuerLabel = trim((string)($event['issuer_display_name'] ?? ''));

        if ($issuerLabel === '') {
            $issuerLabel = trim((string)($event['issued_by_employee_uid'] ?? ''));
        }

        $normalizedEvent = [
            'event_id' => (string)$event['id'],
            'visitor_name' => (string)$event['visitor_name_snapshot'],
            'companions_expected' => (int)($event['companions_expected'] ?? 0),
            'issuer_name' => $issuerLabel,
            'result' => (string)$event['result'],
            'is_valid_access' => in_array((string)$event['result'], ['OK_FIRST', 'OK_REDISPLAY'], true),
            'scanned_at' => $scannedAt->format(DateTimeInterface::ATOM),
            'scanned_at_label' => sprintf(
                '%s %s, %s',
                $weekdayLabel,
                $scannedAt->format('j'),
                $scannedAt->format('H:i')
            ),
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
