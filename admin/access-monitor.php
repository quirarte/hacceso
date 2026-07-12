<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

$pdo = db_pdo($config);
$currentUser = auth_require_roles($pdo, $config, [AUTH_ROLE_ADMIN, AUTH_ROLE_EMPLOYEE]);
$backHref = $currentUser['role'] === AUTH_ROLE_ADMIN ? '/admin/users.php' : '/employee/passes.php';
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Hacceso Admin - Monitoreo de accesos</title>
    <style>
        :root {
            color-scheme: dark;
            --bg: #000000;
            --panel: rgba(255, 255, 255, 0.04);
            --line: rgba(255, 255, 255, 0.08);
            --muted: #8a8a8a;
            --muted-strong: #b4b4b4;
            --danger: #ff3b30;
        }

        * {
            box-sizing: border-box;
        }

        html,
        body {
            margin: 0;
            min-height: 100%;
            background: var(--bg);
            color: #ffffff;
            font-family: Arial, sans-serif;
        }

        body {
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            padding: 20px 28px 28px;
        }

        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 16px;
            margin-bottom: 20px;
        }

        .topbar a {
            color: var(--muted-strong);
            text-decoration: none;
            border: 1px solid var(--line);
            border-radius: 999px;
            padding: 10px 14px;
        }

        .topbar a:hover {
            color: #ffffff;
            border-color: rgba(255, 255, 255, 0.2);
        }

        .status {
            color: var(--muted);
            font-size: 12px;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }

        .recent-accesses {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 10px;
            margin-bottom: 24px;
        }

        .recent-item {
            padding: 12px 14px;
            border: 1px solid var(--line);
            border-radius: 12px;
            background: var(--panel);
            color: var(--muted);
            min-height: 72px;
        }

        .recent-item time {
            display: block;
            font-size: 12px;
            margin-bottom: 6px;
            color: #9b9b9b;
        }

        .recent-item strong {
            display: block;
            font-size: 15px;
            font-weight: 600;
            color: #c3c3c3;
        }

        .empty-state {
            color: var(--muted);
            font-size: 14px;
            padding: 16px 0;
        }

        .hero {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            text-align: center;
            padding: 24px;
        }

        .hero-name {
            margin: 0;
            max-width: 1400px;
            color: var(--danger);
            font-size: clamp(68px, 11vw, 180px);
            line-height: 0.95;
            letter-spacing: 0.01em;
            text-transform: uppercase;
            word-break: break-word;
        }

        .hero-placeholder {
            margin: 0;
            color: #2f2f2f;
            font-size: clamp(28px, 4vw, 52px);
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }

        .footer-note {
            color: #5f5f5f;
            font-size: 12px;
            text-align: center;
            margin-top: 16px;
        }

        @media (max-width: 720px) {
            body {
                padding: 14px;
            }

            .topbar {
                flex-direction: column;
            }

            .recent-accesses {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="topbar">
        <div class="status">
            Monitoreo en vivo de accesos
            <div id="connection-status">Conectando...</div>
        </div>
        <a href="<?= htmlspecialchars($backHref, ENT_QUOTES, 'UTF-8') ?>">Volver al panel</a>
    </div>

    <section id="recent-accesses" class="recent-accesses" aria-label="Ultimos 10 accesos"></section>

    <main class="hero">
        <div>
            <p id="hero-placeholder" class="hero-placeholder">Esperando siguiente acceso</p>
            <h1 id="hero-name" class="hero-name" hidden></h1>
        </div>
    </main>

    <p class="footer-note">Audio esperado en <code>/admin/assets/audio/monitor-bell.mp3</code>. Si el navegador bloquea el sonido, interactua una vez con la pagina para habilitarlo.</p>

    <audio id="bell-sound" preload="auto">
        <source src="/admin/assets/audio/monitor-bell.mp3" type="audio/mpeg">
    </audio>

    <script>
        (() => {
            const feedUrl = '/api/admin/access_monitor_feed.php';
            const recentAccessesNode = document.getElementById('recent-accesses');
            const connectionStatusNode = document.getElementById('connection-status');
            const heroNameNode = document.getElementById('hero-name');
            const heroPlaceholderNode = document.getElementById('hero-placeholder');
            const bellSoundNode = document.getElementById('bell-sound');
            const pollIntervalMs = 3000;

            let lastAnnouncedEventId = null;
            let currentHighlightedEventId = null;
            let currentHighlightUntilMs = 0;

            function escapeHtml(value) {
                return String(value)
                    .replaceAll('&', '&amp;')
                    .replaceAll('<', '&lt;')
                    .replaceAll('>', '&gt;')
                    .replaceAll('"', '&quot;')
                    .replaceAll("'", '&#039;');
            }

            function renderRecentAccesses(accesses) {
                if (!recentAccessesNode) {
                    return;
                }

                if (!Array.isArray(accesses) || accesses.length === 0) {
                    recentAccessesNode.innerHTML = '<div class="empty-state">Aun no hay accesos exitosos registrados.</div>';
                    return;
                }

                recentAccessesNode.innerHTML = accesses.map((access) => {
                    const scannedAt = escapeHtml(access.scanned_at_label || '');
                    const visitorName = escapeHtml(access.visitor_name || 'Sin nombre');

                    return `
                        <article class="recent-item">
                            <time>${scannedAt}</time>
                            <strong>${visitorName}</strong>
                        </article>
                    `;
                }).join('');
            }

            function renderHero(nowMs) {
                if (!heroNameNode || !heroPlaceholderNode) {
                    return;
                }

                if (currentHighlightedEventId !== null && nowMs < currentHighlightUntilMs) {
                    heroNameNode.hidden = false;
                    heroPlaceholderNode.hidden = true;
                    return;
                }

                currentHighlightedEventId = null;
                currentHighlightUntilMs = 0;
                heroNameNode.hidden = true;
                heroNameNode.textContent = '';
                heroPlaceholderNode.hidden = false;
            }

            async function playBell() {
                if (!bellSoundNode) {
                    return;
                }

                try {
                    bellSoundNode.currentTime = 0;
                    await bellSoundNode.play();
                } catch (error) {
                    // Algunos navegadores bloquean autoplay hasta la primera interaccion.
                }
            }

            function applyPayload(payload, shouldAnnounce) {
                const recentAccesses = Array.isArray(payload.recent_accesses) ? payload.recent_accesses : [];
                const highlightedAccess = payload.highlighted_access && typeof payload.highlighted_access === 'object'
                    ? payload.highlighted_access
                    : null;

                renderRecentAccesses(recentAccesses);

                if (highlightedAccess && typeof highlightedAccess.event_id === 'string') {
                    currentHighlightedEventId = highlightedAccess.event_id;
                    currentHighlightUntilMs = Date.parse(highlightedAccess.highlight_until_iso || '');

                    if (!Number.isFinite(currentHighlightUntilMs)) {
                        currentHighlightUntilMs = Date.now();
                    }

                    heroNameNode.textContent = highlightedAccess.visitor_name || '';

                    if (shouldAnnounce && lastAnnouncedEventId !== highlightedAccess.event_id) {
                        playBell();
                    }

                    lastAnnouncedEventId = highlightedAccess.event_id;
                }

                renderHero(Date.now());
            }

            async function fetchFeed(isInitialLoad) {
                try {
                    const response = await fetch(feedUrl, {
                        method: 'GET',
                        credentials: 'same-origin',
                        cache: 'no-store',
                        headers: {
                            'Accept': 'application/json',
                        },
                    });

                    if (!response.ok) {
                        throw new Error(`HTTP ${response.status}`);
                    }

                    const payload = await response.json();
                    connectionStatusNode.textContent = `Actualizado ${new Date().toLocaleTimeString('es-MX')}`;
                    applyPayload(payload, !isInitialLoad);
                } catch (error) {
                    connectionStatusNode.textContent = 'Sin conexion con el feed';
                }
            }

            window.setInterval(() => {
                renderHero(Date.now());
            }, 1000);

            fetchFeed(true);
            window.setInterval(() => {
                fetchFeed(false);
            }, pollIntervalMs);
        })();
    </script>
</body>
</html>
