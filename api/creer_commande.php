<?php
/**
 * API - Créer / Saisir une Commande
 * Implémente le Cas d'Utilisation : "Saisir la commande"
 * Règles métier : RG01, RG02, RG03, RG04, RG05, RG06
 * Numérotation client simplifiée : 1, 2, 3...
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

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

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Security Guard: Authentication required
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Session requise pour créer une commande.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$sessionRole = strtoupper((string)($_SESSION['user_role'] ?? ''));
if (!in_array($sessionRole, ['CAISSIER', 'ADMIN', 'SUPERADMIN'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Privilèges insuffisants pour créer une commande.'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 1. Basic validation (Tenant-locked)
$idCaissier = (int)$_SESSION['user_id'];
$idRestaurant = isset($_SESSION['restaurant_id']) ? (int)$_SESSION['restaurant_id'] : 1;
if ($sessionRole === 'SUPERADMIN' && isset($data['id_restaurant']) && is_numeric($data['id_restaurant'])) {
    $idRestaurant = (int)$data['id_restaurant'];
}
$items = $data['items'] ?? [];

// RG02: Une commande comporte au moins un sandwich
if (!is_array($items) || count($items) === 0) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'message' => 'Règle RG02 : une commande doit comporter au moins un sandwich.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // Begin atomic transaction
    $pdo->beginTransaction();

    // 2. Fetch all sandwich prices & ingredient prices for THIS restaurant to prevent client-side tampering
    $stmtSandwichRows = $pdo->prepare("SELECT id_sandwich, nom, prix, disponible FROM sandwichs WHERE id_restaurant = ? AND disponible = 1");
    $stmtSandwichRows->execute([$idRestaurant]);
    $sandwichRows = $stmtSandwichRows->fetchAll();
    $sandwichesCatalog = [];
    foreach ($sandwichRows as $s) {
        $sandwichesCatalog[(int)$s['id_sandwich']] = [
            'nom' => $s['nom'],
            'prix' => (float)$s['prix']
        ];
    }

    $stmtIngRows = $pdo->prepare("SELECT id_ingredient, nom, prix_supplement FROM ingredients WHERE id_restaurant = ?");
    $stmtIngRows->execute([$idRestaurant]);
    $ingredientRows = $stmtIngRows->fetchAll();
    $ingredientsCatalog = [];
    foreach ($ingredientRows as $ing) {
        $ingredientsCatalog[(int)$ing['id_ingredient']] = [
            'nom' => $ing['nom'],
            'prix_supplement' => (float)$ing['prix_supplement']
        ];
    }

    // 3. Generate Simple Sequential Order Number per Restaurant (1, 2, 3...)
    // Locked in transaction to prevent race conditions
    $stmtCount = $pdo->prepare("SELECT COALESCE(MAX(CAST(numero_commande AS UNSIGNED)), 0) + 1 FROM commandes WHERE id_restaurant = ? FOR UPDATE");
    $stmtCount->execute([$idRestaurant]);
    $nextSeq = (int)$stmtCount->fetchColumn();
    $numeroCommande = (string)$nextSeq;

    // 4. Calculate total amount server-side & validate each item
    $montantTotal = 0.00;
    $preparedItems = [];

    foreach ($items as $idx => $item) {
        $idSandwich = (int)($item['id_sandwich'] ?? 0);
        $quantite = max(1, (int)($item['quantite'] ?? 1));
        $commentaire = trim((string)($item['commentaire'] ?? ''));

        if (!isset($sandwichesCatalog[$idSandwich])) {
            throw new InvalidArgumentException("Le sandwich #$idSandwich est introuvable ou indisponible.");
        }

        $basePrix = $sandwichesCatalog[$idSandwich]['prix'];
        $sandwichNom = $sandwichesCatalog[$idSandwich]['nom'];

        // Process ingredient actions (RG03)
        $preferences = $item['preferences'] ?? [];
        $preparedPrefs = [];
        $itemSupplementsTotal = 0.00;

        if (is_array($preferences)) {
            foreach ($preferences as $pref) {
                $idIngredient = (int)($pref['id_ingredient'] ?? 0);
                $action = strtoupper((string)($pref['action'] ?? 'AJOUTER'));

                if (!in_array($action, ['AJOUTER', 'RETIRER'], true)) {
                    continue;
                }

                if (!isset($ingredientsCatalog[$idIngredient])) {
                    throw new InvalidArgumentException("L'ingrédient #$idIngredient est invalide.");
                }

                $ingNom = $ingredientsCatalog[$idIngredient]['nom'];
                $prixApplique = ($action === 'AJOUTER') ? $ingredientsCatalog[$idIngredient]['prix_supplement'] : 0.00;

                $itemSupplementsTotal += $prixApplique;

                $preparedPrefs[] = [
                    'id_ingredient' => $idIngredient,
                    'nom' => $ingNom,
                    'action' => $action,
                    'prix_applique' => $prixApplique
                ];
            }
        }

        $lignePrixUnitaire = $basePrix + $itemSupplementsTotal;
        $ligneTotal = $lignePrixUnitaire * $quantite;
        $montantTotal += $ligneTotal;

        $preparedItems[] = [
            'id_sandwich' => $idSandwich,
            'nom' => $sandwichNom,
            'quantite' => $quantite,
            'prix_unitaire' => $lignePrixUnitaire,
            'base_prix' => $basePrix,
            'commentaire' => $commentaire ?: null,
            'preferences' => $preparedPrefs
        ];
    }

    // 5. Insert Order into `commandes` (RG05: Statut initial = 'EN_PREPARATION')
    $stmtOrder = $pdo->prepare(
        "INSERT INTO commandes (id_restaurant, numero_commande, date_commande, id_caissier, statut, montant_total)
         VALUES (:rid, :num, NOW(), :caissier, 'EN_PREPARATION', :total)"
    );
    $stmtOrder->execute([
        'rid'      => $idRestaurant,
        'num'      => $numeroCommande,
        'caissier' => $idCaissier,
        'total'    => $montantTotal
    ]);
    $idCommande = (int)$pdo->lastInsertId();

    // 6. Insert Order Lines & Line Ingredients
    $stmtDetail = $pdo->prepare(
        "INSERT INTO details_commande (id_commande, id_sandwich, quantite, prix_unitaire, commentaire)
         VALUES (:cmd, :sandwich, :qty, :pu, :comment)"
    );

    $stmtPref = $pdo->prepare(
        "INSERT INTO details_ingredients (id_detail, id_ingredient, action_ingredient, prix_applique)
         VALUES (:detail, :ingredient, :action, :prix)"
    );

    foreach ($preparedItems as &$pItem) {
        $stmtDetail->execute([
            'cmd' => $idCommande,
            'sandwich' => $pItem['id_sandwich'],
            'qty' => $pItem['quantite'],
            'pu' => $pItem['prix_unitaire'],
            'comment' => $pItem['commentaire']
        ]);
        $idDetail = (int)$pdo->lastInsertId();
        $pItem['id_detail'] = $idDetail;

        foreach ($pItem['preferences'] as $pPref) {
            $stmtPref->execute([
                'detail' => $idDetail,
                'ingredient' => $pPref['id_ingredient'],
                'action' => $pPref['action'],
                'prix' => $pPref['prix_applique']
            ]);
        }
    }
    unset($pItem);

    // 7. Insert Initial Status Audit Trail in `historique_statuts`
    $stmtHist = $pdo->prepare(
        "INSERT INTO historique_statuts (id_commande, id_utilisateur, ancien_statut, nouveau_statut, date_changement)
         VALUES (:cmd, :user, NULL, 'EN_PREPARATION', NOW())"
    );
    $stmtHist->execute([
        'cmd' => $idCommande,
        'user' => $idCaissier
    ]);

    // Commit Transaction
    $pdo->commit();

    // 8. Prepare Formatted Response with Dual Ticket Data (RG04 / F03)
    $stmtCaissier = $pdo->prepare("SELECT nom FROM utilisateurs WHERE id_utilisateur = :id");
    $stmtCaissier->execute(['id' => $idCaissier]);
    $caissierNom = $stmtCaissier->fetchColumn() ?: "Caissier #$idCaissier";

    $orderPayload = [
        'id_commande' => $idCommande,
        'numero_commande' => $numeroCommande,
        'date_commande' => date('Y-m-d H:i:s'),
        'caissier' => $caissierNom,
        'statut' => 'EN_PREPARATION',
        'montant_total' => $montantTotal,
        'items' => $preparedItems
    ];

    echo json_encode([
        'success' => true,
        'message' => "Commande N° $numeroCommande enregistrée avec succès.",
        'data' => $orderPayload
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Erreur lors de la création de la commande : ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
