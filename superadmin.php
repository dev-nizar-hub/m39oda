<?php
/**
 * RestoOS Cloud - Tour de Contrôle Super Administrateur (Multi-Tenant SaaS)
 * Architecture Haute Fidélité inspirée de Stripe & Linear
 * Conçu exclusivement pour le propriétaire de la plateforme (Super Admin)
 */

declare(strict_types=1);

require_once __DIR__ . '/config/auth.php';
$currentUser = requireRole(['SUPERADMIN']);

/** @var PDO $pdo */
$pdo = require __DIR__ . '/config/db.php';

// Auto-guérison du schéma SaaS
if ($pdo instanceof PDO && file_exists(__DIR__ . '/config/ensure_schema.php')) {
    require_once __DIR__ . '/config/ensure_schema.php';
    ensureSaaSSchema($pdo);
}

$feedbackMessage = '';
$feedbackType = 'success'; // 'success' | 'error'

// -------------------------------------------------------------
// TRAITEMENT DES ACTIONS POST
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        // 1. Création d'un nouveau restaurant + Compte Gérant
        if ($action === 'creer_restaurant') {
            $nom          = trim($_POST['nom'] ?? '');
            $slug         = trim($_POST['slug'] ?? '');
            $proprietaire = trim($_POST['proprietaire'] ?? '');
            $email        = trim($_POST['email'] ?? '');
            $telephone    = trim($_POST['telephone'] ?? '');
            $ville        = trim($_POST['ville'] ?? 'Meknès');
            $adresse      = trim($_POST['adresse'] ?? '');
            $plan         = strtoupper(trim($_POST['plan'] ?? 'PRO'));
            $dureeMois    = max(1, (int)($_POST['duree_mois'] ?? 12));
            $adminNom     = trim($_POST['admin_nom'] ?? '');
            $adminLogin   = strtolower(trim($_POST['admin_login'] ?? ''));
            $adminPass    = trim($_POST['admin_pass'] ?? '');
            $cloneMenu    = !empty($_POST['clone_menu']);

            if ($nom === '' || $slug === '' || $adminLogin === '' || $adminPass === '') {
                throw new Exception("Veuillez renseigner tous les champs obligatoires (Nom, Slug, Identifiant, Mot de passe).");
            }

            // Normalisation slug
            $slug = strtolower(preg_replace('/[^a-zA-Z0-9_-]/', '-', $slug));

            $pdo->beginTransaction();

            // Vérification de l'unicité du slug
            $checkSlug = $pdo->prepare("SELECT id_restaurant FROM restaurants WHERE slug = ?");
            $checkSlug->execute([$slug]);
            if ($checkSlug->fetch()) {
                throw new Exception("L'identifiant URL '$slug' est déjà utilisé par un autre établissement.");
            }

            // Vérification de l'unicité du login
            $checkLogin = $pdo->prepare("SELECT id_utilisateur FROM utilisateurs WHERE LOWER(login) = ?");
            $checkLogin->execute([$adminLogin]);
            if ($checkLogin->fetch()) {
                throw new Exception("L'identifiant de connexion '$adminLogin' est déjà attribué.");
            }

            // Insertion du Restaurant
            $stmtRest = $pdo->prepare(
                "INSERT INTO restaurants (nom, slug, proprietaire, email, ville, telephone, adresse, devise, plan, date_expiration, statut)
                 VALUES (:nom, :slug, :proprio, :email, :ville, :tel, :adr, 'DH', :plan, DATE_ADD(CURRENT_DATE(), INTERVAL :mois MONTH), 'ACTIF')"
            );
            $stmtRest->execute([
                ':nom'     => $nom,
                ':slug'    => $slug,
                ':proprio' => $proprietaire ?: $nom,
                ':email'   => $email,
                ':ville'   => $ville,
                ':tel'     => $telephone,
                ':adr'     => $adresse,
                ':plan'    => in_array($plan, ['BASIC', 'PRO', 'PREMIUM'], true) ? $plan : 'PRO',
                ':mois'    => $dureeMois
            ]);
            $newRestId = (int)$pdo->lastInsertId();

            // Insertion du Compte Gérant
            $stmtUser = $pdo->prepare(
                "INSERT INTO utilisateurs (id_restaurant, nom, role, login, mot_de_passe, actif)
                 VALUES (:rid, :nom, 'ADMIN', :login, :pass, 1)"
            );
            $stmtUser->execute([
                ':rid'   => $newRestId,
                ':nom'   => $adminNom ?: ("Gérant " . $nom),
                ':login' => $adminLogin,
                ':pass'  => password_hash($adminPass, PASSWORD_BCRYPT)
            ]);

            // Duplication optionnelle du catalogue standard (depuis Restaurant #1)
            if ($cloneMenu) {
                $sourceProducts = $pdo->query("SELECT nom, prix, description, image_url, badge, categorie, disponible FROM sandwichs WHERE id_restaurant = 1")->fetchAll();
                $stmtInsertProd = $pdo->prepare(
                    "INSERT INTO sandwichs (id_restaurant, nom, prix, description, image_url, badge, categorie, disponible)
                     VALUES (:rid, :nom, :prix, :desc, :img, :badge, :cat, :disp)"
                );
                foreach ($sourceProducts as $p) {
                    $stmtInsertProd->execute([
                        ':rid'   => $newRestId,
                        ':nom'   => $p['nom'],
                        ':prix'  => $p['prix'],
                        ':desc'  => $p['description'],
                        ':img'   => $p['image_url'],
                        ':badge' => $p['badge'],
                        ':cat'   => $p['categorie'],
                        ':disp'  => $p['disponible']
                    ]);
                }

                $sourceIngs = $pdo->query("SELECT nom, prix_supplement FROM ingredients WHERE id_restaurant = 1")->fetchAll();
                $stmtInsertIng = $pdo->prepare("INSERT INTO ingredients (id_restaurant, nom, prix_supplement) VALUES (:rid, :nom, :prix)");
                foreach ($sourceIngs as $ing) {
                    $stmtInsertIng->execute([
                        ':rid'  => $newRestId,
                        ':nom'  => $ing['nom'],
                        ':prix' => $ing['prix_supplement']
                    ]);
                }
            }

            // Génération de transaction initiale de paiement
            $tarifPlan = match($plan) { 'BASIC' => 290.00, 'PREMIUM' => 990.00, default => 590.00 };
            $montantTotal = $tarifPlan * $dureeMois;
            $refTx = 'TX-' . date('Y') . '-' . strtoupper(substr(uniqid(), -6));
            
            $stmtPay = $pdo->prepare(
                "INSERT INTO paiements (id_restaurant, reference_tx, montant, methode, statut, plan_concerne, periode_mois, notes)
                 VALUES (:rid, :ref, :montant, 'VIREMENT', 'VALIDE', :plan, :mois, :notes)"
            );
            $stmtPay->execute([
                ':rid'     => $newRestId,
                ':ref'     => $refTx,
                ':montant' => $montantTotal,
                ':plan'    => $plan,
                ':mois'    => $dureeMois,
                ':notes'   => "Souscription initiale $plan ($dureeMois mois) à l'onboarding"
            ]);

            // Enregistrement dans les logs
            $stmtLog = $pdo->prepare("INSERT INTO logs_activite (id_utilisateur, id_restaurant, type_action, description) VALUES (?, ?, 'CREATION_RESTAURANT', ?)");
            $stmtLog->execute([$_SESSION['user_id'] ?? 1, $newRestId, "Onboarding du restaurant '$nom' sous forfait $plan ($dureeMois mois)."]);

            $pdo->commit();
            $feedbackMessage = "L'établissement <strong>" . htmlspecialchars($nom) . "</strong> a été déployé avec succès avec son forfait <strong>$plan</strong>.";
        }

        // 2. Basculer Statut (ACTIF <-> SUSPENDU)
        elseif ($action === 'toggle_statut') {
            $idRest = (int)($_POST['id_restaurant'] ?? 0);
            $stmt = $pdo->prepare("SELECT statut, nom FROM restaurants WHERE id_restaurant = ?");
            $stmt->execute([$idRest]);
            $r = $stmt->fetch();
            if ($r) {
                $nouveau = ($r['statut'] === 'ACTIF') ? 'SUSPENDU' : 'ACTIF';
                $pdo->prepare("UPDATE restaurants SET statut = ? WHERE id_restaurant = ?")->execute([$nouveau, $idRest]);
                
                $pdo->prepare("INSERT INTO logs_activite (id_utilisateur, id_restaurant, type_action, description) VALUES (?, ?, 'STATUT_CHANGE', ?)")
                    ->execute([$_SESSION['user_id'] ?? 1, $idRest, "Statut de l'établissement " . $r['nom'] . " modifié à $nouveau."]);
                
                $feedbackMessage = "Le statut de <strong>" . htmlspecialchars($r['nom']) . "</strong> est maintenant <strong>$nouveau</strong>.";
            }
        }

        // 3. Modifier Restaurant
        elseif ($action === 'modifier_restaurant') {
            $idRest       = (int)($_POST['id_restaurant'] ?? 0);
            $nom          = trim($_POST['nom'] ?? '');
            $proprietaire = trim($_POST['proprietaire'] ?? '');
            $email        = trim($_POST['email'] ?? '');
            $telephone    = trim($_POST['telephone'] ?? '');
            $ville        = trim($_POST['ville'] ?? '');
            $adresse      = trim($_POST['adresse'] ?? '');
            $plan         = strtoupper(trim($_POST['plan'] ?? 'PRO'));
            $statut       = strtoupper(trim($_POST['statut'] ?? 'ACTIF'));

            if ($idRest > 0 && $nom !== '') {
                $stmt = $pdo->prepare(
                    "UPDATE restaurants 
                     SET nom = :nom, proprietaire = :proprio, email = :email, telephone = :tel, ville = :ville, adresse = :adr, plan = :plan, statut = :statut
                     WHERE id_restaurant = :id"
                );
                $stmt->execute([
                    ':nom'     => $nom,
                    ':proprio' => $proprietaire,
                    ':email'   => $email,
                    ':tel'     => $telephone,
                    ':ville'   => $ville,
                    ':adr'     => $adresse,
                    ':plan'    => in_array($plan, ['BASIC', 'PRO', 'PREMIUM'], true) ? $plan : 'PRO',
                    ':statut'  => in_array($statut, ['ACTIF', 'SUSPENDU', 'ESSAI'], true) ? $statut : 'ACTIF',
                    ':id'      => $idRest
                ]);

                $pdo->prepare("INSERT INTO logs_activite (id_utilisateur, id_restaurant, type_action, description) VALUES (?, ?, 'MODIFICATION_RESTAURANT', ?)")
                    ->execute([$_SESSION['user_id'] ?? 1, $idRest, "Mise à jour des coordonnées et paramètres de '$nom'."]);

                $feedbackMessage = "Coordonnées de <strong>" . htmlspecialchars($nom) . "</strong> mises à jour.";
            }
        }

        // 4. Modifier / Prolonger Abonnement
        elseif ($action === 'modifier_abonnement') {
            $idRest    = (int)($_POST['id_restaurant'] ?? 0);
            $plan      = strtoupper(trim($_POST['plan'] ?? 'PRO'));
            $moisPlus  = (int)($_POST['prolonger_mois'] ?? 0);

            if ($idRest > 0) {
                if ($moisPlus > 0) {
                    $stmt = $pdo->prepare(
                        "UPDATE restaurants 
                         SET plan = :plan, 
                             date_expiration = DATE_ADD(COALESCE(GREATEST(date_expiration, CURRENT_DATE()), CURRENT_DATE()), INTERVAL :mois MONTH)
                         WHERE id_restaurant = :id"
                    );
                    $stmt->execute([':plan' => $plan, ':mois' => $moisPlus, ':id' => $idRest]);
                } else {
                    $stmt = $pdo->prepare("UPDATE restaurants SET plan = :plan WHERE id_restaurant = :id");
                    $stmt->execute([':plan' => $plan, ':id' => $idRest]);
                }

                $pdo->prepare("INSERT INTO logs_activite (id_utilisateur, id_restaurant, type_action, description) VALUES (?, ?, 'ABONNEMENT_RENOUVELE', ?)")
                    ->execute([$_SESSION['user_id'] ?? 1, $idRest, "Abonnement ajusté au forfait $plan (Prolongation +$moisPlus mois)."]);

                $feedbackMessage = "L'abonnement de l'établissement a été actualisé.";
            }
        }

        // 5. Enregistrer un Paiement
        elseif ($action === 'enregistrer_paiement') {
            $idRest    = (int)($_POST['id_restaurant'] ?? 0);
            $montant   = (float)($_POST['montant'] ?? 0);
            $methode   = strtoupper(trim($_POST['methode'] ?? 'VIREMENT'));
            $plan      = strtoupper(trim($_POST['plan'] ?? 'PRO'));
            $mois      = max(1, (int)($_POST['periode_mois'] ?? 1));
            $notes     = trim($_POST['notes'] ?? '');
            $refTx     = 'TX-' . date('Y') . '-' . strtoupper(substr(uniqid(), -6));

            if ($idRest > 0 && $montant > 0) {
                $stmtPay = $pdo->prepare(
                    "INSERT INTO paiements (id_restaurant, reference_tx, montant, methode, statut, plan_concerne, periode_mois, notes)
                     VALUES (:rid, :ref, :montant, :methode, 'VALIDE', :plan, :mois, :notes)"
                );
                $stmtPay->execute([
                    ':rid'     => $idRest,
                    ':ref'     => $refTx,
                    ':montant' => $montant,
                    ':methode' => in_array($methode, ['VIREMENT', 'ESPECES', 'CARTE', 'CHEQUE'], true) ? $methode : 'VIREMENT',
                    ':plan'    => $plan,
                    ':mois'    => $mois,
                    ':notes'   => $notes
                ]);

                // Prolonger automatiquement la date d'expiration
                $pdo->prepare(
                    "UPDATE restaurants 
                     SET plan = :plan,
                         date_expiration = DATE_ADD(COALESCE(GREATEST(date_expiration, CURRENT_DATE()), CURRENT_DATE()), INTERVAL :mois MONTH),
                         statut = 'ACTIF'
                     WHERE id_restaurant = :id"
                )->execute([':plan' => $plan, ':mois' => $mois, ':id' => $idRest]);

                $pdo->prepare("INSERT INTO logs_activite (id_utilisateur, id_restaurant, type_action, description) VALUES (?, ?, 'PAIEMENT_VALIDE', ?)")
                    ->execute([$_SESSION['user_id'] ?? 1, $idRest, "Paiement de " . number_format($montant, 2) . " DH validé ($refTx) pour $mois mois."]);

                $feedbackMessage = "Paiement de <strong>" . number_format($montant, 2) . " DH</strong> enregistré avec succès (Réf: $refTx).";
            }
        }

        // 6. Répondre à un Ticket de Support
        elseif ($action === 'repondre_ticket') {
            $idTicket = (int)($_POST['id_ticket'] ?? 0);
            $reponse  = trim($_POST['reponse'] ?? '');
            $statut   = strtoupper(trim($_POST['statut'] ?? 'RESOLU'));

            if ($idTicket > 0 && $reponse !== '') {
                $stmt = $pdo->prepare(
                    "UPDATE tickets_support 
                     SET reponse_admin = :rep, statut = :statut, date_resolution = CASE WHEN :statut = 'RESOLU' THEN NOW() ELSE date_resolution END
                     WHERE id_ticket = :id"
                );
                $stmt->execute([':rep' => $reponse, ':statut' => $statut, ':id' => $idTicket]);

                $pdo->prepare("INSERT INTO logs_activite (id_utilisateur, type_action, description) VALUES (?, 'TICKET_SUPPORT', ?)")
                    ->execute([$_SESSION['user_id'] ?? 1, "Réponse apportée au ticket support #$idTicket (Statut: $statut)."]);

                $feedbackMessage = "Le ticket support #$idTicket a été traité.";
            }
        }

        // 7. Supprimer Restaurant
        elseif ($action === 'supprimer_restaurant') {
            $idRest = (int)($_POST['id_restaurant'] ?? 0);
            if ($idRest > 1) { // Protection du Restaurant #1
                $nomSuppr = (string)$pdo->query("SELECT nom FROM restaurants WHERE id_restaurant = $idRest")->fetchColumn();
                $pdo->prepare("DELETE FROM restaurants WHERE id_restaurant = ?")->execute([$idRest]);

                $pdo->prepare("INSERT INTO logs_activite (id_utilisateur, type_action, description) VALUES (?, 'SUPPRESSION_RESTAURANT', ?)")
                    ->execute([$_SESSION['user_id'] ?? 1, "Suppression définitive du restaurant $nomSuppr (ID: $idRest)."]);

                $feedbackMessage = "L'établissement <strong>" . htmlspecialchars($nomSuppr) . "</strong> a été supprimé.";
            } else {
                throw new Exception("Le restaurant principal #1 ne peut pas être supprimé.");
            }
        }

        // 8. Sauvegarde des Paramètres Généraux
        elseif ($action === 'sauvegarder_parametres') {
            $nomPlat   = trim($_POST['nom_plateforme'] ?? 'RestoOS Cloud');
            $devise    = trim($_POST['devise'] ?? 'DH');
            $email     = trim($_POST['contact_email'] ?? 'contact@restoos.ma');
            $tel       = trim($_POST['contact_telephone'] ?? '+212 6 00 00 00 00');
            $essaiJours= trim($_POST['duree_essai_jours'] ?? '14');

            $stmtConf = $pdo->prepare("INSERT INTO plateforme_config (cle, valeur) VALUES (?, ?) ON DUPLICATE KEY UPDATE valeur=VALUES(valeur)");
            $stmtConf->execute(['nom_plateforme', $nomPlat]);
            $stmtConf->execute(['devise', $devise]);
            $stmtConf->execute(['contact_email', $email]);
            $stmtConf->execute(['contact_telephone', $tel]);
            $stmtConf->execute(['duree_essai_jours', $essaiJours]);

            $feedbackMessage = "Les paramètres généraux de la plateforme ont été enregistrés.";
        }

        // 9. Mise à Jour Forfait Commercial (Offres & Tarifs)
        elseif ($action === 'sauvegarder_plan') {
            $code        = strtoupper(trim($_POST['plan_code'] ?? ''));
            $nom         = trim($_POST['plan_nom'] ?? '');
            $badge       = trim($_POST['plan_badge'] ?? '');
            $prixMensuel = (float)($_POST['prix_mensuel'] ?? 0);
            $prixAnnuel  = (float)($_POST['prix_annuel'] ?? 0);
            $maxEmp      = max(1, (int)($_POST['max_employes'] ?? 1));
            $maxProd     = max(1, (int)($_POST['max_produits'] ?? 10));
            $desc        = trim($_POST['plan_description'] ?? '');

            if ($code !== '' && $prixMensuel >= 0) {
                $stmtPlan = $pdo->prepare(
                    "UPDATE plans 
                     SET nom = :nom, badge = :badge, prix_mensuel = :pm, prix_annuel = :pa, max_employes = :me, max_produits = :mp, description = :desc 
                     WHERE code = :code"
                );
                $stmtPlan->execute([
                    ':nom'   => $nom ?: $code,
                    ':badge' => $badge ?: null,
                    ':pm'    => $prixMensuel,
                    ':pa'    => $prixAnnuel,
                    ':me'    => $maxEmp,
                    ':mp'    => $maxProd,
                    ':desc'  => $desc,
                    ':code'  => $code
                ]);

                $pdo->prepare("INSERT INTO logs_activite (id_utilisateur, type_action, description) VALUES (?, 'MODIFICATION_FORFAIT', ?)")
                    ->execute([$_SESSION['user_id'] ?? 1, "Mise à jour tarifaire du forfait $code ($nom - $prixMensuel DH/m, max $maxEmp caisses, max $maxProd produits)."]);

                $feedbackMessage = "Le forfait commercial <strong>" . htmlspecialchars($nom ?: $code) . "</strong> a été mis à jour avec succès.";
            }
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $feedbackMessage = "Erreur : " . $e->getMessage();
        $feedbackType = 'error';
    }
}

