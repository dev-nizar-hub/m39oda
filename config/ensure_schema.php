<?php
/**
 * RestoOS SaaS - Auto-Guérison & Migration Automatique du Schéma de Base de Données
 * Exécuté automatiquement pour garantir que toutes les tables et colonnes existent
 * aussi bien sur XAMPP en local que sur InfinityFree / CPanel sans intervention manuelle.
 */

declare(strict_types=1);

function ensureSaaSSchema(PDO $pdo): void
{
    static $alreadyRun = false;
    if ($alreadyRun) {
        return;
    }
    $alreadyRun = true;

    try {
        // 1. Vérification / Création de la table restaurants
        $pdo->exec("CREATE TABLE IF NOT EXISTS restaurants (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // Aligner les colonnes de restaurants si la table existait déjà sous un ancien format
        $restCols = $pdo->query("DESCRIBE restaurants")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('plan', $restCols, true)) {
            $pdo->exec("ALTER TABLE restaurants ADD COLUMN plan ENUM('BASIC', 'PRO', 'PREMIUM') NOT NULL DEFAULT 'PRO'");
        }
        if (!in_array('date_expiration', $restCols, true)) {
            $pdo->exec("ALTER TABLE restaurants ADD COLUMN date_expiration DATE NULL");
            $pdo->exec("UPDATE restaurants SET date_expiration = DATE_ADD(CURRENT_DATE(), INTERVAL 1 YEAR) WHERE date_expiration IS NULL");
        }
        if (!in_array('email', $restCols, true)) {
            $pdo->exec("ALTER TABLE restaurants ADD COLUMN email VARCHAR(100) NULL");
        }
        if (!in_array('proprietaire', $restCols, true)) {
            $pdo->exec("ALTER TABLE restaurants ADD COLUMN proprietaire VARCHAR(100) NULL");
        }
        if (!in_array('slug', $restCols, true)) {
            $pdo->exec("ALTER TABLE restaurants ADD COLUMN slug VARCHAR(50) NOT NULL DEFAULT 'ba-mbarek'");
        }

        // 2. Vérification / Création de la table utilisateurs
        $pdo->exec("CREATE TABLE IF NOT EXISTS utilisateurs (
            id_utilisateur INT AUTO_INCREMENT PRIMARY KEY,
            id_restaurant INT NULL,
            nom VARCHAR(100) NOT NULL,
            role ENUM('SUPERADMIN', 'ADMIN', 'CAISSIER', 'PREPARATEUR') NOT NULL,
            login VARCHAR(50) NOT NULL UNIQUE,
            mot_de_passe VARCHAR(255) NOT NULL,
            actif TINYINT(1) NOT NULL DEFAULT 1,
            date_creation DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // Aligner les colonnes de utilisateurs si la table existait déjà
        $userCols = $pdo->query("DESCRIBE utilisateurs")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('id_restaurant', $userCols, true)) {
            $pdo->exec("ALTER TABLE utilisateurs ADD COLUMN id_restaurant INT NULL AFTER id_utilisateur");
        }

        // 3. Table Plans SaaS
        $pdo->exec("CREATE TABLE IF NOT EXISTS plans (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // 4. Table Paiements SaaS
        $pdo->exec("CREATE TABLE IF NOT EXISTS paiements (
            id_paiement INT AUTO_INCREMENT PRIMARY KEY,
            id_restaurant INT NOT NULL,
            reference_tx VARCHAR(50) NOT NULL UNIQUE,
            montant DECIMAL(8,2) NOT NULL,
            methode ENUM('VIREMENT', 'ESPECES', 'CARTE', 'CHEQUE') NOT NULL DEFAULT 'VIREMENT',
            statut ENUM('VALIDE', 'EN_ATTENTE', 'ECHOUE') NOT NULL DEFAULT 'VALIDE',
            plan_concerne VARCHAR(30) NOT NULL DEFAULT 'PRO',
            periode_mois INT NOT NULL DEFAULT 1,
            date_paiement DATETIME DEFAULT CURRENT_TIMESTAMP,
            notes VARCHAR(255) NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // 5. Table Logs Activité
        $pdo->exec("CREATE TABLE IF NOT EXISTS logs_activite (
            id_log INT AUTO_INCREMENT PRIMARY KEY,
            id_utilisateur INT NULL,
            id_restaurant INT NULL,
            type_action VARCHAR(50) NOT NULL,
            description TEXT NOT NULL,
            ip_source VARCHAR(45) NULL,
            date_creation DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // 6. Table Tickets Support
        $pdo->exec("CREATE TABLE IF NOT EXISTS tickets_support (
            id_ticket INT AUTO_INCREMENT PRIMARY KEY,
            id_restaurant INT NOT NULL,
            sujet VARCHAR(200) NOT NULL,
            message TEXT NOT NULL,
            priorite ENUM('BASSE', 'MOYENNE', 'HAUTE', 'URGENTE') NOT NULL DEFAULT 'MOYENNE',
            statut ENUM('OUVERT', 'EN_COURS', 'RESOLU') NOT NULL DEFAULT 'OUVERT',
            reponse_admin TEXT NULL,
            date_creation DATETIME DEFAULT CURRENT_TIMESTAMP,
            date_resolution DATETIME NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // 7. Table Configuration Plateforme
        $pdo->exec("CREATE TABLE IF NOT EXISTS plateforme_config (
            cle VARCHAR(50) PRIMARY KEY,
            valeur TEXT NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // 8. Seeding essentiel si les données sont absentes
        $restCount = (int)$pdo->query("SELECT COUNT(*) FROM restaurants")->fetchColumn();
        if ($restCount === 0) {
            $pdo->exec("INSERT INTO restaurants (id_restaurant, nom, slug, proprietaire, email, ville, telephone, statut, plan, date_expiration) VALUES
            (1, 'M3i9dat Ba M\'Barek', 'ba-mbarek', 'Ba M\'Barek', 'contact@bambarek.ma', 'Meknès', '0661234567', 'ACTIF', 'PREMIUM', DATE_ADD(CURRENT_DATE(), INTERVAL 1 YEAR)),
            (2, 'Snack Al-Baraka', 'al-baraka', 'Rachid Baraka', 'albaraka.fes@gmail.com', 'Fès', '0669876543', 'ACTIF', 'PRO', DATE_ADD(CURRENT_DATE(), INTERVAL 6 MONTH))");
        }

        $userCount = (int)$pdo->query("SELECT COUNT(*) FROM utilisateurs")->fetchColumn();
        if ($userCount === 0) {
            $pdo->exec("INSERT INTO utilisateurs (id_utilisateur, id_restaurant, nom, role, login, mot_de_passe) VALUES
            (1, NULL, 'Super Administrateur SaaS', 'SUPERADMIN', 'superadmin', 'admin'),
            (2, 1, 'Karim (Caisse 1)', 'CAISSIER', 'karim', 'caisse1'),
            (3, 1, 'Said (Chef Cuisine)', 'PREPARATEUR', 'said', 'cuisine1'),
            (4, 1, 'Gérant Ba M\'Barek', 'ADMIN', 'admin', 'admin'),
            (5, 2, 'Rachid (Caisse Al-Baraka)', 'CAISSIER', 'rachid', 'caisse2'),
            (6, 2, 'Gérant Al-Baraka', 'ADMIN', 'admin_baraka', 'admin')");
        }

        $planCount = (int)$pdo->query("SELECT COUNT(*) FROM plans")->fetchColumn();
        if ($planCount === 0) {
            $pdo->exec("INSERT INTO plans (code, nom, prix_mensuel, prix_annuel, max_employes, max_produits, badge, description) VALUES
            ('BASIC', 'Basic Starter', 290.00, 2900.00, 2, 25, 'DÉMARRAGE', 'Caisse tactile POS, gestion du menu, jusqu\'à 2 employés, support standard.'),
            ('PRO', 'Pro Restaurant', 590.00, 5900.00, 8, 100, 'LE PLUS POPULAIRE', 'Tout Basic + Écran Cuisine KDS, affichage TV public, gestion suppléments, rapports.'),
            ('PREMIUM', 'Enterprise Franchise', 990.00, 9900.00, 25, 500, 'MULTI-ÉTABLISSEMENTS', 'Tout Pro + Multi-caisses, exports comptables Z, support prioritaire 24/7.')");
        }

        $configCount = (int)$pdo->query("SELECT COUNT(*) FROM plateforme_config")->fetchColumn();
        if ($configCount === 0) {
            $pdo->exec("INSERT INTO plateforme_config (cle, valeur) VALUES
            ('nom_plateforme', 'RestoOS Cloud'),
            ('devise', 'DH'),
            ('contact_email', 'contact@restoos.ma'),
            ('contact_telephone', '+212 6 00 00 00 00'),
            ('duree_essai_jours', '14')");
        }

        $payCount = (int)$pdo->query("SELECT COUNT(*) FROM paiements")->fetchColumn();
        if ($payCount === 0) {
            $pdo->exec("INSERT INTO paiements (id_restaurant, reference_tx, montant, methode, statut, plan_concerne, periode_mois, date_paiement, notes) VALUES
            (1, 'TX-2026-001', 5900.00, 'VIREMENT', 'VALIDE', 'PREMIUM', 12, DATE_SUB(NOW(), INTERVAL 25 DAY), 'Abonnement Annuel Premium — Virement CIH Bank'),
            (2, 'TX-2026-002', 590.00, 'ESPECES', 'VALIDE', 'PRO', 1, DATE_SUB(NOW(), INTERVAL 5 DAY), 'Mensualité Pro — Règlement espèces agent')");
        }

        $ticketCount = (int)$pdo->query("SELECT COUNT(*) FROM tickets_support")->fetchColumn();
        if ($ticketCount === 0) {
            $pdo->exec("INSERT INTO tickets_support (id_ticket, id_restaurant, sujet, message, priorite, statut, date_creation) VALUES
            (1, 1, 'Configuration Imprimante Thermique 80mm', 'Nous souhaitons calibrer les marges d\'impression pour notre tiroir caisse Sunmi.', 'MOYENNE', 'OUVERT', DATE_SUB(NOW(), INTERVAL 2 DAY)),
            (2, 2, 'Ajout d\'un second écran cuisine KDS', 'Comment connecter une tablette supplémentaire sur le même compte cuisine ?', 'BASSE', 'RESOLU', DATE_SUB(NOW(), INTERVAL 7 DAY))");
        }

        $logCount = (int)$pdo->query("SELECT COUNT(*) FROM logs_activite")->fetchColumn();
        if ($logCount === 0) {
            $pdo->exec("INSERT INTO logs_activite (id_utilisateur, id_restaurant, type_action, description, date_creation) VALUES
            (1, 1, 'CREATION_RESTAURANT', 'Intégration initiale du restaurant M3i9dat Ba M\'Barek avec carte culte.', DATE_SUB(NOW(), INTERVAL 30 DAY)),
            (1, 2, 'CREATION_RESTAURANT', 'Intégration du restaurant Snack Al-Baraka (Fès) sous forfait Pro.', DATE_SUB(NOW(), INTERVAL 14 DAY)),
            (1, 1, 'PAIEMENT_VALIDE', 'Paiement annuel de 5 900 DH validé pour Ba M\'Barek.', DATE_SUB(NOW(), INTERVAL 25 DAY))");
        }
    } catch (Throwable $e) {
        // En cas d'erreur mineure de contrainte, loguer sans interrompre l'exécution
        error_log("SaaS Schema auto-heal notice: " . $e->getMessage());
    }
}

