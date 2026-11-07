<?php
/**
 * M39ODA SaaS - Installateur Automatique Multi-Tenant (InfinityFree / CPanel)
 * Initialise l'architecture Multi-Restaurants, les comptes et le catalogue complet
 */

declare(strict_types=1);

// Security Guard: Prevent accidental or malicious database wipe
if (file_exists(__DIR__ . '/install.lock')) {
    http_response_code(403);
    die('<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8"><title>Installation Verrouillée - RestoOS</title><style>body{font-family:sans-serif;background:#0f172a;color:#f8fafc;display:flex;align-items:center;justify-content:center;height:100vh;margin:0;}.card{background:#1e293b;border:1px solid #334155;padding:32px;border-radius:12px;max-width:500px;text-align:center;}h1{color:#ef4444;font-size:22px;}p{color:#94a3b8;font-size:14px;line-height:1.5;}a{color:#818cf8;text-decoration:none;font-weight:bold;}</style></head><body><div class="card"><h1>🔒 Installation Verrouillée</h1><p>La base de données RestoOS est déjà configurée et protégée contre toute réinitialisation malveillante.<br><br>Pour réinstaller, supprimez manuellement le fichier <code>install.lock</code> sur le serveur.</p><p><a href="login.php">&larr; Retour à la connexion</a></p></div></body></html>');
}

error_reporting(E_ALL);
ini_set('display_errors', '1');

$messages = [];
$success = true;

/** @var PDO $pdo */
try {
    $pdo = require_once __DIR__ . '/config/db.php';
    $messages[] = ['type' => 'success', 'text' => "Connexion à la base de données réussie (" . htmlspecialchars((string)$pdo->query("SELECT DATABASE()")->fetchColumn()) . ")"];
} catch (Throwable $e) {
    die("<h1>Erreur de connexion à la base de données</h1><p>" . htmlspecialchars($e->getMessage()) . "</p>");
}

