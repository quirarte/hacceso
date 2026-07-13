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
                scan_events.code_id,
                scan_events.scanned_at,
                scan_events.created_at,
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
             WHERE result IN ('OK_FIRST', 'OK_REDISPLAY', 'EXPIRED', 'REVOKED', 'USED', 'INEXISTENT')
             ORDER BY scan_events.scanned_at DESC, scan_events.created_at DESC, scan_events.id DESC
             LIMIT 50"
        );
        $events = $eventsStmt->fetchAll();
    } catch (Throwable $exception) {
        $events = [];
    }

    $recentAccesses = [];
    $highlightedAccess = null;
    $activeMonitorAlert = null;
    $messagingAlert = null;
    $latestScannedAtByCode = [];
    $now = new DateTimeImmutable('now');

    try {
        $alertStmt = $pdo->query(
            "SELECT id, alert_type, created_at, expires_at
             FROM monitor_alerts
             WHERE alert_type IN ('MESSAGING', 'DESCENDING')
               AND expires_at > NOW()
             ORDER BY created_at DESC
             LIMIT 1"
        );
        $alert = $alertStmt->fetch();

        if (is_array($alert)) {
            $activeMonitorAlert = [
                'alert_id' => (string)$alert['id'],
                'alert_type' => (string)$alert['alert_type'],
                'created_at' => (new DateTimeImmutable((string)$alert['created_at']))->format(DateTimeInterface::ATOM),
                'expires_at' => (new DateTimeImmutable((string)$alert['expires_at']))->format(DateTimeInterface::ATOM),
            ];

            if ($activeMonitorAlert['alert_type'] === 'MESSAGING') {
                $messagingAlert = $activeMonitorAlert;
            }
        }
    } catch (Throwable $exception) {
        // La tabla de alertas puede no existir antes del primer aviso.
    }

    foreach ($events as $event) {
        $scannedAt = new DateTimeImmutable((string)$event['scanned_at']);
        $codeId = (string)$event['code_id'];
        $scannedAtTimestamp = $scannedAt->getTimestamp();

        if (
            $codeId !== '' &&
            isset($latestScannedAtByCode[$codeId]) &&
            $latestScannedAtByCode[$codeId] - $scannedAtTimestamp <= 5
        ) {
            continue;
        }

        if ($codeId !== '') {
            $latestScannedAtByCode[$codeId] = $scannedAtTimestamp;
        }

        $highlightUntil = $scannedAt->modify('+30 seconds');
        $weekdayNumber = (int)$scannedAt->format('N');
        $weekdayLabel = $weekdayLabels[$weekdayNumber] ?? $scannedAt->format('l');
        $issuerLabel = trim((string)($event['issuer_display_name'] ?? ''));

        if ($issuerLabel === '') {
            $issuerLabel = trim((string)($event['issued_by_employee_uid'] ?? ''));
        }

        $visitorLabel = trim((string)($event['visitor_name_snapshot'] ?? ''));
        if ($visitorLabel === '') {
            $visitorLabel = 'QR no registrado';
        }

        $normalizedEvent = [
            'event_id' => (string)$event['id'],
            'code_id' => $codeId,
            'visitor_name' => $visitorLabel,
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

        if ($highlightedAccess === null && $highlightUntil > $now) {
            $highlightedAccess = $normalizedEvent;
        }

        if (count($recentAccesses) >= 10) {
            break;
        }
    }

    json_response(200, [
        'ok' => true,
        'server_time' => $now->format(DateTimeInterface::ATOM),
        'recent_accesses' => $recentAccesses,
        'highlighted_access' => $highlightedAccess,
        'active_alert' => $activeMonitorAlert,
        'messaging_alert' => $messagingAlert,
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
