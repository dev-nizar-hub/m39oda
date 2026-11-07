<?php
/**
 * API - Changer le Statut d'une Commande
 * Implémente le Cycle de Vie strict (Section 6 & RG06, RG07, RG08)
 * Transition autorisée : EN_PREPARATION -> PRETE -> SERVIE
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 1. Authentication & Role Guard
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Session requise. Veuillez vous authentifier.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$sessionRole = strtoupper((string)($_SESSION['user_role'] ?? ''));
if (!in_array($sessionRole, ['PREPARATEUR', 'CAISSIER', 'ADMIN', 'SUPERADMIN'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Privilèges insuffisants pour modifier le statut.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$idUtilisateur = (int)$_SESSION['user_id'];
$idRestaurant = isset($_SESSION['restaurant_id']) ? (int)$_SESSION['restaurant_id'] : null;

/** @var PDO $pdo */
$pdo = require_once __DIR__ . '/../config/db.php';

// Accept JSON payload or standard POST
$rawInput = file_get_contents('php://input');
if ($rawInput !== false) {
    // Strip UTF-8 BOM if present
    $rawInput = preg_replace('/^\xEF\xBB\xBF/', '', $rawInput);
}
$data = json_decode($rawInput, true);

if (!is_array($data) || empty($data)) {
    $data = $_POST;
}

$idCommande = isset($data['id_commande']) ? (int)$data['id_commande'] : 0;
$nouveauStatut = isset($data['nouveau_statut']) ? strtoupper(trim((string)$data['nouveau_statut'])) : '';

if ($idCommande <= 0) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Identifiant de commande manquant.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!in_array($nouveauStatut, ['PRETE', 'SERVIE'], true)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Nouveau statut invalide. Valeurs autorisées: PRETE, SERVIE.'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $pdo->beginTransaction();

    // 1. Fetch current order with tenant isolation & row lock
    if ($sessionRole === 'SUPERADMIN' || $idRestaurant === null) {
        $stmt = $pdo->prepare("SELECT id_commande, numero_commande, statut, id_restaurant FROM commandes WHERE id_commande = :id FOR UPDATE");
        $stmt->execute(['id' => $idCommande]);
    } else {
        $stmt = $pdo->prepare("SELECT id_commande, numero_commande, statut, id_restaurant FROM commandes WHERE id_commande = :id AND id_restaurant = :rid FOR UPDATE");
        $stmt->execute(['id' => $idCommande, 'rid' => $idRestaurant]);
    }
    $commande = $stmt->fetch();

    if (!$commande) {
        throw new RuntimeException("Commande #$idCommande introuvable ou non autorisée pour votre établissement.");
    }

    $ancienStatut = $commande['statut'];

    // 2. Enforce RG07: Cycle de Vie strict
    if ($ancienStatut === 'EN_PREPARATION') {
        if ($nouveauStatut !== 'PRETE') {
            throw new DomainException("Règle RG07 enfreinte : le système interdit le passage direct de 'En préparation' à 'Servie'. L'ordre doit d'abord être déclaré 'Prête'.");
        }
    } elseif ($ancienStatut === 'PRETE') {
        if ($nouveauStatut !== 'SERVIE') {
            throw new DomainException("Une commande 'Prête' ne peut transiter que vers le statut 'Servie'.");
        }
    } elseif ($ancienStatut === 'SERVIE') {
        throw new DomainException("Cette commande est déjà clôturée et servie.");
    }

    // 3. Update order status
    $stmtUpdate = $pdo->prepare("UPDATE commandes SET statut = :statut WHERE id_commande = :id");
    $stmtUpdate->execute([
        'statut' => $nouveauStatut,
        'id'     => $idCommande
    ]);

    // 4. Log in audit trail `historique_statuts`
    $stmtHist = $pdo->prepare(
        "INSERT INTO historique_statuts (id_commande, id_utilisateur, ancien_statut, nouveau_statut, date_changement)
         VALUES (:cmd, :user, :ancien, :nouveau, NOW())"
    );
    $stmtHist->execute([
        'cmd'     => $idCommande,
        'user'    => $idUtilisateur,
        'ancien'  => $ancienStatut,
        'nouveau' => $nouveauStatut
    ]);

    $pdo->commit();

    echo json_encode([
        'success'        => true,
        'message'        => "Commande N° {$commande['numero_commande']} passée à '$nouveauStatut'.",
        'id_commande'    => $idCommande,
        'numero_commande'=> $commande['numero_commande'],
        'ancien_statut'  => $ancienStatut,
        'nouveau_statut' => $nouveauStatut
    ], JSON_UNESCAPED_UNICODE);

} catch (DomainException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(409); // Conflict / Business Rule violation
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Erreur serveur : ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}

