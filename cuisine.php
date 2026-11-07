<?php
/**
 * M3i9dat Ba M'Barek - Écran Cuisine & Préparation (KDS - Kitchen Display System)
 * Implémente F04, F05, F07 et RG06, RG07, RG08, RG09
 */

declare(strict_types=1);

require_once __DIR__ . '/config/auth.php';
$currentUser = requireRole(['PREPARATEUR', 'CAISSIER', 'ADMIN', 'SUPERADMIN']);

/** @var PDO $pdo */
$pdo = require_once __DIR__ . '/config/db.php';

// Resolve active tenant restaurant
$idRestaurant = (int)($_SESSION['restaurant_id'] ?? 1);

$stmtResto = $pdo->prepare("SELECT * FROM restaurants WHERE id_restaurant = ?");
$stmtResto->execute([$idRestaurant]);
$restaurant = $stmtResto->fetch() ?: ['nom' => "M3i9dat Ba M'Barek", 'ville' => 'Meknès'];

// Fetch preparer user
$preparateur = ['id_utilisateur' => $currentUser['id'], 'nom' => $currentUser['nom']];

// Check Plan Feature Gate (KDS requires PRO or PREMIUM)
$activePlan = strtoupper($restaurant['plan'] ?? 'PRO');
if ($activePlan === 'BASIC' && $currentUser['role'] !== 'SUPERADMIN') {
    ?>
    <!DOCTYPE html>
    <html lang="fr">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Écran Cuisine Verrouillé — <?= htmlspecialchars($restaurant['nom']) ?></title>
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
        <style>
            body { margin: 0; padding: 20px; background: #0F172A; color: #F8FAFC; font-family: 'Inter', sans-serif; display: flex; align-items: center; justify-content: center; min-height: 100vh; text-align: center; box-sizing: border-box; }
            .lock-card { background: #1E293B; border: 1px solid #334155; border-radius: 16px; padding: 40px 32px; max-width: 480px; width: 100%; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.5); }
            .lock-icon { width: 56px; height: 56px; background: rgba(245, 158, 11, 0.12); border: 1px solid rgba(245, 158, 11, 0.3); border-radius: 14px; display: inline-flex; align-items: center; justify-content: center; color: #F59E0B; margin-bottom: 20px; }
            h1 { font-size: 20px; font-weight: 700; margin: 0 0 10px 0; color: #FFFFFF; letter-spacing: -0.02em; }
            p { font-size: 14px; color: #94A3B8; line-height: 1.6; margin: 0 0 24px 0; }
            .badge { display: inline-block; background: #374151; color: #F3F4F6; padding: 4px 10px; border-radius: 6px; font-size: 11.5px; font-weight: 700; margin-bottom: 16px; text-transform: uppercase; letter-spacing: 0.05em; }
            .actions { display: flex; gap: 12px; justify-content: center; }
            .btn { display: inline-block; padding: 10px 18px; border-radius: 8px; font-size: 13.5px; font-weight: 600; text-decoration: none; transition: 0.15s; }
            .btn-primary { background: #6366F1; color: #FFFFFF; }
            .btn-primary:hover { background: #4F46E5; }
            .btn-secondary { background: #334155; color: #F1F5F9; }
            .btn-secondary:hover { background: #475569; }
        </style>
    </head>
    <body>
        <div class="lock-card">
            <div class="lock-icon">
                <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
            </div>
            <div><span class="badge">FORFAIT BASIC STARTER</span></div>
            <h1>Écran Cuisine (KDS) Verrouillé</h1>
            <p>L'Atelier Cuisine KDS interactif en temps réel n'est pas inclus dans le forfait <strong>Basic Starter</strong> de <strong><?= htmlspecialchars($restaurant['nom']) ?></strong>.<br><br>Veuillez contacter l'administrateur de la plateforme pour surclasser votre licence vers le forfait <strong>Pro Restaurant</strong>.</p>
            <div class="actions">
                <a href="index.php" class="btn btn-primary">Accéder à la Caisse POS</a>
                <a href="login.php?action=logout" class="btn btn-secondary">Déconnexion</a>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit();
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>M3i9dat Ba M'Barek — Écran Cuisine (KDS)</title>
    <link rel="icon" type="image/svg+xml" href="assets/img/favicon.svg">
    <link rel="alternate icon" href="favicon.ico">
    <link rel="stylesheet" href="assets/css/pos.css?v=<?= time() ?>">
    <style>
        :root {
            --kds-bg: #18181b;
            --kds-card-bg: #27272a;
            --kds-card-border: #3f3f46;
            --kds-text: #f4f4f5;
            --kds-text-dim: #a1a1aa;
            --kds-amber: #f59e0b;
            --kds-green: #22c55e;
            --kds-red: #ef4444;
        }

        body {
            background-color: var(--kds-bg);
            color: var(--kds-text);
            height: 100vh;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
        }

        /* KDS Header */
        .kds-header {
            background: #09090b;
            border-bottom: 2px solid var(--kds-card-border);
            padding: 12px 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.4);
            z-index: 20;
        }

        .kds-brand {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .kds-badge-logo {
            background: linear-gradient(135deg, #b91c1c, #ea580c);
            color: #fff;
            width: 42px;
            height: 42px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
            font-weight: 900;
        }

        .kds-title {
            font-size: 20px;
            font-weight: 900;
            letter-spacing: -0.5px;
            color: #ffffff;
        }

        .kds-title span {
            color: var(--kds-amber);
            font-size: 14px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-left: 8px;
            padding: 2px 8px;
            background: rgba(245, 158, 11, 0.15);
            border-radius: 6px;
            border: 1px solid rgba(245, 158, 11, 0.3);
        }

        .kds-controls {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .kds-tab-btn {
            background: #27272a;
            border: 1px solid #3f3f46;
            color: #e4e4e7;
            padding: 8px 16px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 800;
            cursor: pointer;
            transition: all 0.15s;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .kds-tab-btn:hover {
            background: #3f3f46;
        }

        .kds-tab-btn.active {
            background: #ffffff;
            color: #09090b;
            border-color: #ffffff;
            box-shadow: 0 2px 8px rgba(255, 255, 255, 0.2);
        }

        .tab-counter {
            padding: 2px 7px;
            border-radius: 10px;
            font-size: 11px;
            font-weight: 900;
        }

        .tab-counter.prep { background: #ea580c; color: #fff; }
        .tab-counter.ready { background: #16a34a; color: #fff; }

        .btn-kds-nav {
            background: #18181b;
            border: 1px solid #3f3f46;
            color: #a1a1aa;
            text-decoration: none;
            padding: 8px 14px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 700;
            transition: all 0.15s;
        }

        .btn-kds-nav:hover {
            background: #27272a;
            color: #fff;
        }

        /* Orders Grid View */
        .kds-grid-container {
            flex: 1;
            overflow-y: auto;
            padding: 24px;
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 20px;
            align-content: start;
        }

        /* Bulk action toolbar */
        .kds-bulk-bar {
            padding: 10px 24px;
            background: #09090b;
            border-bottom: 1px solid #27272a;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            font-size: 13px;
            color: var(--kds-text-dim);
            min-height: 48px;
        }

        .btn-bulk-confirm {
            background: linear-gradient(135deg, #15803d, #22c55e);
            color: #fff;
            border: none;
            border-radius: 8px;
            padding: 8px 20px;
            font-size: 13px;
            font-weight: 900;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 6px;
            box-shadow: 0 2px 8px rgba(34, 197, 94, 0.3);
            transition: all 0.15s;
        }

        .btn-bulk-confirm:hover { background: #16a34a; transform: translateY(-1px); }
        .btn-bulk-confirm:disabled { background: #3f3f46; cursor: not-allowed; box-shadow: none; transform: none; }

        /* Slide-out on status change */
        .kds-card.slide-out {
            animation: slideCardOut 0.45s ease forwards;
            pointer-events: none;
        }

        @keyframes slideCardOut {
            0%   { transform: scale(1); opacity: 1; }
            40%  { transform: scale(1.03); border-color: #22c55e; box-shadow: 0 0 24px rgba(34,197,94,0.45); }
            100% { transform: scale(0.8) translateY(-16px); opacity: 0; }
        }

        /* Order Card */
        .kds-card {
            background: var(--kds-card-bg);
            border: 2px solid var(--kds-card-border);
            border-radius: 16px;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.3);
            transition: transform 0.2s, border-color 0.2s;
            animation: cardAppear 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
        }

        @keyframes cardAppear {
            from { transform: scale(0.92); opacity: 0; }
            to { transform: scale(1); opacity: 1; }
        }

        .kds-card.is-ready {
            border-color: var(--kds-green);
            background: #1c271f;
        }

        .kds-card-header {
            padding: 14px 18px;
            background: #1f1f23;
            border-bottom: 2px solid #333338;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .kds-order-num {
            font-size: 26px;
            font-weight: 900;
            color: #ffffff;
            letter-spacing: -0.5px;
            display: flex;
            align-items: baseline;
            gap: 4px;
        }

        .kds-order-num small {
            font-size: 14px;
            color: var(--kds-text-dim);
            font-weight: 700;
        }

        .kds-timer-badge {
            font-family: ui-monospace, SFMono-Regular, monospace;
            font-size: 13px;
            font-weight: 800;
            padding: 4px 10px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .timer-normal { background: #1e3a1f; color: #4ade80; border: 1px solid #166534; }
        .timer-warning { background: #451a03; color: #f97316; border: 1px solid #9a3412; }
        .timer-alert { background: #450a0a; color: #f87171; border: 1px solid #991b1b; animation: pulseRed 1.5s infinite; }

        @keyframes pulseRed {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.7; }
        }

        .kds-items-list {
            padding: 16px 18px;
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 14px;
            overflow-y: auto;
            max-height: 400px;
        }

        .kds-item-block {
            background: #1f1f23;
            border: 1px solid #333338;
            border-radius: 10px;
            padding: 12px;
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .kds-item-main {
            display: flex;
            align-items: baseline;
            gap: 8px;
        }

        .kds-item-qty {
            background: #ffffff;
            color: #000000;
            font-size: 15px;
            font-weight: 900;
            width: 26px;
            height: 26px;
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .kds-item-name {
            font-size: 16px;
            font-weight: 800;
            color: #ffffff;
            line-height: 1.2;
        }

        .kds-pref-tag {
            font-size: 12px;
            font-weight: 800;
            padding: 3px 8px;
            border-radius: 6px;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            margin-top: 2px;
        }

        .kds-pref-tag.omission {
            background: #450a0a;
            color: #fca5a5;
            border: 1px solid #7f1d1d;
        }

        .kds-pref-tag.extra {
            background: #14532d;
            color: #86efac;
            border: 1px solid #166534;
        }

        .kds-pref-tag.sauce {
            background: #0c4a6e;
            color: #7dd3fc;
            border: 1px solid #0369a1;
        }

        .kds-comment-box {
            background: #3f2c06;
            color: #fef08a;
            border: 1px dashed #ca8a04;
            padding: 6px 10px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 700;
            margin-top: 4px;
        }

        /* Action Buttons */
        .kds-card-footer {
            padding: 14px 18px;
            background: #1a1a1e;
            border-top: 2px solid #333338;
        }

        .btn-kds-action {
            width: 100%;
            padding: 14px;
            border: none;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 900;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            letter-spacing: 0.5px;
            transition: all 0.15s;
        }

        .btn-kds-action.btn-mark-ready {
            background: linear-gradient(135deg, #15803d, #22c55e);
            color: #ffffff;
            box-shadow: 0 4px 12px rgba(34, 197, 94, 0.3);
        }

        .btn-kds-action.btn-mark-ready:hover {
            background: #16a34a;
            transform: translateY(-2px);
        }

        .btn-kds-action.btn-mark-served {
            background: linear-gradient(135deg, #0284c7, #38bdf8);
            color: #ffffff;
            box-shadow: 0 4px 12px rgba(2, 132, 199, 0.3);
        }

        .btn-kds-action.btn-mark-served:hover {
            background: #0369a1;
            transform: translateY(-2px);
        }

        /* Empty Kitchen View */
        .kds-empty-view {
            grid-column: 1/-1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 80px 20px;
            color: var(--kds-text-dim);
            gap: 12px;
        }

        .kds-empty-icon {
            font-size: 56px;
        }
    </style>
</head>
<body>

    <!-- KDS Header -->
    <header class="kds-header">
        <div class="kds-brand">
            <div class="kds-badge-logo">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M6 13.87A4 4 0 0 1 7.41 6a5.11 5.11 0 0 1 1.05-1.54 5 5 0 0 1 7.08 0A5.11 5.11 0 0 1 16.59 6 4 4 0 0 1 18 13.87V21H6Z"/>
                    <line x1="6" y1="17" x2="18" y2="17"/>
                </svg>
            </div>
            <div>
                <div class="kds-title">
                    M3i9dat Ba M'Barek <span>CUISINE • KDS</span>
                </div>
            </div>
        </div>

        <!-- Filter tabs: À Préparer vs Prêtes -->
        <div class="kds-controls">
            <button type="button" class="kds-tab-btn active" id="tabPrep" onclick="switchKdsTab('EN_PREPARATION')">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:middle; margin-right:4px;"><path d="M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.38-.5-2-1-3-1.072-2.143-.224-4.054 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7 7 0 1 1-14 0c0-1.153.433-2.294 1-3a2.5 2.5 0 0 0 2.5 3.5z"/></svg><span>À Préparer</span>
                <span class="tab-counter prep" id="countPrep">0</span>
            </button>
            <button type="button" class="kds-tab-btn" id="tabReady" onclick="switchKdsTab('PRETE')">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:middle; margin-right:4px;"><polyline points="20 6 9 17 4 12"/></svg><span>Prêtes au Comptoir</span>
                <span class="tab-counter ready" id="countReady">0</span>
            </button>

            <a href="ecran.php" class="btn-kds-nav" target="_blank" title="Ouvrir l'écran d'appel client">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:middle; margin-right:4px;"><rect x="2" y="7" width="20" height="15" rx="2" ry="2"/><polyline points="17 2 12 7 7 2"/></svg>Écran Public
            </a>
            <a href="index.php" class="btn-kds-nav" title="Aller au terminal de caisse">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:middle; margin-right:4px;"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>Caisse
            </a>
            <a href="commandes.php" class="btn-kds-nav" title="Consulter l'historique des commandes">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:middle; margin-right:4px;"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>Historique
            </a>
            <a href="login.php?action=logout" class="btn-kds-nav" style="border-color:#fca5a5; color:#f87171;" title="Fermer la session">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:middle; margin-right:4px;"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>Quitter
            </a>
        </div>
    </header>

    <!-- Bulk Action Bar (injected between header & grid) -->
    <div class="kds-bulk-bar" id="kdsBulkBar">
        <span id="kdsBulkLabel">— Aucune action groupée disponible</span>
        <button type="button" class="btn-bulk-confirm" id="btnBulkConfirm" disabled>
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:middle; margin-right:5px;"><polyline points="20 6 9 17 4 12"/></svg>Tout Marquer Prêtes
        </button>
    </div>

    <!-- Main Orders Grid -->
    <main class="kds-grid-container" id="kdsOrdersGrid">
        <!-- Injected dynamically by JS -->
    </main>
    <script>
        let currentTab = 'EN_PREPARATION'; // 'EN_PREPARATION' or 'PRETE'
        let cachedOrders = { en_preparation: [], prete: [] };
        let previousPrepCount = 0;

        // Web Audio synthesized chime for new kitchen orders
        const audioCtx = (typeof window.AudioContext !== 'undefined' || typeof window.webkitAudioContext !== 'undefined')
            ? new (window.AudioContext || window.webkitAudioContext)() : null;

        function playKitchenBell() {
            if (!audioCtx) return;
            if (audioCtx.state === 'suspended') audioCtx.resume();
            const now = audioCtx.currentTime;
            [880, 1174.66].forEach((freq, i) => {
                const osc = audioCtx.createOscillator();
                const gain = audioCtx.createGain();
                osc.connect(gain);
                gain.connect(audioCtx.destination);
                osc.frequency.setValueAtTime(freq, now + i * 0.12);
                gain.gain.setValueAtTime(0.15, now + i * 0.12);
                gain.gain.exponentialRampToValueAtTime(0.001, now + i * 0.12 + 0.35);
                osc.start(now + i * 0.12);
                osc.stop(now + i * 0.12 + 0.35);
            });
        }

        function switchKdsTab(tab) {
            currentTab = tab;
            document.getElementById('tabPrep').classList.toggle('active', tab === 'EN_PREPARATION');
            document.getElementById('tabReady').classList.toggle('active', tab === 'PRETE');
            renderKdsGrid();
        }

        async function fetchKdsOrders() {
            try {
                const resp = await fetch('api/get_commandes.php?filter=active&restaurant_id=<?= $idRestaurant ?>');
                const res = await resp.json();
                if (res.success) {
                    cachedOrders = res.data;
                    document.getElementById('countPrep').textContent = res.data.en_preparation.length;
                    document.getElementById('countReady').textContent = res.data.prete.length;

                    // Play bell if new order arrived in preparation
                    if (res.data.en_preparation.length > previousPrepCount && previousPrepCount !== 0) {
                        playKitchenBell();
                    }
                    previousPrepCount = res.data.en_preparation.length;

                    renderKdsGrid();
                }
            } catch (err) {
                console.error('KDS Fetch Error:', err);
            }
        }

        function formatElapsedTime(seconds) {
            const m = Math.floor(seconds / 60);
            const s = seconds % 60;
            return `${m}m ${s < 10 ? '0' : ''}${s}s`;
        }

        function humanTime(seconds) {
            if (seconds < 60) return "À l'instant";
            if (seconds < 120) return 'Il y a 1 min';
            if (seconds < 3600) return `Il y a ${Math.floor(seconds / 60)} min`;
            return `Il y a ${Math.floor(seconds / 3600)}h${Math.floor((seconds % 3600) / 60).toString().padStart(2,'0')}`;
        }

        function getTimerClass(seconds) {
            if (seconds > 600) return 'timer-alert';
            if (seconds > 300) return 'timer-warning';
            return 'timer-normal';
        }

        function updateBulkBar(orders) {
            const bar = document.getElementById('kdsBulkBar');
            const btn = document.getElementById('btnBulkConfirm');
            const label = document.getElementById('kdsBulkLabel');
            if (!bar) return;

            if (currentTab === 'EN_PREPARATION' && orders && orders.length > 1) {
                label.textContent = `${orders.length} commandes en préparation — action groupée :`;
                btn.disabled = false;
                btn.innerHTML = `<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:middle; margin-right:5px;"><polyline points="20 6 9 17 4 12"/></svg>Tout Marquer Prêtes (${orders.length})`;
            } else if (currentTab === 'PRETE' && orders && orders.length > 1) {
                label.textContent = `${orders.length} commandes prêtes — action groupée :`;
                btn.disabled = false;
                btn.innerHTML = `<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:middle; margin-right:5px;"><polyline points="20 6 9 17 4 12"/><polyline points="20 12 9 23 4 18"/></svg>Tout Marquer Servies (${orders.length})`;
            } else {
                label.textContent = '— Sélectionnez un groupe pour agir en masse';
                btn.disabled = true;
                btn.innerHTML = currentTab === 'EN_PREPARATION' 
                    ? `<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:middle; margin-right:5px;"><polyline points="20 6 9 17 4 12"/></svg>Tout Marquer Prêtes` 
                    : `<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:middle; margin-right:5px;"><polyline points="20 6 9 17 4 12"/><polyline points="20 12 9 23 4 18"/></svg>Tout Marquer Servies`;
            }
        }

        function renderKdsGrid() {
            const container = document.getElementById('kdsOrdersGrid');
            const orders = (currentTab === 'EN_PREPARATION') ? cachedOrders.en_preparation : cachedOrders.prete;

            updateBulkBar(orders);

            if (!orders || orders.length === 0) {
                container.innerHTML = `
                    <div class="kds-empty-view">
                        <div class="kds-empty-icon" style="color:var(--kds-text-dim); display:flex; justify-content:center; margin-bottom:12px;">
                            <svg width="52" height="52" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                                <circle cx="12" cy="12" r="10"/>
                                <path d="m9 12 2 2 4-4"/>
                            </svg>
                        </div>
                        <div style="font-size:20px; font-weight:800; color:#fff;">
                            ${currentTab === 'EN_PREPARATION' ? 'Toutes les commandes sont prêtes !' : 'Aucune commande en attente de retrait.'}
                        </div>
                        <div style="font-size:14px; margin-top:6px;">Le poste cuisine est à jour en temps réel.</div>
                    </div>
                `;
                return;
            }

            container.innerHTML = '';
            orders.forEach(ord => {
                const card = document.createElement('div');
                card.className = `kds-card ${ord.statut === 'PRETE' ? 'is-ready' : ''}`;
                card.dataset.idCommande = ord.id_commande;

                const timerClass = getTimerClass(ord.elapsed_seconds || 0);
                const timerText  = formatElapsedTime(ord.elapsed_seconds || 0);
                const timeHuman  = humanTime(ord.elapsed_seconds || 0);

                let itemsHtml = '';
                (ord.items || []).forEach(it => {
                    let prefsHtml = '';
                    (it.preferences || []).forEach(p => {
                        if (p.action === 'RETIRER') {
                            prefsHtml += `<div class="kds-pref-tag omission"><svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:middle; margin-right:3px;"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>SANS ${escapeHtml(p.nom).toUpperCase()}</div>`;
                        } else if (p.prix_applique > 0) {
                            prefsHtml += `<div class="kds-pref-tag extra">+ EXTRA ${escapeHtml(p.nom).toUpperCase()}</div>`;
                        } else {
                            prefsHtml += `<div class="kds-pref-tag sauce">SAUCE ${escapeHtml(p.nom).toUpperCase()}</div>`;
                        }
                    });

                    const commentHtml = it.commentaire ? `<div class="kds-comment-box"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:middle; margin-right:4px;"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>NOTE : ${escapeHtml(it.commentaire)}</div>` : '';

                    itemsHtml += `
                        <div class="kds-item-block">
                            <div class="kds-item-main">
                                <div class="kds-item-qty">${it.quantite}</div>
                                <div class="kds-item-name">${escapeHtml(it.nom_sandwich)}</div>
                            </div>
                            ${prefsHtml}
                            ${commentHtml}
                        </div>
                    `;
                });

                const isPrep = (ord.statut === 'EN_PREPARATION');
                const actionBtnHtml = isPrep ? `
                    <button type="button" class="btn-kds-action btn-mark-ready" onclick="advanceOrderStatus(${ord.id_commande}, 'PRETE', this)">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:middle; margin-right:6px;"><polyline points="20 6 9 17 4 12"/></svg>MARQUER PRÊTE !
                    </button>
                ` : `
                    <button type="button" class="btn-kds-action btn-mark-served" onclick="advanceOrderStatus(${ord.id_commande}, 'SERVIE', this)">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:middle; margin-right:6px;"><polyline points="20 6 9 17 4 12"/><polyline points="20 12 9 23 4 18"/></svg>MARQUER SERVIE / CLÔTURER
                    </button>
                `;

                card.innerHTML = `
                    <div class="kds-card-header">
                        <div class="kds-order-num">
                            <small>COMMANDE</small> N° ${escapeHtml(ord.numero_commande)}
                        </div>
                        <div>
                            <div class="kds-timer-badge ${timerClass}">
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:middle; margin-right:4px;"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>${timerText}
                            </div>
                            <div style="font-size:11px; color:var(--kds-text-dim); text-align:right; margin-top:3px;">
                                ${timeHuman}
                            </div>
                        </div>
                    </div>
                    <div class="kds-items-list">
                        ${itemsHtml}
                    </div>
                    <div class="kds-card-footer">
                        ${actionBtnHtml}
                    </div>
                `;

                container.appendChild(card);
            });
        }

        async function advanceOrderStatus(idCommande, nouveauStatut, triggerEl) {
            // Animate card out before refresh
            const card = triggerEl ? triggerEl.closest('.kds-card') : null;
            if (card) {
                card.classList.add('slide-out');
                await new Promise(r => setTimeout(r, 420));
            }

            try {
                const resp = await fetch('api/changer_statut.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        id_commande: idCommande,
                        nouveau_statut: nouveauStatut,
                        id_utilisateur: <?= (int)($preparateur['id_utilisateur'] ?? 3) ?>
                    })
                });

                const res = await resp.json();
                if (res.success) {
                    fetchKdsOrders();
                } else {
                    alert('Erreur transition : ' + res.message);
                    if (card) card.classList.remove('slide-out');
                }
            } catch (err) {
                alert('Erreur réseau / serveur : ' + err.message);
                if (card) card.classList.remove('slide-out');
            }
        }

        // Bulk confirm handler
        document.addEventListener('DOMContentLoaded', () => {
            const btnBulk = document.getElementById('btnBulkConfirm');
            if (btnBulk) {
                btnBulk.addEventListener('click', async () => {
                    const orders = (currentTab === 'EN_PREPARATION') ? cachedOrders.en_preparation : cachedOrders.prete;
                    const nextStatus = (currentTab === 'EN_PREPARATION') ? 'PRETE' : 'SERVIE';
                    if (!orders || orders.length === 0) return;
                    btnBulk.disabled = true;
                    btnBulk.textContent = 'En cours...';
                    for (const ord of orders) {
                        await advanceOrderStatus(ord.id_commande, nextStatus, null);
                    }
                });
            }
        });

        function escapeHtml(str) {
            if (!str) return '';
            const d = document.createElement('div');
            d.textContent = str;
            return d.innerHTML;
        }

        // Real-time polling every 3 seconds (RG08)
        setInterval(fetchKdsOrders, 3000);
        fetchKdsOrders();
    </script>
</body>
</html>

