<?php
/**
 * M3i9dat Ba M'Barek - Tableau de Bord Exécutif & Historique des Ventes
 * Filtres : date, statut, recherche par N°. Récapitulatif CA & Business Intelligence.
 */

declare(strict_types=1);

require_once __DIR__ . '/config/auth.php';
$currentUser = requireRole(['CAISSIER', 'ADMIN', 'SUPERADMIN']);

/** @var PDO $pdo */
$pdo = require_once __DIR__ . '/config/db.php';

// Resolve active tenant restaurant
$isSuperAdmin = ($currentUser['role'] === 'SUPERADMIN');
$idRestaurant = (int)($_SESSION['restaurant_id'] ?? 1);
if ($isSuperAdmin && isset($_GET['restaurant_id']) && is_numeric($_GET['restaurant_id'])) {
    $idRestaurant = (int)$_GET['restaurant_id'];
    $_SESSION['restaurant_id'] = $idRestaurant;
}

// Fetch all restaurants if superadmin
$allRestaurants = [];
if ($isSuperAdmin) {
    $allRestaurants = $pdo->query("SELECT id_restaurant, nom, slug, ville FROM restaurants ORDER BY id_restaurant ASC")->fetchAll();
}

$stmtResto = $pdo->prepare("SELECT * FROM restaurants WHERE id_restaurant = ?");
$stmtResto->execute([$idRestaurant]);
$restaurant = $stmtResto->fetch() ?: ['nom' => "M3i9dat Ba M'Barek", 'ville' => 'Meknès', 'devise' => 'DH'];

// -- Filters from GET --
$filterDate   = $_GET['date']   ?? 'all';     // all | today | week (default to 'all' so full history shows)
$filterStatut = $_GET['statut'] ?? 'all';     // all | EN_PREPARATION | PRETE | SERVIE
$filterSearch = trim($_GET['q'] ?? '');

$tenantWhere = "AND c.id_restaurant = " . (int)$idRestaurant;

// Build date condition
$dateWhere = match ($filterDate) {
    'today' => "AND DATE(c.date_commande) = CURDATE()",
    'week'  => "AND c.date_commande >= DATE_SUB(NOW(), INTERVAL 7 DAY)",
    default => ''
};

// Build statut condition
$statutWhere = ($filterStatut !== 'all' && in_array($filterStatut, ['EN_PREPARATION', 'PRETE', 'SERVIE'], true))
    ? "AND c.statut = " . $pdo->quote($filterStatut)
    : '';

// Build search condition: match order #, cashier name, OR sandwich names
$searchWhere = '';
$searchBind  = [];
if ($filterSearch !== '') {
    $searchWhere = "AND (
        CAST(c.numero_commande AS CHAR) LIKE :s1
        OR u.nom LIKE :s2
        OR c.id_commande IN (
            SELECT d2.id_commande FROM details_commande d2 
            JOIN sandwichs s2 ON d2.id_sandwich = s2.id_sandwich 
            WHERE s2.nom LIKE :s3
        )
    )";
    $term = '%' . $filterSearch . '%';
    $searchBind[':s1'] = $term;
    $searchBind[':s2'] = $term;
    $searchBind[':s3'] = $term;
}

$sql = "SELECT c.*, u.nom AS nom_caissier,
               COUNT(DISTINCT d.id_detail) AS nb_lignes,
               COALESCE(SUM(d.quantite), 0) AS total_sandwichs
        FROM commandes c
        LEFT JOIN utilisateurs u ON c.id_caissier = u.id_utilisateur
        LEFT JOIN details_commande d ON c.id_commande = d.id_commande
        WHERE 1=1 $tenantWhere $dateWhere $statutWhere $searchWhere
        GROUP BY c.id_commande
        ORDER BY c.date_commande DESC
        LIMIT 100";

$stmt = $pdo->prepare($sql);
$stmt->execute($searchBind);
$commandes = $stmt->fetchAll();

