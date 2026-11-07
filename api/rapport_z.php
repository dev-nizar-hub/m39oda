<?php
/**
 * M3i9dat Ba M'Barek - API Rapport Z de Clôture de Caisse
 * Génère le récapitulatif financier certifié de fin de service pour impression thermique
 * Sécurisé : Isolation Tenant & Authentification requise
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Session requise pour consulter le rapport Z.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$sessionRole = strtoupper((string)($_SESSION['user_role'] ?? ''));
if (!in_array($sessionRole, ['ADMIN', 'CAISSIER', 'SUPERADMIN'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Privilèges insuffisants pour cette action.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$idRestaurant = isset($_SESSION['restaurant_id']) ? (int)$_SESSION['restaurant_id'] : 1;
if ($sessionRole === 'SUPERADMIN' && isset($_GET['restaurant_id']) && is_numeric($_GET['restaurant_id'])) {
    $idRestaurant = (int)$_GET['restaurant_id'];
}

/** @var PDO $pdo */
$pdo = require_once __DIR__ . '/../config/db.php';

try {
    $dateJour = $_GET['date'] ?? date('Y-m-d');

    // 1. Statistiques globales de la journée (Tenant-Isolated)
    $stmtStats = $pdo->prepare(
        "SELECT 
            COUNT(id_commande) AS total_tickets,
            COALESCE(SUM(montant_total), 0) AS ca_total,
            COALESCE(SUM(CASE WHEN statut = 'SERVIE' THEN montant_total ELSE 0 END), 0) AS ca_encaisse,
            COALESCE(SUM(CASE WHEN statut = 'EN_PREPARATION' THEN montant_total ELSE 0 END), 0) AS ca_en_cours,
            MIN(numero_commande) AS premier_ticket,
            MAX(numero_commande) AS dernier_ticket
         FROM commandes 
         WHERE id_restaurant = :rid AND DATE(date_commande) = :date"
    );
    $stmtStats->execute(['rid' => $idRestaurant, 'date' => $dateJour]);
    $stats = $stmtStats->fetch();

    $totalTickets = (int)($stats['total_tickets'] ?? 0);
    $caTotal      = (float)($stats['ca_total'] ?? 0.0);
    $caEncaisse   = (float)($stats['ca_encaisse'] ?? 0.0);
    $panierMoyen  = $totalTickets > 0 ? round($caTotal / $totalTickets, 2) : 0.0;

    // 2. Ventes détaillées par sandwich aujourd'hui
    $stmtProducts = $pdo->prepare(
        "SELECT s.nom, SUM(d.quantite) AS qte_vendue, SUM(d.prix_unitaire * d.quantite) AS ca_produit
         FROM details_commande d
         JOIN sandwichs s ON d.id_sandwich = s.id_sandwich
         JOIN commandes c ON d.id_commande = c.id_commande
         WHERE c.id_restaurant = :rid AND DATE(c.date_commande) = :date
         GROUP BY s.id_sandwich
         ORDER BY qte_vendue DESC, ca_produit DESC"
    );
    $stmtProducts->execute(['rid' => $idRestaurant, 'date' => $dateJour]);
    $produits = $stmtProducts->fetchAll();

    // 3. Répartition par caissier
    $stmtCashiers = $pdo->prepare(
        "SELECT COALESCE(u.nom, 'Caisse Principale') AS caissier,
                COUNT(c.id_commande) AS nb_commandes,
                COALESCE(SUM(c.montant_total), 0) AS ca_caissier
         FROM commandes c
         LEFT JOIN utilisateurs u ON c.id_caissier = u.id_utilisateur
         WHERE c.id_restaurant = :rid AND DATE(c.date_commande) = :date
         GROUP BY c.id_caissier"
    );
    $stmtCashiers->execute(['rid' => $idRestaurant, 'date' => $dateJour]);
    $caissiers = $stmtCashiers->fetchAll();

    echo json_encode([
        'success' => true,
        'data' => [
            'date_cloture'     => date('d/m/Y H:i:s'),
            'date_jour'        => $dateJour,
            'total_tickets'    => $totalTickets,
            'premier_ticket'   => $stats['premier_ticket'] ?? '-',
            'dernier_ticket'   => $stats['dernier_ticket'] ?? '-',
            'ca_total'         => $caTotal,
            'ca_encaisse'      => $caEncaisse,
            'ca_en_cours'      => (float)($stats['ca_en_cours'] ?? 0.0),
            'panier_moyen'     => $panierMoyen,
            'produits_vendus'  => $produits,
            'caissiers'        => $caissiers
        ]
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Erreur lors de la génération du rapport Z : ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