// -------------------------------------------------------------
// IMPERSONATION / SWITCH TENANT (1-CLIC SHORTCUT)
// -------------------------------------------------------------
if (isset($_GET['switch_to'])) {
    $targetId = (int)$_GET['switch_to'];
    $stmt = $pdo->prepare("SELECT id_restaurant, nom, slug FROM restaurants WHERE id_restaurant = ?");
    $stmt->execute([$targetId]);
    $targetRest = $stmt->fetch();
    if ($targetRest) {
        $_SESSION['restaurant_id']   = (int)$targetRest['id_restaurant'];
        $_SESSION['restaurant_nom']  = $targetRest['nom'];
        $_SESSION['restaurant_slug'] = $targetRest['slug'];
        
        $dest = $_GET['dest'] ?? 'menu';
        $redirectMap = [
            'pos' => 'index.php',
            'kds' => 'cuisine.php',
            'menu' => 'menu_admin.php?restaurant_id=' . $targetRest['id_restaurant']
        ];
        header('Location: ' . ($redirectMap[$dest] ?? 'menu_admin.php'));
        exit();
    }
}

// -------------------------------------------------------------
// TÉLÉMÉTRIE & STATISTIQUES SAAS (AVEC TOLÉRANCE AUX PANNES)
// -------------------------------------------------------------
$statsRest = [
    'total_count' => 0, 'active_count' => 0, 'trial_count' => 0, 'suspended_count' => 0,
    'count_basic' => 0, 'count_pro' => 0, 'count_premium' => 0
];
$restaurants = [];
$plans = [];
$paiements = [];
$tickets = [];
$logs = [];
$configRaw = [];
$totalRevenue = 0.0;
$openTicketsCount = 0;

// 1. Ventilation des Restaurants
try {
    $row = $pdo->query(
        "SELECT 
            COUNT(*) AS total_count,
            SUM(CASE WHEN statut = 'ACTIF' THEN 1 ELSE 0 END) AS active_count,
            SUM(CASE WHEN statut = 'ESSAI' THEN 1 ELSE 0 END) AS trial_count,
            SUM(CASE WHEN statut = 'SUSPENDU' THEN 1 ELSE 0 END) AS suspended_count,
            SUM(CASE WHEN plan = 'BASIC' THEN 1 ELSE 0 END) AS count_basic,
            SUM(CASE WHEN plan = 'PRO' THEN 1 ELSE 0 END) AS count_pro,
            SUM(CASE WHEN plan = 'PREMIUM' THEN 1 ELSE 0 END) AS count_premium
         FROM restaurants"
    )->fetch(PDO::FETCH_ASSOC);
    if ($row) $statsRest = $row;
} catch (Throwable $e) {
    try {
        $count = (int)$pdo->query("SELECT COUNT(*) FROM restaurants")->fetchColumn();
        $statsRest['total_count'] = $count;
        $statsRest['active_count'] = $count;
    } catch (Throwable $e2) {}
}

$totalRestaurants     = (int)($statsRest['total_count'] ?? 0);
$activeRestaurants    = (int)($statsRest['active_count'] ?? 0);
$trialRestaurants     = (int)($statsRest['trial_count'] ?? 0);
$suspendedRestaurants = (int)($statsRest['suspended_count'] ?? 0);
$planBasicCount       = (int)($statsRest['count_basic'] ?? 0);
$planProCount         = (int)($statsRest['count_pro'] ?? 0);
$planPremiumCount     = (int)($statsRest['count_premium'] ?? 0);

// 2. Calcul du MRR (Monthly Recurring Revenue)
$mrr = ($planBasicCount * 290) + ($planProCount * 590) + ($planPremiumCount * 990);
$arr = $mrr * 12;

// 3. Total Paiements Encaissés (DH)
try {
    $totalRevenue = (float)$pdo->query("SELECT COALESCE(SUM(montant), 0) FROM paiements WHERE statut = 'VALIDE'")->fetchColumn();
} catch (Throwable $e) {
    $totalRevenue = 6490.00;
}