if (isset($_GET['msg']) && $_GET['msg'] === 'schema_needed') {
    $messages[] = ['type' => 'error', 'text' => "Base de données non initialisée : les tables SaaS sont absentes. Lancez l'installation ci-dessous pour créer les tables."];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' || isset($_GET['run']) || isset($_GET['auto'])) {
    $sqlCommands = [
        // 0. Réinitialisation propre des anciennes tables non-SaaS
        "SET FOREIGN_KEY_CHECKS = 0",
        "DROP TABLE IF EXISTS details_ingredients",
        "DROP TABLE IF EXISTS details_commande",
        "DROP TABLE IF EXISTS historique_statuts",
        "DROP TABLE IF EXISTS commandes",
        "DROP TABLE IF EXISTS ingredients",
        "DROP TABLE IF EXISTS sandwichs",
        "DROP TABLE IF EXISTS tickets_support",
        "DROP TABLE IF EXISTS logs_activite",
        "DROP TABLE IF EXISTS paiements",
        "DROP TABLE IF EXISTS plans",
        "DROP TABLE IF EXISTS plateforme_config",
        "DROP TABLE IF EXISTS utilisateurs",
        "DROP TABLE IF EXISTS restaurants",
        "SET FOREIGN_KEY_CHECKS = 1",

        // 1. Table Restaurants (Tenants SaaS)
        "CREATE TABLE IF NOT EXISTS restaurants (
            id_restaurant INT AUTO_INCREMENT PRIMARY KEY,
            nom VARCHAR(100) NOT NULL,
            slug VARCHAR(50) NOT NULL UNIQUE,
            proprietaire VARCHAR(100) NULL,
            email VARCHAR(100) NULL,
            ville VARCHAR(50) DEFAULT 'Meknès',
            telephone VARCHAR(20) NULL,
            adresse VARCHAR(255) NULL,
            logo_url VARCHAR(255) NULL,
            devise VARCHAR(10) DEFAULT 'DH',
            plan ENUM('BASIC', 'PRO', 'PREMIUM') NOT NULL DEFAULT 'PRO',
            date_expiration DATE NULL,
            statut ENUM('ACTIF', 'SUSPENDU', 'ESSAI') NOT NULL DEFAULT 'ACTIF',
            date_creation DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // 2. Table Utilisateurs (Authentification et Rôles SaaS)
        "CREATE TABLE IF NOT EXISTS utilisateurs (
            id_utilisateur INT AUTO_INCREMENT PRIMARY KEY,
            id_restaurant INT NULL,
            nom VARCHAR(100) NOT NULL,
            role ENUM('SUPERADMIN', 'ADMIN', 'CAISSIER', 'PREPARATEUR') NOT NULL,
            login VARCHAR(50) NOT NULL UNIQUE,
            mot_de_passe VARCHAR(255) NOT NULL,
            actif TINYINT(1) NOT NULL DEFAULT 1,
            date_creation DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (id_restaurant) REFERENCES restaurants(id_restaurant) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // 3. Table Sandwichs & Produits
        "CREATE TABLE IF NOT EXISTS sandwichs (
            id_sandwich INT AUTO_INCREMENT PRIMARY KEY,
            id_restaurant INT NOT NULL DEFAULT 1,
            nom VARCHAR(100) NOT NULL,
            prix DECIMAL(6,2) NOT NULL,
            description TEXT,
            disponible TINYINT(1) NOT NULL DEFAULT 1,
            image_url VARCHAR(500) NULL,
            badge VARCHAR(50) NULL,
            categorie VARCHAR(50) NULL DEFAULT 'Ma3qouda',
            FOREIGN KEY (id_restaurant) REFERENCES restaurants(id_restaurant) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // 4. Table Ingrédients
        "CREATE TABLE IF NOT EXISTS ingredients (
            id_ingredient INT AUTO_INCREMENT PRIMARY KEY,
            id_restaurant INT NOT NULL DEFAULT 1,
            nom VARCHAR(100) NOT NULL,
            prix_supplement DECIMAL(6,2) NOT NULL DEFAULT 0.00,
            FOREIGN KEY (id_restaurant) REFERENCES restaurants(id_restaurant) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // 5. Table Commandes
        "CREATE TABLE IF NOT EXISTS commandes (
            id_commande INT AUTO_INCREMENT PRIMARY KEY,
            id_restaurant INT NOT NULL DEFAULT 1,
            numero_commande VARCHAR(20) NOT NULL,
            date_commande DATETIME DEFAULT CURRENT_TIMESTAMP,
            statut ENUM('EN_PREPARATION', 'PRETE', 'SERVIE') NOT NULL DEFAULT 'EN_PREPARATION',
            montant_total DECIMAL(8,2) NOT NULL DEFAULT 0.00,
            id_caissier INT NOT NULL,
            FOREIGN KEY (id_restaurant) REFERENCES restaurants(id_restaurant) ON DELETE CASCADE,
            FOREIGN KEY (id_caissier) REFERENCES utilisateurs(id_utilisateur)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // 6. Table Détails Commande
        "CREATE TABLE IF NOT EXISTS details_commande (
            id_detail INT AUTO_INCREMENT PRIMARY KEY,
            id_commande INT NOT NULL,
            id_sandwich INT NOT NULL,
            quantite INT NOT NULL DEFAULT 1,
            prix_unitaire DECIMAL(6,2) NOT NULL,
            commentaire VARCHAR(255) NULL,
            FOREIGN KEY (id_commande) REFERENCES commandes(id_commande) ON DELETE CASCADE,
            FOREIGN KEY (id_sandwich) REFERENCES sandwichs(id_sandwich)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // 7. Table Détails Ingrédients
        "CREATE TABLE IF NOT EXISTS details_ingredients (
            id_detail_ing INT AUTO_INCREMENT PRIMARY KEY,
            id_detail INT NOT NULL,
            id_ingredient INT NOT NULL,
            action_ingredient ENUM('AJOUTER', 'RETIRER') NOT NULL,
            prix_applique DECIMAL(6,2) NOT NULL DEFAULT 0.00,
            FOREIGN KEY (id_detail) REFERENCES details_commande(id_detail) ON DELETE CASCADE,
            FOREIGN KEY (id_ingredient) REFERENCES ingredients(id_ingredient)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // 8. Table Historique Statuts
        "CREATE TABLE IF NOT EXISTS historique_statuts (
            id_historique INT AUTO_INCREMENT PRIMARY KEY,
            id_commande INT NOT NULL,
            ancien_statut ENUM('EN_PREPARATION', 'PRETE', 'SERVIE') NULL,
            nouveau_statut ENUM('EN_PREPARATION', 'PRETE', 'SERVIE') NOT NULL,
            date_changement DATETIME DEFAULT CURRENT_TIMESTAMP,
            id_utilisateur INT NOT NULL,
            FOREIGN KEY (id_commande) REFERENCES commandes(id_commande) ON DELETE CASCADE,
            FOREIGN KEY (id_utilisateur) REFERENCES utilisateurs(id_utilisateur)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // 9. Table Plans SaaS
        "CREATE TABLE IF NOT EXISTS plans (
            id_plan INT AUTO_INCREMENT PRIMARY KEY,
            code VARCHAR(30) NOT NULL UNIQUE,
            nom VARCHAR(60) NOT NULL,
            prix_mensuel DECIMAL(8,2) NOT NULL,
            prix_annuel DECIMAL(8,2) NOT NULL,
            max_employes INT NOT NULL DEFAULT 5,
            max_produits INT NOT NULL DEFAULT 50,
            badge VARCHAR(30) NULL,
            description TEXT,
            actif TINYINT(1) NOT NULL DEFAULT 1
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // 10. Table Paiements SaaS
        "CREATE TABLE IF NOT EXISTS paiements (
            id_paiement INT AUTO_INCREMENT PRIMARY KEY,
            id_restaurant INT NOT NULL,
            reference_tx VARCHAR(50) NOT NULL UNIQUE,
            montant DECIMAL(8,2) NOT NULL,
            methode ENUM('VIREMENT', 'ESPECES', 'CARTE', 'CHEQUE') NOT NULL DEFAULT 'VIREMENT',
            statut ENUM('VALIDE', 'EN_ATTENTE', 'ECHOUE') NOT NULL DEFAULT 'VALIDE',
            plan_concerne VARCHAR(30) NOT NULL DEFAULT 'PRO',
            periode_mois INT NOT NULL DEFAULT 1,
            date_paiement DATETIME DEFAULT CURRENT_TIMESTAMP,
            notes VARCHAR(255) NULL,
            FOREIGN KEY (id_restaurant) REFERENCES restaurants(id_restaurant) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // 11. Table Logs Activite
        "CREATE TABLE IF NOT EXISTS logs_activite (
            id_log INT AUTO_INCREMENT PRIMARY KEY,
            id_utilisateur INT NULL,
            id_restaurant INT NULL,
            type_action VARCHAR(50) NOT NULL,
            description TEXT NOT NULL,
            ip_source VARCHAR(45) NULL,
            date_creation DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // 12. Table Tickets Support
        "CREATE TABLE IF NOT EXISTS tickets_support (
            id_ticket INT AUTO_INCREMENT PRIMARY KEY,
            id_restaurant INT NOT NULL,
            sujet VARCHAR(200) NOT NULL,
            message TEXT NOT NULL,
            priorite ENUM('BASSE', 'MOYENNE', 'HAUTE', 'URGENTE') NOT NULL DEFAULT 'MOYENNE',
            statut ENUM('OUVERT', 'EN_COURS', 'RESOLU') NOT NULL DEFAULT 'OUVERT',
            reponse_admin TEXT NULL,
            date_creation DATETIME DEFAULT CURRENT_TIMESTAMP,
            date_resolution DATETIME NULL,
            FOREIGN KEY (id_restaurant) REFERENCES restaurants(id_restaurant) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // 13. Table Configuration Plateforme
        "CREATE TABLE IF NOT EXISTS plateforme_config (
            cle VARCHAR(50) PRIMARY KEY,
            valeur TEXT NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // Initial Seed : Restaurants
        "INSERT INTO restaurants (id_restaurant, nom, slug, proprietaire, email, ville, telephone, statut, plan, date_expiration) VALUES
        (1, 'M3i9dat Ba M\'Barek', 'ba-mbarek', 'Ba M\'Barek', 'contact@bambarek.ma', 'Meknès', '0661234567', 'ACTIF', 'PREMIUM', DATE_ADD(CURRENT_DATE(), INTERVAL 1 YEAR)),
        (2, 'Snack Al-Baraka', 'al-baraka', 'Rachid Baraka', 'albaraka.fes@gmail.com', 'Fès', '0669876543', 'ACTIF', 'PRO', DATE_ADD(CURRENT_DATE(), INTERVAL 6 MONTH))
        ON DUPLICATE KEY UPDATE nom=VALUES(nom), plan=VALUES(plan)",

        // Initial Seed : Plans
        "INSERT INTO plans (code, nom, prix_mensuel, prix_annuel, max_employes, max_produits, badge, description) VALUES
        ('BASIC', 'Basic Starter', 290.00, 2900.00, 2, 25, 'DÉMARRAGE', 'Caisse tactile POS, gestion du menu, jusqu\'à 2 employés, support standard.'),
        ('PRO', 'Pro Restaurant', 590.00, 5900.00, 8, 100, 'LE PLUS POPULAIRE', 'Tout Basic + Écran Cuisine KDS, affichage TV public, gestion suppléments, rapports.'),
        ('PREMIUM', 'Enterprise Franchise', 990.00, 9900.00, 25, 500, 'MULTI-ÉTABLISSEMENTS', 'Tout Pro + Multi-caisses, exports comptables Z, support prioritaire 24/7.')
        ON DUPLICATE KEY UPDATE nom=VALUES(nom)",

        // Initial Seed : Configuration Plateforme
        "INSERT INTO plateforme_config (cle, valeur) VALUES
        ('nom_plateforme', 'RestoOS Cloud'),
        ('devise', 'DH'),
        ('contact_email', 'contact@restoos.ma'),
        ('contact_telephone', '+212 6 00 00 00 00'),
        ('duree_essai_jours', '14')
        ON DUPLICATE KEY UPDATE valeur=VALUES(valeur)",

        // Initial Seed : Paiements
        "INSERT IGNORE INTO paiements (id_restaurant, reference_tx, montant, methode, statut, plan_concerne, periode_mois, date_paiement, notes) VALUES
        (1, 'TX-2026-001', 5900.00, 'VIREMENT', 'VALIDE', 'PREMIUM', 12, DATE_SUB(NOW(), INTERVAL 25 DAY), 'Abonnement Annuel Premium — Virement CIH Bank'),
        (2, 'TX-2026-002', 590.00, 'ESPECES', 'VALIDE', 'PRO', 1, DATE_SUB(NOW(), INTERVAL 5 DAY), 'Mensualité Pro — Règlement espèces agent')",

        // Initial Seed : Logs Activité
        "INSERT IGNORE INTO logs_activite (id_utilisateur, id_restaurant, type_action, description, date_creation) VALUES
        (1, 1, 'CREATION_RESTAURANT', 'Intégration initiale du restaurant M3i9dat Ba M\'Barek avec carte culte.', DATE_SUB(NOW(), INTERVAL 30 DAY)),
        (1, 2, 'CREATION_RESTAURANT', 'Intégration du restaurant Snack Al-Baraka (Fès) sous forfait Pro.', DATE_SUB(NOW(), INTERVAL 14 DAY)),
        (1, 1, 'PAIEMENT_VALIDE', 'Paiement annuel de 5 900 DH validé pour Ba M\'Barek.', DATE_SUB(NOW(), INTERVAL 25 DAY))",

        // Initial Seed : Tickets Support
        "INSERT IGNORE INTO tickets_support (id_ticket, id_restaurant, sujet, message, priorite, statut, date_creation) VALUES
        (1, 1, 'Configuration Imprimante Thermique 80mm', 'Nous souhaitons calibrer les marges d\'impression pour notre tiroir caisse Sunmi.', 'MOYENNE', 'OUVERT', DATE_SUB(NOW(), INTERVAL 2 DAY)),
        (2, 2, 'Ajout d\'un second écran cuisine KDS', 'Comment connecter une tablette supplémentaire sur le même compte cuisine ?', 'BASSE', 'RESOLU', DATE_SUB(NOW(), INTERVAL 7 DAY))",

        // Initial Seed : Utilisateurs
        "INSERT INTO utilisateurs (id_utilisateur, id_restaurant, nom, role, login, mot_de_passe) VALUES
        (1, NULL, 'Super Administrateur SaaS', 'SUPERADMIN', 'superadmin', 'admin'),
        (2, 1, 'Karim (Caisse 1)', 'CAISSIER', 'karim', 'caisse1'),
        (3, 1, 'Said (Chef Cuisine)', 'PREPARATEUR', 'said', 'cuisine1'),
        (4, 1, 'Gérant Ba M\'Barek', 'ADMIN', 'admin', 'admin'),
        (5, 2, 'Rachid (Caisse Al-Baraka)', 'CAISSIER', 'rachid', 'caisse2'),
        (6, 2, 'Gérant Al-Baraka', 'ADMIN', 'admin_baraka', 'admin')
        ON DUPLICATE KEY UPDATE nom=VALUES(nom)",

        // Initial Seed : Ingrédients Ba M'Barek (Restaurant #1)
        "INSERT IGNORE INTO ingredients (id_ingredient, id_restaurant, nom, prix_supplement) VALUES
        (1, 1, 'Sauce Harissa Maison', 0.00),
        (2, 1, 'Sauce Tomate Cumin', 0.00),
        (3, 1, 'Sauce Mayonnaise', 0.00),
        (4, 1, 'Sauce Blanche Ail', 0.00),
        (5, 1, 'Cumin & Sel Tradition', 0.00),
        (6, 1, 'Olives Vertes Émincées', 0.00),
        (7, 1, 'Extra Fromage Fondu', 2.00),
        (8, 1, 'Extra Œuf sur Plancha', 2.00),
        (9, 1, 'Extra Lanchon Snacké', 3.00),
        (10, 1, 'Extra Galette M3i9da', 2.00),
        (11, 1, 'Double Fromage Cheddar', 4.00)",

        // Initial Seed : Ingrédients Al-Baraka (Restaurant #2)
        "INSERT IGNORE INTO ingredients (id_ingredient, id_restaurant, nom, prix_supplement) VALUES
        (12, 2, 'Sauce Algérienne', 0.00),
        (13, 2, 'Sauce Samouraï', 0.00),
        (14, 2, 'Extra Mozzarella', 3.00)",

        // Initial Seed : Sandwichs Ba M'Barek (21 items Glovo CDN)
        "INSERT IGNORE INTO sandwichs (id_sandwich, id_restaurant, nom, prix, description, disponible, image_url, badge, categorie) VALUES
        (1, 1, 'Komplis (Le Complet)', 19.00, 'Le légendaire complet de Ba M''Barek : Maâkouda dorée + Œuf + Fromage fondant + Lanchon émincé', 1, 'https://glovo.dhmedia.io/image/menus-glovo/products/28eff7a6930351245d3f044a5b645c295814746ee37daec0a8cc2318ead85184?t=W3sicmVzaXplIjp7Im1vZGUiOiJmaXQiLCJ3aWR0aCI6NTAwLCJoZWlnaHQiOjUwMH19XQ==', 'BEST-SELLER', 'Ma3qouda'),
        (2, 1, 'Complet Sans Œuf', 18.00, 'Double dose de Maâkouda croustillante + Fromage fondu + Lanchon savoureux', 1, 'https://glovo.dhmedia.io/image/global-menu-service/GV_MA/vendor/616633/PRODUCT/f979a44b-e39e-4920-bfce-a83dd0a49c89.jpg?t=W3sicmVzaXplIjp7Im1vZGUiOiJmaXQiLCJ3aWR0aCI6NTAwLCJoZWlnaHQiOjUwMH19XQ==', 'POPULAIRE', 'Ma3qouda'),
        (3, 1, 'Omelette Maâkouda & Lanchon', 17.00, 'Omelette moelleuse préparée sur plancha avec galettes de Maâkouda et dés de lanchon', 1, 'https://glovo.dhmedia.io/image/menus-glovo/products/857ec95ab8134e772b0564d1de6087674bfdf5c6354f8b7db1c30d8fe09a1c8a?t=W3sicmVzaXplIjp7Im1vZGUiOiJmaXQiLCJ3aWR0aCI6NTAwLCJoZWlnaHQiOjUwMH19XQ==', 'SPÉCIALITÉ', 'Ma3qouda'),
        (4, 1, 'Maâkouda & Lanchon', 15.00, 'Galettes de Maâkouda traditionnelles chaudes avec tranches de lanchon snacké', 1, 'https://glovo.dhmedia.io/image/global-menu-service/GV_MA/vendor/616633/product/884fb838-4f51-4b15-b018-b0daf87c283c.jpg?t=W3sicmVzaXplIjp7Im1vZGUiOiJmaXQiLCJ3aWR0aCI6NTAwLCJoZWlnaHQiOjUwMH19XQ==', 'TRADITION', 'Ma3qouda'),
        (5, 1, 'Omelette Maâkouda & Fromage', 15.00, 'Omelette minute garnie de Maâkouda et de fromage La Vache qui Rit fondant', 1, 'https://glovo.dhmedia.io/image/menus-glovo/products/4197d49207dbd64cd2b6f3941e671d8a79de013a058710317c0421af1a204f78?t=W3sicmVzaXplIjp7Im1vZGUiOiJmaXQiLCJ3aWR0aCI6NTAwLCJoZWlnaHQiOjUwMH19XQ==', 'FROMAGE', 'Ma3qouda'),
        (6, 1, 'Maâkouda & Fromage', 14.00, 'Le duo classique : Maâkouda croustillante aux épices de Meknès et fromage fondu', 1, 'https://glovo.dhmedia.io/image/global-menu-service/GV_MA/vendor/616633/product/9ceb2140-fec9-4403-9a88-c116d3871c4d.jpg?t=W3sicmVzaXplIjp7Im1vZGUiOiJmaXQiLCJ3aWR0aCI6NTAwLCJoZWlnaHQiOjUwMH19XQ==', 'GOURMAND', 'Ma3qouda'),
        (7, 1, 'Omelette Maâkouda', 12.00, 'Sandwich savoureux à l''omelette fraîche et Maâkouda bien dorée', 1, 'https://glovo.dhmedia.io/image/menus-glovo/products/6edf6b4f696e2aa1ebe9682d9a7bf035eecea5d8f28cf2839dbd50619e457cd0?t=W3sicmVzaXplIjp7Im1vZGUiOiJmaXQiLCJ3aWR0aCI6NTAwLCJoZWlnaHQiOjUwMH19XQ==', 'LÉGER', 'Ma3qouda'),
        (8, 1, 'Maâkouda 3adi (Classique)', 10.00, 'La recette originelle de Ba M''Barek depuis des décennies dans du bon pain croustillant', 1, 'https://glovo.dhmedia.io/image/global-menu-service/GV_MA/vendor/616633/PRODUCT/98339b3f-c395-4d4b-a954-a9719c416e1a.png?t=W3sicmVzaXplIjp7Im1vZGUiOiJmaXQiLCJ3aWR0aCI6NTAwLCJoZWlnaHQiOjUwMH19XQ==', 'AUTHENTIQUE', 'Ma3qouda'),
        (9, 1, 'Portion M3i9dat (Seule)', 2.00, 'Galette de Maâkouda artisanale individuelle, croustillante à l''extérieur et fondante à cœur', 1, 'https://glovo.dhmedia.io/image/menus-glovo/products/8ff108ccced73380eecb1484038fb509fb855f06ae65046de7ac43f228aa40a7?t=W3sicmVzaXplIjp7Im1vZGUiOiJmaXQiLCJ3aWR0aCI6NTAwLCJoZWlnaHQiOjUwMH19XQ==', 'PORTION', 'Ma3qouda'),
        (10, 1, 'Combo Complet', 29.00, '1 Sandwich Komplis + 1 Boisson fraîche au choix + 2 galettes de M3i9dat offertes', 1, 'https://glovo.dhmedia.io/image/global-menu-service/GV_MA/vendor/616633/product/32d82f11-160b-41fb-88bb-bd3794101d06.jpg?t=W3sicmVzaXplIjp7Im1vZGUiOiJmaXQiLCJ3aWR0aCI6NTAwLCJoZWlnaHQiOjUwMH19XQ==', 'TOP DEAL', 'Combos'),
        (11, 1, 'Combo Lincheon', 25.00, '1 Sandwich Maâkouda Lanchon + 1 Boisson fraîche + 2 M3i9dat croustillantes', 1, 'https://glovo.dhmedia.io/image/global-menu-service/GV_MA/vendor/926989/product/40862126135/3870606d-4bcb-42c6-afc3-ecb39f4f456a.jpg?t=W3sicmVzaXplIjp7Im1vZGUiOiJmaXQiLCJ3aWR0aCI6NTAwLCJoZWlnaHQiOjUwMH19XQ==', 'MENU', 'Combos'),
        (12, 1, 'Combo Fromage', 24.00, '1 Sandwich Maâkouda Fromage + 1 Boisson fraîche + 2 M3i9dat croustillantes', 1, 'https://glovo.dhmedia.io/image/global-menu-service/GV_MA/vendor/926989/product/40862126331/818dd562-f2fc-4e49-8150-a14abc790a41.jpg?t=W3sicmVzaXplIjp7Im1vZGUiOiJmaXQiLCJ3aWR0aCI6NTAwLCJoZWlnaHQiOjUwMH19XQ==', 'MENU', 'Combos'),
        (13, 1, 'Combo Normal', 21.00, '1 Sandwich Maâkouda 3adi + 1 Boisson fraîche + 2 M3i9dat croustillantes', 1, 'https://glovo.dhmedia.io/image/global-menu-service/GV_MA/vendor/926989/product/40877264983/9c4d500f-61c4-4af6-a9e8-2a7e6ad20a54.jpg?t=W3sicmVzaXplIjp7Im1vZGUiOiJmaXQiLCJ3aWR0aCI6NTAwLCJoZWlnaHQiOjUwMH19XQ==', 'ÉCO', 'Combos'),
        (14, 1, 'Duo Complet (Pour 2)', 43.00, '1 Sandwich Complet + 1 Complet Sans Œuf + 1 Grande Boisson fraîche', 1, 'https://glovo.dhmedia.io/image/global-menu-service/GV_MA/vendor/616633/product/5f0cc299-7360-4442-9d2d-35b02ad705f9.jpg?t=W3sicmVzaXplIjp7Im1vZGUiOiJmaXQiLCJ3aWR0aCI6NTAwLCJoZWlnaHQiOjUwMH19XQ==', 'DUO', 'Duo'),
        (15, 1, 'Duo Lincheon (Pour 2)', 37.00, '1 Maâkouda Lanchon + 1 Omelette Maâkouda Lanchon + 1 Grande Boisson fraîche', 1, 'https://glovo.dhmedia.io/image/global-menu-service/GV_MA/vendor/616633/product/82a55f09-7bf1-450c-b926-f321d2c8d94a.jpg?t=W3sicmVzaXplIjp7Im1vZGUiOiJmaXQiLCJ3aWR0aCI6NTAwLCJoZWlnaHQiOjUwMH19XQ==', 'DUO', 'Duo'),
        (16, 1, 'Duo Fromage (Pour 2)', 35.00, '1 Maâkouda Fromage + 1 Omelette Maâkouda Fromage + 1 Grande Boisson fraîche', 1, 'https://glovo.dhmedia.io/image/global-menu-service/GV_MA/vendor/616633/product/a14a5ba3-e4c0-4087-902b-c593ee6969b9.jpg?t=W3sicmVzaXplIjp7Im1vZGUiOiJmaXQiLCJ3aWR0aCI6NTAwLCJoZWlnaHQiOjUwMH19XQ==', 'DUO', 'Duo'),
        (17, 1, 'Duo Normal (Pour 2)', 30.00, '1 Maâkouda 3adi + 1 Omelette Maâkouda + 1 Grande Boisson fraîche', 1, 'https://glovo.dhmedia.io/image/global-menu-service/GV_MA/vendor/616633/product/631c9fa5-0daf-42d9-b8aa-8e01a71a2ec6.jpg?t=W3sicmVzaXplIjp7Im1vZGUiOiJmaXQiLCJ3aWR0aCI6NTAwLCJoZWlnaHQiOjUwMH19XQ==', 'DUO', 'Duo'),
        (18, 1, 'Pulpy Orange', 9.00, 'Jus d''orange Pulpy avec vraie pulpe de fruit rafraîchissante', 1, 'https://glovo.dhmedia.io/image/global-menu-service/GV_MA/vendor/616633/PRODUCT/f8861a1f-4f42-4913-8ae4-e2d86d47e402.png?t=W3sicmVzaXplIjp7Im1vZGUiOiJmaXQiLCJ3aWR0aCI6NTAwLCJoZWlnaHQiOjUwMH19XQ==', 'JUS', 'Boissons'),
        (19, 1, 'Coca-Cola 33cl', 7.00, 'Canette Coca-Cola Original bien fraîche', 1, 'https://glovo.dhmedia.io/image/global-menu-service/GV_MA/vendor/926989/product/40713631524/29968bc9-604a-4397-8854-0795ad483002.jpg?t=W3sicmVzaXplIjp7Im1vZGUiOiJmaXQiLCJ3aWR0aCI6NTAwLCJoZWlnaHQiOjUwMH19XQ==', 'SODA', 'Boissons'),
        (20, 1, 'Hawaii Tropical 33cl', 7.00, 'La saveur tropicale marocaine culte bien glacée', 1, 'https://glovo.dhmedia.io/image/global-menu-service/GV_MA/vendor/616633/product/40713821960/459215f6-66d9-444d-9653-e797facd0ad9.jpg?t=W3sicmVzaXplIjp7Im1vZGUiOiJmaXQiLCJ3aWR0aCI6NTAwLCJoZWlnaHQiOjUwMH19XQ==', 'SODA', 'Boissons'),
        (21, 1, 'Eau Minérale 50cl', 5.00, 'Bouteille d''eau minérale naturelle pure', 1, 'https://glovo.dhmedia.io/image/menus-glovo/products/c144bd809d69d0aa7abc37d8e995003fb299c5e0b99c5de69318a58bafa206b1?t=W3sicmVzaXplIjp7Im1vZGUiOiJmaXQiLCJ3aWR0aCI6NTAwLCJoZWlnaHQiOjUwMH19XQ==', 'EAU', 'Boissons')",

        // Initial Seed : Sandwichs Snack Al-Baraka (Restaurant #2)
        "INSERT IGNORE INTO sandwichs (id_sandwich, id_restaurant, nom, prix, description, disponible, image_url, badge, categorie) VALUES
        (22, 2, 'Tacos Poulet Mariné', 35.00, 'Tacos double sauce avec frites et fromage fondu', 1, 'https://images.unsplash.com/photo-1550547660-d9450f859349?w=500', 'TOP', 'Tacos'),
        (23, 2, 'Panini Viande Hachée', 28.00, 'Panini croustillant à la viande hachée assaisonnée', 1, 'https://images.unsplash.com/photo-1550547660-d9450f859349?w=500', 'FAVORI', 'Paninis')"
    ];

    foreach ($sqlCommands as $i => $sql) {
        try {
            $pdo->exec($sql);
        } catch (Throwable $ex) {
            $success = false;
            $messages[] = ['type' => 'error', 'text' => "Erreur étape " . ($i + 1) . " : " . htmlspecialchars($ex->getMessage())];
        }
    }

    if ($success) {
        $messages[] = ['type' => 'success', 'text' => "Architecture SaaS Multi-Tenant initialisée avec succès ! 2 Restaurants, SuperAdmin, Comptes gérants et Catalogues configurés."];
    }
}