// CSV Export Handler
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $cleanResto = preg_replace('/[^a-zA-Z0-9_-]/', '', strtolower($restaurant['nom'] ?? 'resto'));
    $filename = "ventes-{$cleanResto}-" . date('Y-m-d') . ".csv";

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $fp = fopen('php://output', 'w');
    // Write UTF-8 BOM so Excel opens it with French accents perfectly
    fprintf($fp, chr(0xEF).chr(0xBB).chr(0xBF));

    // CSV Header row
    fputcsv($fp, ['ID', 'N° Commande', 'Date & Heure', 'Caissier', 'Statut', 'Montant Total (DH)', 'Articles'], ';');

    foreach ($commandes as $c) {
        // Fetch items summary
        $stmtLines = $pdo->prepare("SELECT s.nom, d.quantite FROM details_commande d JOIN sandwichs s ON d.id_sandwich = s.id_sandwich WHERE d.id_commande = ?");
        $stmtLines->execute([$c['id_commande']]);
        $lines = $stmtLines->fetchAll(PDO::FETCH_ASSOC);
        $summary = [];
        foreach ($lines as $l) {
            $summary[] = "{$l['quantite']}x {$l['nom']}";
        }

        fputcsv($fp, [
            $c['id_commande'],
            $c['numero_commande'],
            $c['date_commande'],
            $c['nom_caissier'] ?: 'Caisse 1',
            $c['statut'],
            number_format((float)$c['montant_total'], 2, '.', ''),
            implode(' | ', $summary)
        ], ';');
    }

    fclose($fp);
    exit;
}

// Revenue summary (same filters, no search)
$sqlRev = "SELECT
               COUNT(*) AS total_count,
               COALESCE(SUM(montant_total), 0) AS ca_total,
               COALESCE(SUM(CASE WHEN statut='SERVIE' THEN montant_total END), 0) AS ca_encaisse,
               SUM(statut='EN_PREPARATION') AS cnt_prep,
               SUM(statut='PRETE') AS cnt_prete,
               SUM(statut='SERVIE') AS cnt_servie
           FROM commandes c
           WHERE 1=1 $tenantWhere $dateWhere $statutWhere";
$stmtRev = $pdo->prepare($sqlRev);
$stmtRev->execute();
$rev = $stmtRev->fetch();

// -- Chart Data: Hourly Traffic (Courbe d'Affluence) --
$sqlHourly = "SELECT HOUR(date_commande) AS h, COUNT(*) AS cnt
              FROM commandes c WHERE 1=1 $tenantWhere $dateWhere
              GROUP BY h ORDER BY h";
$stmtH = $pdo->prepare($sqlHourly);
$stmtH->execute();
$hourlyData = $stmtH->fetchAll();
$hourlyMap = [];
foreach ($hourlyData as $row) { $hourlyMap[(int)$row['h']] = (int)$row['cnt']; }
$maxHourly = max(1, max($hourlyMap ?: [0]));

// -- Chart Data: Category Breakdown (Répartition) --
$sqlCat = "SELECT s.categorie, COUNT(d.id_detail) AS cnt, SUM(d.quantite) AS total_qty
           FROM details_commande d
           JOIN sandwichs s ON d.id_sandwich = s.id_sandwich
           JOIN commandes c ON d.id_commande = c.id_commande
           WHERE 1=1 $tenantWhere $dateWhere
           GROUP BY s.categorie ORDER BY total_qty DESC";
$stmtCat = $pdo->prepare($sqlCat);
$stmtCat->execute();
$catData = $stmtCat->fetchAll();
$totalCatQty = max(1, array_sum(array_column($catData, 'total_qty')));

// -- Chart Data: Top 5 Best-Sellers --
$sqlTop = "SELECT s.nom, SUM(d.quantite) AS total_qty
           FROM details_commande d
           JOIN sandwichs s ON d.id_sandwich = s.id_sandwich
           JOIN commandes c ON d.id_commande = c.id_commande
           WHERE 1=1 $tenantWhere $dateWhere
           GROUP BY s.id_sandwich ORDER BY total_qty DESC LIMIT 5";