// 3b. Historique Mensuel des Revenus Encaissés
$monthlyRevenue = [];
try {
    $monthlyRevenue = $pdo->query(
        "SELECT DATE_FORMAT(date_paiement, '%m/%Y') AS mois_nom,
                DATE_FORMAT(date_paiement, '%Y-%m') AS mois_cle,
                SUM(montant) AS total_mois,
                COUNT(*) AS nb_tx
         FROM paiements
         WHERE statut = 'VALIDE'
         GROUP BY DATE_FORMAT(date_paiement, '%Y-%m'), DATE_FORMAT(date_paiement, '%m/%Y')
         ORDER BY mois_cle DESC
         LIMIT 6"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $monthlyRevenue = [];
}

// 4. Liste Complète des Restaurants
try {
    $restaurants = $pdo->query(
        "SELECT r.*,
                DATEDIFF(COALESCE(r.date_expiration, CURRENT_DATE()), CURRENT_DATE()) AS jours_restants,
                (SELECT COUNT(*) FROM sandwichs s WHERE s.id_restaurant = r.id_restaurant) AS total_produits,
                (SELECT COUNT(*) FROM commandes c WHERE c.id_restaurant = r.id_restaurant) AS total_commandes,
                (SELECT COALESCE(SUM(c.montant_total), 0) FROM commandes c WHERE c.id_restaurant = r.id_restaurant) AS ca_commandes,
                (SELECT u.login FROM utilisateurs u WHERE u.id_restaurant = r.id_restaurant AND u.role = 'ADMIN' LIMIT 1) AS admin_login
         FROM restaurants r
         ORDER BY r.id_restaurant ASC"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    try {
        $restaurants = $pdo->query("SELECT * FROM restaurants ORDER BY id_restaurant ASC")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e2) {
        $restaurants = [];
    }
}

// 5. Plans SaaS
try {
    $plans = $pdo->query("SELECT * FROM plans ORDER BY prix_mensuel ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $plans = [
        ['code' => 'BASIC', 'nom' => 'Basic Starter', 'prix_mensuel' => 290.00, 'prix_annuel' => 2900.00, 'max_employes' => 2, 'max_produits' => 25, 'badge' => 'DÉMARRAGE', 'description' => 'Caisse tactile POS et gestion menu.'],
        ['code' => 'PRO', 'nom' => 'Pro Restaurant', 'prix_mensuel' => 590.00, 'prix_annuel' => 5900.00, 'max_employes' => 8, 'max_produits' => 100, 'badge' => 'LE PLUS POPULAIRE', 'description' => 'Tout Basic + Écran Cuisine KDS, TV public et rapports.'],
        ['code' => 'PREMIUM', 'nom' => 'Enterprise Franchise', 'prix_mensuel' => 990.00, 'prix_annuel' => 9900.00, 'max_employes' => 25, 'max_produits' => 500, 'badge' => 'MULTI-ÉTABLISSEMENTS', 'description' => 'Tout Pro + Multi-caisses, audit Z et support 24/7.']
    ];
}

// 6. Historique Récent des Paiements
try {
    $paiements = $pdo->query(
        "SELECT p.*, r.nom AS restaurant_nom
         FROM paiements p
         JOIN restaurants r ON r.id_restaurant = p.id_restaurant
         ORDER BY p.date_paiement DESC LIMIT 30"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $paiements = [];
}

// 7. Tickets de Support
try {
    $tickets = $pdo->query(
        "SELECT t.*, r.nom AS restaurant_nom, r.telephone, r.email, r.contact_gerant, r.ville
         FROM tickets_support t
         JOIN restaurants r ON r.id_restaurant = t.id_restaurant
         ORDER BY CASE WHEN t.statut = 'OUVERT' THEN 1 WHEN t.statut = 'EN_COURS' THEN 2 ELSE 3 END, t.date_creation DESC"
    )->fetchAll(PDO::FETCH_ASSOC);
    foreach ($tickets as $t) {
        if (($t['statut'] ?? '') === 'OUVERT') $openTicketsCount++;
    }
} catch (Throwable $e) {
    $tickets = [];
}

// 8. Logs d'Activité Récents
try {
    $logs = $pdo->query(
        "SELECT l.*, u.nom AS utilisateur_nom, r.nom AS restaurant_nom
         FROM logs_activite l
         LEFT JOIN utilisateurs u ON u.id_utilisateur = l.id_utilisateur
         LEFT JOIN restaurants r ON r.id_restaurant = l.id_restaurant
         ORDER BY l.date_creation DESC LIMIT 40"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $logs = [];
}

// 9. Configuration Plateforme
try {
    $configRaw = $pdo->query("SELECT cle, valeur FROM plateforme_config")->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
} catch (Throwable $e) {
    $configRaw = [];
}
$nomPlateforme = $configRaw['nom_plateforme'] ?? 'RestoOS Cloud';
$deviseSymbol  = $configRaw['devise'] ?? 'DH';

// Formattage de la date en français
setlocale(LC_TIME, 'fr_FR.utf8', 'fra');
$dateToday = date('d/m/Y');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Super Admin — <?= htmlspecialchars($nomPlateforme) ?></title>
    <link rel="icon" type="image/svg+xml" href="assets/img/favicon.svg">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #6366F1;
            --primary-hover: #4F46E5;
            --primary-light: #EEF2FF;
            --sidebar-bg: #111827;
            --sidebar-hover: #1F2937;
            --sidebar-active: #374151;
            --bg-page: #F8FAFC;
            --card-bg: #FFFFFF;
            --text-heading: #0F172A;
            --text-body: #334155;
            --text-muted: #64748B;
            --border-subtle: #E2E8F0;
            --success: #10B981;
            --warning: #F59E0B;
            --danger: #EF4444;
            --radius-card: 14px;
            --radius-btn: 8px;
            --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
            --shadow-card: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px -1px rgba(0, 0, 0, 0.1);
            --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -4px rgba(0, 0, 0, 0.1);
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: var(--bg-page);
            color: var(--text-body);
            min-height: 100vh;
            display: block;
            overflow-x: hidden;
            width: 100%;
        }

        /* -------------------------------------------------------------
           SIDEBAR FIXE & PLIABLE
        ------------------------------------------------------------- */
        .sidebar {
            width: 260px;
            background: var(--sidebar-bg);
            color: #94A3B8;
            flex-shrink: 0;
            display: flex;
            flex-direction: column;
            border-right: 1px solid rgba(255, 255, 255, 0.08);
            position: fixed;
            top: 0;
            left: 0;
            bottom: 0;
            z-index: 50;
            transition: width 0.25s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .sidebar.collapsed {
            width: 76px;
        }

        .sidebar-brand {
            padding: 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 1px solid rgba(255, 255, 255, 0.06);
            height: 70px;
        }

        .brand-link {
            display: flex;
            align-items: center;
            gap: 12px;
            text-decoration: none;
            color: #FFFFFF;
            font-weight: 700;
            font-size: 17px;
            letter-spacing: -0.02em;
            overflow: hidden;
            white-space: nowrap;
        }

        .brand-logo-icon {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            background: linear-gradient(135deg, #6366F1, #8B5CF6);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #FFFFFF;
            flex-shrink: 0;
            box-shadow: 0 4px 12px rgba(99, 102, 241, 0.4);
        }

        .brand-text {
            display: flex;
            flex-direction: column;
        }

        .brand-subtitle {
            font-size: 10.5px;
            font-weight: 600;
            color: #818CF8;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .btn-collapse-sidebar {
            background: none;
            border: none;
            color: #64748B;
            cursor: pointer;
            padding: 6px;
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s ease;
        }

        .btn-collapse-sidebar:hover {
            color: #FFFFFF;
            background: rgba(255, 255, 255, 0.08);
        }

        .sidebar-nav {
            flex: 1;
            padding: 16px 12px;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            gap: 24px;
        }

        .nav-group-title {
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: #64748B;
            padding: 0 12px;
            margin-bottom: 8px;
            white-space: nowrap;
            overflow: hidden;
            transition: opacity 0.2s ease;
        }

        .sidebar.collapsed .nav-group-title {
            opacity: 0;
            height: 0;
            margin: 0;
        }

        .nav-list {
            list-style: none;
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .nav-item-btn {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 9px 12px;
            border-radius: var(--radius-btn);
            color: #94A3B8;
            text-decoration: none;
            font-size: 13.5px;
            font-weight: 500;
            background: transparent;
            border: none;
            width: 100%;
            cursor: pointer;
            text-align: left;
            transition: all 0.15s ease;
            white-space: nowrap;
            position: relative;
        }

        .nav-item-btn:hover {
            color: #F1F5F9;
            background: var(--sidebar-hover);
        }

        .nav-item-btn.active {
            color: #FFFFFF;
            background: var(--primary);
            font-weight: 600;
            box-shadow: 0 4px 12px rgba(99, 102, 241, 0.35);
        }

        .nav-icon {
            flex-shrink: 0;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .nav-badge {
            margin-left: auto;
            background: rgba(255, 255, 255, 0.15);
            color: #FFFFFF;
            font-size: 11px;
            font-weight: 700;
            padding: 2px 7px;
            border-radius: 999px;
        }

        .nav-badge.badge-amber {
            background: #F59E0B;
            color: #FFFFFF;
        }

        .sidebar.collapsed .nav-text,
        .sidebar.collapsed .nav-badge {
            display: none;
        }

        /* Sidebar Profile Card at Bottom */
        .sidebar-footer {
            padding: 14px 16px;
            border-top: 1px solid rgba(255, 255, 255, 0.06);
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: rgba(0, 0, 0, 0.15);
            height: 70px;
        }

        .user-profile-widget {
            display: flex;
            align-items: center;
            gap: 10px;
            overflow: hidden;
            text-decoration: none;
        }

        .user-avatar-circle {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            background: linear-gradient(135deg, #4F46E5, #6366F1);
            color: #FFFFFF;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            font-weight: 700;
            flex-shrink: 0;
            border: 2px solid rgba(255, 255, 255, 0.1);
        }

        .user-meta {
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        .user-name {
            font-size: 13.5px;
            font-weight: 600;
            color: #F8FAFC;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .user-role-tag {
            font-size: 11px;
            color: #818CF8;
            font-weight: 600;
        }

        .btn-logout {
            color: #94A3B8;
            background: none;
            border: none;
            cursor: pointer;
            padding: 6px;
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s ease;
        }

        .btn-logout:hover {
            color: #EF4444;
            background: rgba(239, 68, 68, 0.1);
        }

        .sidebar.collapsed .user-meta,
        .sidebar.collapsed .btn-logout {
            display: none;
        }

        /* -------------------------------------------------------------
           MAIN CONTENT AREA
        ------------------------------------------------------------- */
        .main-wrapper {
            margin-left: 260px;
            width: calc(100% - 260px);
            max-width: calc(100% - 260px);
            min-width: 0;
            box-sizing: border-box;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
            transition: margin-left 0.25s cubic-bezier(0.4, 0, 0.2, 1), width 0.25s cubic-bezier(0.4, 0, 0.2, 1), max-width 0.25s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .main-wrapper.expanded {
            margin-left: 76px;
            width: calc(100% - 76px);
            max-width: calc(100% - 76px);
        }

        /* Top Platform Header */
        .topbar {
            height: 70px;
            background: #FFFFFF;
            border-bottom: 1px solid var(--border-subtle);
            padding: 0 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: sticky;
            top: 0;
            z-index: 40;
            box-shadow: var(--shadow-sm);
            width: 100%;
            box-sizing: border-box;
        }

        .topbar-left {
            display: flex;
            align-items: center;
            gap: 20px;
            flex: 1;
            min-width: 0;
        }

        .topbar-title {
            font-size: 18px;
            font-weight: 700;
            color: var(--text-heading);
            letter-spacing: -0.02em;
            white-space: nowrap;
        }

        .topbar-search-box {
            position: relative;
            max-width: 280px;
            width: 100%;
            flex-shrink: 1;
        }

        .topbar-search-box input {
            width: 100%;
            height: 38px;
            padding: 8px 12px 8px 36px;
            font-size: 13.5px;
            border-radius: 8px;
            border: 1px solid var(--border-subtle);
            background: #F8FAFC;
            font-family: inherit;
            color: var(--text-heading);
            transition: all 0.2s ease;
        }

        .topbar-search-box input:focus {
            outline: none;
            border-color: var(--primary);
            background: #FFFFFF;
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.15);
        }

        .topbar-search-icon {
            position: absolute;
            left: 11px;
            top: 10px;
            color: #94A3B8;
            pointer-events: none;
        }

        .topbar-right {
            display: flex;
            align-items: center;
            gap: 16px;
            flex-shrink: 0;
        }

        .date-chip {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 13px;
            color: var(--text-muted);
            font-weight: 500;
            background: #F1F5F9;
            padding: 6px 12px;
            border-radius: 999px;
            white-space: nowrap;
        }

        .btn-primary-action {
            display: flex;
            align-items: center;
            gap: 8px;
            background: var(--primary);
            color: #FFFFFF;
            border: none;
            padding: 9px 16px;
            border-radius: var(--radius-btn);
            font-size: 13.5px;
            font-weight: 600;
            cursor: pointer;
            box-shadow: 0 2px 8px rgba(99, 102, 241, 0.35);
            transition: all 0.2s ease;
            white-space: nowrap;
            flex-shrink: 0;
        }

        .btn-primary-action:hover {
            background: var(--primary-hover);
            transform: translateY(-1px);
        }

        /* Notification Pill Alert */
        .toast-notification {
            margin: 20px 32px 0 32px;
            padding: 14px 18px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 14px;
            box-shadow: var(--shadow-sm);
            animation: slideDown 0.3s ease;
        }

        .toast-success {
            background: #ECFDF5;
            border: 1px solid #A7F3D0;
            color: #065F46;
        }

        .toast-error {
            background: #FEF2F2;
            border: 1px solid #FCA5A5;
            color: #991B1B;
        }

        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-8px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* Platform Body Container */
        .content-area {
            padding: 24px 28px;
            flex: 1;
            width: 100%;
            box-sizing: border-box;
            min-width: 0;
        }

        /* Tab Views */
        .tab-panel {
            display: none;
        }

        .tab-panel.active {
            display: block;
            animation: fadeIn 0.25s ease;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(4px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* -------------------------------------------------------------
           STATISTICS KPI CARDS (6 CARDS)
        ------------------------------------------------------------- */
        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(6, 1fr);
            gap: 18px;
            margin-bottom: 28px;
        }

        .kpi-card {
            background: var(--card-bg);
            border: 1px solid var(--border-subtle);
            border-radius: var(--radius-card);
            padding: 20px;
            box-shadow: var(--shadow-card);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            transition: all 0.2s ease;
            position: relative;
            overflow: hidden;
        }

        .kpi-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
            border-color: #CBD5E1;
        }

        .kpi-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 12px;
        }

        .kpi-title {
            font-size: 12.5px;
            font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        .kpi-icon-badge {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 15px;
        }

        .kpi-icon-indigo  { background: #EEF2FF; color: #4F46E5; }
        .kpi-icon-emerald { background: #ECFDF5; color: #059669; }
        .kpi-icon-amber   { background: #FFFBEB; color: #D97706; }
        .kpi-icon-rose    { background: #FFF1F2; color: #E11D48; }
        .kpi-icon-blue    { background: #EFF6FF; color: #2563EB; }
        .kpi-icon-violet  { background: #F5F3FF; color: #7C3AED; }

        .kpi-value {
            font-size: 26px;
            font-weight: 800;
            color: var(--text-heading);
            letter-spacing: -0.03em;
            line-height: 1.1;
            margin-bottom: 8px;
        }

        .kpi-subtext {
            font-size: 12px;
            display: flex;
            align-items: center;
            gap: 6px;
            color: var(--text-muted);
        }

        .growth-pill {
            font-weight: 700;
            font-size: 11px;
            padding: 2px 6px;
            border-radius: 999px;
            display: inline-flex;
            align-items: center;
            gap: 2px;
        }

        .growth-pos { background: #D1FAE5; color: #065F46; }
        .growth-neg { background: #FEE2E2; color: #991B1B; }

        /* -------------------------------------------------------------
           ANALYTICS & CHARTS SECTION (OVERVIEW)
        ------------------------------------------------------------- */
        .analytics-row {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 24px;
            margin-bottom: 28px;
        }

        .dashboard-card {
            background: var(--card-bg);
            border: 1px solid var(--border-subtle);
            border-radius: var(--radius-card);
            box-shadow: var(--shadow-card);
            padding: 20px 24px;
            width: 100%;
            box-sizing: border-box;
            min-width: 0;
        }

        .card-header-flex {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 12px;
        }

        .card-title-group h3 {
            font-size: 16px;
            font-weight: 700;
            color: var(--text-heading);
            letter-spacing: -0.01em;
            margin-bottom: 4px;
        }

        .card-title-group p {
            font-size: 13px;
            color: var(--text-muted);
        }

        .chart-period-switch {
            display: flex;
            background: #F1F5F9;
            padding: 3px;
            border-radius: 8px;
            gap: 2px;
        }

        .btn-period {
            border: none;
            background: transparent;
            padding: 5px 12px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
            color: var(--text-muted);
            cursor: pointer;
            transition: all 0.15s ease;
        }

        .btn-period.active {
            background: #FFFFFF;
            color: var(--primary);
            box-shadow: 0 1px 2px rgba(0,0,0,0.06);
        }

        /* SVG Interactive Revenue Chart */
        .chart-container-box {
            height: 230px;
            width: 100%;
            position: relative;
        }

        .revenue-chart-svg {
            width: 100%;
            height: 100%;
            overflow: visible;
        }

        /* Donut Chart (Abonnements) */
        .donut-container-box {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            height: 230px;
        }

        .donut-legend {
            width: 100%;
            display: flex;
            flex-direction: column;
            gap: 8px;
            margin-top: 16px;
        }

        .legend-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 13px;
        }

        .legend-label {
            display: flex;
            align-items: center;
            gap: 8px;
            color: var(--text-body);
        }

        .legend-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
        }

        .dot-basic   { background: #94A3B8; }
        .dot-pro     { background: #6366F1; }
        .dot-premium { background: #8B5CF6; }

        .legend-value {
            font-weight: 700;
            color: var(--text-heading);
        }

        /* -------------------------------------------------------------
           TABLES MODERNES (STRIPE / LINEAR STYLE)
        ------------------------------------------------------------- */
        .table-responsive {
            width: 100%;
            max-width: 100%;
            overflow-x: auto;
            border-radius: 10px;
            border: 1px solid var(--border-subtle);
            -webkit-overflow-scrolling: touch;
        }

        .table-responsive::-webkit-scrollbar {
            height: 6px;
        }

        .table-responsive::-webkit-scrollbar-track {
            background: #F1F5F9;
            border-radius: 4px;
        }

        .table-responsive::-webkit-scrollbar-thumb {
            background: #CBD5E1;
            border-radius: 4px;
        }

        .table-responsive::-webkit-scrollbar-thumb:hover {
            background: #94A3B8;
        }

        .enterprise-table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
            font-size: 13px;
            background: #FFFFFF;
        }

        .enterprise-table th {
            background: #F8FAFC;
            color: var(--text-muted);
            font-weight: 600;
            font-size: 11.5px;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            padding: 12px 14px;
            border-bottom: 1px solid var(--border-subtle);
            white-space: nowrap;
        }

        .enterprise-table td {
            padding: 12px 14px;
            border-bottom: 1px solid #F1F5F9;
            vertical-align: middle;
            color: var(--text-body);
            white-space: nowrap;
        }

        .enterprise-table tr:last-child td {
            border-bottom: none;
        }

        .enterprise-table tr:hover td {
            background: #F8FAFC;
        }

        /* Restaurant Identity Badge in Table */
        .tenant-cell {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .tenant-avatar {
            width: 36px;
            height: 36px;
            border-radius: 8px;
            background: linear-gradient(135deg, #1E293B, #0F172A);
            color: #FFFFFF;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 13px;
            flex-shrink: 0;
            box-shadow: var(--shadow-sm);
        }

        .tenant-info {
            display: flex;
            flex-direction: column;
        }

        .tenant-name {
            font-weight: 600;
            color: var(--text-heading);
        }

        .tenant-slug {
            font-size: 12px;
            color: var(--text-muted);
        }

        /* Plan Badges */
        .plan-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-size: 11.5px;
            font-weight: 700;
            padding: 3px 8px;
            border-radius: 999px;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        .plan-basic   { background: #F1F5F9; color: #475569; border: 1px solid #E2E8F0; }
        .plan-pro     { background: #EEF2FF; color: #4338CA; border: 1px solid #C7D2FE; }
        .plan-premium { background: #FAF5FF; color: #6B21A8; border: 1px solid #E9D5FF; }

        /* Status Badges */
        .status-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 12px;
            font-weight: 600;
            padding: 3px 10px;
            border-radius: 999px;
        }

        .status-pill::before {
            content: '';
            width: 6px;
            height: 6px;
            border-radius: 50%;
        }

        .status-active   { background: #ECFDF5; color: #065F46; }
        .status-active::before { background: #10B981; box-shadow: 0 0 6px #10B981; }

        .status-trial    { background: #FFFBEB; color: #92400E; }
        .status-trial::before { background: #F59E0B; }

        .status-suspended{ background: #FEF2F2; color: #991B1B; }
        .status-suspended::before { background: #EF4444; }

        /* Actions Dropdown / Action Buttons */
        .actions-cell {
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .btn-table-action {
            background: #FFFFFF;
            border: 1px solid var(--border-subtle);
            color: var(--text-body);
            padding: 6px 10px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            text-decoration: none;
            transition: all 0.15s ease;
        }

        .btn-table-action:hover {
            border-color: var(--primary);
            color: var(--primary);
            background: var(--primary-light);
        }

        .btn-table-action.btn-danger-action:hover {
            border-color: var(--danger);
            color: var(--danger);
            background: #FEF2F2;
        }

        /* -------------------------------------------------------------
           PLANS & PRICING CARDS
        ------------------------------------------------------------- */
        .pricing-cards-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 24px;
            margin-top: 16px;
        }

        .pricing-card {
            background: #FFFFFF;
            border: 1px solid var(--border-subtle);
            border-radius: var(--radius-card);
            padding: 32px;
            box-shadow: var(--shadow-card);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            position: relative;
            transition: all 0.2s ease;
        }

        .pricing-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-lg);
            border-color: #CBD5E1;
        }

        .pricing-card.featured {
            border-color: var(--primary);
            box-shadow: 0 10px 25px -5px rgba(99, 102, 241, 0.25);
        }

        .pricing-badge-popular {
            position: absolute;
            top: -12px;
            left: 50%;
            transform: translateX(-50%);
            background: var(--primary);
            color: #FFFFFF;
            font-size: 11px;
            font-weight: 700;
            padding: 4px 12px;
            border-radius: 999px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .plan-price-box {
            margin: 20px 0;
        }

        .plan-price-main {
            font-size: 36px;
            font-weight: 800;
            color: var(--text-heading);
            letter-spacing: -0.03em;
        }

        .plan-price-period {
            font-size: 14px;
            color: var(--text-muted);
            font-weight: 500;
        }

        .plan-feature-list {
            list-style: none;
            display: flex;
            flex-direction: column;
            gap: 12px;
            margin: 20px 0 28px 0;
            font-size: 13.5px;
        }

        .plan-feature-item {
            display: flex;
            align-items: center;
            gap: 10px;
            color: var(--text-body);
        }

        .check-icon {
            color: var(--success);
            flex-shrink: 0;
        }

        /* -------------------------------------------------------------
           MODALS (STRIPE MODAL STANDARD)
        ------------------------------------------------------------- */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(15, 23, 42, 0.65);
            backdrop-filter: blur(4px);
            z-index: 1000;
            align-items: flex-start;
            justify-content: center;
            overflow-y: auto;
            padding: 40px 16px;
        }

        .modal-overlay.active {
            display: flex;
        }

        .modal-card {
            background: #FFFFFF;
            width: 100%;
            max-width: 620px;
            border-radius: 16px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
            border: 1px solid var(--border-subtle);
            margin: auto;
            overflow: hidden;
            animation: modalPop 0.2s cubic-bezier(0.16, 1, 0.3, 1);
        }

        @keyframes modalPop {
            from { transform: scale(0.96); opacity: 0; }
            to { transform: scale(1); opacity: 1; }
        }

        .modal-header {
            padding: 20px 24px;
            border-bottom: 1px solid var(--border-subtle);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .modal-header h3 {
            font-size: 17px;
            font-weight: 700;
            color: var(--text-heading);
            letter-spacing: -0.02em;
        }

        .btn-close-modal {
            background: none;
            border: none;
            color: #94A3B8;
            cursor: pointer;
            padding: 4px;
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .btn-close-modal:hover {
            color: var(--text-heading);
            background: #F1F5F9;
        }

        .modal-body {
            padding: 24px;
            max-height: calc(85vh - 140px);
            overflow-y: auto;
        }

        .modal-footer {
            padding: 16px 24px;
            border-top: 1px solid var(--border-subtle);
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 12px;
            background: #F8FAFC;
        }

        .form-grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
            margin-bottom: 16px;
        }

        .form-field {
            display: flex;
            flex-direction: column;
            gap: 6px;
            margin-bottom: 16px;
            width: 100%;
        }

        .form-field label {
            font-size: 12.5px;
            font-weight: 700;
            color: #1E293B;
            letter-spacing: -0.01em;
        }

        .form-field input,
        .form-field select,
        .form-field textarea {
            width: 100%;
            box-sizing: border-box;
            height: 42px;
            padding: 9px 14px;
            font-size: 14px;
            font-weight: 500;
            border-radius: 8px;
            border: 1px solid #CBD5E1;
            font-family: inherit;
            color: #0F172A;
            background: #FFFFFF;
            transition: all 0.15s ease;
        }

        .form-field textarea {
            height: auto;
            min-height: 70px;
            padding: 10px 14px;
            resize: vertical;
        }

        .form-field input:focus,
        .form-field select:focus,
        .form-field textarea:focus {
            outline: none;
            border-color: #6366F1;
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.15);
            background: #FFFFFF;
        }

        .input-currency-wrapper {
            position: relative;
            display: flex;
            align-items: center;
            width: 100%;
        }

        .input-currency-wrapper input {
            padding-right: 76px;
        }

        .input-currency-tag {
            position: absolute;
            right: 10px;
            font-size: 12px;
            font-weight: 700;
            color: #64748B;
            background: #F1F5F9;
            padding: 4px 8px;
            border-radius: 5px;
            pointer-events: none;
            border: 1px solid #E2E8F0;
        }

        .btn-cancel {
            background: #FFFFFF;
            border: 1px solid var(--border-subtle);
            color: var(--text-body);
            padding: 9px 16px;
            border-radius: var(--radius-btn);
            font-size: 13.5px;
            font-weight: 600;
            cursor: pointer;
        }

        .btn-cancel:hover {
            background: #F1F5F9;
        }

        .btn-confirm {
            background: var(--primary);
            border: none;
            color: #FFFFFF;
            padding: 9px 18px;
            border-radius: var(--radius-btn);
            font-size: 13.5px;
            font-weight: 600;
            cursor: pointer;
            box-shadow: 0 2px 8px rgba(99, 102, 241, 0.35);
        }

        .btn-confirm:hover {
            background: var(--primary-hover);
        }

        .btn-danger-confirm {
            background: var(--danger);
            border: none;
            color: #FFFFFF;
            padding: 9px 18px;
            border-radius: var(--radius-btn);
            font-size: 13.5px;
            font-weight: 600;
            cursor: pointer;
        }

        /* Responsive Breakpoints */
        @media (max-width: 1200px) {
            .kpi-grid {
                grid-template-columns: repeat(3, 1fr);
            }
            .pricing-cards-grid {
                grid-template-columns: 1fr;
            }
            .analytics-row {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
            }
            .main-wrapper, .main-wrapper.expanded {
                margin-left: 0;
                width: 100%;
                max-width: 100%;
            }
            .kpi-grid {
                grid-template-columns: 1fr 1fr;
            }
            .topbar-search-box {
                display: none;
            }
        }

        /* Styles d'impression haute fidélité pour reçus */
        @media print {
            body * {
                visibility: hidden !important;
            }
            #printableReceiptArea, #printableReceiptArea * {
                visibility: visible !important;
            }
            #printableReceiptArea {
                position: fixed !important;
                left: 0 !important;
                top: 0 !important;
                width: 100% !important;
                max-width: 190mm !important;
                margin: 10mm auto !important;
                padding: 24px !important;
                border: 1px solid #CBD5E1 !important;
                box-shadow: none !important;
                background: #FFFFFF !important;
                color: #0F172A !important;
                z-index: 999999 !important;
            }
        }
    </style>
</head>
<body>

    <!-- -------------------------------------------------------------
         SIDEBAR NAVIGATION
    ------------------------------------------------------------- -->
    <aside class="sidebar" id="appSidebar">
        <div class="sidebar-brand">
            <a href="superadmin.php" class="brand-link">
                <div class="brand-logo-icon">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3">
                        <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon>
                    </svg>
                </div>
                <div class="brand-text">
                    <span><?= htmlspecialchars($nomPlateforme) ?></span>
                    <span class="brand-subtitle">Super Admin</span>
                </div>
            </a>
            <button class="btn-collapse-sidebar" id="btnToggleSidebar" title="Réduire / Agrandir la barre latérale">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <polyline points="11 17 6 12 11 7"></polyline>
                    <polyline points="18 17 13 12 18 7"></polyline>
                </svg>
            </button>
        </div>

        <nav class="sidebar-nav">
            <!-- MAIN -->
            <div>
                <div class="nav-group-title">Principal</div>
                <ul class="nav-list">
                    <li>
                        <button class="nav-item-btn active" onclick="switchTab('overview', this)">
                            <span class="nav-icon">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <rect x="3" y="3" width="7" height="7"></rect>
                                    <rect x="14" y="3" width="7" height="7"></rect>
                                    <rect x="14" y="14" width="7" height="7"></rect>
                                    <rect x="3" y="14" width="7" height="7"></rect>
                                </svg>
                            </span>
                            <span class="nav-text">Vue d'ensemble</span>
                        </button>
                    </li>
                    <li>
                        <button class="nav-item-btn" onclick="switchTab('restaurants', this)">
                            <span class="nav-icon">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path>
                                    <polyline points="9 22 9 12 15 12 15 22"></polyline>
                                </svg>
                            </span>
                            <span class="nav-text">Restaurants</span>
                            <span class="nav-badge"><?= $totalRestaurants ?></span>
                        </button>
                    </li>
                    <li>
                        <button class="nav-item-btn" onclick="switchTab('subscriptions', this)">
                            <span class="nav-icon">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <rect x="1" y="4" width="22" height="16" rx="2" ry="2"></rect>
                                    <line x1="1" y1="10" x2="23" y2="10"></line>
                                </svg>
                            </span>
                            <span class="nav-text">Abonnements</span>
                        </button>
                    </li>
                    <li>
                        <button class="nav-item-btn" onclick="switchTab('plans', this)">
                            <span class="nav-icon">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"></path>
                                    <line x1="7" y1="7" x2="7.01" y2="7"></line>
                                </svg>
                            </span>
                            <span class="nav-text">Offres & Tarifs</span>
                        </button>
                    </li>
                    <li>
                        <button class="nav-item-btn" onclick="switchTab('payments', this)">
                            <span class="nav-icon">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <line x1="12" y1="1" x2="12" y2="23"></line>
                                    <path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path>
                                </svg>
                            </span>
                            <span class="nav-text">Paiements</span>
                        </button>
                    </li>
                    <li>
                        <button class="nav-item-btn" onclick="switchTab('analytics', this)">
                            <span class="nav-icon">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <line x1="18" y1="20" x2="18" y2="10"></line>
                                    <line x1="12" y1="20" x2="12" y2="4"></line>
                                    <line x1="6" y1="20" x2="6" y2="14"></line>
                                </svg>
                            </span>
                            <span class="nav-text">Revenus & Télémétrie</span>
                        </button>
                    </li>
                </ul>
            </div>

            <!-- MANAGEMENT -->
            <div>
                <div class="nav-group-title">Gestion & Support</div>
                <ul class="nav-list">
                    <li>
                        <button class="nav-item-btn" onclick="switchTab('support', this)">
                            <span class="nav-icon">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <circle cx="12" cy="12" r="10"></circle>
                                    <path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"></path>
                                    <line x1="12" y1="17" x2="12.01" y2="17"></line>
                                </svg>
                            </span>
                            <span class="nav-text">Tickets Support</span>
                            <?php if ($openTicketsCount > 0): ?>
                                <span class="nav-badge badge-amber"><?= $openTicketsCount ?></span>
                            <?php endif; ?>
                        </button>
                    </li>
                    <li>
                        <button class="nav-item-btn" onclick="switchTab('logs', this)">
                            <span class="nav-icon">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <polyline points="4 17 10 11 4 5"></polyline>
                                    <line x1="12" y1="19" x2="20" y2="19"></line>
                                </svg>
                            </span>
                            <span class="nav-text">Journal d'Activité</span>
                        </button>
                    </li>
                </ul>
            </div>

            <!-- SYSTEM -->
            <div>
                <div class="nav-group-title">Système</div>
                <ul class="nav-list">
                    <li>
                        <button class="nav-item-btn" onclick="switchTab('settings', this)">
                            <span class="nav-icon">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <circle cx="12" cy="12" r="3"></circle>
                                    <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path>
                                </svg>
                            </span>
                            <span class="nav-text">Paramètres Plateforme</span>
                        </button>
                    </li>
                </ul>
            </div>
        </nav>

        <!-- Profile Bar at Bottom -->
        <div class="sidebar-footer">
            <div class="user-profile-widget">
                <div class="user-avatar-circle">NZ</div>
                <div class="user-meta">
                    <span class="user-name">Nizar</span>
                    <span class="user-role-tag">Super Administrateur</span>
                </div>
            </div>
            <a href="login.php?action=logout" class="btn-logout" title="Déconnexion">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
                    <polyline points="16 17 21 12 16 7"></polyline>
                    <line x1="21" y1="12" x2="9" y2="12"></line>
                </svg>
            </a>
        </div>
    </aside>

    <!-- -------------------------------------------------------------
         MAIN WRAPPER
    ------------------------------------------------------------- -->
    <div class="main-wrapper" id="mainWrapper">
        
        <!-- TOPBAR -->
        <header class="topbar">
            <div class="topbar-left">
                <h1 class="topbar-title" id="pageHeaderTitle">Tableau de Bord</h1>
                <div class="topbar-search-box">
                    <span class="topbar-search-icon">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="11" cy="11" r="8"></circle>
                            <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                        </svg>
                    </span>
                    <input type="text" id="globalSearchInput" placeholder="Rechercher restaurant, transaction, gérant..." oninput="filterGlobalContent(this.value)">
                </div>
            </div>

            <div class="topbar-right">
                <div class="date-chip">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                        <line x1="16" y1="2" x2="16" y2="6"></line>
                        <line x1="8" y1="2" x2="8" y2="6"></line>
                        <line x1="3" y1="10" x2="21" y2="10"></line>
                    </svg>
                    <span><?= $dateToday ?></span>
                </div>

                <button class="btn-primary-action" onclick="openModal('modalNewRestaurant')">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                        <line x1="12" y1="5" x2="12" y2="19"></line>
                        <line x1="5" y1="12" x2="19" y2="12"></line>
                    </svg>
                    <span>Nouveau Restaurant</span>
                </button>
            </div>
        </header>

        <!-- FLASH NOTIFICATION -->
        <?php if ($feedbackMessage !== ''): ?>
            <div class="toast-notification <?= $feedbackType === 'success' ? 'toast-success' : 'toast-error' ?>">
                <div style="display: flex; align-items: center; gap: 10px;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <?php if ($feedbackType === 'success'): ?>
                            <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                            <polyline points="22 4 12 14.01 9 11.01"></polyline>
                        <?php else: ?>
                            <circle cx="12" cy="12" r="10"></circle>
                            <line x1="12" y1="8" x2="12" y2="12"></line>
                            <line x1="12" y1="16" x2="12.01" y2="16"></line>
                        <?php endif; ?>
                    </svg>
                    <span><?= $feedbackMessage ?></span>
                </div>
                <button onclick="this.parentElement.remove()" style="background:none; border:none; color:inherit; cursor:pointer;">&times;</button>
            </div>
        <?php endif; ?>

        <!-- CONTENT AREA -->
        <main class="content-area">

            <!-- =========================================================
                 1. OVERVIEW TAB
            ========================================================= -->
            <div class="tab-panel active" id="tab-overview">
                
                <!-- 6 STATISTICS CARDS -->
                <div class="kpi-grid">
                    <div class="kpi-card">
                        <div class="kpi-header">
                            <span class="kpi-title">Total Restaurants</span>
                            <div class="kpi-icon-badge kpi-icon-indigo">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path></svg>
                            </div>
                        </div>
                        <div class="kpi-value"><?= $totalRestaurants ?></div>
                        <div class="kpi-subtext">
                            <span class="growth-pill growth-pos">+100%</span>
                            <span>vs mois dernier</span>
                        </div>
                    </div>

                    <div class="kpi-card">
                        <div class="kpi-header">
                            <span class="kpi-title">Actifs</span>
                            <div class="kpi-icon-badge kpi-icon-emerald">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"></polyline></svg>
                            </div>
                        </div>
                        <div class="kpi-value" style="color: var(--success);"><?= $activeRestaurants ?></div>
                        <div class="kpi-subtext">
                            <span>Établissements en ligne</span>
                        </div>
                    </div>

                    <div class="kpi-card">
                        <div class="kpi-header">
                            <span class="kpi-title">Période d'Essai</span>
                            <div class="kpi-icon-badge kpi-icon-amber">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                            </div>
                        </div>
                        <div class="kpi-value" style="color: var(--warning);"><?= $trialRestaurants ?></div>
                        <div class="kpi-subtext">
                            <span>Essai gratuit 14j</span>
                        </div>
                    </div>

                    <div class="kpi-card">
                        <div class="kpi-header">
                            <span class="kpi-title">Suspendus</span>
                            <div class="kpi-icon-badge kpi-icon-rose">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"></line></svg>
                            </div>
                        </div>
                        <div class="kpi-value" style="color: var(--danger);"><?= $suspendedRestaurants ?></div>
                        <div class="kpi-subtext">
                            <span>Inactifs ou impayés</span>
                        </div>
                    </div>

                    <div class="kpi-card">
                        <div class="kpi-header">
                            <span class="kpi-title">MRR Récurrent</span>
                            <div class="kpi-icon-badge kpi-icon-blue">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"></line><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path></svg>
                            </div>
                        </div>
                        <div class="kpi-value"><?= number_format($mrr, 0, ',', ' ') ?> <span style="font-size: 15px; font-weight: 600;">DH</span></div>
                        <div class="kpi-subtext">
                            <span class="growth-pill growth-pos">+24.5%</span>
                            <span>revenu mensuel récurrent</span>
                        </div>
                    </div>

                    <div class="kpi-card">
                        <div class="kpi-header">
                            <span class="kpi-title">Revenu Global</span>
                            <div class="kpi-icon-badge kpi-icon-violet">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"></path></svg>
                            </div>
                        </div>
                        <div class="kpi-value"><?= number_format($totalRevenue, 0, ',', ' ') ?> <span style="font-size: 15px; font-weight: 600;">DH</span></div>
                        <div class="kpi-subtext">
                            <span>Total abonnements encaissés</span>
                        </div>
                    </div>
                </div>

                <!-- CHARTS ROW: REVENUE TREND + SUBSCRIPTION DISTRIBUTION -->
                <div class="analytics-row">
                    <div class="dashboard-card">
                        <div class="card-header-flex">
                            <div class="card-title-group">
                                <h3>Trajectoire des Revenus SaaS</h3>
                                <p>Volume des souscriptions et encaissements de la plateforme</p>
                            </div>
                            <div class="chart-period-switch">
                                <button class="btn-period" onclick="setChartPeriod('jour', this)">Jour</button>
                                <button class="btn-period" onclick="setChartPeriod('semaine', this)">Semaine</button>
                                <button class="btn-period active" onclick="setChartPeriod('mois', this)">Mois</button>
                                <button class="btn-period" onclick="setChartPeriod('annee', this)">Année</button>
                            </div>
                        </div>

                        <div class="chart-container-box">
                            <!-- Pure SVG Interactive Responsive Chart -->
                            <svg class="revenue-chart-svg" viewBox="0 0 650 200" preserveAspectRatio="none">
                                <defs>
                                    <linearGradient id="chartGradient" x1="0" y1="0" x2="0" y2="1">
                                        <stop offset="0%" stop-color="#6366F1" stop-opacity="0.35"/>
                                        <stop offset="100%" stop-color="#6366F1" stop-opacity="0.0"/>
                                    </linearGradient>
                                </defs>
                                <!-- Gridlines -->
                                <line x1="0" y1="40" x2="650" y2="40" stroke="#F1F5F9" stroke-width="1" stroke-dasharray="4"/>
                                <line x1="0" y1="90" x2="650" y2="90" stroke="#F1F5F9" stroke-width="1" stroke-dasharray="4"/>
                                <line x1="0" y1="140" x2="650" y2="140" stroke="#F1F5F9" stroke-width="1" stroke-dasharray="4"/>
                                <line x1="0" y1="180" x2="650" y2="180" stroke="#E2E8F0" stroke-width="1.5"/>

                                <!-- Smooth Area Fill -->
                                <path id="chartArea" d="M 0,180 L 0,140 Q 110,120 180,95 T 350,85 T 480,50 T 650,25 L 650,180 Z" fill="url(#chartGradient)"/>

                                <!-- Primary Curve Line -->
                                <path id="chartLine" d="M 0,140 Q 110,120 180,95 T 350,85 T 480,50 T 650,25" fill="none" stroke="#6366F1" stroke-width="3" stroke-linecap="round"/>

                                <!-- Data Points -->
                                <circle cx="180" cy="95" r="4.5" fill="#FFFFFF" stroke="#6366F1" stroke-width="2.5"/>
                                <circle cx="350" cy="85" r="4.5" fill="#FFFFFF" stroke="#6366F1" stroke-width="2.5"/>
                                <circle cx="480" cy="50" r="4.5" fill="#FFFFFF" stroke="#6366F1" stroke-width="2.5"/>
                                <circle cx="650" cy="25" r="5.5" fill="#FFFFFF" stroke="#4F46E5" stroke-width="3"/>
                            </svg>
                        </div>
                    </div>

                    <!-- Donut Distribution -->
                    <div class="dashboard-card">
                        <div class="card-header-flex">
                            <div class="card-title-group">
                                <h3>Ventilation Forfaits</h3>
                                <p>Répartition des abonnements actifs</p>
                            </div>
                        </div>

                        <div class="donut-container-box">
                            <svg width="140" height="140" viewBox="0 0 100 100">
                                <circle cx="50" cy="50" r="38" fill="transparent" stroke="#F1F5F9" stroke-width="16"/>
                                <!-- Segment Pro (Indigo) -->
                                <circle cx="50" cy="50" r="38" fill="transparent" stroke="#6366F1" stroke-width="16" stroke-dasharray="120 238" stroke-dashoffset="0"/>
                                <!-- Segment Premium (Violet) -->
                                <circle cx="50" cy="50" r="38" fill="transparent" stroke="#8B5CF6" stroke-width="16" stroke-dasharray="118 238" stroke-dashoffset="-120"/>
                                <text x="50" y="55" text-anchor="middle" font-size="14" font-weight="800" fill="#0F172A"><?= $totalRestaurants ?></text>
                            </svg>

                            <div class="donut-legend">
                                <div class="legend-row">
                                    <span class="legend-label">
                                        <span class="legend-dot dot-basic"></span>
                                        <span>Starter (Basic)</span>
                                    </span>
                                    <span class="legend-value"><?= $planBasicCount ?></span>
                                </div>
                                <div class="legend-row">
                                    <span class="legend-label">
                                        <span class="legend-dot dot-pro"></span>
                                        <span>Pro Restaurant</span>
                                    </span>
                                    <span class="legend-value"><?= $planProCount ?></span>
                                </div>
                                <div class="legend-row">
                                    <span class="legend-label">
                                        <span class="legend-dot dot-premium"></span>
                                        <span>Enterprise Franchise</span>
                                    </span>
                                    <span class="legend-value"><?= $planPremiumCount ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- RECENT RESTAURANTS & RECENT TRANSACTIONS -->
                <div style="display: grid; grid-template-columns: 1.3fr 1fr; gap: 24px;">
                    <!-- Recent Registrations -->
                    <div class="dashboard-card">
                        <div class="card-header-flex">
                            <div class="card-title-group">
                                <h3>Dernières Intégrations</h3>
                                <p>Établissements récemment onboarding sur le cloud</p>
                            </div>
                            <button class="btn-table-action" onclick="switchTab('restaurants')">Voir tout →</button>
                        </div>

                        <div class="table-responsive">
                            <table class="enterprise-table">
                                <thead>
                                    <tr>
                                        <th>Établissement</th>
                                        <th>Forfait</th>
                                        <th>Statut</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($restaurants as $r): ?>
                                        <tr>
                                            <td>
                                                <div class="tenant-cell">
                                                    <div class="tenant-avatar"><?= strtoupper(substr($r['nom'], 0, 2)) ?></div>
                                                    <div class="tenant-info">
                                                        <span class="tenant-name"><?= htmlspecialchars($r['nom']) ?></span>
                                                        <span class="tenant-slug"><?= htmlspecialchars($r['ville']) ?> &bull; <?= htmlspecialchars($r['proprietaire'] ?? '') ?></span>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <span class="plan-badge plan-<?= strtolower($r['plan'] ?? 'pro') ?>">
                                                    <?= htmlspecialchars($r['plan'] ?? 'PRO') ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="status-pill status-<?= strtolower($r['statut']) ?>">
                                                    <?= htmlspecialchars($r['statut']) ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div class="actions-cell">
                                                    <a href="superadmin.php?switch_to=<?= $r['id_restaurant'] ?>&dest=pos" class="btn-table-action" title="Ouvrir le terminal Caisse POS">
                                                        POS
                                                    </a>
                                                    <a href="superadmin.php?switch_to=<?= $r['id_restaurant'] ?>&dest=kds" class="btn-table-action" title="Ouvrir l'écran Cuisine KDS">
                                                        KDS
                                                    </a>
                                                    <a href="superadmin.php?switch_to=<?= $r['id_restaurant'] ?>&dest=menu" class="btn-table-action" title="Gérer le Menu Studio">
                                                        Studio
                                                    </a>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Recent Payments -->
                    <div class="dashboard-card">
                        <div class="card-header-flex">
                            <div class="card-title-group">
                                <h3>Transactions Récentes</h3>
                                <p>Derniers règlements d'abonnements</p>
                            </div>
                            <button class="btn-table-action" onclick="switchTab('payments')">Toutes →</button>
                        </div>

                        <div class="table-responsive">
                            <table class="enterprise-table">
                                <thead>
                                    <tr>
                                        <th>Réf. & Restaurant</th>
                                        <th>Montant</th>
                                        <th>Statut</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach (array_slice($paiements, 0, 5) as $p): ?>
                                        <tr>
                                            <td>
                                                <div style="font-weight: 600; color: var(--text-heading);"><?= htmlspecialchars($p['reference_tx']) ?></div>
                                                <div style="font-size: 12px; color: var(--text-muted);"><?= htmlspecialchars($p['restaurant_nom']) ?> &bull; <?= htmlspecialchars($p['methode']) ?></div>
                                            </td>
                                            <td>
                                                <div style="font-weight: 700; color: var(--text-heading);"><?= number_format((float)$p['montant'], 2) ?> DH</div>
                                                <div style="font-size: 11px; color: var(--text-muted);"><?= htmlspecialchars($p['plan_concerne']) ?> (<?= (int)$p['periode_mois'] ?>m)</div>
                                            </td>
                                            <td>
                                                <span class="status-pill status-active">Validé</span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

            </div>


            <!-- =========================================================
                 2. RESTAURANTS MANAGEMENT TAB
            ========================================================= -->
            <div class="tab-panel" id="tab-restaurants">
                
                <div class="dashboard-card" style="margin-bottom: 24px;">
                    <div class="card-header-flex">
                        <div class="card-title-group">
                            <h3>Gestion des Restaurants Tenants</h3>
                            <p>Supervisez tous les établissements souscrits sur la plateforme</p>
                        </div>
                        <button class="btn-primary-action" onclick="openModal('modalNewRestaurant')">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                            <span>Déployer un Établissement</span>
                        </button>
                    </div>

                    <!-- Toolbar Filters -->
                    <div style="display: flex; align-items: center; gap: 14px; margin-bottom: 20px; flex-wrap: wrap;">
                        <input type="text" id="filterRestInput" placeholder="Filtrer par nom, ville ou gérant..." oninput="filterRestaurantsTable()" style="padding: 8px 12px; border: 1px solid var(--border-subtle); border-radius: 8px; width: 280px; font-size: 13.5px;">
                        
                        <select id="filterPlanSelect" onchange="filterRestaurantsTable()" style="padding: 8px 12px; border: 1px solid var(--border-subtle); border-radius: 8px; font-size: 13.5px; background: #FFF;">
                            <option value="">Tous les forfaits</option>
                            <option value="BASIC">Starter (Basic)</option>
                            <option value="PRO">Pro Restaurant</option>
                            <option value="PREMIUM">Enterprise Franchise</option>
                        </select>

                        <select id="filterStatutSelect" onchange="filterRestaurantsTable()" style="padding: 8px 12px; border: 1px solid var(--border-subtle); border-radius: 8px; font-size: 13.5px; background: #FFF;">
                            <option value="">Tous les statuts</option>
                            <option value="ACTIF">Actif</option>
                            <option value="ESSAI">Essai</option>
                            <option value="SUSPENDU">Suspendu</option>
                        </select>
                    </div>

                    <!-- Restaurants Full Table -->
                    <div class="table-responsive">
                        <table class="enterprise-table" id="tableRestaurants">
                            <thead>
                                <tr>
                                    <th>Établissement</th>
                                    <th>Propriétaire & Contact</th>
                                    <th>Forfait SaaS</th>
                                    <th>Expiration</th>
                                    <th>Commandes & CA</th>
                                    <th>Statut</th>
                                    <th>Accès Direct (1-Clic)</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($restaurants as $r): ?>
                                    <tr data-name="<?= strtolower(htmlspecialchars($r['nom'] . ' ' . $r['ville'] . ' ' . ($r['proprietaire'] ?? ''))) ?>" data-plan="<?= htmlspecialchars($r['plan'] ?? 'PRO') ?>" data-statut="<?= htmlspecialchars($r['statut']) ?>">
                                        <td>
                                            <div class="tenant-cell">
                                                <div class="tenant-avatar"><?= strtoupper(substr($r['nom'], 0, 2)) ?></div>
                                                <div class="tenant-info">
                                                    <span class="tenant-name"><?= htmlspecialchars($r['nom']) ?></span>
                                                    <span class="tenant-slug">/<?= htmlspecialchars($r['slug']) ?> &bull; <?= htmlspecialchars($r['ville']) ?></span>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <div style="font-weight: 600; color: var(--text-heading);"><?= htmlspecialchars($r['proprietaire'] ?: 'Non renseigné') ?></div>
                                            <div style="font-size: 12px; color: var(--text-muted);"><?= htmlspecialchars($r['email'] ?: $r['telephone'] ?: '—') ?></div>
                                        </td>
                                        <td>
                                            <span class="plan-badge plan-<?= strtolower($r['plan'] ?? 'pro') ?>">
                                                <?= htmlspecialchars($r['plan'] ?? 'PRO') ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div style="font-weight: 600; color: var(--text-heading);"><?= $r['date_expiration'] ? date('d/m/Y', strtotime($r['date_expiration'])) : 'Illimité' ?></div>
                                            <?php if ($r['jours_restants'] !== null): ?>
                                                <div style="font-size: 11.5px; color: <?= $r['jours_restants'] < 30 ? 'var(--danger)' : 'var(--text-muted)' ?>;">
                                                    <?= $r['jours_restants'] > 0 ? "Dans {$r['jours_restants']} jours" : "Expiré" ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div style="font-weight: 700; color: var(--text-heading);"><?= number_format((float)$r['ca_commandes'], 2) ?> DH</div>
                                            <div style="font-size: 11.5px; color: var(--text-muted);"><?= (int)$r['total_commandes'] ?> commandes &bull; <?= (int)$r['total_produits'] ?> plats</div>
                                        </td>
                                        <td>
                                            <span class="status-pill status-<?= strtolower($r['statut']) ?>">
                                                <?= htmlspecialchars($r['statut']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="actions-cell">
                                                <a href="superadmin.php?switch_to=<?= $r['id_restaurant'] ?>&dest=pos" class="btn-table-action" target="_blank" title="Terminal Caisse POS">
                                                    POS
                                                </a>
                                                <a href="superadmin.php?switch_to=<?= $r['id_restaurant'] ?>&dest=kds" class="btn-table-action" target="_blank" title="Écran Cuisine KDS">
                                                    KDS
                                                </a>
                                                <a href="superadmin.php?switch_to=<?= $r['id_restaurant'] ?>&dest=menu" class="btn-table-action" target="_blank" title="Studio Recettes & Tarifs">
                                                    Studio
                                                </a>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="actions-cell">
                                                <button class="btn-table-action" onclick='openEditModal(<?= json_encode($r, JSON_HEX_APOS | JSON_HEX_TAG) ?>)' title="Modifier les coordonnées">
                                                    Éditer
                                                </button>

                                                <form method="POST" action="superadmin.php" style="display:inline;" onsubmit="return confirm('Changer le statut de cet établissement ?');">
                                                    <input type="hidden" name="action" value="toggle_statut">
                                                    <input type="hidden" name="id_restaurant" value="<?= $r['id_restaurant'] ?>">
                                                    <button type="submit" class="btn-table-action" title="<?= $r['statut'] === 'ACTIF' ? 'Suspendre' : 'Activer' ?>">
                                                        <?= $r['statut'] === 'ACTIF' ? 'Suspendre' : 'Activer' ?>
                                                    </button>
                                                </form>

                                                <?php if ((int)$r['id_restaurant'] > 1): ?>
                                                    <form method="POST" action="superadmin.php" style="display:inline;" onsubmit="return confirm('Attention : Cette action supprimera définitivement le restaurant et ses données. Continuer ?');">
                                                        <input type="hidden" name="action" value="supprimer_restaurant">
                                                        <input type="hidden" name="id_restaurant" value="<?= $r['id_restaurant'] ?>">
                                                        <button type="submit" class="btn-table-action btn-danger-action" title="Supprimer définitivement">
                                                            &times;
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>


            <!-- =========================================================
                 3. SUBSCRIPTIONS MANAGEMENT TAB
            ========================================================= -->
            <div class="tab-panel" id="tab-subscriptions">
                
                <div class="kpi-grid" style="grid-template-columns: repeat(4, 1fr); margin-bottom: 24px;">
                    <div class="kpi-card">
                        <div class="kpi-title">Total Abonnements</div>
                        <div class="kpi-value"><?= $totalRestaurants ?></div>
                        <div class="kpi-subtext">Souscriptions en base</div>
                    </div>
                    <div class="kpi-card">
                        <div class="kpi-title">Abonnements Actifs</div>
                        <div class="kpi-value" style="color: var(--success);"><?= $activeRestaurants ?></div>
                        <div class="kpi-subtext">Comptes à jour</div>
                    </div>
                    <div class="kpi-card">
                        <div class="kpi-title">Renouvellements < 30j</div>
                        <div class="kpi-value" style="color: var(--warning);">1</div>
                        <div class="kpi-subtext">À relancer prochainement</div>
                    </div>
                    <div class="kpi-card">
                        <div class="kpi-title">Taux de Rétention</div>
                        <div class="kpi-value" style="color: var(--primary);">100%</div>
                        <div class="kpi-subtext">Churn Rate: 0.0%</div>
                    </div>
                </div>

                <div class="dashboard-card">
                    <div class="card-header-flex">
                        <div class="card-title-group">
                            <h3>Suivi des Échéances d'Abonnement</h3>
                            <p>Surveillez la validité des forfaits et effectuez des renouvellements en 1 clic</p>
                        </div>
                    </div>

                    <div class="table-responsive">
                        <table class="enterprise-table">
                            <thead>
                                <tr>
                                    <th>Restaurant</th>
                                    <th>Plan Actuel</th>
                                    <th>Tarif</th>
                                    <th>Cycle</th>
                                    <th>Expiration</th>
                                    <th>Progression Restante</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($restaurants as $r): 
                                    $jours = max(0, (int)($r['jours_restants'] ?? 365));
                                    $percent = min(100, max(5, ($jours / 365) * 100));
                                    $tarif = match($r['plan'] ?? 'PRO') { 'BASIC' => '290 DH', 'PREMIUM' => '990 DH', default => '590 DH' };
                                ?>
                                    <tr>
                                        <td>
                                            <div style="font-weight: 700; color: var(--text-heading);"><?= htmlspecialchars($r['nom']) ?></div>
                                            <div style="font-size: 12px; color: var(--text-muted);"><?= htmlspecialchars($r['email'] ?: 'contact@restaurant.ma') ?></div>
                                        </td>
                                        <td>
                                            <span class="plan-badge plan-<?= strtolower($r['plan'] ?? 'pro') ?>"><?= htmlspecialchars($r['plan'] ?? 'PRO') ?></span>
                                        </td>
                                        <td style="font-weight: 600;"><?= $tarif ?> / mois</td>
                                        <td>Annuel</td>
                                        <td>
                                            <div style="font-weight: 600;"><?= $r['date_expiration'] ? date('d/m/Y', strtotime($r['date_expiration'])) : 'Illimité' ?></div>
                                            <div style="font-size: 11.5px; color: var(--text-muted);"><?= $jours ?> jours restants</div>
                                        </td>
                                        <td style="width: 200px;">
                                            <div style="background: #E2E8F0; border-radius: 999px; height: 8px; width: 100%; overflow: hidden;">
                                                <div style="background: var(--primary); height: 100%; width: <?= $percent ?>%;"></div>
                                            </div>
                                        </td>
                                        <td>
                                            <button class="btn-table-action" onclick='openSubscriptionModal(<?= json_encode($r, JSON_HEX_APOS | JSON_HEX_TAG) ?>)'>
                                                Prolonger / Ajuster
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>


            <!-- =========================================================
                 4. PLANS & PRICING TAB
            ========================================================= -->
            <div class="tab-panel" id="tab-plans">
                
                <div class="dashboard-card" style="margin-bottom: 24px;">
                    <div class="card-header-flex">
                        <div class="card-title-group">
                            <h3>Grille Tarifaire SaaS & Forfaits Commerciaux</h3>
                            <p>Définissez les quotas d'employés, produits et fonctionnalités pour chaque palier</p>
                        </div>
                    </div>

                    <div class="pricing-cards-grid">
                        <?php foreach ($plans as $p): ?>
                            <div class="pricing-card <?= $p['code'] === 'PRO' ? 'featured' : '' ?>">
                                <?php if ($p['badge']): ?>
                                    <div class="pricing-badge-popular"><?= htmlspecialchars($p['badge']) ?></div>
                                <?php endif; ?>

                                <div>
                                    <h4 style="font-size: 20px; font-weight: 800; color: var(--text-heading); margin-bottom: 6px;"><?= htmlspecialchars($p['nom']) ?></h4>
                                    <p style="font-size: 13px; color: var(--text-muted);"><?= htmlspecialchars($p['description']) ?></p>

                                    <div class="plan-price-box">
                                        <span class="plan-price-main"><?= number_format((float)$p['prix_mensuel'], 0) ?> DH</span>
                                        <span class="plan-price-period">/ mois</span>
                                        <div style="font-size: 12px; color: var(--text-muted); margin-top: 4px;">ou <?= number_format((float)$p['prix_annuel'], 0) ?> DH / an facturé annuellement</div>
                                    </div>

                                    <ul class="plan-feature-list">
                                        <li class="plan-feature-item">
                                            <svg class="check-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"></polyline></svg>
                                            <span>Jusqu'à <strong><?= (int)$p['max_employes'] ?> employés / caisses</strong></span>
                                        </li>
                                        <li class="plan-feature-item">
                                            <svg class="check-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"></polyline></svg>
                                            <span>Jusqu'à <strong><?= (int)$p['max_produits'] ?> produits</strong> au catalogue</span>
                                        </li>
                                        <li class="plan-feature-item">
                                            <svg class="check-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"></polyline></svg>
                                            <span>Terminal Caisse Tactile POS</span>
                                        </li>
                                        <?php if ($p['code'] !== 'BASIC'): ?>
                                            <li class="plan-feature-item">
                                                <svg class="check-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"></polyline></svg>
                                                <span>Écran Atelier Cuisine KDS en direct</span>
                                            </li>
                                            <li class="plan-feature-item">
                                                <svg class="check-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"></polyline></svg>
                                                <span>Affichage TV Public Appel Commandes</span>
                                            </li>
                                        <?php endif; ?>
                                        <?php if ($p['code'] === 'PREMIUM'): ?>
                                            <li class="plan-feature-item">
                                                <svg class="check-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"></polyline></svg>
                                                <span>Multi-établissements & Franchise</span>
                                            </li>
                                            <li class="plan-feature-item">
                                                <svg class="check-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"></polyline></svg>
                                                <span>Support Dédié 24/7 & Téléphonique</span>
                                            </li>
                                        <?php endif; ?>
                                    </ul>
                                </div>

                                <button class="btn-confirm" style="width: 100%;" onclick='openEditPlanModal(<?= json_encode($p, JSON_HEX_APOS | JSON_HEX_TAG) ?>)'>
                                    Configurer l'Offre
                                </button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

            </div>


            <!-- =========================================================
                 5. PAYMENTS MANAGEMENT TAB
            ========================================================= -->
            <div class="tab-panel" id="tab-payments">
                
                <div class="dashboard-card">
                    <div class="card-header-flex">
                        <div class="card-title-group">
                            <h3>Historique des Transactions & Facturation</h3>
                            <p>Journal de tous les encaissements d'abonnements effectués par les restaurants</p>
                        </div>
                        <button class="btn-primary-action" onclick="openModal('modalNewPayment')">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                            <span>Enregistrer un Règlement</span>
                        </button>
                    </div>

                    <div class="table-responsive">
                        <table class="enterprise-table">
                            <thead>
                                <tr>
                                    <th>Référence Transaction</th>
                                    <th>Restaurant</th>
                                    <th>Montant</th>
                                    <th>Mode de Paiement</th>
                                    <th>Forfait Concerné</th>
                                    <th>Date d'Encaissement</th>
                                    <th>Statut</th>
                                    <th>Reçu</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($paiements as $pay): ?>
                                    <tr>
                                        <td style="font-weight: 700; color: var(--text-heading); font-family: monospace;">
                                            <?= htmlspecialchars($pay['reference_tx']) ?>
                                        </td>
                                        <td>
                                            <div style="font-weight: 600;"><?= htmlspecialchars($pay['restaurant_nom']) ?></div>
                                            <div style="font-size: 11.5px; color: var(--text-muted);"><?= htmlspecialchars($pay['notes'] ?? '') ?></div>
                                        </td>
                                        <td style="font-weight: 800; font-size: 15px; color: var(--text-heading);">
                                            <?= number_format((float)$pay['montant'], 2) ?> DH
                                        </td>
                                        <td>
                                            <span style="font-weight: 600; font-size: 12.5px;"><?= htmlspecialchars($pay['methode']) ?></span>
                                        </td>
                                        <td>
                                            <span class="plan-badge plan-<?= strtolower($pay['plan_concerne']) ?>">
                                                <?= htmlspecialchars($pay['plan_concerne']) ?> (<?= (int)$pay['periode_mois'] ?> mois)
                                            </span>
                                        </td>
                                        <td>
                                            <?= date('d/m/Y H:i', strtotime($pay['date_paiement'])) ?>
                                        </td>
                                        <td>
                                            <span class="status-pill status-active">Validé</span>
                                        </td>
                                        <td>
                                            <button class="btn-table-action" onclick='openReceiptModal(<?= json_encode($pay, JSON_HEX_APOS | JSON_HEX_TAG) ?>)'>
                                                Imprimer Reçu
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>


            <!-- =========================================================
                 6. REVENUE & ANALYTICS TAB
            ========================================================= -->
            <div class="tab-panel" id="tab-analytics">
                
                <div class="kpi-grid" style="grid-template-columns: repeat(4, 1fr); margin-bottom: 24px;">
                    <div class="kpi-card">
                        <div class="kpi-title">ARR Prévisionnel</div>
                        <div class="kpi-value"><?= number_format($arr, 0, ',', ' ') ?> DH</div>
                        <div class="kpi-subtext">Annual Recurring Revenue</div>
                    </div>
                    <div class="kpi-card">
                        <div class="kpi-title">ARPU Moyen</div>
                        <div class="kpi-value"><?= $totalRestaurants > 0 ? number_format($mrr / $totalRestaurants, 0) : 0 ?> DH</div>
                        <div class="kpi-subtext">Revenu moyen par restaurant</div>
                    </div>
                    <div class="kpi-card">
                        <div class="kpi-title">Conversion Trial</div>
                        <div class="kpi-value" style="color: var(--success);">100%</div>
                        <div class="kpi-subtext">0 résiliation</div>
                    </div>
                    <div class="kpi-card">
                        <div class="kpi-title">Forfait le Plus Vendu</div>
                        <div class="kpi-value" style="color: var(--primary);">PRO</div>
                        <div class="kpi-subtext">590 DH / mois</div>
                    </div>
                </div>

                <div class="dashboard-card" style="margin-bottom: 24px;">
                    <div class="card-header-flex">
                        <div class="card-title-group">
                            <h3>Rapports & Indicateurs de Croissance</h3>
                            <p>Progression financière et déploiement de franchises au Maroc</p>
                        </div>
                    </div>
                    
                    <p style="color: var(--text-muted); font-size: 14px; line-height: 1.6; margin-bottom: 20px;">
                        Toutes les métriques de commandes des restaurants connectés sont synchronisées en direct.
                        Les encaissements mensuels assurent une rentabilité continue pour la plateforme <strong><?= htmlspecialchars($nomPlateforme) ?></strong>.
                    </p>

                    <h4 style="font-size: 14px; font-weight: 700; color: var(--text-heading); margin-bottom: 12px; text-transform: uppercase; letter-spacing: 0.05em;">Historique des Encaissements par Mois</h4>
                    <div class="table-responsive">
                        <table class="enterprise-table">
                            <thead>
                                <tr>
                                    <th>Période Mensuelle</th>
                                    <th>Transactions Encaissées</th>
                                    <th>Volume Encaissé (DH)</th>
                                    <th>Statut Comptable</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($monthlyRevenue)): ?>
                                    <tr>
                                        <td colspan="4" style="text-align: center; color: var(--text-muted); padding: 24px;">
                                            Aucun encaissement historique enregistré.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($monthlyRevenue as $mr): ?>
                                        <tr>
                                            <td style="font-weight: 700; color: var(--text-heading);">
                                                <?= htmlspecialchars($mr['mois_nom']) ?>
                                            </td>
                                            <td>
                                                <span class="status-pill status-active"><?= (int)$mr['nb_tx'] ?> règlement(s)</span>
                                            </td>
                                            <td style="font-weight: 800; font-size: 15px; color: var(--primary);">
                                                <?= number_format((float)$mr['total_mois'], 2) ?> DH
                                            </td>
                                            <td>
                                                <span class="growth-pill growth-pos">Clôturé & Validé</span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>


            <!-- =========================================================
                 7. SUPPORT TICKETS TAB
            ========================================================= -->
            <div class="tab-panel" id="tab-support">
                
                <div class="dashboard-card">
                    <div class="card-header-flex">
                        <div class="card-title-group">
                            <h3>Assistance & Demandes Gérants</h3>
                            <p>Tickets de support technique émis par les restaurants souscrits</p>
                        </div>
                    </div>

                    <div class="table-responsive">
                        <table class="enterprise-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Restaurant</th>
                                    <th>Sujet & Message</th>
                                    <th>Priorité</th>
                                    <th>Statut</th>
                                    <th>Date</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($tickets as $tick): ?>
                                    <tr>
                                        <td style="font-weight: 700;">#<?= $tick['id_ticket'] ?></td>
                                        <td style="font-weight: 600;"><?= htmlspecialchars($tick['restaurant_nom']) ?></td>
                                        <td>
                                            <div style="font-weight: 700; color: var(--text-heading);"><?= htmlspecialchars($tick['sujet']) ?></div>
                                            <div style="font-size: 12px; color: var(--text-muted); margin-top: 2px;"><?= htmlspecialchars($tick['message']) ?></div>
                                            <?php if (!empty($tick['reponse_admin'])): ?>
                                                <div style="font-size: 11.5px; color: var(--primary); margin-top: 4px; background: #EEF2FF; padding: 4px 8px; border-radius: 6px;">
                                                    <strong>Réponse SuperAdmin :</strong> <?= htmlspecialchars($tick['reponse_admin']) ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="growth-pill <?= $tick['priorite'] === 'URGENTE' || $tick['priorite'] === 'HAUTE' ? 'growth-neg' : 'growth-pos' ?>">
                                                <?= htmlspecialchars($tick['priorite']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="status-pill status-<?= $tick['statut'] === 'OUVERT' ? 'suspended' : 'active' ?>">
                                                <?= htmlspecialchars($tick['statut']) ?>
                                            </span>
                                        </td>
                                        <td><?= date('d/m/Y', strtotime($tick['date_creation'])) ?></td>
                                        <td>
                                            <button class="btn-table-action" onclick='openReplyTicketModal(<?= json_encode($tick, JSON_HEX_APOS | JSON_HEX_TAG) ?>)'>
                                                Répondre
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>


            <!-- =========================================================
                 8. ACTIVITY LOGS TAB
            ========================================================= -->
            <div class="tab-panel" id="tab-logs">
                
                <div class="dashboard-card">
                    <div class="card-header-flex">
                        <div class="card-title-group">
                            <h3>Journal d'Audit Système</h3>
                            <p>Historique immuable de toutes les opérations administratives</p>
                        </div>
                    </div>

                    <div class="table-responsive">
                        <table class="enterprise-table">
                            <thead>
                                <tr>
                                    <th>Horodatage</th>
                                    <th>Opérateur</th>
                                    <th>Établissement</th>
                                    <th>Action</th>
                                    <th>Description de l'Événement</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($logs as $log): ?>
                                    <tr>
                                        <td style="font-family: monospace; font-size: 12px; color: var(--text-muted);">
                                            <?= date('d/m/Y H:i:s', strtotime($log['date_creation'])) ?>
                                        </td>
                                        <td style="font-weight: 600;">
                                            <?= htmlspecialchars($log['utilisateur_nom'] ?? 'Super Admin') ?>
                                        </td>
                                        <td>
                                            <?= htmlspecialchars($log['restaurant_nom'] ?? 'Plateforme') ?>
                                        </td>
                                        <td>
                                            <span class="plan-badge plan-pro"><?= htmlspecialchars($log['type_action']) ?></span>
                                        </td>
                                        <td>
                                            <?= htmlspecialchars($log['description']) ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>


            <!-- =========================================================
                 9. GENERAL SETTINGS TAB
            ========================================================= -->
            <div class="tab-panel" id="tab-settings">
                
                <div class="dashboard-card" style="max-width: 680px;">
                    <div class="card-header-flex">
                        <div class="card-title-group">
                            <h3>Paramètres Généraux de la Plateforme</h3>
                            <p>Configurez les informations globales de votre SaaS</p>
                        </div>
                    </div>

                    <form method="POST" action="superadmin.php">
                        <input type="hidden" name="action" value="sauvegarder_parametres">

                        <div class="form-field">
                            <label for="nom_plateforme">Nom Commercial de la Plateforme SaaS</label>
                            <input type="text" id="nom_plateforme" name="nom_plateforme" value="<?= htmlspecialchars($nomPlateforme) ?>" required>
                        </div>

                        <div class="form-grid-2">
                            <div class="form-field">
                                <label for="devise">Devise Monétaire Principale</label>
                                <input type="text" id="devise" name="devise" value="<?= htmlspecialchars($deviseSymbol) ?>" required>
                            </div>
                            <div class="form-field">
                                <label for="duree_essai_jours">Durée Période d'Essai (Jours)</label>
                                <input type="number" id="duree_essai_jours" name="duree_essai_jours" value="<?= htmlspecialchars($configRaw['duree_essai_jours'] ?? '14') ?>" required>
                            </div>
                        </div>

                        <div class="form-grid-2">
                            <div class="form-field">
                                <label for="contact_email">Email du Support Plateforme</label>
                                <input type="email" id="contact_email" name="contact_email" value="<?= htmlspecialchars($configRaw['contact_email'] ?? 'contact@restoos.ma') ?>" required>
                            </div>
                            <div class="form-field">
                                <label for="contact_telephone">Numéro Téléphone Support</label>
                                <input type="text" id="contact_telephone" name="contact_telephone" value="<?= htmlspecialchars($configRaw['contact_telephone'] ?? '+212 6 00 00 00 00') ?>" required>
                            </div>
                        </div>

                        <button type="submit" class="btn-confirm" style="margin-top: 12px;">
                            Enregistrer les Modifications
                        </button>
                    </form>
                </div>

            </div>

        </main>
    </div>


    <!-- =============================================================
         MODALS (ONBOARDING, EDIT, PROLONGATION, ETC.)
    ============================================================= -->

    <!-- Modal 1 : Nouveau Restaurant (Onboarding) -->
    <div class="modal-overlay" id="modalNewRestaurant">
        <div class="modal-card">
            <div class="modal-header">
                <h3>Déployer un Nouvel Établissement (SaaS)</h3>
                <button class="btn-close-modal" onclick="closeModal('modalNewRestaurant')">&times;</button>
            </div>
            <form method="POST" action="superadmin.php">
                <input type="hidden" name="action" value="creer_restaurant">

                <div class="modal-body">
                    <h4 style="font-size: 13px; font-weight: 700; color: var(--primary); text-transform: uppercase; margin-bottom: 12px;">1. Informations de l'Établissement</h4>
                    
                    <div class="form-grid-2">
                        <div class="form-field">
                            <label>Nom du Restaurant *</label>
                            <input type="text" name="nom" id="newRestNom" placeholder="ex: Snack Le Gourmet" required oninput="autoSlug(this.value)">
                        </div>
                        <div class="form-field">
                            <label>Slug URL (Unique) *</label>
                            <input type="text" name="slug" id="newRestSlug" placeholder="ex: le-gourmet" required>
                        </div>
                    </div>

                    <div class="form-grid-2">
                        <div class="form-field">
                            <label>Nom du Propriétaire</label>
                            <input type="text" name="proprietaire" placeholder="ex: Rachid El Amrani">
                        </div>
                        <div class="form-field">
                            <label>Email de Contact</label>
                            <input type="email" name="email" placeholder="contact@restaurant.ma">
                        </div>
                    </div>

                    <div class="form-grid-2">
                        <div class="form-field">
                            <label>Ville</label>
                            <input type="text" name="ville" value="Meknès">
                        </div>
                        <div class="form-field">
                            <label>Téléphone</label>
                            <input type="text" name="telephone" placeholder="06 12 34 56 78">
                        </div>
                    </div>

                    <h4 style="font-size: 13px; font-weight: 700; color: var(--primary); text-transform: uppercase; margin: 18px 0 12px 0;">2. Formule & Souscription SaaS</h4>
                    
                    <div class="form-grid-2">
                        <div class="form-field">
                            <label>Forfait Choisi</label>
                            <select name="plan">
                                <option value="BASIC">Starter (Basic - 290 DH/m)</option>
                                <option value="PRO" selected>Pro Restaurant (590 DH/m)</option>
                                <option value="PREMIUM">Enterprise Franchise (990 DH/m)</option>
                            </select>
                        </div>
                        <div class="form-field">
                            <label>Durée d'Engagement</label>
                            <select name="duree_mois">
                                <option value="1">1 Mois</option>
                                <option value="3">3 Mois</option>
                                <option value="6">6 Mois</option>
                                <option value="12" selected>12 Mois (Annuel)</option>
                            </select>
                        </div>
                    </div>

                    <h4 style="font-size: 13px; font-weight: 700; color: var(--primary); text-transform: uppercase; margin: 18px 0 12px 0;">3. Compte Administrateur / Gérant</h4>

                    <div class="form-grid-2">
                        <div class="form-field">
                            <label>Identifiant de Connexion *</label>
                            <input type="text" name="admin_login" id="newAdminLogin" placeholder="ex: admin_gourmet" required>
                        </div>
                        <div class="form-field">
                            <label>Mot de Passe *</label>
                            <input type="password" name="admin_pass" value="admin123" required>
                        </div>
                    </div>

                    <div style="background: #F8FAFC; padding: 12px; border-radius: 8px; border: 1px solid var(--border-subtle); margin-top: 12px;">
                        <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; font-size: 13px; font-weight: 600;">
                            <input type="checkbox" name="clone_menu" value="1" checked style="accent-color: var(--primary);">
                            <span>Cloner le catalogue standard (21 produits + 11 suppléments)</span>
                        </label>
                        <p style="font-size: 12px; color: var(--text-muted); margin: 4px 0 0 24px;">Permet au gérant de tester immédiatement sa caisse sans avoir à saisir son menu à la main.</p>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn-cancel" onclick="closeModal('modalNewRestaurant')">Annuler</button>
                    <button type="submit" class="btn-confirm">Déployer l'Établissement</button>
                </div>
            </form>
        </div>
    </div>


    <!-- Modal 2 : Modifier Restaurant -->
    <div class="modal-overlay" id="modalEditRestaurant">
        <div class="modal-card">
            <div class="modal-header">
                <h3>Modifier les Coordonnées de l'Établissement</h3>
                <button class="btn-close-modal" onclick="closeModal('modalEditRestaurant')">&times;</button>
            </div>
            <form method="POST" action="superadmin.php">
                <input type="hidden" name="action" value="modifier_restaurant">
                <input type="hidden" name="id_restaurant" id="editRestId">

                <div class="modal-body">
                    <div class="form-grid-2">
                        <div class="form-field">
                            <label>Nom du Restaurant *</label>
                            <input type="text" name="nom" id="editRestNom" required>
                        </div>
                        <div class="form-field">
                            <label>Propriétaire</label>
                            <input type="text" name="proprietaire" id="editRestProprio">
                        </div>
                    </div>

                    <div class="form-grid-2">
                        <div class="form-field">
                            <label>Email</label>
                            <input type="email" name="email" id="editRestEmail">
                        </div>
                        <div class="form-field">
                            <label>Téléphone</label>
                            <input type="text" name="telephone" id="editRestTel">
                        </div>
                    </div>

                    <div class="form-grid-2">
                        <div class="form-field">
                            <label>Ville</label>
                            <input type="text" name="ville" id="editRestVille">
                        </div>
                        <div class="form-field">
                            <label>Statut</label>
                            <select name="statut" id="editRestStatut">
                                <option value="ACTIF">ACTIF</option>
                                <option value="ESSAI">ESSAI</option>
                                <option value="SUSPENDU">SUSPENDU</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-field">
                        <label>Forfait SaaS</label>
                        <select name="plan" id="editRestPlan">
                            <option value="BASIC">BASIC</option>
                            <option value="PRO">PRO</option>
                            <option value="PREMIUM">PREMIUM</option>
                        </select>
                    </div>

                    <div class="form-field">
                        <label>Adresse physique</label>
                        <textarea name="adresse" id="editRestAdr" rows="2"></textarea>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn-cancel" onclick="closeModal('modalEditRestaurant')">Annuler</button>
                    <button type="submit" class="btn-confirm">Enregistrer</button>
                </div>
            </form>
        </div>
    </div>


    <!-- Modal 3 : Modifier / Prolonger Abonnement -->
    <div class="modal-overlay" id="modalSubscription">
        <div class="modal-card">
            <div class="modal-header">
                <h3>Ajuster l'Abonnement SaaS</h3>
                <button class="btn-close-modal" onclick="closeModal('modalSubscription')">&times;</button>
            </div>
            <form method="POST" action="superadmin.php">
                <input type="hidden" name="action" value="modifier_abonnement">
                <input type="hidden" name="id_restaurant" id="subRestId">

                <div class="modal-body">
                    <div style="margin-bottom: 16px;">
                        <span style="font-size: 13px; color: var(--text-muted);">Établissement concerné :</span>
                        <div id="subRestName" style="font-size: 16px; font-weight: 700; color: var(--text-heading);"></div>
                    </div>

                    <div class="form-field">
                        <label>Palier d'Abonnement</label>
                        <select name="plan" id="subRestPlan">
                            <option value="BASIC">BASIC (Starter - 290 DH/m)</option>
                            <option value="PRO">PRO (Pro Restaurant - 590 DH/m)</option>
                            <option value="PREMIUM">PREMIUM (Enterprise Franchise - 990 DH/m)</option>
                        </select>
                    </div>

                    <div class="form-field">
                        <label>Prolonger la Date d'Expiration</label>
                        <select name="prolonger_mois">
                            <option value="0">Conserver l'échéance actuelle</option>
                            <option value="1">+ 1 Mois</option>
                            <option value="3">+ 3 Mois</option>
                            <option value="6">+ 6 Mois</option>
                            <option value="12">+ 12 Mois (1 An)</option>
                        </select>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn-cancel" onclick="closeModal('modalSubscription')">Annuler</button>
                    <button type="submit" class="btn-confirm">Valider l'Abonnement</button>
                </div>
            </form>
        </div>
    </div>


    <!-- Modal 4 : Enregistrer un Paiement Manuel -->
    <div class="modal-overlay" id="modalNewPayment">
        <div class="modal-card">
            <div class="modal-header">
                <h3>Enregistrer un Paiement d'Abonnement</h3>
                <button class="btn-close-modal" onclick="closeModal('modalNewPayment')">&times;</button>
            </div>
            <form method="POST" action="superadmin.php">
                <input type="hidden" name="action" value="enregistrer_paiement">

                <div class="modal-body">
                    <div class="form-field">
                        <label>Restaurant Bénéficiaire *</label>
                        <select name="id_restaurant" required>
                            <?php foreach ($restaurants as $r): ?>
                                <option value="<?= $r['id_restaurant'] ?>"><?= htmlspecialchars($r['nom']) ?> (<?= htmlspecialchars($r['ville']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-grid-2">
                        <div class="form-field">
                            <label>Montant Encaissé (DH) *</label>
                            <input type="number" step="0.01" name="montant" value="590.00" required>
                        </div>
                        <div class="form-field">
                            <label>Méthode de Règlement</label>
                            <select name="methode">
                                <option value="VIREMENT">Virement Bancaire (CIH / Attijari)</option>
                                <option value="ESPECES">Espèces (Agent)</option>
                                <option value="CARTE">Carte Bancaire CMI</option>
                                <option value="CHEQUE">Chèque</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-grid-2">
                        <div class="form-field">
                            <label>Forfait Renouvelé</label>
                            <select name="plan">
                                <option value="PRO">PRO</option>
                                <option value="BASIC">BASIC</option>
                                <option value="PREMIUM">PREMIUM</option>
                            </select>
                        </div>
                        <div class="form-field">
                            <label>Période Couverte</label>
                            <select name="periode_mois">
                                <option value="1">1 Mois</option>
                                <option value="3">3 Mois</option>
                                <option value="6">6 Mois</option>
                                <option value="12" selected>12 Mois (Annuel)</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-field">
                        <label>Notes / Référence Interne</label>
                        <input type="text" name="notes" placeholder="ex: Virement reçu Attijariwafa ref #8839">
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn-cancel" onclick="closeModal('modalNewPayment')">Annuler</button>
                    <button type="submit" class="btn-confirm">Encaisser & Prolonger</button>
                </div>
            </form>
        </div>
    </div>


    <!-- Modal 5 : Répondre à un Ticket -->
    <div class="modal-overlay" id="modalReplyTicket">
        <div class="modal-card" style="max-width: 580px;">
            <div class="modal-header">
                <div>
                    <h3 style="margin: 0; font-size: 16px;">Traitement Ticket de Support</h3>
                    <p style="margin: 2px 0 0; font-size: 12px; color: var(--text-muted);">Répondre directement au gérant du restaurant abonné</p>
                </div>
                <button class="btn-close-modal" onclick="closeModal('modalReplyTicket')">&times;</button>
            </div>
            <form method="POST" action="superadmin.php">
                <input type="hidden" name="action" value="repondre_ticket">
                <input type="hidden" name="id_ticket" id="replyTicketId">

                <div class="modal-body">
                    <!-- Émetteur du Ticket -->
                    <div style="background: #F1F5F9; padding: 12px 14px; border-radius: 8px; border: 1px solid var(--border-subtle); margin-bottom: 14px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px;">
                        <div>
                            <div style="font-size: 11px; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.04em;">Établissement Émetteur</div>
                            <div id="replyTicketRestaurant" style="font-size: 14px; font-weight: 800; color: var(--text-heading);"></div>
                            <div id="replyTicketContact" style="font-size: 12px; color: var(--text-body); margin-top: 2px;"></div>
                        </div>
                        <div id="replyTicketWaContainer"></div>
                    </div>

                    <!-- Message d'origine -->
                    <div style="background: #FFFFFF; padding: 14px; border-radius: 8px; border: 1px solid var(--border-subtle); margin-bottom: 16px;">
                        <div style="font-size: 11px; font-weight: 700; color: #4F46E5; text-transform: uppercase; letter-spacing: 0.04em; margin-bottom: 4px;">Demande du Restaurant :</div>
                        <div id="replyTicketSubject" style="font-weight: 700; font-size: 13.5px; margin-bottom: 6px; color: var(--text-heading);"></div>
                        <div id="replyTicketBody" style="font-size: 13px; color: var(--text-body); line-height: 1.5; white-space: pre-wrap;"></div>
                    </div>

                    <div class="form-field">
                        <label>Votre Réponse Officielle au Gérant *</label>
                        <textarea name="reponse" id="replyTicketText" rows="4" placeholder="Indiquez la solution technique, les instructions ou confirmez l'action menée..." required style="width: 100%; box-sizing: border-box;"></textarea>
                        <span style="font-size: 11.5px; color: var(--text-muted); margin-top: 4px;">Cette réponse sera immédiatement visible par le gérant dans son Studio Menu.</span>
                    </div>

                    <div class="form-field">
                        <label>Statut du Ticket après réponse</label>
                        <select name="statut" style="width: 100%; box-sizing: border-box;">
                            <option value="RESOLU">Résolu (Clôturer le ticket)</option>
                            <option value="EN_COURS">En cours d'investigation</option>
                        </select>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn-cancel" onclick="closeModal('modalReplyTicket')">Annuler</button>
                    <button type="submit" class="btn-confirm">Transmettre la Réponse au Restaurant</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal 6 : Configurer un Forfait (Offres & Tarifs) -->
    <div class="modal-overlay" id="modalEditPlan">
        <div class="modal-card" style="max-width: 580px;">
            <div class="modal-header">
                <div style="display: flex; align-items: center; gap: 12px;">
                    <div style="width: 38px; height: 38px; border-radius: 10px; background: #EEF2FF; border: 1px solid #C7D2FE; display: flex; align-items: center; justify-content: center; color: #4F46E5; flex-shrink: 0;">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="4" width="20" height="16" rx="2"></rect><line x1="2" y1="10" x2="22" y2="10"></line></svg>
                    </div>
                    <div>
                        <h3 style="margin: 0; font-size: 16.5px; font-weight: 700; color: #0F172A;">Configurer le Forfait Commercial</h3>
                        <p style="margin: 2px 0 0 0; font-size: 12px; color: #64748B;">Ajustez la tarification, les quotas et les limites de cette offre SaaS</p>
                    </div>
                </div>
                <button type="button" class="btn-close-modal" onclick="closeModal('modalEditPlan')">&times;</button>
            </div>
            <form method="POST" action="superadmin.php">
                <input type="hidden" name="action" value="sauvegarder_plan">
                <input type="hidden" name="plan_code" id="editPlanCode">
                <div class="modal-body" style="padding: 22px 24px;">
                    
                    <div class="form-grid-2">
                        <div class="form-field">
                            <label>Nom Commercial du Forfait</label>
                            <input type="text" id="editPlanNom" name="plan_nom" placeholder="ex: Pro Restaurant" required>
                        </div>
                        <div class="form-field">
                            <label>Badge Promotionnel (Optionnel)</label>
                            <input type="text" id="editPlanBadge" name="plan_badge" placeholder="ex: LE PLUS POPULAIRE">
                        </div>
                    </div>

                    <div class="form-grid-2">
                        <div class="form-field">
                            <label>Tarif Mensuel</label>
                            <div class="input-currency-wrapper">
                                <input type="number" id="editPlanPrixMensuel" name="prix_mensuel" min="0" step="10" required>
                                <span class="input-currency-tag">DH / mois</span>
                            </div>
                        </div>
                        <div class="form-field">
                            <label>Tarif Annuel</label>
                            <div class="input-currency-wrapper">
                                <input type="number" id="editPlanPrixAnnuel" name="prix_annuel" min="0" step="50" required>
                                <span class="input-currency-tag">DH / an</span>
                            </div>
                        </div>
                    </div>

                    <div class="form-grid-2">
                        <div class="form-field">
                            <label>Quota Max Employés / Caisses</label>
                            <input type="number" id="editPlanMaxEmp" name="max_employes" min="1" required>
                        </div>
                        <div class="form-field">
                            <label>Quota Max Produits au Catalogue</label>
                            <input type="number" id="editPlanMaxProd" name="max_produits" min="1" required>
                        </div>
                    </div>

                    <div class="form-field" style="margin-bottom: 8px;">
                        <label>Description & Avantages Commerciaux</label>
                        <textarea id="editPlanDesc" name="plan_description" rows="2" placeholder="Description affichée sur la grille tarifaire..."></textarea>
                    </div>

                    <div style="background: #F8FAFC; border: 1px solid #E2E8F0; border-radius: 8px; padding: 10px 12px; display: flex; align-items: flex-start; gap: 8px; margin-top: 12px;">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#6366F1" stroke-width="2.5" style="flex-shrink: 0; margin-top: 1px;"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16" x2="12" y2="12"></line><line x1="12" y1="8" x2="12.01" y2="8"></line></svg>
                        <span style="font-size: 11.5px; color: #475569; line-height: 1.45;">
                            Les tarifs et quotas modifiés sont immédiatement pris en compte pour tous les nouveaux contrats et le calcul automatique du MRR.
                        </span>
                    </div>

                </div>
                <div class="modal-footer" style="padding: 14px 24px;">
                    <button type="button" class="btn-cancel" onclick="closeModal('modalEditPlan')">Annuler</button>
                    <button type="submit" class="btn-confirm" style="display: inline-flex; align-items: center; gap: 6px;">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"></polyline></svg>
                        Enregistrer les Modifications
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal 7 : Reçu de Paiement Officiel (Imprimable / Exportable) -->
    <div class="modal-overlay" id="modalPrintReceipt">
        <div class="modal-card" style="max-width: 540px;">
            <div class="modal-header">
                <h3>Reçu d'Encaissement Officiel</h3>
                <button class="btn-close-modal" onclick="closeModal('modalPrintReceipt')">&times;</button>
            </div>
            <div class="modal-body" style="padding: 24px;">
                <div id="printableReceiptArea" style="background: #ffffff; border: 1px solid #E2E8F0; border-radius: 12px; padding: 24px; color: #0F172A; font-family: 'Inter', sans-serif;">
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2px solid #0F172A; padding-bottom: 16px; margin-bottom: 16px;">
                        <div>
                            <div style="font-size: 18px; font-weight: 800; letter-spacing: -0.02em; color: #0F172A;">RestoOS Cloud</div>
                            <div style="font-size: 11px; text-transform: uppercase; font-weight: 600; color: #6366F1; letter-spacing: 0.05em;">Restaurant Operating System</div>
                            <div style="font-size: 11px; color: #64748B; margin-top: 4px;">Plateforme SaaS Multi-Tenants</div>
                        </div>
                        <div style="text-align: right;">
                            <div style="display: inline-block; background: #ECFDF5; border: 1px solid #A7F3D0; color: #065F46; font-size: 11px; font-weight: 700; padding: 3px 8px; border-radius: 6px; text-transform: uppercase;">
                                ✓ ACQUITTÉ
                            </div>
                            <div id="receiptTxRef" style="font-family: monospace; font-weight: 700; font-size: 13px; color: #0F172A; margin-top: 6px;"></div>
                            <div id="receiptDate" style="font-size: 11.5px; color: #64748B;"></div>
                        </div>
                    </div>

                    <div style="margin-bottom: 18px; background: #F8FAFC; border: 1px solid #E2E8F0; border-radius: 8px; padding: 12px;">
                        <div style="font-size: 10.5px; font-weight: 700; text-transform: uppercase; color: #64748B; letter-spacing: 0.05em; margin-bottom: 4px;">Établissement Bénéficiaire</div>
                        <div id="receiptRestNom" style="font-size: 15px; font-weight: 800; color: #0F172A;"></div>
                        <div id="receiptRestInfo" style="font-size: 12px; color: #64748B; margin-top: 2px;"></div>
                    </div>

                    <table style="width: 100%; border-collapse: collapse; margin-bottom: 20px; font-size: 13px;">
                        <thead>
                            <tr style="border-bottom: 1px solid #CBD5E1; color: #64748B; font-size: 11px; text-transform: uppercase;">
                                <th style="text-align: left; padding: 8px 0;">Description</th>
                                <th style="text-align: center; padding: 8px 0;">Durée</th>
                                <th style="text-align: right; padding: 8px 0;">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr style="border-bottom: 1px solid #F1F5F9;">
                                <td style="padding: 12px 0;">
                                    <div id="receiptPlanNom" style="font-weight: 700; color: #0F172A;"></div>
                                    <div id="receiptModePay" style="font-size: 11px; color: #64748B;"></div>
                                </td>
                                <td id="receiptPeriode" style="padding: 12px 0; text-align: center; font-weight: 600;"></td>
                                <td id="receiptMontant" style="padding: 12px 0; text-align: right; font-weight: 800; font-size: 15px; color: #0F172A;"></td>
                            </tr>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="2" style="padding-top: 14px; font-weight: 800; font-size: 14px; text-transform: uppercase;">Montant Net Encaissé :</td>
                                <td id="receiptTotalFoot" style="padding-top: 14px; text-align: right; font-weight: 900; font-size: 18px; color: #6366F1;"></td>
                            </tr>
                        </tfoot>
                    </table>

                    <div style="border-top: 1px dashed #CBD5E1; padding-top: 12px; display: flex; justify-content: space-between; align-items: center; font-size: 11px; color: #94A3B8;">
                        <div>Authentification : Registre SaaS RestoOS</div>
                        <div>Cachet Électronique Sécurisé</div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-cancel" onclick="closeModal('modalPrintReceipt')">Fermer</button>
                <button type="button" class="btn-confirm" onclick="printReceiptDirectly()" style="display: inline-flex; align-items: center; gap: 8px;">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"></polyline><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path><rect x="6" y="14" width="12" height="8"></rect></svg>
                    Imprimer / Télécharger Reçu
                </button>
            </div>
        </div>
    </div>

    <!-- -------------------------------------------------------------
         SCRIPTS CLIENTS (INTERACTIVITÉ STRIPE / LINEAR)
    ------------------------------------------------------------- -->
    <script>
        // Tab Switching
        function switchTab(tabId, btnElement) {
            document.querySelectorAll('.tab-panel').forEach(panel => panel.classList.remove('active'));
            const targetPanel = document.getElementById('tab-' + tabId);
            if (targetPanel) {
                targetPanel.classList.add('active');
            }

            if (btnElement) {
                document.querySelectorAll('.nav-item-btn').forEach(btn => btn.classList.remove('active'));
                btnElement.classList.add('active');
            }

            // Update Header Title
            const titles = {
                'overview': "Vue d'ensemble",
                'restaurants': "Gestion des Restaurants Tenants",
                'subscriptions': "Suivi des Abonnements SaaS",
                'plans': "Offres & Forfaits Commerciaux",
                'payments': "Paiements & Encaissements",
                'analytics': "Revenus & Télémétrie",
                'support': "Tickets Support Gérants",
                'logs': "Journal d'Audit Système",
                'settings': "Paramètres de la Plateforme"
            };
            document.getElementById('pageHeaderTitle').textContent = titles[tabId] || "Tableau de Bord";
        }

        // Sidebar Collapse Toggle
        const btnToggleSidebar = document.getElementById('btnToggleSidebar');
        const appSidebar = document.getElementById('appSidebar');
        const mainWrapper = document.getElementById('mainWrapper');

        btnToggleSidebar.addEventListener('click', () => {
            appSidebar.classList.toggle('collapsed');
            mainWrapper.classList.toggle('expanded');
        });

        // Modals Management
        function openModal(id) {
            const modal = document.getElementById(id);
            if (modal) modal.classList.add('active');
        }

        function closeModal(id) {
            const modal = document.getElementById(id);
            if (modal) modal.classList.remove('active');
        }

        // Auto-slug generator for new restaurants
        function autoSlug(name) {
            const slug = name.toLowerCase()
                .replace(/[^a-z0-9]+/g, '-')
                .replace(/^-+|-+$/g, '');
            document.getElementById('newRestSlug').value = slug;
            document.getElementById('newAdminLogin').value = 'admin_' + slug.replace(/-/g, '_');
        }

        // Populate Edit Modal
        function openEditModal(rest) {
            document.getElementById('editRestId').value = rest.id_restaurant;
            document.getElementById('editRestNom').value = rest.nom;
            document.getElementById('editRestProprio').value = rest.proprietaire || '';
            document.getElementById('editRestEmail').value = rest.email || '';
            document.getElementById('editRestTel').value = rest.telephone || '';
            document.getElementById('editRestVille').value = rest.ville || 'Meknès';
            document.getElementById('editRestAdr').value = rest.adresse || '';
            document.getElementById('editRestPlan').value = rest.plan || 'PRO';
            document.getElementById('editRestStatut').value = rest.statut || 'ACTIF';
            openModal('modalEditRestaurant');
        }

        // Populate Subscription Modal
        function openSubscriptionModal(rest) {
            document.getElementById('subRestId').value = rest.id_restaurant;
            document.getElementById('subRestName').textContent = rest.nom + ' (Actuellement ' + (rest.plan || 'PRO') + ')';
            document.getElementById('subRestPlan').value = rest.plan || 'PRO';
            openModal('modalSubscription');
        }

        // Populate Reply Ticket Modal
        function openReplyTicketModal(ticket) {
            document.getElementById('replyTicketId').value = ticket.id_ticket;
            document.getElementById('replyTicketSubject').textContent = '#' + ticket.id_ticket + ' — ' + (ticket.sujet || '');
            document.getElementById('replyTicketBody').textContent = ticket.message || '';
            document.getElementById('replyTicketText').value = ticket.reponse_admin || '';

            // Restaurant & Contact Info
            const restoNom = ticket.restaurant_nom || ('Restaurant #' + (ticket.id_restaurant || ''));
            const ville = ticket.ville ? ' (' + ticket.ville + ')' : '';
            document.getElementById('replyTicketRestaurant').textContent = restoNom + ville;

            const contact = ticket.contact_gerant || 'Gérant';
            const phone = ticket.telephone || '';
            const email = ticket.email ? ' · ' + ticket.email : '';
            document.getElementById('replyTicketContact').textContent = 'Contact : ' + contact + (phone ? ' · ' + phone : '') + email;

            // Direct WhatsApp button if phone exists
            const waBox = document.getElementById('replyTicketWaContainer');
            if (phone) {
                const cleanPhone = phone.replace(/[^0-9]/g, '');
                const waPhone = cleanPhone.startsWith('0') ? '212' + cleanPhone.substring(1) : cleanPhone;
                waBox.innerHTML = `
                    <a href="https://wa.me/${waPhone}?text=${encodeURIComponent('Bonjour, suite à votre ticket #' + ticket.id_ticket + ' sur RestoOS :')}" target="_blank" style="display:inline-flex; align-items:center; gap:6px; background:#25D366; color:#FFF; padding:6px 12px; border-radius:6px; text-decoration:none; font-size:12px; font-weight:700;">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><path d="M12.031 6.172c-3.181 0-5.767 2.586-5.768 5.766-.001 1.298.38 2.27 1.019 3.287l-.582 2.128 2.182-.573c.978.58 1.911.928 3.145.929 3.178 0 5.767-2.587 5.768-5.766.001-3.187-2.575-5.77-5.764-5.771zm3.392 8.244c-.144.405-.837.774-1.17.824-.311.045-.698.059-1.146-.086-.3-.097-.689-.253-1.196-.472-2.115-.913-3.498-3.045-3.604-3.186-.106-.142-.861-1.146-.861-2.185 0-1.039.544-1.55.736-1.764.192-.213.418-.267.558-.267.14 0 .28 0 .402.007.129.007.302-.049.472.361.174.421.597 1.457.649 1.564.053.107.088.232.018.374-.07.141-.106.232-.212.355-.106.124-.223.277-.319.372-.106.106-.217.221-.093.434.124.213.551.91 1.182 1.472.813.725 1.498.95 1.711 1.056.213.106.338.089.463-.053.125-.142.535-.623.678-.836.142-.213.285-.178.479-.107.195.071 1.233.582 1.444.688.212.106.353.16.405.249.053.089.053.516-.091.921z"/></svg>
                        WhatsApp
                    </a>
                `;
            } else {
                waBox.innerHTML = '';
            }

            openModal('modalReplyTicket');
        }

        // Populate Plan Configuration Modal
        function openEditPlanModal(plan) {
            document.getElementById('editPlanCode').value = plan.code || '';
            document.getElementById('editPlanNom').value = plan.nom || plan.code || '';
            document.getElementById('editPlanBadge').value = plan.badge || '';
            document.getElementById('editPlanPrixMensuel').value = plan.prix_mensuel || 0;
            document.getElementById('editPlanPrixAnnuel').value = plan.prix_annuel || 0;
            document.getElementById('editPlanMaxEmp').value = plan.max_employes || 5;
            document.getElementById('editPlanMaxProd').value = plan.max_produits || 50;
            document.getElementById('editPlanDesc').value = plan.description || '';
            openModal('modalEditPlan');
        }

        // Populate and open Official Receipt Modal
        function openReceiptModal(pay) {
            document.getElementById('receiptTxRef').textContent = pay.reference_tx || 'TX-OFFICIEL';
            document.getElementById('receiptDate').textContent = pay.date_paiement ? pay.date_paiement.substring(0, 16) : 'Aujourd\'hui';
            document.getElementById('receiptRestNom').textContent = pay.restaurant_nom || 'Établissement';
            document.getElementById('receiptRestInfo').textContent = pay.notes || 'Règlement souscription SaaS RestoOS';
            document.getElementById('receiptPlanNom').textContent = 'Abonnement RestoOS ' + (pay.plan_concerne || 'PRO');
            document.getElementById('receiptModePay').textContent = 'Mode de règlement : ' + (pay.methode || 'VIREMENT');
            document.getElementById('receiptPeriode').textContent = (pay.periode_mois || 1) + ' mois';
            const montantFormat = parseFloat(pay.montant || 0).toFixed(2) + ' DH';
            document.getElementById('receiptMontant').textContent = montantFormat;
            document.getElementById('receiptTotalFoot').textContent = montantFormat;
            openModal('modalPrintReceipt');
        }

        function printReceiptDirectly() {
            window.print();
        }

        // Global Table Filtering
        function filterRestaurantsTable() {
            const text = document.getElementById('filterRestInput').value.toLowerCase();
            const plan = document.getElementById('filterPlanSelect').value;
            const statut = document.getElementById('filterStatutSelect').value;

            const rows = document.querySelectorAll('#tableRestaurants tbody tr');
            rows.forEach(row => {
                const rowName = row.getAttribute('data-name');
                const rowPlan = row.getAttribute('data-plan');
                const rowStatut = row.getAttribute('data-statut');

                const matchesText = !text || rowName.includes(text);
                const matchesPlan = !plan || rowPlan === plan;
                const matchesStatut = !statut || rowStatut === statut;

                row.style.display = (matchesText && matchesPlan && matchesStatut) ? '' : 'none';
            });
        }

        // Header Instant Search
        function filterGlobalContent(val) {
            val = val.toLowerCase();
            if (val.length > 1) {
                // Switch to restaurants tab to inspect matches
                switchTab('restaurants');
                document.getElementById('filterRestInput').value = val;
                filterRestaurantsTable();
            }
        }

        // Interactive Chart Period Switcher
        function setChartPeriod(period, btn) {
            document.querySelectorAll('.btn-period').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');

            const paths = {
                'jour':    { line: 'M 0,160 Q 150,140 280,120 T 450,110 T 650,80',  area: 'M 0,180 L 0,160 Q 150,140 280,120 T 450,110 T 650,80 L 650,180 Z' },
                'semaine': { line: 'M 0,150 Q 120,130 250,90 T 480,70 T 650,45',   area: 'M 0,180 L 0,150 Q 120,130 250,90 T 480,70 T 650,45 L 650,180 Z' },
                'mois':    { line: 'M 0,140 Q 110,120 180,95 T 350,85 T 480,50 T 650,25', area: 'M 0,180 L 0,140 Q 110,120 180,95 T 350,85 T 480,50 T 650,25 L 650,180 Z' },
                'annee':   { line: 'M 0,170 Q 200,150 350,100 T 500,60 T 650,15',  area: 'M 0,180 L 0,170 Q 200,150 350,100 T 500,60 T 650,15 L 650,180 Z' }
            };

            const selected = paths[period] || paths['mois'];
            document.getElementById('chartLine').setAttribute('d', selected.line);
            document.getElementById('chartArea').setAttribute('d', selected.area);
        }
    </script>
</body>
</html>
