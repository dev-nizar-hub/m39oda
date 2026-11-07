<?php
/**
 * API - Get Sandwich & Ingredient Catalog (Multi-Tenant SaaS)
 * Filtre les produits et ingrédients en fonction du restaurant actif.
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

// SECURITY: Require active session — unauthenticated callers get 401
require_once __DIR__ . '/../config/auth.php';
if (!isset($_SESSION['user_id']) || empty($_SESSION['user_role'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Non authentifié.'], JSON_UNESCAPED_UNICODE);
    exit;
}

/** @var PDO $pdo */
$pdo = require_once __DIR__ . '/../config/db.php';

try {
    // 1. Déterminer le tenant restaurant
    $idRestaurant = 1;
    if (isset($_GET['restaurant_id']) && is_numeric($_GET['restaurant_id'])) {
        $idRestaurant = (int)$_GET['restaurant_id'];
    } elseif (isset($_GET['r'])) {
        $rParam = trim((string)$_GET['r']);
        if (is_numeric($rParam)) {
            $idRestaurant = (int)$rParam;
        } else {
            $stmtSlug = $pdo->prepare("SELECT id_restaurant FROM restaurants WHERE slug = ? LIMIT 1");
            $stmtSlug->execute([$rParam]);
            $foundId = $stmtSlug->fetchColumn();
            if ($foundId) {
                $idRestaurant = (int)$foundId;
            }
        }
    } elseif (isset($_SESSION['restaurant_id']) && is_numeric($_SESSION['restaurant_id'])) {
        $idRestaurant = (int)$_SESSION['restaurant_id'];
    }

    // 2. Vérifier les informations du restaurant et son statut
    $stmtResto = $pdo->prepare("SELECT id_restaurant, nom, slug, ville, telephone, devise, statut FROM restaurants WHERE id_restaurant = ?");
    $stmtResto->execute([$idRestaurant]);
    $restaurant = $stmtResto->fetch();

    if (!$restaurant) {
        // Fallback vers restaurant 1
        $restaurant = $pdo->query("SELECT id_restaurant, nom, slug, ville, telephone, devise, statut FROM restaurants ORDER BY id_restaurant ASC LIMIT 1")->fetch();
        $idRestaurant = (int)$restaurant['id_restaurant'];
    }

    if ($restaurant['statut'] === 'SUSPENDU' && ($_SESSION['user_role'] ?? '') !== 'SUPERADMIN') {
        echo json_encode([
            'success' => false,
            'message' => 'Ce restaurant est actuellement suspendu.',
            'suspended' => true
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 3. Récupérer les sandwichs du restaurant
    $stmtSandwichs = $pdo->prepare(
        "SELECT id_sandwich, nom, description, image_url, badge, categorie, prix, disponible 
         FROM sandwichs 
         WHERE id_restaurant = :rid
         ORDER BY disponible DESC, id_sandwich ASC"
    );
    $stmtSandwichs->execute([':rid' => $idRestaurant]);
    $sandwichs = $stmtSandwichs->fetchAll();

    // 4. Récupérer les ingrédients / suppléments du restaurant
    $stmtIngredients = $pdo->prepare(
        "SELECT id_ingredient, nom, prix_supplement 
         FROM ingredients 
         WHERE id_restaurant = :rid
         ORDER BY prix_supplement ASC, nom ASC"
    );
    $stmtIngredients->execute([':rid' => $idRestaurant]);
    $ingredients = $stmtIngredients->fetchAll();

    echo json_encode([
        'success' => true,
        'restaurant' => [
            'id'        => (int)$restaurant['id_restaurant'],
            'nom'       => $restaurant['nom'],
            'slug'      => $restaurant['slug'],
            'ville'     => $restaurant['ville'],
            'telephone' => $restaurant['telephone'],
            'devise'    => $restaurant['devise'] ?? 'DH'
        ],
        'data' => [
            'sandwichs'   => $sandwichs,
            'ingredients' => $ingredients
        ]
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Erreur lors de la récupération du catalogue : ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