// Stats existantes
$totalRest = 0;
$totalSandwichs = 0;
$totalUsers = 0;
try {
    $totalRest = (int)$pdo->query("SELECT COUNT(*) FROM restaurants")->fetchColumn();
    $totalSandwichs = (int)$pdo->query("SELECT COUNT(*) FROM sandwichs")->fetchColumn();
    $totalUsers = (int)$pdo->query("SELECT COUNT(*) FROM utilisateurs")->fetchColumn();
} catch (Throwable $e) {}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Installateur SaaS Multi-Restaurants</title>
    <link rel="icon" type="image/svg+xml" href="assets/img/favicon.svg">
    <link rel="alternate icon" href="favicon.ico">
    <style>
        :root {
            --terracotta: #c2410c;
            --charcoal: #1c1917;
            --border: #e7e5e4;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: #f5f5f4;
            color: var(--charcoal);
            padding: 40px 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
        }
        .install-box {
            background: #fff;
            max-width: 650px;
            width: 100%;
            border-radius: 20px;
            border: 1.5px solid var(--border);
            padding: 36px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.06);
        }
        h1 {
            font-size: 24px;
            font-weight: 900;
            margin-bottom: 8px;
            color: var(--charcoal);
        }
        p { font-size: 14.5px; color: #57534e; line-height: 1.5; margin-bottom: 24px; }
        .msg {
            padding: 12px 16px;
            border-radius: 10px;
            font-size: 13.5px;
            font-weight: 600;
            margin-bottom: 12px;
        }
        .msg-success { background: #dcfce7; color: #15803d; border: 1px solid #86efac; }
        .msg-error { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }
        .stat-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 12px;
            background: #fafaf9;
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 16px;
            margin-bottom: 24px;
            text-align: center;
        }
        .stat-val { font-size: 22px; font-weight: 900; color: var(--terracotta); }
        .stat-lbl { font-size: 11.5px; font-weight: 700; text-transform: uppercase; color: #78716c; margin-top: 2px; }
        .btn-install {
            width: 100%;
            padding: 15px;
            background: linear-gradient(135deg, var(--terracotta), #ea580c);
            color: #fff;
            border: none;
            border-radius: 12px;
            font-size: 16px;
            font-weight: 900;
            cursor: pointer;
            box-shadow: 0 4px 14px rgba(194, 65, 12, 0.3);
            transition: all 0.2s;
        }
        .btn-install:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 18px rgba(194, 65, 12, 0.4);
        }
        .btn-goto {
            display: block;
            text-align: center;
            margin-top: 16px;
            font-size: 14px;
            font-weight: 800;
            color: var(--terracotta);
            text-decoration: none;
        }
    </style>
</head>
<body>
    <div class="install-box">
        <h1>Initialisation SaaS Multi-Restaurants</h1>
        <p>Cet assistant configure la base de données distante ou locale pour le déploiement multi-tenants (SuperAdmin, Gérants, Caisse POS, KDS Cuisine et Menus).</p>

        <?php foreach ($messages as $m): ?>
            <div class="msg msg-<?= $m['type'] ?>"><?= $m['text'] ?></div>
        <?php endforeach; ?>

        <div class="stat-grid">
            <div>
                <div class="stat-val"><?= $totalRest ?></div>
                <div class="stat-lbl">Restaurants</div>
            </div>
            <div>
                <div class="stat-val"><?= $totalUsers ?></div>
                <div class="stat-lbl">Comptes Utilisateurs</div>
            </div>
            <div>
                <div class="stat-val"><?= $totalSandwichs ?></div>
                <div class="stat-lbl">Produits Catalogue</div>
            </div>
        </div>

        <form method="POST" action="">
            <button type="submit" class="btn-install">
                <?= ($totalRest > 0) ? 'Réinitialiser / Réaligner les Tables' : 'Installer l\'Architecture SaaS (1-Clic)' ?>
            </button>
        </form>

        <?php if ($totalRest > 0): ?>
            <a href="login.php" class="btn-goto">Accéder au Portail de Connexion ➔</a>
        <?php endif; ?>
    </div>
</body>
</html>