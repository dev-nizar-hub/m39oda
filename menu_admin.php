<?php
/**
 * RestoOS — Espace Gérant (Studio Menu & Opérations)
 * Architecture Haute Fidélité inspirée de Linear & Stripe
 * Permet au gérant du restaurant de :
 * 1. Gérer le catalogue de plats et recettes
 * 2. Basculer instantanément la disponibilité des stocks en caisse
 * 3. Gérer les ingrédients et suppléments payants
 * 4. Consulter la clôture de caisse (Rapport Z)
 * 5. Communiquer directement avec le support plateforme (Tickets)
 */

declare(strict_types=1);

require_once __DIR__ . '/config/auth.php';
$currentUser = requireRole(['ADMIN', 'SUPERADMIN']);

/** @var PDO $pdo */
$pdo = require __DIR__ . '/config/db.php';

// Resolve active tenant restaurant
$idRestaurant = (int)($_SESSION['restaurant_id'] ?? 1);

// If superadmin, allow switching restaurants via GET parameter
if ($currentUser['role'] === 'SUPERADMIN') {
    if (isset($_GET['restaurant_id']) && is_numeric($_GET['restaurant_id'])) {
        $idRestaurant = (int)$_GET['restaurant_id'];
        $_SESSION['restaurant_id'] = $idRestaurant;
    } elseif (isset($_GET['r']) && is_numeric($_GET['r'])) {
        $idRestaurant = (int)$_GET['r'];
        $_SESSION['restaurant_id'] = $idRestaurant;
    }
}

// Fetch restaurant details
$stmtResto = $pdo->prepare("SELECT * FROM restaurants WHERE id_restaurant = :id");
$stmtResto->execute(['id' => $idRestaurant]);
$restaurant = $stmtResto->fetch();
if (!$restaurant) {
    $restaurant = $pdo->query("SELECT * FROM restaurants ORDER BY id_restaurant ASC LIMIT 1")->fetch();
    $idRestaurant = (int)($restaurant['id_restaurant'] ?? 1);
    $_SESSION['restaurant_id'] = $idRestaurant;
}

// Plan Quotas & Verification
$planCode = strtoupper($restaurant['plan'] ?? 'PRO');
$planQuota = [
    'nom'          => 'Pro Restaurant',
    'max_produits' => 100,
    'max_employes' => 8
];
try {
    $stmtPL = $pdo->prepare("SELECT * FROM plans WHERE code = ?");
    $stmtPL->execute([$planCode]);
    $plRow = $stmtPL->fetch(PDO::FETCH_ASSOC);
    if ($plRow) {
        $planQuota['nom']          = $plRow['nom'];
        $planQuota['max_produits'] = (int)$plRow['max_produits'];
        $planQuota['max_employes'] = (int)$plRow['max_employes'];
    }
} catch (Throwable $e) {}

$totalProducts = (int)$pdo->query("SELECT COUNT(*) FROM sandwichs WHERE id_restaurant = $idRestaurant")->fetchColumn();
$quotaReached = ($currentUser['role'] !== 'SUPERADMIN' && $totalProducts >= $planQuota['max_produits']);

