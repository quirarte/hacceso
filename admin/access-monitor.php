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
            const messagingSoundSource = '/admin/assets/audio/mensajeria.wav';
            const pollIntervalMs = 3000;

            let lastAnnouncedEventId = null;
            const announcedEventIds = new Set();
            let currentHighlightedEventId = null;
            let currentHighlightUntilMs = 0;
            let currentHighlightedCodeId = null;
            let currentHighlightedScannedAtMs = 0;
            let currentHighlightedKind = 'access';
            let lastAnnouncedMessagingAlertId = null;
            let audioUnlocked = false;
            let audioPlaybackToken = 0;
            let isFetchingFeed = false;
            let audioUnlockPromise = null;
            let audioContext = null;
            let activeAudioSource = null;
            let ownsAudioLease = false;
            const announcedScanTimes = new Map();
            const audioBuffers = new Map();
            const audioSources = {
                ok: bellSoundSource,
                error: errorSoundSource,
                messaging: messagingSoundSource,
            };
            const monitorLeaseKey = 'hacceso-monitor-audio-lease';
            const sharedAnnouncementKey = 'hacceso-monitor-last-audio-announcement';
            const monitorTabId = `${Date.now()}-${Math.random().toString(36).slice(2)}`;
            const monitorLeaseDurationMs = 6000;
            const sharedAnnouncementWindowMs = 6000;

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
                    recentAccessesNode.innerHTML = '<div class="empty-state">Aun no hay accesos registrados.</div>';
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
                    heroMetaNode.hidden = currentHighlightedKind === 'messaging';
                    heroPlaceholderNode.hidden = true;
                    return;
                }

                currentHighlightedEventId = null;
                currentHighlightUntilMs = 0;
                currentHighlightedCodeId = null;
                currentHighlightedScannedAtMs = 0;
                currentHighlightedKind = 'access';
                heroNameNode.hidden = true;
                heroNameNode.textContent = '';
                heroMetaNode.hidden = true;
                heroMetaNode.textContent = '';
                heroPlaceholderNode.hidden = false;
            }

            function getAudioContext() {
                if (audioContext) {
                    return audioContext;
                }

                const AudioContextConstructor = window.AudioContext || window.webkitAudioContext;
                if (!AudioContextConstructor) {
                    return null;
                }

                audioContext = new AudioContextConstructor();
                return audioContext;
            }

            function stopActiveAudioSource() {
                if (!activeAudioSource) {
                    return;
                }

                const source = activeAudioSource;
                activeAudioSource = null;
                source.onended = null;

                try {
                    source.stop(0);
                } catch (error) {
                    // La fuente puede haber terminado justo antes de detenerla.
                }

                try {
                    source.disconnect();
                } catch (error) {
                    // La fuente ya puede estar desconectada.
                }
            }

            function cancelAudioPlayback() {
                audioPlaybackToken += 1;
                stopActiveAudioSource();
            }

            async function loadAudioBuffer(audioType) {
                const existingBuffer = audioBuffers.get(audioType);
                if (existingBuffer) {
                    return existingBuffer;
                }

                const context = getAudioContext();
                if (!context || !audioSources[audioType]) {
                    throw new Error('Web Audio no disponible');
                }

                const pendingBuffer = fetch(audioSources[audioType], {
                    method: 'GET',
                    credentials: 'same-origin',
                    cache: 'no-store',
                })
                    .then((response) => {
                        if (!response.ok) {
                            throw new Error(`HTTP ${response.status}`);
                        }

                        return response.arrayBuffer();
                    })
                    .then((arrayBuffer) => context.decodeAudioData(arrayBuffer));

                audioBuffers.set(audioType, pendingBuffer);

                try {
                    const buffer = await pendingBuffer;
                    audioBuffers.set(audioType, buffer);
                    return buffer;
                } catch (error) {
                    audioBuffers.delete(audioType);
                    throw error;
                }
            }

            async function playAudio(audioType) {
                if (!ownsAudioLease) {
                    return;
                }

                const context = getAudioContext();
                if (!context) {
                    return;
                }

                const playbackToken = ++audioPlaybackToken;
                stopActiveAudioSource();

                try {
                    if (context.state === 'suspended') {
                        await context.resume();
                    }

                    const buffer = await loadAudioBuffer(audioType);
                    if (playbackToken !== audioPlaybackToken || !ownsAudioLease) {
                        return;
                    }

                    const source = context.createBufferSource();
                    source.buffer = buffer;
                    source.loop = false;
                    source.connect(context.destination);
                    source.onended = () => {
                        if (activeAudioSource === source) {
                            activeAudioSource = null;
                            source.disconnect();
                        }
                    };
                    activeAudioSource = source;
                    source.start(0);
                    audioUnlocked = context.state === 'running';
                } catch (error) {
                    // El navegador intentara desbloquear Web Audio con la primera interaccion.
                }
            }

            function wasRecentlyAnnouncedForCode(codeId, scannedAtMs) {
                if (!codeId || !Number.isFinite(scannedAtMs)) {
                    return false;
                }

                const previousScannedAtMs = announcedScanTimes.get(codeId);

                return Number.isFinite(previousScannedAtMs)
                    && scannedAtMs >= previousScannedAtMs
                    && scannedAtMs - previousScannedAtMs <= sharedAnnouncementWindowMs;
            }

            function claimSharedAudioAnnouncement(signature) {
                try {
                    const rawAnnouncement = window.localStorage.getItem(sharedAnnouncementKey);
                    if (rawAnnouncement) {
                        const previousAnnouncement = JSON.parse(rawAnnouncement);
                        if (
                            previousAnnouncement &&
                            previousAnnouncement.signature === signature &&
                            Number.isFinite(previousAnnouncement.at) &&
                            Date.now() - previousAnnouncement.at <= sharedAnnouncementWindowMs
                        ) {
                            return false;
                        }
                    }

                    window.localStorage.setItem(sharedAnnouncementKey, JSON.stringify({
                        signature,
                        at: Date.now(),
                    }));
                } catch (error) {
                    // Si localStorage no esta disponible, la deduplicacion local continua funcionando.
                }

                return true;
            }

            function markEventAsAnnounced(eventId, codeId, scannedAtMs) {
                if (!eventId) {
                    return;
                }

                announcedEventIds.add(eventId);
                lastAnnouncedEventId = eventId;

                if (codeId && Number.isFinite(scannedAtMs)) {
                    announcedScanTimes.set(codeId, scannedAtMs);
                }
            }

            async function unlockAudio() {
                if (!ownsAudioLease || audioUnlocked || audioUnlockPromise) {
                    return;
                }

                audioUnlockPromise = (async () => {
                    try {
                        const context = getAudioContext();
                        if (!context) {
                            return;
                        }

                        await context.resume();
                        audioUnlocked = context.state === 'running';
                    } catch (error) {
                        audioUnlocked = false;
                    } finally {
                        audioUnlockPromise = null;
                    }
                })();

                await audioUnlockPromise;
            }

            function readAudioLease() {
                try {
                    const rawLease = window.localStorage.getItem(monitorLeaseKey);
                    if (!rawLease) {
                        return null;
                    }

                    const lease = JSON.parse(rawLease);
                    if (!lease || typeof lease.id !== 'string' || !Number.isFinite(lease.expiresAt)) {
                        return null;
                    }

                    return lease;
                } catch (error) {
                    return null;
                }
            }

            function claimAudioLease() {
                const lease = {
                    id: monitorTabId,
                    expiresAt: Date.now() + monitorLeaseDurationMs,
                };

                try {
                    window.localStorage.setItem(monitorLeaseKey, JSON.stringify(lease));
                } catch (error) {
                    // Si el navegador bloquea localStorage, esta pestaña trabaja sola.
                }

                ownsAudioLease = true;
            }

            function refreshAudioLease() {
                const currentLease = readAudioLease();

                if (!currentLease || currentLease.expiresAt <= Date.now() || currentLease.id === monitorTabId) {
                    claimAudioLease();
                    return;
                }

                if (ownsAudioLease) {
                    ownsAudioLease = false;
                    audioUnlocked = false;
                    cancelAudioPlayback();
                }
            }

            function releaseAudioLease() {
                const currentLease = readAudioLease();
                if (!currentLease || currentLease.id !== monitorTabId) {
                    return;
                }

                try {
                    window.localStorage.removeItem(monitorLeaseKey);
                } catch (error) {
                    // El navegador puede impedir cambios durante el cierre de la pestaña.
                }
            }

            window.addEventListener('storage', (event) => {
                if (event.key !== monitorLeaseKey || !event.newValue) {
                    return;
                }

                const currentLease = readAudioLease();
                if (currentLease && currentLease.id !== monitorTabId && ownsAudioLease) {
                    ownsAudioLease = false;
                    audioUnlocked = false;
                    cancelAudioPlayback();
                }
            });

            claimAudioLease();
            window.setInterval(refreshAudioLease, 2000);
            window.addEventListener('beforeunload', releaseAudioLease);

            function applyPayload(payload, shouldAnnounce) {
                const recentAccesses = Array.isArray(payload.recent_accesses) ? payload.recent_accesses : [];
                const highlightedAccess = payload.highlighted_access && typeof payload.highlighted_access === 'object'
                    ? payload.highlighted_access
                    : null;

                renderRecentAccesses(recentAccesses);

                const messagingAlert = payload.messaging_alert && typeof payload.messaging_alert === 'object'
                    ? payload.messaging_alert
                    : null;

                if (messagingAlert && typeof messagingAlert.alert_id === 'string') {
                    currentHighlightedEventId = `messaging:${messagingAlert.alert_id}`;
                    currentHighlightedCodeId = null;
                    currentHighlightedScannedAtMs = 0;
                    currentHighlightedKind = 'messaging';
                    currentHighlightUntilMs = Date.parse(messagingAlert.expires_at || '');

                    if (!Number.isFinite(currentHighlightUntilMs)) {
                        currentHighlightUntilMs = Date.now() + 30000;
                    }

                    heroNameNode.textContent = 'Mensajería';
                    heroNameNode.style.color = '#f4d03f';
                    heroNameNode.style.textTransform = 'none';
                    heroMetaNode.textContent = '';
                    heroMetaNode.hidden = true;
                    heroPlaceholderNode.hidden = true;
                    heroNameNode.hidden = false;

                    if (!shouldAnnounce) {
                        lastAnnouncedMessagingAlertId = messagingAlert.alert_id;
                    } else if (
                        messagingAlert.alert_id !== lastAnnouncedMessagingAlertId &&
                        ownsAudioLease
                    ) {
                        lastAnnouncedMessagingAlertId = messagingAlert.alert_id;
                        const sharedAnnouncementClaimed = claimSharedAudioAnnouncement(
                            `messaging:${messagingAlert.alert_id}`
                        );

                        if (sharedAnnouncementClaimed) {
                            void playAudio('messaging');
                        }
                    }

                    return;
                }

                if (highlightedAccess && typeof highlightedAccess.event_id === 'string') {
                    const nextCodeId = String(highlightedAccess.code_id || '');
                    const nextScannedAtMs = Date.parse(highlightedAccess.scanned_at || '');
                    const hasActiveHighlight = currentHighlightedEventId !== null && Date.now() < currentHighlightUntilMs;
                    const shouldKeepCurrentHighlight =
                        hasActiveHighlight &&
                        currentHighlightedCodeId !== null &&
                        nextCodeId !== '' &&
                        nextCodeId === currentHighlightedCodeId &&
                        Number.isFinite(nextScannedAtMs) &&
                        nextScannedAtMs <= currentHighlightedScannedAtMs &&
                        highlightedAccess.event_id !== currentHighlightedEventId;

                    if (shouldKeepCurrentHighlight) {
                        renderHero(Date.now());
                        return;
                    }

                    currentHighlightedEventId = highlightedAccess.event_id;
                    currentHighlightedCodeId = nextCodeId !== '' ? nextCodeId : null;
                    currentHighlightedScannedAtMs = Number.isFinite(nextScannedAtMs) ? nextScannedAtMs : 0;
                    currentHighlightedKind = 'access';
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
                    heroNameNode.style.textTransform = 'uppercase';

                    if (
                        shouldAnnounce &&
                        highlightedAccess.event_id !== lastAnnouncedEventId &&
                        !announcedEventIds.has(highlightedAccess.event_id)
                    ) {
                        const duplicateScanRecentlyAnnounced = wasRecentlyAnnouncedForCode(
                            nextCodeId,
                            nextScannedAtMs
                        );
                        const announcementIdentity = nextCodeId || `${highlightedAccess.visitor_name || ''}|${companionsExpected}`;
                        const announcementSignature = announcementIdentity;
                        markEventAsAnnounced(highlightedAccess.event_id, nextCodeId, nextScannedAtMs);

                        const sharedAnnouncementClaimed = ownsAudioLease
                            ? claimSharedAudioAnnouncement(announcementSignature)
                            : false;
                        if (!duplicateScanRecentlyAnnounced && sharedAnnouncementClaimed && ownsAudioLease) {
                            void playAudio(isValidAccess ? 'ok' : 'error');
                        }
                    }
                }

                renderHero(Date.now());
            }

            async function fetchFeed(isInitialLoad) {
                if (isFetchingFeed) {
                    return;
                }

                isFetchingFeed = true;

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
                } finally {
                    isFetchingFeed = false;
                }
            }

            window.setInterval(() => {
                renderHero(Date.now());
            }, 1000);

            ['pointerdown', 'keydown', 'touchstart'].forEach((eventName) => {
                window.addEventListener(eventName, unlockAudio, {passive: true, once: true});
            });

            void loadAudioBuffer('ok').catch(() => {});
            void loadAudioBuffer('error').catch(() => {});
            void loadAudioBuffer('messaging').catch(() => {});

            fetchFeed(true);
            window.setInterval(() => {
                fetchFeed(false);
            }, pollIntervalMs);
        })();
    </script>
</body>
</html>
