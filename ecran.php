<?php
/**
 * M39ODA - Écran d'Appel Public Client (TV / Affichage Mural)
 * Implémente F06 et RG08 — Mise à jour temps réel + annonce sonore + ticker défilant
 */
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Determine tenant for public screen
$ecranRestaurantId = 1;
if (isset($_GET['r']) && is_numeric($_GET['r'])) {
    $ecranRestaurantId = (int)$_GET['r'];
} elseif (isset($_GET['restaurant_id']) && is_numeric($_GET['restaurant_id'])) {
    $ecranRestaurantId = (int)$_GET['restaurant_id'];
} elseif (isset($_SESSION['restaurant_id']) && is_numeric($_SESSION['restaurant_id'])) {
    $ecranRestaurantId = (int)$_SESSION['restaurant_id'];
}

/** @var PDO $pdo */
$pdo = require_once __DIR__ . '/config/db.php';
$stmtR = $pdo->prepare("SELECT nom, ville, adresse FROM restaurants WHERE id_restaurant = ?");
$stmtR->execute([$ecranRestaurantId]);
$resto = $stmtR->fetch(PDO::FETCH_ASSOC) ?: [
    'nom' => "M3i9dat Ba M'Barek",
    'ville' => 'Meknès',
    'adresse' => 'Bab Boujloud'
];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($resto['nom']) ?> — Écran d'Appel Public · RestoOS</title>
    <link rel="icon" type="image/svg+xml" href="assets/img/favicon.svg">
    <link rel="alternate icon" href="favicon.ico">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --terracotta: #c2410c;
            --amber: #d97706;
            --olive: #15803d;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            background: #09090b;
            color: #ffffff;
            height: 100vh;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            user-select: none;
        }

        /* Animated ambient background — subtle, premium, not distracting */
        body::before {
            content: '';
            position: fixed;
            inset: 0;
            background:
                radial-gradient(ellipse at 15% 30%, rgba(194, 65, 12, 0.07) 0%, transparent 55%),
                radial-gradient(ellipse at 85% 70%, rgba(21, 128, 61, 0.06) 0%, transparent 55%);
            animation: ambientShift 18s ease-in-out infinite alternate;
            pointer-events: none;
            z-index: 0;
        }

        @keyframes ambientShift {
            0%   { opacity: 0.6; transform: scale(1); }
            50%  { opacity: 1;   transform: scale(1.04); }
            100% { opacity: 0.7; transform: scale(1.02); }
        }

        /* Top Brand Bar */
        .callboard-header {
            background: #000000;
            border-bottom: 2px solid #1f1f23;
            padding: 12px 32px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            z-index: 10;
            position: relative;
            flex-shrink: 0;
        }

        .board-brand {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .board-icon {
            font-size: 26px;
            background: linear-gradient(135deg, #9a3412, #c2410c);
            width: 48px;
            height: 48px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(194, 65, 12, 0.35);
        }

        .board-title {
            font-size: 26px;
            font-weight: 900;
            letter-spacing: -0.5px;
            line-height: 1.1;
        }

        .board-subtitle {
            font-size: 12px;
            color: #52525b;
            font-weight: 700;
            letter-spacing: 0.5px;
            text-transform: uppercase;
        }

        .board-title span { color: #ea580c; }

        .board-meta {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .board-clock {
            font-family: ui-monospace, SFMono-Regular, monospace;
            font-size: 22px;
            font-weight: 800;
            color: #a1a1aa;
            letter-spacing: 0.5px;
        }

        .board-updated {
            font-size: 11px;
            color: #3f3f46;
            font-weight: 600;
        }

        .btn-fullscreen {
            background: #18181b;
            border: 1px solid #3f3f46;
            color: #a1a1aa;
            padding: 8px 16px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 800;
            cursor: pointer;
            transition: all 0.15s;
        }

        .btn-fullscreen:hover { background: #27272a; color: #fff; }

        /* Main Split Board */
        .split-board-container {
            flex: 1;
            display: grid;
            grid-template-columns: 1fr 1fr;
            overflow: hidden;
            position: relative;
            z-index: 1;
        }

        /* Column 1: En Préparation */
        .board-column.prep-col {
            background: #111113;
            border-right: 2px solid #27272a;
            display: flex;
            flex-direction: column;
        }

        /* Column 2: Prêtes */
        .board-column.ready-col {
            background: #071b0f;
            display: flex;
            flex-direction: column;
        }

        .col-header {
            padding: 20px 28px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 2px solid rgba(255, 255, 255, 0.07);
            flex-shrink: 0;
        }

        .col-title-wrap {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .col-icon { font-size: 24px; }

        .col-title {
            font-size: 20px;
            font-weight: 900;
            letter-spacing: 0.5px;
            text-transform: uppercase;
        }

        .col-title.prep  { color: #f59e0b; }
        .col-title.ready { color: #4ade80; }

        .col-badge-count {
            font-size: 13px;
            font-weight: 900;
            padding: 4px 14px;
            border-radius: 20px;
        }

        .col-badge-count.prep  { background: rgba(245,158,11,0.15); color: #fbbf24; border: 1px solid #f59e0b; }
        .col-badge-count.ready { background: rgba(74,222,128,0.15); color: #86efac; border: 1px solid #4ade80; }

        /* Tiles Grid */
        .numbers-grid-scroll {
            flex: 1;
            overflow-y: auto;
            padding: 24px 28px;
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(clamp(110px, 14vw, 160px), 1fr));
            gap: 18px;
            align-content: start;
        }

        /* Order Number Tile */
        .order-number-tile {
            aspect-ratio: 1;
            border-radius: 20px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            font-weight: 900;
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.3);
            transition: transform 0.2s cubic-bezier(0.34, 1.56, 0.64, 1);
            animation: tilePop 0.35s cubic-bezier(0.34, 1.56, 0.64, 1);
        }

        @keyframes tilePop {
            from { transform: scale(0.8); opacity: 0; }
            to   { transform: scale(1);   opacity: 1; }
        }

        .order-number-tile:hover { transform: scale(1.04); }

        .order-number-tile.prep-tile {
            background: #1f1f23;
            border: 2px solid #3f3f46;
            color: #e4e4e7;
        }

        .order-number-tile.ready-tile {
            background: linear-gradient(135deg, #14532d, #166534);
            border: 3px solid #4ade80;
            color: #ffffff;
            box-shadow: 0 0 28px rgba(74, 222, 128, 0.3);
            animation: tilePopReady 0.35s cubic-bezier(0.34, 1.56, 0.64, 1), readyGlow 2.5s 0.35s ease-in-out infinite alternate;
        }

        @keyframes tilePopReady {
            from { transform: scale(0.8); opacity: 0; }
            to   { transform: scale(1);   opacity: 1; }
        }

        @keyframes readyGlow {
            from { box-shadow: 0 0 16px rgba(74,222,128,0.25); }
            to   { box-shadow: 0 0 36px rgba(74,222,128,0.65); border-color: #86efac; }
        }

        .tile-sub-label {
            font-size: clamp(9px, 1.2vw, 12px);
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 1px;
            opacity: 0.7;
            margin-bottom: 4px;
        }

        .tile-main-number {
            font-size: clamp(32px, 5vw, 56px);
            line-height: 1;
            letter-spacing: -1px;
        }

        .ready-tile .tile-main-number { font-size: clamp(38px, 6vw, 64px); }

        /* Empty Column State */
        .empty-col-message {
            grid-column: 1/-1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            min-height: 240px;
            color: rgba(255, 255, 255, 0.2);
            gap: 12px;
            font-size: 15px;
            font-weight: 700;
        }

        /* Bottom Scrolling Ticker */
        .brand-ticker {
            background: #000000;
            border-top: 2px solid #1f1f23;
            overflow: hidden;
            height: 38px;
            display: flex;
            align-items: center;
            flex-shrink: 0;
            z-index: 10;
            position: relative;
        }

        .ticker-inner {
            display: flex;
            white-space: nowrap;
            animation: tickerScroll 30s linear infinite;
            gap: 0;
        }

        @keyframes tickerScroll {
            0%   { transform: translateX(0); }
            100% { transform: translateX(-50%); }
        }

        .ticker-item {
            padding: 0 36px;
            font-size: 13px;
            font-weight: 700;
            color: #52525b;
            letter-spacing: 0.3px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .ticker-item span { color: #c2410c; }

        /* Toast Announcement */
        .ready-call-toast {
            position: fixed;
            bottom: 60px;
            left: 50%;
            transform: translateX(-50%) translateY(0);
            background: linear-gradient(135deg, #14532d, #166534);
            border: 2px solid #4ade80;
            color: #ffffff;
            padding: 18px 40px;
            border-radius: 50px;
            font-size: clamp(16px, 2.5vw, 24px);
            font-weight: 900;
            display: none;
            align-items: center;
            gap: 16px;
            box-shadow: 0 16px 40px rgba(0,0,0,0.7), 0 0 40px rgba(74,222,128,0.3);
            z-index: 200;
            animation: toastSlide 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
            letter-spacing: 0.3px;
        }

        @keyframes toastSlide {
            from { transform: translateX(-50%) translateY(80px); opacity: 0; }
            to   { transform: translateX(-50%) translateY(0);    opacity: 1; }
        }
    </style>
</head>
<body>

    <!-- Header -->
    <header class="callboard-header">
        <div class="board-brand">
            <div class="board-icon">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="2" y="7" width="20" height="14" rx="2"/>
                    <path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/>
                </svg>
            </div>
            <div>
                <div class="board-title"><?= htmlspecialchars($resto['nom']) ?></div>
                <div class="board-subtitle"><?= htmlspecialchars($resto['ville'] ?? 'Maroc') ?> • Suivi des Commandes en Direct</div>
            </div>
        </div>

        <div class="board-meta">
            <button id="btnEnableAudio" onclick="enableAudioAndTest()" style="background:#10B981; color:#FFF; border:none; padding:7px 14px; border-radius:999px; font-weight:800; font-size:12px; cursor:pointer; display:inline-flex; align-items:center; gap:6px; box-shadow:0 2px 8px rgba(16,185,129,0.3);">
                🔊 Activer le Son TV
            </button>
            <div style="text-align:right;">
                <div class="board-clock" id="boardClock">--:--:--</div>
                <div class="board-updated" id="boardUpdated">Mise à jour...</div>
            </div>
            <button type="button" class="btn-fullscreen" onclick="toggleFullscreen()" style="display:inline-flex; align-items:center; gap:6px;">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 3H5a2 2 0 0 0-2 2v3m18 0V5a2 2 0 0 0-2-2h-3m0 18h3a2 2 0 0 0 2-2v-3M3 16v3a2 2 0 0 0 2 2h3"/></svg>
                Plein Écran
            </button>
        </div>
    </header>

    <!-- Main Split Display -->
    <main class="split-board-container">

        <!-- Column 1: En Préparation -->
        <section class="board-column prep-col">
            <div class="col-header">
                <div class="col-title-wrap">
                    <span class="col-icon">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="12" cy="12" r="10"/>
                            <polyline points="12 6 12 12 16 14"/>
                        </svg>
                    </span>
                    <h2 class="col-title prep">En Préparation</h2>
                </div>
                <div class="col-badge-count prep" id="badgePrepCount">0</div>
            </div>
            <div class="numbers-grid-scroll" id="gridPrepNumbers"></div>
        </section>

        <!-- Column 2: Prêtes au Comptoir -->
        <section class="board-column ready-col">
            <div class="col-header">
                <div class="col-title-wrap">
                    <span class="col-icon">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/>
                            <path d="M13.73 21a2 2 0 0 1-3.46 0"/>
                        </svg>
                    </span>
                    <h2 class="col-title ready">Prêtes au Comptoir</h2>
                </div>
                <div class="col-badge-count ready" id="badgeReadyCount">0</div>
            </div>
            <div class="numbers-grid-scroll" id="gridReadyNumbers"></div>
        </section>

    </main>

    <!-- Brand Ticker -->
    <div class="brand-ticker">
        <div class="ticker-inner" id="tickerInner">
            <!-- Doubled for seamless loop -->
        </div>
    </div>

    <!-- Toast Notification Banner -->
    <div class="ready-call-toast" id="readyCallToast">
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;">
            <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/>
            <path d="M13.73 21a2 2 0 0 1-3.46 0"/>
        </svg>
        <span id="readyCallText">COMMANDE N° 1 EST PRÊTE !</span>
    </div>

    <script>
        let knownReadyNumbers = new Set();
        let isFirstLoad = true;
        let lastUpdateTime = null;

        // Ticker content
        const tickerMessages = [
            '<span><?= addslashes(htmlspecialchars($resto['nom'])) ?></span> — Préparation minute à la commande',
            'Surveillez la colonne <strong>PRÊTES AU COMPTOIR</strong> pour récupérer votre plateau',
            'Retrait directement au comptoir avec votre numéro de ticket',
            'Service rapide & Qualité garantie · RestoOS Cloud'
        ];

        // Build ticker
        (function buildTicker() {
            const inner = document.getElementById('tickerInner');
            const content = tickerMessages.join(' &nbsp;&nbsp;&nbsp;•&nbsp;&nbsp;&nbsp; ');
            // Double it for seamless loop
            inner.innerHTML = `<span class="ticker-item">${content}</span><span class="ticker-item">${content}</span>`;
        })();

        // Web Audio — airport-style chime
        const audioCtx = (typeof window.AudioContext !== 'undefined' || typeof window.webkitAudioContext !== 'undefined')
            ? new (window.AudioContext || window.webkitAudioContext)() : null;

        function playCallChime() {
            if (!audioCtx) return;
            if (audioCtx.state === 'suspended') audioCtx.resume();
            const now = audioCtx.currentTime;
            [659.25, 783.99, 880, 1046.5].forEach((freq, idx) => {
                const osc  = audioCtx.createOscillator();
                const gain = audioCtx.createGain();
                osc.type = 'sine';
                osc.connect(gain);
                gain.connect(audioCtx.destination);
                osc.frequency.setValueAtTime(freq, now + idx * 0.18);
                gain.gain.setValueAtTime(0.18, now + idx * 0.18);
                gain.gain.exponentialRampToValueAtTime(0.001, now + idx * 0.18 + 0.7);
                osc.start(now + idx * 0.18);
                osc.stop(now + idx * 0.18 + 0.7);
            });
        }

        // Live Clock
        function updateClock() {
            const now = new Date();
            document.getElementById('boardClock').textContent = now.toLocaleTimeString('fr-FR', {
                hour: '2-digit', minute: '2-digit', second: '2-digit'
            });
            if (lastUpdateTime) {
                const secAgo = Math.round((Date.now() - lastUpdateTime) / 1000);
                document.getElementById('boardUpdated').textContent =
                    secAgo < 5 ? 'À jour' : `Mis à jour il y a ${secAgo}s`;
            }
        }
        setInterval(updateClock, 1000);
        updateClock();

        // Fullscreen
        function toggleFullscreen() {
            if (!document.fullscreenElement) {
                document.documentElement.requestFullscreen().catch(() => {});
            } else {
                if (document.exitFullscreen) document.exitFullscreen();
            }
        }

        // Fetch
        async function fetchCallboard() {
            try {
                const resp = await fetch('api/get_commandes.php?filter=active&restaurant_id=<?= $ecranRestaurantId ?>');
                const res  = await resp.json();
                if (res.success) {
                    lastUpdateTime = Date.now();
                    renderCallboard(res.data);
                }
            } catch (err) {
                console.error('Callboard Fetch Error:', err);
            }
        }

        function renderCallboard(data) {
            const prepGrid   = document.getElementById('gridPrepNumbers');
            const readyGrid  = document.getElementById('gridReadyNumbers');
            const prepCount  = document.getElementById('badgePrepCount');
            const readyCount = document.getElementById('badgeReadyCount');

            prepCount.textContent  = data.en_preparation.length;
            readyCount.textContent = data.prete.length;

            // Render Preparation
            if (data.en_preparation.length === 0) {
                prepGrid.innerHTML = `
                    <div class="empty-col-message">
                        <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="opacity:0.6;"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 14 14"/></svg>
                        <span>Aucune commande en cuisson</span>
                    </div>`;
            } else {
                prepGrid.innerHTML = '';
                data.en_preparation.forEach(ord => {
                    const tile = document.createElement('div');
                    tile.className = 'order-number-tile prep-tile';
                    tile.innerHTML = `
                        <div class="tile-sub-label">Commande</div>
                        <div class="tile-main-number">${escapeHtml(ord.numero_commande)}</div>
                    `;
                    prepGrid.appendChild(tile);
                });
            }

            // Detect freshly ready orders
            const currentReadySet = new Set(data.prete.map(o => String(o.numero_commande)));

            if (!isFirstLoad) {
                const newlyReady = data.prete.filter(o => !knownReadyNumbers.has(String(o.numero_commande)));
                if (newlyReady.length > 0) {
                    // Announce each (staggered if multiple)
                    newlyReady.forEach((ord, i) => {
                        setTimeout(() => triggerReadyAnnouncement(ord.numero_commande), i * 2200);
                    });
                }
            }

            knownReadyNumbers = currentReadySet;
            isFirstLoad = false;

            // Render Ready
            if (data.prete.length === 0) {
                readyGrid.innerHTML = `
                    <div class="empty-col-message">
                        <svg width="42" height="42" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="color:var(--text-muted); margin-bottom:8px;">
                            <circle cx="12" cy="12" r="10"/>
                            <path d="m9 12 2 2 4-4"/>
                        </svg>
                        <span>Toutes les commandes servies</span>
                    </div>`;
            } else {
                readyGrid.innerHTML = '';
                data.prete.forEach(ord => {
                    const tile = document.createElement('div');
                    tile.className = 'order-number-tile ready-tile';
                    tile.innerHTML = `
                        <div class="tile-sub-label">Prête</div>
                        <div class="tile-main-number">${escapeHtml(ord.numero_commande)}</div>
                    `;
                    readyGrid.appendChild(tile);
                });
            }
        }

        // Enable audio with 1 click
        function enableAudioAndTest() {
            if (audioCtx && audioCtx.state === 'suspended') {
                audioCtx.resume();
            }
            playCallChime();
            const btn = document.getElementById('btnEnableAudio');
            if (btn) {
                btn.style.background = '#059669';
                btn.innerHTML = '✓ Carillon Actif';
                setTimeout(() => { btn.style.display = 'none'; }, 2000);
            }
        }

        function triggerReadyAnnouncement(num) {
            // Crisp chime notification only (voice disabled for loud restaurant environments)
            playCallChime();
            const toast = document.getElementById('readyCallToast');
            const text  = document.getElementById('readyCallText');
            text.textContent = `COMMANDE N° ${num} EST PRÊTE AU COMPTOIR !`;
            toast.style.display = 'flex';
            toast.style.animation = 'none';
            requestAnimationFrame(() => { toast.style.animation = ''; });
            clearTimeout(window._toastTimer);
            window._toastTimer = setTimeout(() => { toast.style.display = 'none'; }, 5000);
        }

        function escapeHtml(str) {
            if (!str) return '';
            const d = document.createElement('div');
            d.textContent = str;
            return d.innerHTML;
        }

        // Auto-refresh every 2.5 seconds (RG08)
        setInterval(fetchCallboard, 2500);
        fetchCallboard();
    </script>
</body>
</html>