$stmtTop = $pdo->prepare($sqlTop);
$stmtTop->execute();
$topData = $stmtTop->fetchAll();
$maxTopQty = max(1, (int)($topData[0]['total_qty'] ?? 1));
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tableau de Bord — M3i9dat Ba M'Barek</title>
    <link rel="icon" type="image/svg+xml" href="assets/img/favicon.svg">
    <link rel="alternate icon" href="favicon.ico">
    <link rel="stylesheet" href="assets/css/pos.css?v=<?= time() ?>">
    <style>
        body { height: auto; overflow-y: auto; background: #f5f5f4; }

        .page-wrap {
            max-width: 1200px;
            margin: 0 auto;
            padding: 28px 24px 60px;
        }

        /* KPI Revenue Cards */
        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 28px;
        }

        .kpi-card {
            background: #fff;
            border: 1.5px solid var(--border-color);
            border-radius: 16px;
            padding: 20px 22px;
            box-shadow: var(--shadow-box);
        }

        .kpi-label {
            font-size: 11.5px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--text-muted);
            margin-bottom: 6px;
        }

        .kpi-value {
            font-size: 28px;
            font-weight: 900;
            color: var(--charcoal);
            line-height: 1;
        }

        .kpi-value.terracotta { color: var(--terracotta); }
        .kpi-value.olive { color: var(--olive); }

        .kpi-sub {
            font-size: 11px;
            color: var(--text-muted);
            font-weight: 600;
            margin-top: 4px;
        }

        /* Filters Bar */
        .filters-bar {
            background: #fff;
            border: 1.5px solid var(--border-color);
            border-radius: 14px;
            padding: 14px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            flex-wrap: wrap;
            margin-bottom: 20px;
            box-shadow: var(--shadow-box);
        }

        .filters-left {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
            flex: 1;
        }

        .filters-right {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }

        .filter-group {
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .filters-bar label {
            font-size: 11.5px;
            font-weight: 800;
            color: var(--charcoal);
            text-transform: uppercase;
            letter-spacing: 0.4px;
            white-space: nowrap;
        }

        .filter-select {
            padding: 8px 12px;
            border: 1.5px solid var(--border-color);
            border-radius: 8px;
            font-size: 13px;
            font-weight: 700;
            color: var(--charcoal);
            background: #fafaf9;
            cursor: pointer;
            outline: none;
            transition: border-color 0.15s;
        }

        .filter-select:focus {
            border-color: var(--terracotta);
        }

        .filter-search-wrap {
            position: relative;
            min-width: 200px;
            max-width: 280px;
            flex: 1;
        }

        .filter-search-icon {
            position: absolute;
            left: 10px;
            top: 50%;
            transform: translateY(-50%);
            font-size: 13px;
            color: var(--text-muted);
            pointer-events: none;
        }

        .filter-search-input {
            width: 100%;
            padding: 8px 12px 8px 32px;
            border: 1.5px solid var(--border-color);
            border-radius: 8px;
            font-size: 13px;
            color: var(--charcoal);
            background: #fafaf9;
            outline: none;
            transition: all 0.15s;
        }

        .filter-search-input:focus {
            border-color: var(--terracotta);
            box-shadow: 0 0 0 3px rgba(194,65,12,0.1);
            background: #fff;
        }

        .btn-filter-apply {
            background: var(--charcoal);
            color: #fff;
            border: none;
            border-radius: 8px;
            padding: 8px 18px;
            font-size: 13px;
            font-weight: 800;
            cursor: pointer;
            transition: background 0.15s, transform 0.1s;
            white-space: nowrap;
        }

        .btn-filter-apply:hover {
            background: var(--charcoal-soft);
            transform: translateY(-1px);
        }

        .btn-reset-filter {
            font-size: 12px;
            font-weight: 700;
            color: var(--text-muted);
            text-decoration: none;
            padding: 6px 10px;
            border-radius: 6px;
            transition: all 0.15s;
            white-space: nowrap;
        }

        .btn-reset-filter:hover {
            color: var(--charcoal);
            background: #f5f5f4;
        }

        .btn-z-report {
            background: #fff7ed;
            border: 1.5px solid #fed7aa;
            color: var(--terracotta);
            border-radius: 8px;
            padding: 8px 16px;
            font-size: 13px;
            font-weight: 800;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.15s;
            white-space: nowrap;
        }

        .btn-z-report:hover {
            background: #ffedd5;
            border-color: #fdba74;
            transform: translateY(-1px);
        }

        .btn-print-page {
            background: #f5f5f4;
            color: var(--charcoal);
            border: 1.5px solid var(--border-color);
            border-radius: 8px;
            padding: 8px 16px;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.15s;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            white-space: nowrap;
        }

        .btn-print-page:hover {
            background: #e7e5e4;
            transform: translateY(-1px);
        }

        /* Table */
        .table-card {
            background: #fff;
            border: 1.5px solid var(--border-color);
            border-radius: 16px;
            overflow: hidden;
            box-shadow: var(--shadow-box);
        }

        .orders-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13.5px;
        }

        .orders-table th {
            background: #fafaf9;
            color: var(--text-muted);
            font-weight: 800;
            padding: 13px 18px;
            border-bottom: 1.5px solid var(--border-color);
            text-transform: uppercase;
            font-size: 11px;
            letter-spacing: 0.5px;
            text-align: left;
        }

        .orders-table td {
            padding: 14px 18px;
            border-bottom: 1px solid #f5f5f4;
            color: var(--charcoal);
            vertical-align: middle;
        }

        .orders-table tr:last-child td { border-bottom: none; }

        .orders-table tbody tr:hover { background: #fafaf9; }

        .order-num-cell {
            font-size: 16px;
            font-weight: 900;
            color: var(--terracotta);
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 800;
            letter-spacing: 0.3px;
        }

        .status-badge.EN_PREPARATION {
            background: #fef3c7; color: #b45309; border: 1px solid #fde68a;
        }
        .status-badge.PRETE {
            background: #dbeafe; color: #1d4ed8; border: 1px solid #bfdbfe;
        }
        .status-badge.SERVIE {
            background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0;
        }

        .btn-view-ticket {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 6px 14px;
            border-radius: 8px;
            background: #f5f5f4;
            color: var(--charcoal);
            text-decoration: none;
            font-weight: 700;
            font-size: 12px;
            border: 1.5px solid var(--border-color);
            transition: all 0.15s;
        }

        .btn-view-ticket:hover {
            background: var(--charcoal);
            color: #fff;
            border-color: var(--charcoal);
        }

        .empty-table-row {
            text-align: center;
            color: var(--text-muted);
            padding: 60px;
            font-size: 14px;
        }

        /* Print */
        @media print {
            .pos-header, .filters-bar, .btn-print-page, .btn-view-ticket, .charts-accordion { display: none !important; }
            body { background: #fff !important; }
            .page-wrap { padding: 0 !important; }
        }

        /* ============================================================
           CHARTS ACCORDION & GRID
           ============================================================ */
        .charts-accordion {
            margin-bottom: 24px;
        }

        .charts-accordion-toggle {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 15px;
            font-weight: 900;
            color: var(--charcoal);
            cursor: pointer;
            padding: 14px 20px;
            background: #fff;
            border: 1.5px solid var(--border-color);
            border-radius: 14px;
            box-shadow: var(--shadow-box);
            list-style: none;
            transition: border-color 0.15s;
        }

        .charts-accordion-toggle::-webkit-details-marker { display: none; }

        .charts-accordion-toggle:hover {
            border-color: #d6d3d1;
        }

        .charts-accordion-toggle .chevron-icon {
            margin-left: auto;
            transition: transform 0.2s;
        }

        .charts-accordion[open] .charts-accordion-toggle .chevron-icon {
            transform: rotate(180deg);
        }

        .charts-accordion[open] .charts-accordion-toggle {
            border-radius: 14px 14px 0 0;
            border-bottom-color: transparent;
        }

        .charts-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
            gap: 16px;
            padding: 20px;
            background: #fff;
            border: 1.5px solid var(--border-color);
            border-top: none;
            border-radius: 0 0 14px 14px;
            box-shadow: var(--shadow-box);
        }

        .chart-card {
            background: #fafaf9;
            border: 1.5px solid var(--border-color);
            border-radius: 12px;
            padding: 16px 18px;
        }

        .chart-card-wide {
            grid-column: 1 / -1;
        }

        .chart-title {
            font-size: 12px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--text-muted);
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .chart-body {
            min-height: 80px;
        }

        .chart-svg {
            width: 100%;
            height: auto;
            max-height: 200px;
        }

        .chart-empty {
            text-align: center;
            color: var(--text-muted);
            font-size: 13px;
            font-weight: 600;
            padding: 30px 0;
        }

        /* Horizontal Bar Rows */
        .hbar-row {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 10px;
        }

        .hbar-row:last-child { margin-bottom: 0; }

        .hbar-label {
            font-size: 12.5px;
            font-weight: 700;
            color: var(--charcoal);
            min-width: 70px;
            flex-shrink: 0;
        }

        .hbar-rank {
            width: 22px;
            height: 22px;
            border-radius: 6px;
            background: var(--charcoal);
            color: #fff;
            font-size: 11px;
            font-weight: 900;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .hbar-track {
            flex: 1;
            height: 18px;
            background: #e7e5e4;
            border-radius: 6px;
            overflow: hidden;
        }

        .hbar-fill {
            height: 100%;
            border-radius: 6px;
            transition: width 0.4s ease;
            min-width: 4px;
        }

        .hbar-value {
            font-size: 12.5px;
            font-weight: 800;
            color: var(--charcoal);
            min-width: 55px;
            text-align: right;
        }

        .hbar-value small {
            color: var(--text-muted);
            font-weight: 600;
        }
    </style>
</head>
<body>

    <header class="pos-header">
        <div class="brand-section">
            <div class="brand-badge">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M3 3v18h18"/>
                    <path d="M18 17V9"/>
                    <path d="M13 17V5"/>
                    <path d="M8 17v-3"/>
                </svg>
            </div>
            <div>
                <h1 class="brand-title"><?= htmlspecialchars($restaurant['nom']) ?></h1>
                <div class="brand-subtitle">Tableau de Bord · Historique des Ventes &amp; Activité (<?= htmlspecialchars($restaurant['ville'] ?: 'Maroc') ?>)</div>
            </div>
        </div>

        <div class="header-right">
            <?php if ($isSuperAdmin && !empty($allRestaurants)): ?>
                <div style="display:flex; align-items:center; gap:6px; background:#fff; padding:4px 10px; border-radius:8px; border:1.5px solid var(--border-color);">
                    <span style="font-size:11px; font-weight:800; color:var(--text-muted);">TENANT:</span>
                    <select onchange="location.href='commandes.php?restaurant_id=' + this.value" style="border:none; background:transparent; font-weight:700; font-size:13px; outline:none; cursor:pointer;">
                        <?php foreach ($allRestaurants as $r): ?>
                            <option value="<?= $r['id_restaurant'] ?>" <?= $r['id_restaurant'] === $idRestaurant ? 'selected' : '' ?>>
                                <?= htmlspecialchars($r['nom']) ?> (<?= htmlspecialchars($r['ville']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>

            <?php if ($isSuperAdmin): ?>
                <a href="superadmin.php" class="btn-header-link" style="border-color:#fbcfe8; color:#db2777; font-weight:800;">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg> Tour SaaS
                </a>
            <?php endif; ?>

            <a href="menu_admin.php" class="btn-header-link" title="Gérer la carte et les prix">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg> Studio Menu
            </a>
            <a href="index.php" class="btn-header-link">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg> Caisse
            </a>
            <a href="cuisine.php" class="btn-header-link">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 13.87A4 4 0 0 1 7.41 6a5.11 5.11 0 0 1 1.05-1.54 5 5 0 0 1 7.08 0A5.11 5.11 0 0 1 16.59 6 4 4 0 0 1 18 13.87V21H6Z"/><line x1="6" y1="17" x2="18" y2="17"/></svg> Cuisine (KDS)
            </a>
            <a href="login.php?action=logout" class="btn-header-link" style="border-color:#fca5a5; color:#b91c1c;">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg> Déconnexion
            </a>
        </div>
    </header>

    <div class="page-wrap">

        <!-- KPI Cards -->
        <div class="kpi-grid">
            <div class="kpi-card">
                <div class="kpi-label">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:middle; margin-right:4px;"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/></svg>Total Commandes
                </div>
                <div class="kpi-value"><?= (int)$rev['total_count'] ?></div>
                <div class="kpi-sub">sur la période sélectionnée</div>
            </div>
            <div class="kpi-card">
                <div class="kpi-label">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:middle; margin-right:4px;"><circle cx="12" cy="12" r="10"/><path d="M16 8h-6a2 2 0 1 0 0 4h4a2 2 0 1 1 0 4H8"/><path d="M12 18V6"/></svg>Chiffre d'Affaires
                </div>
                <div class="kpi-value terracotta"><?= number_format((float)$rev['ca_total'], 2) ?> <small style="font-size:16px">DH</small></div>
                <div class="kpi-sub">Valeur totale générée</div>
            </div>
            <div class="kpi-card">
                <div class="kpi-label">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:middle; margin-right:4px;"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>CA Encaissé (Servi)
                </div>
                <div class="kpi-value olive"><?= number_format((float)$rev['ca_encaisse'], 2) ?> <small style="font-size:16px">DH</small></div>
                <div class="kpi-sub">Commandes clôturées</div>
            </div>
            <div class="kpi-card">
                <div class="kpi-label">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:middle; margin-right:4px; color:#d97706;"><path d="M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.38-.5-2-1-3-1.072-2.143-.224-4.054 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7 7 0 1 1-14 0c0-1.153.433-2.294 1-3a2.5 2.5 0 0 0 2.5 3.5z"/></svg>En Préparation
                </div>
                <div class="kpi-value" style="color:#d97706"><?= (int)$rev['cnt_prep'] ?></div>
                <div class="kpi-sub">commandes en cuisine</div>
            </div>
            <div class="kpi-card">
                <div class="kpi-label">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:middle; margin-right:4px; color:#1d4ed8;"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>Prêtes au Comptoir
                </div>
                <div class="kpi-value" style="color:#1d4ed8"><?= (int)$rev['cnt_prete'] ?></div>
                <div class="kpi-sub">en attente de retrait</div>
            </div>
            <div class="kpi-card">
                <div class="kpi-label">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:middle; margin-right:4px; color:var(--olive);"><polyline points="20 6 9 17 4 12"/><polyline points="20 12 9 23 4 18"/></svg>Servies / Clôturées
                </div>
                <div class="kpi-value" style="color:var(--olive)"><?= (int)$rev['cnt_servie'] ?></div>
                <div class="kpi-sub">sur la période</div>
            </div>
        </div>

        <!-- Analyse des Ventes (Charts) -->
        <details class="charts-accordion" open>
            <summary class="charts-accordion-toggle">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><path d="M18 17V9"/><path d="M13 17V5"/><path d="M8 17v-3"/></svg>
                Analyse des Ventes
                <span class="chevron-icon">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
                </span>
            </summary>
            <div class="charts-grid">

                <!-- Chart 1: Courbe d'Affluence Horaire -->
                <div class="chart-card chart-card-wide">
                    <div class="chart-title">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                        Affluence Horaire
                    </div>
                    <div class="chart-body">
                        <?php if (empty($hourlyMap)): ?>
                            <div class="chart-empty">Aucune donnée pour cette période</div>
                        <?php else: ?>
                            <svg viewBox="0 0 600 180" class="chart-svg" preserveAspectRatio="xMidYMid meet">
                                <!-- Grid lines -->
                                <?php for ($g = 0; $g <= 4; $g++): ?>
                                    <?php $gy = 160 - ($g * 35); ?>
                                    <line x1="40" y1="<?= $gy ?>" x2="580" y2="<?= $gy ?>" stroke="#e7e5e4" stroke-width="1"/>
                                    <text x="35" y="<?= $gy + 4 ?>" text-anchor="end" fill="#78716c" font-size="10" font-weight="600"><?= round($maxHourly * $g / 4) ?></text>
                                <?php endfor; ?>

                                <!-- Bars -->
                                <?php
                                $hours = range(min(array_keys($hourlyMap)), max(array_keys($hourlyMap)));
                                $barCount = count($hours);
                                $barWidth = min(28, max(12, (int)(520 / max(1, $barCount))));
                                $gap = max(2, (int)((520 - $barCount * $barWidth) / max(1, $barCount + 1)));
                                ?>
                                <?php foreach ($hours as $i => $hr): ?>
                                    <?php
                                    $val = $hourlyMap[$hr] ?? 0;
                                    $barH = ($val / $maxHourly) * 130;
                                    $x = 50 + $i * ($barWidth + $gap);
                                    $y = 160 - $barH;
                                    $fill = ($val === $maxHourly) ? '#c2410c' : '#ea580c';
                                    $opacity = ($val === $maxHourly) ? '1' : '0.7';
                                    ?>
                                    <rect x="<?= $x ?>" y="<?= $y ?>" width="<?= $barWidth ?>" height="<?= max(2, $barH) ?>"
                                          rx="4" fill="<?= $fill ?>" opacity="<?= $opacity ?>">
                                        <title><?= $hr ?>h : <?= $val ?> commande(s)</title>
                                    </rect>
                                    <?php if ($val > 0): ?>
                                        <text x="<?= $x + $barWidth/2 ?>" y="<?= $y - 5 ?>" text-anchor="middle"
                                              fill="var(--charcoal)" font-size="10" font-weight="800"><?= $val ?></text>
                                    <?php endif; ?>
                                    <text x="<?= $x + $barWidth/2 ?>" y="174" text-anchor="middle"
                                          fill="#78716c" font-size="9" font-weight="600"><?= $hr ?>h</text>
                                <?php endforeach; ?>
                            </svg>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Chart 2: Répartition par Catégorie (Horizontal Bars) -->
                <div class="chart-card">
                    <div class="chart-title">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21.21 15.89A10 10 0 1 1 8 2.83"/><path d="M22 12A10 10 0 0 0 12 2v10z"/></svg>
                        Répartition par Catégorie
                    </div>
                    <div class="chart-body">
                        <?php if (empty($catData)): ?>
                            <div class="chart-empty">Aucune donnée</div>
                        <?php else: ?>
                            <?php
                            $catColors = ['#c2410c', '#d97706', '#15803d', '#0284c7', '#7c3aed'];
                            foreach ($catData as $ci => $cat):
                                $pct = round(((int)$cat['total_qty'] / $totalCatQty) * 100);
                                $color = $catColors[$ci % count($catColors)];
                                $labels = [
                                    'Ma3qouda' => 'Sandwichs',
                                    'Combos'   => 'Combos',
                                    'Duo'      => 'Duo',
                                    'Boissons' => 'Boissons'
                                ];
                                $label = $labels[$cat['categorie']] ?? $cat['categorie'];
                            ?>
                            <div class="hbar-row">
                                <div class="hbar-label"><?= htmlspecialchars($label) ?></div>
                                <div class="hbar-track">
                                    <div class="hbar-fill" style="width:<?= $pct ?>%; background:<?= $color ?>;"></div>
                                </div>
                                <div class="hbar-value"><?= (int)$cat['total_qty'] ?> <small>(<?= $pct ?>%)</small></div>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Chart 3: Top 5 Best-Sellers -->
                <div class="chart-card">
                    <div class="chart-title">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                        Top 5 Produits
                    </div>
                    <div class="chart-body">
                        <?php if (empty($topData)): ?>
                            <div class="chart-empty">Aucune donnée</div>
                        <?php else: ?>
                            <?php foreach ($topData as $ti => $top):
                                $topPct = round(((int)$top['total_qty'] / $maxTopQty) * 100);
                                $rankColors = ['#c2410c', '#ea580c', '#d97706', '#15803d', '#0284c7'];
                            ?>
                            <div class="hbar-row">
                                <div class="hbar-rank"><?= $ti + 1 ?></div>
                                <div class="hbar-label" style="flex:1.5;"><?= htmlspecialchars($top['nom']) ?></div>
                                <div class="hbar-track">
                                    <div class="hbar-fill" style="width:<?= $topPct ?>%; background:<?= $rankColors[$ti] ?>;"></div>
                                </div>
                                <div class="hbar-value" style="font-weight:900;"><?= (int)$top['total_qty'] ?></div>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

            </div>
        </details>

        <!-- Filters Bar -->
        <form method="GET" action="" id="filterForm">
            <div class="filters-bar">
                <div class="filters-left">
                    <div class="filter-group">
                        <label for="filterDate">Période :</label>
                        <select id="filterDate" name="date" class="filter-select" onchange="this.form.submit()">
                            <option value="all"   <?= $filterDate === 'all'   ? 'selected' : '' ?>>Tout l'historique</option>
                            <option value="today" <?= $filterDate === 'today' ? 'selected' : '' ?>>Aujourd'hui</option>
                            <option value="week"  <?= $filterDate === 'week'  ? 'selected' : '' ?>>7 derniers jours</option>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label for="filterStatut">Statut :</label>
                        <select id="filterStatut" name="statut" class="filter-select" onchange="this.form.submit()">
                            <option value="all"            <?= $filterStatut === 'all'            ? 'selected' : '' ?>>Tous les statuts</option>
                            <option value="EN_PREPARATION" <?= $filterStatut === 'EN_PREPARATION' ? 'selected' : '' ?>>En Préparation</option>
                            <option value="PRETE"          <?= $filterStatut === 'PRETE'          ? 'selected' : '' ?>>Prête</option>
                            <option value="SERVIE"         <?= $filterStatut === 'SERVIE'         ? 'selected' : '' ?>>Servie</option>
                        </select>
                    </div>

                    <div class="filter-search-wrap">
                        <span class="filter-search-icon">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                        </span>
                        <input type="text" id="filterSearchInput" name="q" class="filter-search-input"
                               placeholder="Recherche N°, caissier, sandwich..."
                               value="<?= htmlspecialchars($filterSearch) ?>" autocomplete="off">
                    </div>

                    <button type="submit" class="btn-filter-apply">Filtrer</button>
                    <a href="commandes.php?date=all" class="btn-reset-filter">Réinitialiser</a>
                </div>

                <div class="filters-right">
                    <a href="commandes.php?export=csv<?= !empty($_GET['date']) ? '&date=' . urlencode($_GET['date']) : '' ?><?= !empty($_GET['statut']) ? '&statut=' . urlencode($_GET['statut']) : '' ?><?= !empty($_GET['q']) ? '&q=' . urlencode($_GET['q']) : '' ?>" class="btn-z-report" style="background:#0F172A; border-color:#0F172A;" title="Télécharger l'historique sous format Excel / CSV">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="8" y1="13" x2="16" y2="13"/><line x1="8" y1="17" x2="16" y2="17"/></svg>
                        Exporter Excel (CSV)
                    </a>

                    <a href="tickets.php?report=z" target="_blank" class="btn-z-report" title="Clôture Journalière Certifiée">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/></svg>
                        Rapport Z (Clôture)
                    </a>

                    <button type="button" class="btn-print-page" onclick="window.print()" title="Imprimer l'historique">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
                        Imprimer
                    </button>
                </div>
            </div>
        </form>

        <!-- Orders Table -->
        <div class="table-card">
            <table class="orders-table">
                <thead>
                    <tr>
                        <th>N° Commande</th>
                        <th>Date &amp; Heure</th>
                        <th>Caissier</th>
                        <th>Sandwichs</th>
                        <th>Montant</th>
                        <th>Statut</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($commandes)): ?>
                        <tr>
                            <td colspan="7" class="empty-table-row">
                                <div style="color:var(--text-muted); margin-bottom:10px;">
                                    <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/>
                                        <rect x="8" y="2" width="8" height="4" rx="1" ry="1"/>
                                    </svg>
                                </div>
                                Aucune commande trouvée pour ces critères.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($commandes as $cmd): ?>
                            <?php
                            $statusLabels = [
                                'EN_PREPARATION' => 'En Préparation',
                                'PRETE'          => 'Prête',
                                'SERVIE'         => 'Servie',
                            ];
                            $label = $statusLabels[$cmd['statut']] ?? $cmd['statut'];
                            ?>
                            <tr>
                                <td>
                                    <span class="order-num-cell">N° <?= htmlspecialchars($cmd['numero_commande']) ?></span>
                                </td>
                                <td>
                                    <?php
                                    $dt = new DateTime($cmd['date_commande']);
                                    echo $dt->format('d/m/Y') . '<br><small style="color:var(--text-muted); font-size:12px;">' . $dt->format('H:i:s') . '</small>';
                                    ?>
                                </td>
                                <td><?= htmlspecialchars($cmd['nom_caissier'] ?? 'Caisse') ?></td>
                                <td>
                                    <strong><?= (int)$cmd['total_sandwichs'] ?></strong>
                                    <small style="color:var(--text-muted);">(<?= (int)$cmd['nb_lignes'] ?> ligne<?= $cmd['nb_lignes'] > 1 ? 's' : '' ?>)</small>
                                </td>
                                <td>
                                    <strong style="font-size:15px; color:var(--terracotta);">
                                        <?= number_format((float)$cmd['montant_total'], 2) ?> DH
                                    </strong>
                                </td>
                                <td>
                                    <span class="status-badge <?= htmlspecialchars($cmd['statut']) ?>">
                                        <?= $label ?>
                                    </span>
                                </td>
                                <td>
                                    <a href="tickets.php?id=<?= (int)$cmd['id_commande'] ?>" class="btn-view-ticket" target="_blank">
                                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:middle; margin-right:4px;"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>Tickets
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div style="margin-top: 14px; font-size:12px; color:var(--text-muted); text-align:right;">
            <span id="displayedCount"><?= count($commandes) ?></span> commande(s) affichée(s) · Données en temps réel
        </div>

    </div>

    <script>
    document.addEventListener('DOMContentLoaded', () => {
        const searchInput = document.getElementById('filterSearchInput');
        const tableBody = document.querySelector('.orders-table tbody');
        const countSpan = document.getElementById('displayedCount');
        const rows = tableBody ? Array.from(tableBody.querySelectorAll('tr')) : [];

        if (searchInput && rows.length > 0) {
            searchInput.addEventListener('input', () => {
                const term = searchInput.value.toLowerCase().trim();
                let visible = 0;
                rows.forEach(r => {
                    if (r.querySelector('.empty-table-row')) return;
                    const text = r.textContent.toLowerCase();
                    const matches = !term || text.includes(term);
                    r.style.display = matches ? '' : 'none';
                    if (matches) visible++;
                });
                if (countSpan) countSpan.textContent = visible;
            });
        }
    });
    </script>
</body>
</html>
