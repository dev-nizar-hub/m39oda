<?php
/**
 * API - Get Active Orders for Kitchen & Public Display
 * Powers: cuisine.php & ecran.php (RG04, RG06, RG08)
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// SECURITY: Authenticated staff get full order details; unauthenticated callers can ONLY view order numbers for the public TV display (ecran.php).
require_once __DIR__ . '/../config/auth.php';
$isStaff = isset($_SESSION['user_id']) && !empty($_SESSION['user_role']);

/** @var PDO $pdo */
$pdo = require_once __DIR__ . '/../config/db.php';

$filter = isset($_GET['filter']) ? trim((string)$_GET['filter']) : 'active'; // 'active', 'all', 'prete'

// Tenant Isolation: Resolve active restaurant
$idRestaurant = 1;
if (isset($_GET['restaurant_id']) && is_numeric($_GET['restaurant_id'])) {
    $idRestaurant = (int)$_GET['restaurant_id'];
} elseif (isset($_GET['r']) && is_numeric($_GET['r'])) {
    $idRestaurant = (int)$_GET['r'];
} elseif (isset($_SESSION['restaurant_id']) && is_numeric($_SESSION['restaurant_id'])) {
    $idRestaurant = (int)$_SESSION['restaurant_id'];
}

// If not logged-in staff, only allow public TV display callboard (filter=active, stripped data only)
if (!$isStaff) {
    if ($filter !== 'active') {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Accès restreint aux employés.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        $stmt = $pdo->prepare(
            "SELECT numero_commande, statut FROM commandes 
             WHERE id_restaurant = :rid AND statut IN ('EN_PREPARATION', 'PRETE') 
             ORDER BY date_commande ASC"
        );
        $stmt->execute(['rid' => $idRestaurant]);
        $publicOrders = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $grouped = ['en_preparation' => [], 'prete' => [], 'total_active' => count($publicOrders)];
        foreach ($publicOrders as $po) {
            if ($po['statut'] === 'EN_PREPARATION') {
                $grouped['en_preparation'][] = ['numero_commande' => $po['numero_commande']];
            } elseif ($po['statut'] === 'PRETE') {
                $grouped['prete'][] = ['numero_commande' => $po['numero_commande']];
            }
        }

        echo json_encode(['success' => true, 'data' => $grouped], JSON_UNESCAPED_UNICODE);
        exit;
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Erreur serveur.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

try {
    // 1. Fetch orders based on filter and tenant restaurant
    if ($filter === 'active') {
        $stmt = $pdo->prepare(
            "SELECT c.*, u.nom AS nom_caissier,
                    TIMESTAMPDIFF(SECOND, c.date_commande, NOW()) AS elapsed_seconds
             FROM commandes c
             LEFT JOIN utilisateurs u ON c.id_caissier = u.id_utilisateur
             WHERE c.id_restaurant = :rid AND c.statut IN ('EN_PREPARATION', 'PRETE')
             ORDER BY c.date_commande ASC"
        );
        $stmt->execute(['rid' => $idRestaurant]);
    } elseif ($filter === 'all') {
        $stmt = $pdo->prepare(
            "SELECT c.*, u.nom AS nom_caissier,
                    TIMESTAMPDIFF(SECOND, c.date_commande, NOW()) AS elapsed_seconds
             FROM commandes c
             LEFT JOIN utilisateurs u ON c.id_caissier = u.id_utilisateur
             WHERE c.id_restaurant = :rid
             ORDER BY c.date_commande DESC
             LIMIT 100"
        );
        $stmt->execute(['rid' => $idRestaurant]);
    } else {
        $stmt = $pdo->prepare(
            "SELECT c.*, u.nom AS nom_caissier,
                    TIMESTAMPDIFF(SECOND, c.date_commande, NOW()) AS elapsed_seconds
             FROM commandes c
             LEFT JOIN utilisateurs u ON c.id_caissier = u.id_utilisateur
             WHERE c.id_restaurant = :rid AND c.statut = :statut
             ORDER BY c.date_commande ASC"
        );
        $stmt->execute(['rid' => $idRestaurant, 'statut' => $filter]);
    }

    $orders = $stmt->fetchAll();

    if (empty($orders)) {
        echo json_encode([
            'success' => true,
            'data' => [
                'en_preparation' => [],
                'prete'          => [],
                'total_active'   => 0
            ]
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 2. Fetch items & preferences for each order
    $orderIds = array_column($orders, 'id_commande');
    $inPlaceholders = implode(',', array_fill(0, count($orderIds), '?'));

    $stmtItems = $pdo->prepare(
        "SELECT d.*, s.nom AS nom_sandwich, s.image_url
         FROM details_commande d
         JOIN sandwichs s ON d.id_sandwich = s.id_sandwich
         WHERE d.id_commande IN ($inPlaceholders)
         ORDER BY d.id_detail ASC"
    );
    $stmtItems->execute($orderIds);
    $allDetails = $stmtItems->fetchAll();

    // Map details by detail id to fetch preferences
    $detailsById = [];
    $detailIds = [];
    foreach ($allDetails as $d) {
        $detailsById[(int)$d['id_detail']] = $d;
        $detailsById[(int)$d['id_detail']]['preferences'] = [];
        $detailIds[] = (int)$d['id_detail'];
    }

    if (!empty($detailIds)) {
        $detailPlaceholders = implode(',', array_fill(0, count($detailIds), '?'));
        $stmtPrefs = $pdo->prepare(
            "SELECT di.*, i.nom AS nom_ingredient
             FROM details_ingredients di
             JOIN ingredients i ON di.id_ingredient = i.id_ingredient
             WHERE di.id_detail IN ($detailPlaceholders)"
        );
        $stmtPrefs->execute($detailIds);
        $allPrefs = $stmtPrefs->fetchAll();

        foreach ($allPrefs as $p) {
            $detId = (int)$p['id_detail'];
            if (isset($detailsById[$detId])) {
                $detailsById[$detId]['preferences'][] = [
                    'id_ingredient' => (int)$p['id_ingredient'],
                    'nom'           => $p['nom_ingredient'],
                    'action'        => $p['action_ingredient'],
                    'prix_applique' => (float)$p['prix_applique']
                ];
            }
        }
    }

    // Group details by order id
    $detailsByOrder = [];
    foreach ($detailsById as $d) {
        $cmdId = (int)$d['id_commande'];
        if (!isset($detailsByOrder[$cmdId])) {
            $detailsByOrder[$cmdId] = [];
        }
        $detailsByOrder[$cmdId][] = $d;
    }

    // Segregate into EN_PREPARATION and PRETE
    $enPrep = [];
    $prete = [];

    foreach ($orders as &$ord) {
        $cmdId = (int)$ord['id_commande'];
        $ord['items'] = $detailsByOrder[$cmdId] ?? [];

        if ($ord['statut'] === 'EN_PREPARATION') {
            $enPrep[] = $ord;
        } elseif ($ord['statut'] === 'PRETE') {
            $prete[] = $ord;
        }
    }
    unset($ord);

    echo json_encode([
        'success' => true,
        'data' => [
            'en_preparation' => $enPrep,
            'prete'          => $prete,
            'total_active'   => count($enPrep) + count($prete)
        ]
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Erreur API récupération commandes : ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}

