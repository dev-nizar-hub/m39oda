<?php
/**
 * M3i9dat Ba M'Barek - API Gestion Rupture de Stock
 * Bascule la disponibilité d'un sandwich ou d'une formule (1 <-> 0)
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Authentication Guard
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Session requise. Veuillez vous authentifier.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$sessionRole = strtoupper((string)($_SESSION['user_role'] ?? ''));
if (!in_array($sessionRole, ['ADMIN', 'SUPERADMIN', 'CAISSIER', 'PREPARATEUR'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Privilèges insuffisants pour modifier la disponibilité du stock.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$idRestaurant = isset($_SESSION['restaurant_id']) ? (int)$_SESSION['restaurant_id'] : null;

/** @var PDO $pdo */
$pdo = require_once __DIR__ . '/../config/db.php';

$raw = file_get_contents('php://input');
if ($raw !== false) {
    $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
}
$data = json_decode($raw, true);
if (!is_array($data) || empty($data)) {
    $data = $_POST;
}

$idSandwich = isset($data['id_sandwich']) ? (int)$data['id_sandwich'] : 0;

if ($idSandwich <= 0) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Identifiant sandwich manquant.'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    if ($sessionRole === 'SUPERADMIN' || $idRestaurant === null) {
        $stmt = $pdo->prepare("SELECT id_sandwich, nom, disponible FROM sandwichs WHERE id_sandwich = ? LIMIT 1");
        $stmt->execute([$idSandwich]);
    } else {
        $stmt = $pdo->prepare("SELECT id_sandwich, nom, disponible FROM sandwichs WHERE id_sandwich = ? AND id_restaurant = ? LIMIT 1");
        $stmt->execute([$idSandwich, $idRestaurant]);
    }
    $item = $stmt->fetch();

    if (!$item) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Sandwich introuvable ou non autorisé pour cet établissement.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $nouveauStatut = ((int)$item['disponible'] === 1) ? 0 : 1;
    $stmtUpdate = $pdo->prepare("UPDATE sandwichs SET disponible = ? WHERE id_sandwich = ?");
    $stmtUpdate->execute([$nouveauStatut, $idSandwich]);

    echo json_encode([
        'success'        => true,
        'id_sandwich'    => $idSandwich,
        'nom'            => $item['nom'],
        'disponible'     => $nouveauStatut,
        'message'        => ($nouveauStatut === 1) ? "{$item['nom']} remis en stock." : "{$item['nom']} marqué en RUPTURE DE STOCK."
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}

