<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

$pdo = db_pdo($config);
auth_require_roles($pdo, $config, [AUTH_ROLE_ADMIN, AUTH_ROLE_EMPLOYEE]);
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
            --accent: #3fbf63;
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

        .clock-row {
            display: flex;
            justify-content: flex-end;
            margin-bottom: 2px;
        }

        .status {
            color: #3fbf63;
            font-size: 12px;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            margin-bottom: 0;
        }

        .recent-accesses {
            display: flex;
            flex-direction: column;
            gap: 0;
            margin-bottom: 16px;
        }

        .recent-item {
            display: flex;
            align-items: baseline;
            gap: 10px;
            padding: 2px 0;
            color: var(--muted);
        }

        .recent-item time {
            font-size: 12px;
            color: #7a7a7a;
            flex: 0 0 auto;
        }

        .recent-item strong {
            font-size: 13px;
            font-weight: 500;
            color: #9a9a9a;
        }

        .empty-state {
            color: var(--muted);
            font-size: 12px;
            padding: 4px 0;
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
            color: var(--accent);
            font-size: clamp(54px, 8.8vw, 144px);
            line-height: 0.95;
            letter-spacing: 0.01em;
            text-transform: uppercase;
            word-break: break-word;
        }

        .hero-meta {
            margin: 12px 0 0;
            color: #b6b6b6;
            font-size: clamp(18px, 3vw, 48px);
            line-height: 1.05;
        }

        .hero-placeholder {
            margin: 0;
            color: #2f2f2f;
            font-size: clamp(28px, 4vw, 52px);
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }

        @media (max-width: 720px) {
            body {
                padding: 14px;
            }
        }
    </style>