// Handle Form Submissions
$notification = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // 1. Ajouter un Plat
    if ($action === 'create_sandwich') {
        if ($quotaReached) {
            $notification = [
                'type' => 'error',
                'message' => "Quota de produits atteint ({$totalProducts} / {$planQuota['max_produits']}) pour le forfait {$planQuota['nom']}. Contactez la plateforme pour surclasser votre offre."
            ];
        } else {
            $nom         = trim($_POST['nom'] ?? '');
            $prix        = (float)($_POST['prix'] ?? 0);
            $categorie   = trim($_POST['categorie'] ?? 'Ma3qouda');
            $badge       = trim($_POST['badge'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $imageUrl    = trim($_POST['image_url'] ?? '');

            if ($nom !== '' && $prix > 0) {
                $stmt = $pdo->prepare(
                    "INSERT INTO sandwichs (id_restaurant, nom, prix, categorie, badge, description, image_url, disponible)
                     VALUES (:r, :nom, :prix, :cat, :badge, :desc, :img, 1)"
                );
                $stmt->execute([
                    'r'     => $idRestaurant,
                    'nom'   => $nom,
                    'prix'  => $prix,
                    'cat'   => $categorie,
                    'badge' => $badge ?: null,
                    'desc'  => $description ?: null,
                    'img'   => $imageUrl ?: 'https://images.unsplash.com/photo-1550547660-d9450f859349?w=500'
                ]);
                $notification = ['type' => 'success', 'message' => "Le produit \"$nom\" a été ajouté au catalogue avec succès."];
                $totalProducts++;
                $quotaReached = ($currentUser['role'] !== 'SUPERADMIN' && $totalProducts >= $planQuota['max_produits']);
            } else {
                $notification = ['type' => 'error', 'message' => "Veuillez renseigner un nom valide et un prix supérieur à 0 DH."];
            }
        }
    }

    // 2. Modifier un Plat
    elseif ($action === 'update_sandwich') {
        $idSandwich  = (int)($_POST['id_sandwich'] ?? 0);
        $nom         = trim($_POST['nom'] ?? '');
        $prix        = (float)($_POST['prix'] ?? 0);
        $categorie   = trim($_POST['categorie'] ?? 'Ma3qouda');
        $badge       = trim($_POST['badge'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $imageUrl    = trim($_POST['image_url'] ?? '');

        if ($idSandwich > 0 && $nom !== '' && $prix > 0) {
            $stmt = $pdo->prepare(
                "UPDATE sandwichs 
                 SET nom = :nom, prix = :prix, categorie = :cat, badge = :badge, description = :desc, image_url = :img
                 WHERE id_sandwich = :id AND id_restaurant = :r"
            );
            $stmt->execute([
                'nom'   => $nom,
                'prix'  => $prix,
                'cat'   => $categorie,
                'badge' => $badge ?: null,
                'desc'  => $description ?: null,
                'img'   => $imageUrl,
                'id'    => $idSandwich,
                'r'     => $idRestaurant
            ]);
            $notification = ['type' => 'success', 'message' => "Le plat \"$nom\" a été mis à jour."];
        }
    }

    // 3. Supprimer un Plat
    elseif ($action === 'delete_sandwich') {
        $idSandwich = (int)($_POST['id_sandwich'] ?? 0);
        if ($idSandwich > 0) {
            $stmt = $pdo->prepare("DELETE FROM sandwichs WHERE id_sandwich = :id AND id_restaurant = :r");
            $stmt->execute(['id' => $idSandwich, 'r' => $idRestaurant]);
            $notification = ['type' => 'success', 'message' => "Le plat a été retiré de votre carte."];
            $totalProducts = max(0, $totalProducts - 1);
            $quotaReached = ($currentUser['role'] !== 'SUPERADMIN' && $totalProducts >= $planQuota['max_produits']);
        }
    }

    // 4. Basculer Disponibilité Stock
    elseif ($action === 'toggle_disponible') {
        $idSandwich = (int)($_POST['id_sandwich'] ?? 0);
        if ($idSandwich > 0) {
            $stmt = $pdo->prepare(
                "UPDATE sandwichs 
                 SET disponible = IF(disponible = 1, 0, 1) 
                 WHERE id_sandwich = :id AND id_restaurant = :r"
            );
            $stmt->execute(['id' => $idSandwich, 'r' => $idRestaurant]);
            $notification = ['type' => 'success', 'message' => "Statut de stock actualisé pour la caisse tactile."];
        }
    }

    // 5. Ajouter un Ingrédient / Extra
    elseif ($action === 'create_ingredient') {
        $nom = trim($_POST['nom_ingredient'] ?? '');
        $prix = (float)($_POST['prix_supplement'] ?? 0);
        if ($nom !== '') {
            $stmt = $pdo->prepare("INSERT INTO ingredients (id_restaurant, nom, prix_supplement) VALUES (:r, :nom, :prix)");
            $stmt->execute(['r' => $idRestaurant, 'nom' => $nom, 'prix' => $prix]);
            $notification = ['type' => 'success', 'message' => "L'extra \"$nom\" a été enregistré."];
        }
    }

    // 6. Supprimer un Ingrédient
    elseif ($action === 'delete_ingredient') {
        $idIng = (int)($_POST['id_ingredient'] ?? 0);
        if ($idIng > 0) {
            $stmt = $pdo->prepare("DELETE FROM ingredients WHERE id_ingredient = :id AND id_restaurant = :r");
            $stmt->execute(['id' => $idIng, 'r' => $idRestaurant]);
            $notification = ['type' => 'success', 'message' => "L'ingrédient a été supprimé."];
        }
    }

    // 7. Soumettre un Ticket de Support
    elseif ($action === 'create_ticket') {
        $sujet    = trim($_POST['sujet'] ?? '');
        $message  = trim($_POST['message'] ?? '');
        $priorite = in_array($_POST['priorite'] ?? '', ['BASSE', 'MOYENNE', 'HAUTE', 'URGENTE'], true) ? $_POST['priorite'] : 'MOYENNE';

        if ($sujet !== '' && $message !== '') {
            $stmt = $pdo->prepare(
                "INSERT INTO tickets_support (id_restaurant, sujet, message, priorite, statut, date_creation)
                 VALUES (:r, :s, :m, :p, 'OUVERT', NOW())"
            );
            $stmt->execute(['r' => $idRestaurant, 's' => $sujet, 'm' => $message, 'p' => $priorite]);
            $notification = ['type' => 'success', 'message' => "Votre demande d'assistance #{$pdo->lastInsertId()} a été transmise à l'administrateur RestoOS."];
        } else {
            $notification = ['type' => 'error', 'message' => "Veuillez préciser le sujet et le détail de votre demande."];
        }
    }
}

// Fetch all sandwiches for this restaurant
$stmt = $pdo->prepare("SELECT * FROM sandwichs WHERE id_restaurant = :r ORDER BY categorie ASC, nom ASC");
$stmt->execute(['r' => $idRestaurant]);
$sandwiches = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch ingredients for this restaurant
$stmtIng = $pdo->prepare("SELECT * FROM ingredients WHERE id_restaurant = :r ORDER BY prix_supplement ASC, nom ASC");
$stmtIng->execute(['r' => $idRestaurant]);
$ingredients = $stmtIng->fetchAll(PDO::FETCH_ASSOC);

// Fetch support tickets for this restaurant
$stmtTickets = $pdo->prepare("SELECT * FROM tickets_support WHERE id_restaurant = :r ORDER BY date_creation DESC");
$stmtTickets->execute(['r' => $idRestaurant]);
$tickets = $stmtTickets->fetchAll(PDO::FETCH_ASSOC);

// Distinct Categories for Filters
$categories = [];
$inStockCount = 0;
$outOfStockCount = 0;
foreach ($sandwiches as $s) {
    if (!in_array($s['categorie'], $categories, true)) {
        $categories[] = $s['categorie'];
    }
    if ((int)$s['disponible'] === 1) {
        $inStockCount++;
    } else {
        $outOfStockCount++;
    }
}

// Fetch list of restaurants for selector (if superadmin)
$allRestaurants = [];
if ($currentUser['role'] === 'SUPERADMIN') {
    $allRestaurants = $pdo->query("SELECT id_restaurant, nom, ville FROM restaurants ORDER BY nom ASC")->fetchAll(PDO::FETCH_ASSOC);
}
$initials = strtoupper(substr(preg_replace('/[^a-zA-Z0-9]/', '', $restaurant['nom'] ?? 'RO'), 0, 2)) ?: 'RO';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($restaurant['nom']) ?> — Studio Menu & Recettes · RestoOS</title>
    <link rel="icon" type="image/svg+xml" href="assets/img/favicon.svg">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-page: #F8FAFC;
            --surface-card: #FFFFFF;
            --text-heading: #0F172A;
            --text-body: #334155;
            --text-muted: #64748B;
            --border-subtle: #E2E8F0;
            --primary: #4F46E5;
            --primary-hover: #4338CA;
            --primary-light: #EEF2FF;
            --success: #10B981;
            --success-light: #ECFDF5;
            --danger: #EF4444;
            --danger-light: #FEF2F2;
            --warning: #F59E0B;
            --radius-card: 14px;
            --radius-btn: 8px;
            --shadow-subtle: 0 1px 3px 0 rgba(0, 0, 0, 0.05), 0 1px 2px -1px rgba(0, 0, 0, 0.05);
            --shadow-card: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -2px rgba(0, 0, 0, 0.05);
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background-color: var(--bg-page);
            color: var(--text-body);
            min-height: 100vh;
            -webkit-font-smoothing: antialiased;
        }

        /* Top Navigation Bar */
        .workspace-header {
            background: #FFFFFF;
            border-bottom: 1px solid var(--border-subtle);
            padding: 0 28px;
            height: 68px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: sticky;
            top: 0;
            z-index: 40;
        }

        .brand-cluster {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .brand-monogram {
            width: 38px;
            height: 38px;
            background: #0F172A;
            color: #FFFFFF;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            font-size: 14px;
            letter-spacing: -0.02em;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.06);
        }

        .brand-meta {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }

        .brand-title-row {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .brand-name {
            font-size: 16px;
            font-weight: 800;
            color: var(--text-heading);
            letter-spacing: -0.02em;
        }

        .plan-quota-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 2px 8px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.02em;
        }

        .plan-quota-badge.normal {
            background: var(--primary-light);
            color: var(--primary);
            border: 1px solid #C7D2FE;
        }

        .plan-quota-badge.quota-full {
            background: var(--danger-light);
            color: var(--danger);
            border: 1px solid #FECACA;
        }

        .brand-subtitle {
            font-size: 12px;
            color: var(--text-muted);
            font-weight: 500;
        }

        .nav-actions {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .nav-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 14px;
            border-radius: var(--radius-btn);
            font-size: 13px;
            font-weight: 600;
            text-decoration: none;
            color: var(--text-heading);
            background: #FFFFFF;
            border: 1px solid var(--border-subtle);
            transition: all 0.15s ease;
        }

        .nav-btn:hover {
            background: #F8FAFC;
            border-color: #CBD5E1;
        }

        .nav-btn.primary-link {
            background: var(--primary-light);
            color: var(--primary);
            border-color: #C7D2FE;
        }

        .nav-btn.primary-link:hover {
            background: #E0E7FF;
        }

        .nav-btn.danger-link {
            color: var(--danger);
            border-color: #FECACA;
        }

        .nav-btn.danger-link:hover {
            background: var(--danger-light);
        }

        /* Container Layout */
        .workspace-body {
            max-width: 1400px;
            margin: 0 auto;
            padding: 28px 24px;
        }

        /* Hero / KPI Chips */
        .hero-banner {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 24px;
            gap: 16px;
            flex-wrap: wrap;
        }

        .hero-text h1 {
            font-size: 24px;
            font-weight: 800;
            color: var(--text-heading);
            letter-spacing: -0.03em;
            margin-bottom: 4px;
        }

        .hero-text p {
            font-size: 13.5px;
            color: var(--text-muted);
        }

        .stats-strip {
            display: flex;
            gap: 12px;
            margin-bottom: 24px;
            flex-wrap: wrap;
        }

        .stat-chip {
            background: var(--surface-card);
            border: 1px solid var(--border-subtle);
            border-radius: 10px;
            padding: 10px 16px;
            display: flex;
            align-items: center;
            gap: 10px;
            box-shadow: var(--shadow-subtle);
            flex: 1;
            min-width: 180px;
        }

        .stat-icon {
            width: 36px;
            height: 36px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .stat-meta {
            display: flex;
            flex-direction: column;
        }

        .stat-label {
            font-size: 11.5px;
            font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        .stat-number {
            font-size: 17px;
            font-weight: 800;
            color: var(--text-heading);
        }

        /* Notifications */
        .alert-banner {
            padding: 12px 18px;
            border-radius: 10px;
            font-size: 13.5px;
            font-weight: 600;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .alert-banner.success {
            background: var(--success-light);
            border: 1px solid #A7F3D0;
            color: #065F46;
        }

        .alert-banner.error {
            background: var(--danger-light);
            border: 1px solid #FECACA;
            color: #991B1B;
        }

        /* 2-Column Studio Grid */
        .studio-layout {
            display: grid;
            grid-template-columns: 1fr 360px;
            gap: 24px;
            align-items: start;
        }

        @media (max-width: 1080px) {
            .studio-layout {
                grid-template-columns: 1fr;
            }
        }

        /* Panel Card */
        .panel-card {
            background: var(--surface-card);
            border: 1px solid var(--border-subtle);
            border-radius: var(--radius-card);
            box-shadow: var(--shadow-subtle);
            overflow: hidden;
        }

        .panel-header {
            padding: 18px 22px;
            border-bottom: 1px solid var(--border-subtle);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
        }

        .panel-title {
            font-size: 15px;
            font-weight: 800;
            color: var(--text-heading);
            letter-spacing: -0.01em;
        }

        /* Filter Controls */
        .table-filter-bar {
            padding: 12px 22px;
            background: #FAFAFA;
            border-bottom: 1px solid var(--border-subtle);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
        }

        .category-pills {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
        }

        .category-pill-btn {
            background: #FFFFFF;
            border: 1px solid var(--border-subtle);
            color: var(--text-muted);
            padding: 5px 12px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.15s ease;
        }

        .category-pill-btn:hover {
            background: #F1F5F9;
            color: var(--text-heading);
        }

        .category-pill-btn.active {
            background: #0F172A;
            border-color: #0F172A;
            color: #FFFFFF;
        }

        .search-box {
            position: relative;
            width: 240px;
        }

        .search-box input {
            width: 100%;
            height: 34px;
            padding: 0 10px 0 32px;
            font-size: 12.5px;
            border-radius: 6px;
            border: 1px solid var(--border-subtle);
            background: #FFFFFF;
            outline: none;
            font-family: inherit;
        }

        .search-box input:focus {
            border-color: var(--primary);
        }

        .search-box svg {
            position: absolute;
            left: 10px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-muted);
            pointer-events: none;
        }

        /* Products Table */
        .products-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13.5px;
            text-align: left;
        }

        .products-table th {
            background: #F8FAFC;
            padding: 12px 18px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            color: var(--text-muted);
            letter-spacing: 0.05em;
            border-bottom: 1px solid var(--border-subtle);
        }

        .products-table td {
            padding: 14px 18px;
            border-bottom: 1px solid var(--border-subtle);
            vertical-align: middle;
        }

        .products-table tbody tr {
            transition: background-color 0.12s ease;
        }

        .products-table tbody tr:hover {
            background-color: #F8FAFC;
        }

        .prod-thumb {
            width: 44px;
            height: 44px;
            border-radius: 8px;
            object-fit: cover;
            border: 1px solid var(--border-subtle);
            background: #F1F5F9;
            display: block;
        }

        .prod-info-block {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }

        .prod-name {
            font-weight: 700;
            color: var(--text-heading);
            font-size: 14px;
            letter-spacing: -0.01em;
        }

        .prod-desc {
            font-size: 12px;
            color: var(--text-muted);
            max-width: 320px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .prod-tag {
            display: inline-block;
            font-size: 10px;
            font-weight: 700;
            padding: 2px 6px;
            border-radius: 4px;
            background: #FEF3C7;
            color: #92400E;
            margin-left: 6px;
        }

        .cat-pill {
            display: inline-block;
            background: #F1F5F9;
            color: #475569;
            font-size: 11.5px;
            font-weight: 600;
            padding: 3px 8px;
            border-radius: 6px;
        }

        .price-text {
            font-weight: 800;
            color: var(--text-heading);
            font-size: 14.5px;
        }

        /* Stock Toggle Pill */
        .stock-toggle-btn {
            border: none;
            padding: 5px 10px;
            border-radius: 999px;
            font-size: 11.5px;
            font-weight: 700;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            transition: transform 0.08s ease;
        }

        .stock-toggle-btn:active {
            transform: scale(0.96);
        }

        .stock-toggle-btn.is-active {
            background: var(--success-light);
            color: var(--success);
            border: 1px solid #A7F3D0;
        }

        .stock-toggle-btn.is-out {
            background: var(--danger-light);
            color: var(--danger);
            border: 1px solid #FECACA;
        }

        /* Action Buttons */
        .btn-edit-action {
            background: #FFFFFF;
            border: 1px solid var(--border-subtle);
            color: var(--text-body);
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 12.5px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.12s ease;
        }

        .btn-edit-action:hover {
            background: #F1F5F9;
            border-color: #CBD5E1;
        }

        .btn-delete-action {
            background: none;
            border: none;
            color: #94A3B8;
            cursor: pointer;
            padding: 6px 8px;
            font-size: 14px;
            border-radius: 6px;
            transition: color 0.12s ease;
        }

        .btn-delete-action:hover {
            color: var(--danger);
            background: var(--danger-light);
        }

        /* Primary Action Button */
        .btn-primary-action {
            background: var(--primary);
            color: #FFFFFF;
            border: none;
            padding: 9px 16px;
            border-radius: var(--radius-btn);
            font-size: 13.5px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 1px 2px rgba(79, 70, 229, 0.2);
            transition: background 0.15s ease;
        }

        .btn-primary-action:hover {
            background: var(--primary-hover);
        }

        .btn-primary-action:disabled {
            opacity: 0.55;
            cursor: not-allowed;
        }

        /* Right Column Form & Extras */
        .ingredient-add-form {
            padding: 16px;
            background: #F8FAFC;
            border-bottom: 1px solid var(--border-subtle);
        }

        .ingredient-add-form input {
            width: 100%;
            height: 38px;
            padding: 0 12px;
            font-size: 13px;
            border-radius: 6px;
            border: 1px solid var(--border-subtle);
            background: #FFFFFF;
            margin-bottom: 8px;
            box-sizing: border-box;
            outline: none;
            font-family: inherit;
        }

        .ingredient-add-form input:focus {
            border-color: var(--primary);
        }

        .ingredient-list {
            padding: 12px 16px;
            display: flex;
            flex-direction: column;
            gap: 6px;
            max-height: 480px;
            overflow-y: auto;
        }

        .ingredient-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: #FFFFFF;
            border: 1px solid var(--border-subtle);
            border-radius: 8px;
            padding: 9px 12px;
            transition: border-color 0.12s ease;
        }

        .ingredient-item:hover {
            border-color: #CBD5E1;
        }

        .ingredient-name {
            font-size: 13px;
            font-weight: 600;
            color: var(--text-heading);
        }

        .ingredient-price {
            font-size: 11.5px;
            font-weight: 700;
            color: #0284C7;
        }

        .ingredient-price.free {
            color: var(--success);
        }

        /* Modals */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(15, 23, 42, 0.5);
            backdrop-filter: blur(4px);
            z-index: 100;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .modal-overlay.active {
            display: flex;
        }

        .modal-card {
            background: #FFFFFF;
            border-radius: var(--radius-card);
            border: 1px solid var(--border-subtle);
            width: 100%;
            max-width: 580px;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
            overflow: hidden;
            animation: popIn 0.15s ease-out;
        }

        @keyframes popIn {
            from { transform: scale(0.96); opacity: 0; }
            to { transform: scale(1); opacity: 1; }
        }

        .modal-header {
            padding: 18px 22px;
            border-bottom: 1px solid var(--border-subtle);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .modal-header h3 {
            font-size: 16px;
            font-weight: 700;
            color: var(--text-heading);
        }

        .modal-close {
            background: none;
            border: none;
            font-size: 20px;
            color: var(--text-muted);
            cursor: pointer;
            padding: 4px;
        }

        .modal-body {
            padding: 22px;
            max-height: calc(85vh - 120px);
            overflow-y: auto;
        }

        .form-grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px;
            margin-bottom: 14px;
        }

        .form-field {
            display: flex;
            flex-direction: column;
            gap: 6px;
            margin-bottom: 14px;
        }

        .form-field label {
            font-size: 12.5px;
            font-weight: 700;
            color: var(--text-heading);
        }

        .form-field input,
        .form-field select,
        .form-field textarea {
            width: 100%;
            height: 40px;
            padding: 0 12px;
            font-size: 13.5px;
            border-radius: 7px;
            border: 1px solid var(--border-subtle);
            background: #FFFFFF;
            color: var(--text-heading);
            outline: none;
            font-family: inherit;
            box-sizing: border-box;
            transition: border-color 0.15s ease;
        }

        .form-field textarea {
            height: auto;
            min-height: 72px;
            padding: 8px 12px;
            resize: vertical;
        }

        .form-field input:focus,
        .form-field select:focus,
        .form-field textarea:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.12);
        }

        .modal-footer {
            padding: 14px 22px;
            background: #F8FAFC;
            border-top: 1px solid var(--border-subtle);
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }

        .btn-cancel {
            background: #FFFFFF;
            border: 1px solid var(--border-subtle);
            color: var(--text-body);
            padding: 8px 16px;
            border-radius: 7px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
        }

        .btn-submit {
            background: var(--primary);
            color: #FFFFFF;
            border: none;
            padding: 8px 18px;
            border-radius: 7px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
        }
    </style>
</head>
<body>

    <!-- 1. Top Navigation Bar -->
    <header class="workspace-header">
        <div class="brand-cluster">
            <div class="brand-monogram"><?= htmlspecialchars($initials) ?></div>
            <div class="brand-meta">
                <div class="brand-title-row">
                    <span class="brand-name"><?= htmlspecialchars($restaurant['nom']) ?></span>
                    <span class="plan-quota-badge <?= $quotaReached ? 'quota-full' : 'normal' ?>">
                        FORFAIT <?= htmlspecialchars($planCode) ?> · <?= $totalProducts ?>/<?= $planQuota['max_produits'] ?> plats
                    </span>
                </div>
                <span class="brand-subtitle"><?= htmlspecialchars($restaurant['ville'] ?? 'Maroc') ?> · Espace Gérant</span>
            </div>
        </div>

        <div class="nav-actions">
            <?php if ($currentUser['role'] === 'SUPERADMIN' && !empty($allRestaurants)): ?>
                <select onchange="location.href='menu_admin.php?r=' + this.value" style="height:34px; border-radius:6px; border:1px solid var(--border-subtle); font-size:12px; font-weight:600; padding:0 8px;">
                    <?php foreach ($allRestaurants as $ar): ?>
                        <option value="<?= $ar['id_restaurant'] ?>" <?= (int)$ar['id_restaurant'] === $idRestaurant ? 'selected' : '' ?>>
                            <?= htmlspecialchars($ar['nom']) ?> (<?= htmlspecialchars($ar['ville']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            <?php endif; ?>

            <a href="index.php" class="nav-btn" title="Ouvrir le terminal de caisse tactile">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>
                Caisse POS
            </a>
            <a href="commandes.php" class="nav-btn" title="Consulter l'historique des ventes">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                Ventes
            </a>
            <a href="tickets.php?report=z" target="_blank" class="nav-btn" title="Imprimer la clôture de caisse quotidienne">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
                Clôture Z
            </a>
            <button type="button" class="nav-btn primary-link" onclick="openModal('modalSupportDesk')" title="Contacter l'administrateur RestoOS">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                Support <?= count($tickets) > 0 ? '(' . count($tickets) . ')' : '' ?>
            </button>
            <?php if ($currentUser['role'] === 'SUPERADMIN'): ?>
                <a href="superadmin.php" class="nav-btn" style="background:#0F172A; color:#FFFFFF; border-color:#0F172A;">
                    Console SaaS
                </a>
            <?php endif; ?>
            <a href="login.php?action=logout" class="nav-btn danger-link" title="Fermer la session">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
            </a>
        </div>
    </header>

    <!-- 2. Workspace Body -->
    <main class="workspace-body">

        <!-- Hero Header -->
        <div class="hero-banner">
            <div class="hero-text">
                <h1>Studio Menu &amp; Recettes</h1>
                <p>Configurez la carte de votre établissement, vos tarifs et la disponibilité des stocks en temps réel.</p>
            </div>
            <div>
                <button type="button" class="btn-primary-action" onclick="openAddSandwichModal()" <?= $quotaReached ? 'disabled title="Quota de plats atteint pour votre forfait"' : '' ?>>
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                    Nouveau Produit
                </button>
            </div>
        </div>

        <!-- System Alerts -->
        <?php if ($notification): ?>
            <div class="alert-banner <?= $notification['type'] ?>">
                <?= htmlspecialchars($notification['message']) ?>
            </div>
        <?php endif; ?>

        <!-- KPI Strip -->
        <div class="stats-strip">
            <div class="stat-chip">
                <div class="stat-icon" style="background:#EEF2FF; color:#4F46E5;">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 11h16a3 3 0 0 0 0-6H4a3 3 0 0 0 0 6z"/><path d="M4 15h16a3 3 0 0 1 0 6H4a3 3 0 0 1 0-6z"/></svg>
                </div>
                <div class="stat-meta">
                    <span class="stat-label">Catalogue Menu</span>
                    <span class="stat-number"><?= count($sandwiches) ?> plats</span>
                </div>
            </div>

            <div class="stat-chip">
                <div class="stat-icon" style="background:#ECFDF5; color:#10B981;">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
                </div>
                <div class="stat-meta">
                    <span class="stat-label">En Vente Caisse</span>
                    <span class="stat-number"><?= $inStockCount ?> actifs</span>
                </div>
            </div>

            <div class="stat-chip">
                <div class="stat-icon" style="background:#FEF2F2; color:#EF4444;">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                </div>
                <div class="stat-meta">
                    <span class="stat-label">Rupture de Stock</span>
                    <span class="stat-number"><?= $outOfStockCount ?> masqués</span>
                </div>
            </div>

            <div class="stat-chip">
                <div class="stat-icon" style="background:#F0F9FF; color:#0284C7;">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M8 14s1.5 2 4 2 4-2 4-2"/></svg>
                </div>
                <div class="stat-meta">
                    <span class="stat-label">Extras & Suppléments</span>
                    <span class="stat-number"><?= count($ingredients) ?> options</span>
                </div>
            </div>
        </div>

        <!-- 3. Studio 2-Column Grid -->
        <div class="studio-layout">

            <!-- Left: Products Catalog Table -->
            <div class="panel-card">
                <div class="panel-header">
                    <span class="panel-title">Catalogue des Recettes &amp; Sandwichs (<?= count($sandwiches) ?>)</span>
                    <span style="font-size: 12px; color: var(--text-muted);">Cliquez sur le statut pour basculer la rupture en caisse</span>
                </div>

                <!-- Category Pills & Live Search -->
                <div class="table-filter-bar">
                    <div class="category-pills">
                        <button type="button" class="category-pill-btn active" onclick="filterCategory('ALL', this)">Tous (<?= count($sandwiches) ?>)</button>
                        <?php foreach ($categories as $cat): ?>
                            <button type="button" class="category-pill-btn" onclick="filterCategory('<?= htmlspecialchars($cat) ?>', this)">
                                <?= htmlspecialchars($cat) ?>
                            </button>
                        <?php endforeach; ?>
                    </div>
                    <div class="search-box">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                        <input type="text" id="catalogSearchInput" placeholder="Rechercher un plat..." onkeyup="filterMenuTable()">
                    </div>
                </div>

                <div style="overflow-x: auto;">
                    <table class="products-table" id="menuCatalogTable">
                        <thead>
                            <tr>
                                <th style="width: 54px;">Visuel</th>
                                <th>Produit</th>
                                <th>Catégorie</th>
                                <th>Tarif TTC</th>
                                <th>Disponibilité</th>
                                <th style="text-align: right;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($sandwiches)): ?>
                                <tr>
                                    <td colspan="6" style="text-align:center; padding: 48px; color: var(--text-muted);">
                                        Aucun produit au menu. Cliquez sur "Nouveau Produit" pour créer votre premier plat.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($sandwiches as $s): ?>
                                    <tr data-name="<?= strtolower(htmlspecialchars($s['nom'])) ?>" data-category="<?= htmlspecialchars($s['categorie']) ?>">
                                        <td>
                                            <img src="<?= htmlspecialchars($s['image_url'] ?: 'assets/img/dish-placeholder.svg') ?>" 
                                                 onerror="this.onerror=null; this.src='assets/img/dish-placeholder.svg';"
                                                 class="prod-thumb" alt="<?= htmlspecialchars($s['nom']) ?>" loading="lazy">
                                        </td>
                                        <td>
                                            <div class="prod-info-block">
                                                <div>
                                                    <span class="prod-name"><?= htmlspecialchars($s['nom']) ?></span>
                                                    <?php if (!empty($s['badge'])): ?>
                                                        <span class="prod-tag"><?= htmlspecialchars($s['badge']) ?></span>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="prod-desc" title="<?= htmlspecialchars($s['description'] ?? '') ?>">
                                                    <?= htmlspecialchars($s['description'] ?? 'Recette standard de la maison') ?>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="cat-pill"><?= htmlspecialchars($s['categorie']) ?></span>
                                        </td>
                                        <td>
                                            <span class="price-text"><?= number_format((float)$s['prix'], 2) ?> DH</span>
                                        </td>
                                        <td>
                                            <form method="POST" style="display:inline;">
                                                <input type="hidden" name="action" value="toggle_disponible">
                                                <input type="hidden" name="id_sandwich" value="<?= $s['id_sandwich'] ?>">
                                                <?php if ((int)$s['disponible'] === 1): ?>
                                                    <button type="submit" class="stock-toggle-btn is-active" title="Cliquer pour déclarer une rupture">
                                                        <span style="width:6px; height:6px; border-radius:50%; background:#10B981;"></span>
                                                        En Caisse
                                                    </button>
                                                <?php else: ?>
                                                    <button type="submit" class="stock-toggle-btn is-out" title="Cliquer pour réactiver en caisse">
                                                        <span style="width:6px; height:6px; border-radius:50%; background:#EF4444;"></span>
                                                        Rupture
                                                    </button>
                                                <?php endif; ?>
                                            </form>
                                        </td>
                                        <td style="text-align: right; white-space: nowrap;">
                                            <button type="button" class="btn-edit-action" onclick='openEditSandwichModal(<?= json_encode($s, JSON_HEX_APOS | JSON_HEX_TAG) ?>)'>
                                                Modifier
                                            </button>
                                            <form method="POST" style="display:inline;" onsubmit="return confirm('Retirer ce produit du menu ?');">
                                                <input type="hidden" name="action" value="delete_sandwich">
                                                <input type="hidden" name="id_sandwich" value="<?= $s['id_sandwich'] ?>">
                                                <button type="submit" class="btn-delete-action" title="Supprimer">
                                                    ✕
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Right: Ingredients & Extras -->
            <div class="panel-card">
                <div class="panel-header">
                    <span class="panel-title">Ingrédients &amp; Extras (<?= count($ingredients) ?>)</span>
                </div>

                <!-- Add Ingredient Form -->
                <form method="POST" class="ingredient-add-form">
                    <input type="hidden" name="action" value="create_ingredient">
                    <input type="text" name="nom_ingredient" placeholder="Nom de l'extra (ex: Fromage Fondu)" required>
                    <div style="display: flex; gap: 8px;">
                        <input type="number" step="0.5" min="0" name="prix_supplement" placeholder="Prix (+DH)" value="0.00" style="margin-bottom:0; width: 110px;" required>
                        <button type="submit" class="btn-primary-action" style="flex:1; justify-content:center; height:38px;">
                            + Ajouter
                        </button>
                    </div>
                </form>

                <!-- Scrollable Extras List -->
                <div class="ingredient-list">
                    <?php if (empty($ingredients)): ?>
                        <div style="text-align:center; padding: 24px; color: var(--text-muted); font-size:12.5px;">
                            Aucun ingrédient configuré.
                        </div>
                    <?php else: ?>
                        <?php foreach ($ingredients as $ing): ?>
                            <div class="ingredient-item">
                                <div>
                                    <div class="ingredient-name"><?= htmlspecialchars($ing['nom']) ?></div>
                                    <div class="ingredient-price <?= (float)$ing['prix_supplement'] > 0 ? '' : 'free' ?>">
                                        <?= (float)$ing['prix_supplement'] > 0 ? '+ ' . number_format((float)$ing['prix_supplement'], 2) . ' DH' : 'Inclus (Gratuit)' ?>
                                    </div>
                                </div>
                                <form method="POST" onsubmit="return confirm('Supprimer cet ingrédient ?');">
                                    <input type="hidden" name="action" value="delete_ingredient">
                                    <input type="hidden" name="id_ingredient" value="<?= $ing['id_ingredient'] ?>">
                                    <button type="submit" class="btn-delete-action" title="Supprimer">✕</button>
                                </form>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

        </div>

    </main>

    <!-- Modal 1 : Ajouter / Modifier Plat -->
    <div class="modal-overlay" id="modalSandwichForm" onclick="if(event.target===this)closeModal('modalSandwichForm')">
        <div class="modal-card">
            <div class="modal-header">
                <h3 id="modalSandwichTitle">Nouveau Produit au Catalogue</h3>
                <button type="button" class="modal-close" onclick="closeModal('modalSandwichForm')">&times;</button>
            </div>
            <form method="POST" id="formSandwich">
                <input type="hidden" name="action" id="formAction" value="create_sandwich">
                <input type="hidden" name="id_sandwich" id="formIdSandwich" value="">

                <div class="modal-body">
                    <div class="form-field">
                        <label>Nom du Produit *</label>
                        <input type="text" name="nom" id="inputNom" placeholder="ex: Sandwich Komplis (Le Complet)" required>
                    </div>

                    <div class="form-grid-2">
                        <div class="form-field">
                            <label>Prix TTC (DH) *</label>
                            <input type="number" step="0.5" min="0.5" name="prix" id="inputPrix" placeholder="19.00" required>
                        </div>
                        <div class="form-field">
                            <label>Catégorie de Vente *</label>
                            <select name="categorie" id="inputCategorie">
                                <option value="Ma3qouda">Sandwichs Maâkouda</option>
                                <option value="Combos">Formules Combos</option>
                                <option value="Boissons">Boissons &amp; Eaux</option>
                                <option value="Plats">Plats &amp; Assiettes</option>
                                <option value="Snacks">Snacks &amp; Extras</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-grid-2">
                        <div class="form-field">
                            <label>Badge Promo (Optionnel)</label>
                            <input type="text" id="inputBadge" name="badge" placeholder="ex: BEST-SELLER, NOUVEAU">
                        </div>
                        <div class="form-field">
                            <label>URL de la Photo</label>
                            <input type="url" id="inputImg" name="image_url" placeholder="https://images.unsplash.com/...">
                        </div>
                    </div>

                    <div class="form-field" style="margin-bottom:0;">
                        <label>Composition &amp; Recette</label>
                        <textarea name="description" id="inputDesc" rows="3" placeholder="Ingrédients de base, préparation sur plancha, sauces..."></textarea>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn-cancel" onclick="closeModal('modalSandwichForm')">Annuler</button>
                    <button type="submit" class="btn-submit" id="btnSubmitModal">Créer le Produit</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal 2 : Support Desk & Demandes d'Assistance -->
    <div class="modal-overlay" id="modalSupportDesk" onclick="if(event.target===this)closeModal('modalSupportDesk')">
        <div class="modal-card" style="max-width: 620px;">
            <div class="modal-header">
                <div style="display:flex; align-items:center; gap:10px;">
                    <div style="width:36px; height:36px; border-radius:8px; background:var(--primary-light); color:var(--primary); display:flex; align-items:center; justify-content:center;">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                    </div>
                    <div>
                        <h3 style="margin:0; font-size:16px;">Assistance &amp; Support Technique</h3>
                        <p style="margin:2px 0 0; font-size:12px; color:var(--text-muted);">Échange direct avec les administrateurs RestoOS</p>
                    </div>
                </div>
                <button type="button" class="modal-close" onclick="closeModal('modalSupportDesk')">&times;</button>
            </div>

            <!-- Modal Sub-Tabs -->
            <div style="display:flex; border-bottom:1px solid var(--border-subtle); background:#F8FAFC; padding:0 22px;">
                <button type="button" id="tabBtnTickets" onclick="switchSupportTab('history')" style="padding:10px 14px; border:none; background:none; font-size:13px; font-weight:700; color:var(--primary); border-bottom:2px solid var(--primary); cursor:pointer;">
                    Mes Demandes (<?= count($tickets) ?>)
                </button>
                <button type="button" id="tabBtnNewTicket" onclick="switchSupportTab('new')" style="padding:10px 14px; border:none; background:none; font-size:13px; font-weight:600; color:var(--text-muted); cursor:pointer;">
                    + Nouveau Ticket
                </button>
            </div>

            <div class="modal-body" style="padding: 22px;">

                <!-- Tab 1: Ticket History & Answers -->
                <div id="supportHistoryView">
                    <?php if (empty($tickets)): ?>
                        <div style="text-align:center; padding:32px; color:var(--text-muted); font-size:13px;">
                            Aucune demande d'assistance enregistrée pour cet établissement.
                        </div>
                    <?php else: ?>
                        <div style="display:flex; flex-direction:column; gap:14px;">
                            <?php foreach ($tickets as $t): ?>
                                <div style="background:#F8FAFC; border:1px solid var(--border-subtle); border-radius:10px; padding:14px;">
                                    <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:6px;">
                                        <div style="font-weight:700; font-size:14px; color:var(--text-heading);">
                                            #<?= $t['id_ticket'] ?> — <?= htmlspecialchars($t['sujet']) ?>
                                        </div>
                                        <span style="font-size:11px; font-weight:700; padding:2px 8px; border-radius:999px; background:<?= $t['statut'] === 'RESOLU' ? '#ECFDF5' : '#FEF3C7' ?>; color:<?= $t['statut'] === 'RESOLU' ? '#065F46' : '#92400E' ?>;">
                                            <?= $t['statut'] === 'RESOLU' ? '✓ Résolu' : '● ' . htmlspecialchars($t['statut']) ?>
                                        </span>
                                    </div>
                                    <div style="font-size:12.5px; color:var(--text-body); margin-bottom:8px; line-height:1.4;">
                                        <?= nl2br(htmlspecialchars($t['message'])) ?>
                                    </div>
                                    <div style="font-size:11px; color:var(--text-muted);">
                                        Envoyé le <?= date('d/m/Y H:i', strtotime($t['date_creation'])) ?>
                                    </div>

                                    <!-- Super Admin Official Resolution -->
                                    <?php if (!empty($t['reponse_admin'])): ?>
                                        <div style="margin-top:12px; background:#FFFFFF; border:1px solid #A7F3D0; border-left:3px solid #10B981; border-radius:6px; padding:10px 12px;">
                                            <div style="font-size:11px; font-weight:800; color:#065F46; text-transform:uppercase; letter-spacing:0.04em; margin-bottom:3px;">
                                                ✓ Réponse officielle de l'Administrateur RestoOS :
                                            </div>
                                            <div style="font-size:13px; color:#0F172A; line-height:1.45;">
                                                <?= nl2br(htmlspecialchars($t['reponse_admin'])) ?>
                                            </div>
                                            <?php if (!empty($t['date_resolution'])): ?>
                                                <div style="font-size:10.5px; color:#64748B; margin-top:4px;">
                                                    Résolu le <?= date('d/m/Y H:i', strtotime($t['date_resolution'])) ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Tab 2: Create Ticket Form -->
                <div id="supportNewView" style="display:none;">
                    <form method="POST">
                        <input type="hidden" name="action" value="create_ticket">

                        <div class="form-field">
                            <label>Sujet de votre demande *</label>
                            <input type="text" name="sujet" placeholder="ex: Problème d'impression thermique ou tablette cuisine" required>
                        </div>

                        <div class="form-field">
                            <label>Degré d'urgence</label>
                            <select name="priorite">
                                <option value="MOYENNE" selected>Moyenne (Opérationnel)</option>
                                <option value="BASSE">Basse (Question générale)</option>
                                <option value="HAUTE">Haute (Blocage partiel)</option>
                                <option value="URGENTE">Urgente (Service interrompu)</option>
                            </select>
                        </div>

                        <div class="form-field">
                            <label>Description détaillée du problème *</label>
                            <textarea name="message" rows="4" placeholder="Expliquez ce que vous observez, l'équipement concerné ou la configuration souhaitée..." required></textarea>
                        </div>

                        <button type="submit" class="btn-primary-action" style="width:100%; justify-content:center; height:42px;">
                            Transmettre la Demande à la Plateforme
                        </button>
                    </form>
                </div>

            </div>
            <div class="modal-footer">
                <button type="button" class="btn-cancel" onclick="closeModal('modalSupportDesk')">Fermer</button>
            </div>
        </div>
    </div>

    <!-- 4. Interactive Client Scripts -->
    <script>
        // Modal Control
        function openModal(id) {
            const m = document.getElementById(id);
            if (m) m.classList.add('active');
        }

        function closeModal(id) {
            const m = document.getElementById(id);
            if (m) m.classList.remove('active');
        }

        // Add Product Modal
        function openAddSandwichModal() {
            document.getElementById('formAction').value = 'create_sandwich';
            document.getElementById('formIdSandwich').value = '';
            document.getElementById('modalSandwichTitle').textContent = 'Nouveau Produit au Catalogue';
            document.getElementById('btnSubmitModal').textContent = 'Créer le Produit';
            document.getElementById('formSandwich').reset();
            openModal('modalSandwichForm');
            setTimeout(() => document.getElementById('inputNom').focus(), 80);
        }

        // Edit Product Modal
        function openEditSandwichModal(item) {
            document.getElementById('formAction').value = 'update_sandwich';
            document.getElementById('formIdSandwich').value = item.id_sandwich;
            document.getElementById('modalSandwichTitle').textContent = 'Modifier : ' + item.nom;
            document.getElementById('btnSubmitModal').textContent = 'Enregistrer les Modifications';
            document.getElementById('inputNom').value = item.nom || '';
            document.getElementById('inputPrix').value = item.prix || '';
            document.getElementById('inputCategorie').value = item.categorie || 'Ma3qouda';
            document.getElementById('inputBadge').value = item.badge || '';
            document.getElementById('inputDesc').value = item.description || '';
            document.getElementById('inputImg').value = item.image_url || '';
            openModal('modalSandwichForm');
            setTimeout(() => document.getElementById('inputNom').focus(), 80);
        }

        // Category Filter
        function filterCategory(cat, btn) {
            document.querySelectorAll('.category-pill-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');

            const rows = document.querySelectorAll('#menuCatalogTable tbody tr');
            rows.forEach(r => {
                const rCat = r.getAttribute('data-category');
                if (cat === 'ALL' || rCat === cat) {
                    r.style.display = '';
                } else {
                    r.style.display = 'none';
                }
            });
        }

        // Real-time Text Filter
        function filterMenuTable() {
            const query = document.getElementById('catalogSearchInput').value.toLowerCase().trim();
            const rows = document.querySelectorAll('#menuCatalogTable tbody tr');
            rows.forEach(r => {
                const name = r.getAttribute('data-name') || '';
                r.style.display = name.includes(query) ? '' : 'none';
            });
        }

        // Support Desk Tabs
        function switchSupportTab(tab) {
            const historyView = document.getElementById('supportHistoryView');
            const newView = document.getElementById('supportNewView');
            const btnHist = document.getElementById('tabBtnTickets');
            const btnNew = document.getElementById('tabBtnNewTicket');

            if (tab === 'new') {
                historyView.style.display = 'none';
                newView.style.display = 'block';
                btnHist.style.color = 'var(--text-muted)';
                btnHist.style.borderBottom = 'none';
                btnNew.style.color = 'var(--primary)';
                btnNew.style.borderBottom = '2px solid var(--primary)';
            } else {
                historyView.style.display = 'block';
                newView.style.display = 'none';
                btnHist.style.color = 'var(--primary)';
                btnHist.style.borderBottom = '2px solid var(--primary)';
                btnNew.style.color = 'var(--text-muted)';
                btnNew.style.borderBottom = 'none';
            }
        }

        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                closeModal('modalSandwichForm');
                closeModal('modalSupportDesk');
            }
        });
    </script>
</body>
</html>
