<?php
declare(strict_types=1);

/** @var PDO $pdo */
$pdo = require __DIR__ . '/db.php';

echo "Running SaaS Platform Schema Migration...\n";

// 1. Add columns to restaurants if not exists
$existingCols = $pdo->query("DESCRIBE restaurants")->fetchAll(PDO::FETCH_COLUMN);

if (!in_array('plan', $existingCols, true)) {
    $pdo->exec("ALTER TABLE restaurants ADD COLUMN plan ENUM('BASIC', 'PRO', 'PREMIUM') NOT NULL DEFAULT 'PRO' AFTER devise");
    echo "+ Added 'plan' column to restaurants\n";
}

if (!in_array('date_expiration', $existingCols, true)) {
    $pdo->exec("ALTER TABLE restaurants ADD COLUMN date_expiration DATE NULL AFTER plan");
    // Set default expiration to 1 year ahead for existing restaurants
    $pdo->exec("UPDATE restaurants SET date_expiration = DATE_ADD(CURRENT_DATE(), INTERVAL 1 YEAR) WHERE date_expiration IS NULL");
    echo "+ Added 'date_expiration' column to restaurants\n";
}

if (!in_array('email', $existingCols, true)) {
    $pdo->exec("ALTER TABLE restaurants ADD COLUMN email VARCHAR(100) NULL AFTER telephone");
    echo "+ Added 'email' column to restaurants\n";
}

if (!in_array('proprietaire', $existingCols, true)) {
    $pdo->exec("ALTER TABLE restaurants ADD COLUMN proprietaire VARCHAR(100) NULL AFTER nom");
    echo "+ Added 'proprietaire' column to restaurants\n";
}

// Update restaurant owner names & emails
$pdo->exec("UPDATE restaurants SET proprietaire = 'Ba M\'Barek', email = 'contact@bambarek.ma', plan = 'PREMIUM' WHERE id_restaurant = 1");
$pdo->exec("UPDATE restaurants SET proprietaire = 'Rachid Baraka', email = 'albaraka.fes@gmail.com', plan = 'PRO' WHERE id_restaurant = 2");

// 2. Table Plans
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
echo "+ Verified table 'plans'\n";

// Seed plans
$pdo->exec("INSERT INTO plans (code, nom, prix_mensuel, prix_annuel, max_employes, max_produits, badge, description) VALUES
('BASIC', 'Basic Starter', 290.00, 2900.00, 2, 25, 'DÉMARRAGE', 'Caisse tactile POS, gestion du menu, jusqu\'à 2 employés, support par email standard.'),
('PRO', 'Pro Restaurant', 590.00, 5900.00, 8, 100, 'LE PLUS POPULAIRE', 'Tout Basic + Écran Cuisine KDS, affichage TV public, gestion des ingrédients/suppléments, rapports de vente.'),
('PREMIUM', 'Enterprise Franchise', 990.00, 9900.00, 25, 500, 'MULTI-ÉTABLISSEMENTS', 'Tout Pro + Multi-caisses, exports comptables Z, support prioritaire 24/7, personnalisation avancée.')
ON DUPLICATE KEY UPDATE nom=VALUES(nom), prix_mensuel=VALUES(prix_mensuel), prix_annuel=VALUES(prix_annuel)");
echo "+ Seeded default SaaS plans (Basic, Pro, Premium)\n";

// 3. Table Paiements
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
    notes VARCHAR(255) NULL,
    FOREIGN KEY (id_restaurant) REFERENCES restaurants(id_restaurant) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
echo "+ Verified table 'paiements'\n";

// Seed initial payments for demo telemetry
$pdo->exec("INSERT IGNORE INTO paiements (id_restaurant, reference_tx, montant, methode, statut, plan_concerne, periode_mois, date_paiement, notes) VALUES
(1, 'TX-2026-001', 5900.00, 'VIREMENT', 'VALIDE', 'PREMIUM', 12, DATE_SUB(NOW(), INTERVAL 25 DAY), 'Abonnement Annuel Premium — Virement CIH Bank'),
(2, 'TX-2026-002', 590.00, 'ESPECES', 'VALIDE', 'PRO', 1, DATE_SUB(NOW(), INTERVAL 5 DAY), 'Mensualité Pro — Règlement espèces agent')");
echo "+ Seeded initial SaaS payment transactions\n";

// 4. Table Logs Activite
$pdo->exec("CREATE TABLE IF NOT EXISTS logs_activite (
    id_log INT AUTO_INCREMENT PRIMARY KEY,
    id_utilisateur INT NULL,
    id_restaurant INT NULL,
    type_action VARCHAR(50) NOT NULL,
    description TEXT NOT NULL,
    ip_source VARCHAR(45) NULL,
    date_creation DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
echo "+ Verified table 'logs_activite'\n";

// Seed initial activity logs
$pdo->exec("INSERT IGNORE INTO logs_activite (id_utilisateur, id_restaurant, type_action, description, date_creation) VALUES
(1, 1, 'CREATION_RESTAURANT', 'Intégration initiale du restaurant M3i9dat Ba M\'Barek avec carte culte.', DATE_SUB(NOW(), INTERVAL 30 DAY)),
(1, 2, 'CREATION_RESTAURANT', 'Intégration du restaurant Snack Al-Baraka (Fès) sous forfait Pro.', DATE_SUB(NOW(), INTERVAL 14 DAY)),
(1, 1, 'PAIEMENT_VALIDE', 'Paiement annuel de 5 900 DH validé pour Ba M\'Barek.', DATE_SUB(NOW(), INTERVAL 25 DAY))");
echo "+ Seeded initial activity logs\n";

// 5. Table Support Tickets
$pdo->exec("CREATE TABLE IF NOT EXISTS tickets_support (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
echo "+ Verified table 'tickets_support'\n";

// Seed initial tickets
$pdo->exec("INSERT IGNORE INTO tickets_support (id_ticket, id_restaurant, sujet, message, priorite, statut, date_creation) VALUES
(1, 1, 'Configuration Imprimante Thermique 80mm', 'Nous souhaitons calibrer les marges d\'impression pour notre tiroir caisse Sunmi.', 'MOYENNE', 'OUVERT', DATE_SUB(NOW(), INTERVAL 2 DAY)),
(2, 2, 'Ajout d\'un second écran cuisine KDS', 'Comment connecter une tablette supplémentaire sur le même compte cuisine ?', 'BASSE', 'RESOLU', DATE_SUB(NOW(), INTERVAL 7 DAY))");
echo "+ Seeded initial support tickets\n";

// 6. Table Configuration Plateforme
$pdo->exec("CREATE TABLE IF NOT EXISTS plateforme_config (
    cle VARCHAR(50) PRIMARY KEY,
    valeur TEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
echo "+ Verified table 'plateforme_config'\n";

$pdo->exec("INSERT INTO plateforme_config (cle, valeur) VALUES
('nom_plateforme', 'RestoOS Cloud'),
('devise', 'DH'),
('contact_email', 'contact@restoos.ma'),
('contact_telephone', '+212 6 00 00 00 00'),
('duree_essai_jours', '14')
ON DUPLICATE KEY UPDATE valeur=VALUES(valeur)");
echo "+ Seeded platform configuration\n";

echo "Migration completed successfully!\n";