</head>
<body>
    <div class="clock-row">
        <div class="status" id="connection-status">Conectando...</div>
    </div>

    <section id="recent-accesses" class="recent-accesses" aria-label="Ultimos 10 accesos"></section>

    <main class="hero">
        <div>
            <p id="hero-placeholder" class="hero-placeholder">Esperando siguiente acceso</p>
            <h1 id="hero-name" class="hero-name" hidden></h1>
            <p id="hero-meta" class="hero-meta" hidden></p>
        </div>
    </main>

    <script>
        (() => {
            const feedUrl = '/api/admin/access_monitor_feed.php';
            const recentAccessesNode = document.getElementById('recent-accesses');
            const connectionStatusNode = document.getElementById('connection-status');
            const heroNameNode = document.getElementById('hero-name');
            const heroMetaNode = document.getElementById('hero-meta');
            const heroPlaceholderNode = document.getElementById('hero-placeholder');
            const bellSoundSource = '/admin/assets/audio/monitor-bell.wav';
            const errorSoundSource = '/admin/assets/audio/error.wav';
            const pollIntervalMs = 3000;

            let lastAnnouncedEventId = null;
            let currentHighlightedEventId = null;
            let currentHighlightUntilMs = 0;
            let audioUnlocked = false;
            let audioPlaybackToken = 0;
            const audioPlayers = {
                ok: new Audio(bellSoundSource),
                error: new Audio(errorSoundSource),
            };

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
                    const companionsExpected = escapeHtml(access.companions_expected ?? 0);
                    const issuerName = escapeHtml(access.issuer_name || 'Sin emisor');

                    return `
                        <article class="recent-item">
                            <time>${scannedAt}</time>
                            <strong>${visitorName} - ${companionsExpected} - ${issuerName}</strong>
                        </article>
                    `;
                }).join('');
            }

            function renderHero(nowMs) {
                if (!heroNameNode || !heroMetaNode || !heroPlaceholderNode) {
                    return;
                }

                if (currentHighlightedEventId !== null && nowMs < currentHighlightUntilMs) {
                    heroNameNode.hidden = false;
                    heroMetaNode.hidden = false;
                    heroPlaceholderNode.hidden = true;
                    return;
                }

                currentHighlightedEventId = null;
                currentHighlightUntilMs = 0;
                heroNameNode.hidden = true;
                heroNameNode.textContent = '';
                heroMetaNode.hidden = true;
                heroMetaNode.textContent = '';
                heroPlaceholderNode.hidden = false;
            }

            Object.values(audioPlayers).forEach((audio) => {
                audio.preload = 'auto';
            });

            function waitForEvent(target, eventName) {
                return new Promise((resolve) => {
                    const handleEvent = () => {
                        target.removeEventListener(eventName, handleEvent);
                        resolve();
                    };

                    target.addEventListener(eventName, handleEvent);
                });
            }

            async function prepareAudioFromStart(targetAudio) {
                if (!Number.isFinite(targetAudio.duration) || targetAudio.readyState < 3) {
                    targetAudio.load();
                    await waitForEvent(targetAudio, 'canplaythrough');
                }

                targetAudio.pause();
                targetAudio.currentTime = 0;

                if (targetAudio.currentTime > 0.05) {
                    targetAudio.load();
                    await waitForEvent(targetAudio, 'canplaythrough');
                    targetAudio.currentTime = 0;
                }
            }

            function stopAllAudio() {
                Object.values(audioPlayers).forEach((audio) => {
                    try {
                        audio.pause();
                        audio.currentTime = 0;
                    } catch (error) {
                        // Ignoramos fallos del navegador al resetear el audio.
                    }
                });
            }

            async function playAudio(audioType) {
                const playbackToken = ++audioPlaybackToken;
                const targetAudio = audioPlayers[audioType] || audioPlayers.ok;

                if (!targetAudio) {
                    return;
                }

                try {
                    stopAllAudio();
                    await prepareAudioFromStart(targetAudio);

                    if (playbackToken !== audioPlaybackToken) {
                        return;
                    }

                    stopAllAudio();
                    await targetAudio.play();
                    audioUnlocked = true;
                } catch (error) {
                    // Intentaremos desbloquear audio en la primera interaccion del usuario.
                }
            }

            async function unlockAudio() {
                if (audioUnlocked) {
                    return;
                }

                try {
                    stopAllAudio();
                    for (const targetAudio of Object.values(audioPlayers)) {
                        await prepareAudioFromStart(targetAudio);
                        targetAudio.muted = true;
                        await targetAudio.play();
                        targetAudio.pause();
                        targetAudio.currentTime = 0;
                        targetAudio.muted = false;
                    }
                    audioUnlocked = true;
                } catch (error) {
                    Object.values(audioPlayers).forEach((audio) => {
                        audio.muted = false;
                    });
                    audioUnlocked = false;
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

                    const companionsExpected = Number.parseInt(highlightedAccess.companions_expected || 0, 10);
                    const issuerName = highlightedAccess.issuer_name || '';
                    const result = String(highlightedAccess.result || '');
                    const isValidAccess = result === 'OK_FIRST' || result === 'OK_REDISPLAY';
                    heroNameNode.textContent = `${highlightedAccess.visitor_name || ''} - ${Number.isFinite(companionsExpected) ? companionsExpected : 0}`;
                    heroMetaNode.textContent = issuerName;
                    heroNameNode.style.color = isValidAccess ? 'var(--accent)' : 'var(--danger)';

                    if (shouldAnnounce && lastAnnouncedEventId !== highlightedAccess.event_id) {
                        playAudio(isValidAccess ? 'ok' : 'error');
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
                    connectionStatusNode.textContent = new Date().toLocaleTimeString('es-MX');
                    applyPayload(payload, !isInitialLoad);
                } catch (error) {
                    connectionStatusNode.textContent = 'Sin conexion con el feed';
                }
            }

            window.setInterval(() => {
                renderHero(Date.now());
            }, 1000);

            ['pointerdown', 'keydown', 'touchstart'].forEach((eventName) => {
                window.addEventListener(eventName, unlockAudio, {passive: true, once: true});
            });

            Object.values(audioPlayers).forEach((audio) => {
                try {
                    audio.load();
                } catch (error) {
                    // Si falla la precarga inicial, se intentara de nuevo al reproducir.
                }
            });

            fetchFeed(true);
            window.setInterval(() => {
                fetchFeed(false);
            }, pollIntervalMs);
        })();
    </script>
</body>
</html>
