-- ========================================================
-- Base de données Multi-Tenant SaaS : sandwicherie
-- Support Multi-Restaurants & Isolation des Menus
-- Port : 3306 (MySQL 8.0 / HeidiSQL)
-- ========================================================

CREATE DATABASE IF NOT EXISTS sandwicherie CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE sandwicherie;

-- 1. Table Restaurants (Tenants SaaS)
CREATE TABLE IF NOT EXISTS restaurants (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Table Utilisateurs (Authentification et Rôles)
CREATE TABLE IF NOT EXISTS utilisateurs (
    id_utilisateur INT AUTO_INCREMENT PRIMARY KEY,
    id_restaurant INT NULL,
    nom VARCHAR(100) NOT NULL,
    role ENUM('SUPERADMIN', 'ADMIN', 'CAISSIER', 'PREPARATEUR') NOT NULL,
    login VARCHAR(50) NOT NULL UNIQUE,
    mot_de_passe VARCHAR(255) NOT NULL,
    actif TINYINT(1) NOT NULL DEFAULT 1,
    date_creation DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_restaurant) REFERENCES restaurants(id_restaurant) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Table Sandwichs & Produits
CREATE TABLE IF NOT EXISTS sandwichs (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Table Ingrédients
CREATE TABLE IF NOT EXISTS ingredients (
    id_ingredient INT AUTO_INCREMENT PRIMARY KEY,
    id_restaurant INT NOT NULL DEFAULT 1,
    nom VARCHAR(100) NOT NULL,
    prix_supplement DECIMAL(6,2) NOT NULL DEFAULT 0.00,
    FOREIGN KEY (id_restaurant) REFERENCES restaurants(id_restaurant) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Table Commandes
CREATE TABLE IF NOT EXISTS commandes (
    id_commande INT AUTO_INCREMENT PRIMARY KEY,
    id_restaurant INT NOT NULL DEFAULT 1,
    numero_commande VARCHAR(20) NOT NULL,
    date_commande DATETIME DEFAULT CURRENT_TIMESTAMP,
    statut ENUM('EN_PREPARATION', 'PRETE', 'SERVIE') NOT NULL DEFAULT 'EN_PREPARATION',
    montant_total DECIMAL(8,2) NOT NULL DEFAULT 0.00,
    id_caissier INT NOT NULL,
    FOREIGN KEY (id_restaurant) REFERENCES restaurants(id_restaurant) ON DELETE CASCADE,
    FOREIGN KEY (id_caissier) REFERENCES utilisateurs(id_utilisateur)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Table Détails Commande
CREATE TABLE IF NOT EXISTS details_commande (
    id_detail INT AUTO_INCREMENT PRIMARY KEY,
    id_commande INT NOT NULL,
    id_sandwich INT NOT NULL,
    quantite INT NOT NULL DEFAULT 1,
    prix_unitaire DECIMAL(6,2) NOT NULL,
    commentaire VARCHAR(255) NULL,
    FOREIGN KEY (id_commande) REFERENCES commandes(id_commande) ON DELETE CASCADE,
    FOREIGN KEY (id_sandwich) REFERENCES sandwichs(id_sandwich)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. Table Détails Ingrédients
CREATE TABLE IF NOT EXISTS details_ingredients (
    id_detail_ing INT AUTO_INCREMENT PRIMARY KEY,
    id_detail INT NOT NULL,
    id_ingredient INT NOT NULL,
    action_ingredient ENUM('AJOUTER', 'RETIRER') NOT NULL,
    prix_applique DECIMAL(6,2) NOT NULL DEFAULT 0.00,
    FOREIGN KEY (id_detail) REFERENCES details_commande(id_detail) ON DELETE CASCADE,
    FOREIGN KEY (id_ingredient) REFERENCES ingredients(id_ingredient)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. Table Historique Statuts
CREATE TABLE IF NOT EXISTS historique_statuts (
    id_historique INT AUTO_INCREMENT PRIMARY KEY,
    id_commande INT NOT NULL,
    ancien_statut ENUM('EN_PREPARATION', 'PRETE', 'SERVIE') NULL,
    nouveau_statut ENUM('EN_PREPARATION', 'PRETE', 'SERVIE') NOT NULL,
    date_changement DATETIME DEFAULT CURRENT_TIMESTAMP,
    id_utilisateur INT NOT NULL,
    FOREIGN KEY (id_commande) REFERENCES commandes(id_commande) ON DELETE CASCADE,
    FOREIGN KEY (id_utilisateur) REFERENCES utilisateurs(id_utilisateur)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 9. Table Plans SaaS
CREATE TABLE IF NOT EXISTS plans (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 10. Table Paiements SaaS
CREATE TABLE IF NOT EXISTS paiements (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 11. Table Logs Activite
CREATE TABLE IF NOT EXISTS logs_activite (
    id_log INT AUTO_INCREMENT PRIMARY KEY,
    id_utilisateur INT NULL,
    id_restaurant INT NULL,
    type_action VARCHAR(50) NOT NULL,
    description TEXT NOT NULL,
    ip_source VARCHAR(45) NULL,
    date_creation DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 12. Table Tickets Support
CREATE TABLE IF NOT EXISTS tickets_support (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 13. Table Configuration Plateforme
CREATE TABLE IF NOT EXISTS plateforme_config (
    cle VARCHAR(50) PRIMARY KEY,
    valeur TEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ========================================================
-- Données Initiales : Tenants & Produits
-- ========================================================

-- Restaurants
INSERT INTO restaurants (id_restaurant, nom, slug, ville, telephone, adresse, statut) VALUES
(1, 'M3i9dat Ba M''Barek', 'ba-mbarek', 'Meknès', '0661234567', 'Rue Kénitra, Hamria', 'ACTIF'),
(2, 'Snack Al-Baraka', 'al-baraka', 'Fès', '0669876543', 'Avenue Hassan II', 'ACTIF')
ON DUPLICATE KEY UPDATE nom=VALUES(nom);

-- Utilisateurs (Plateforme & Restaurants)
INSERT INTO utilisateurs (id_utilisateur, id_restaurant, nom, role, login, mot_de_passe) VALUES
(1, NULL, 'Super Administrateur SaaS', 'SUPERADMIN', 'superadmin', 'admin'),
(2, 1, 'Karim (Caisse 1)', 'CAISSIER', 'karim', 'caisse1'),
(3, 1, 'Said (Chef Cuisine)', 'PREPARATEUR', 'said', 'cuisine1'),
(4, 1, 'Gérant Ba M''Barek', 'ADMIN', 'admin', 'admin'),
(5, 2, 'Rachid (Caisse Al-Baraka)', 'CAISSIER', 'rachid', 'caisse2'),
(6, 2, 'Gérant Al-Baraka', 'ADMIN', 'admin_baraka', 'admin123')
ON DUPLICATE KEY UPDATE nom=VALUES(nom);

-- Ingrédients
INSERT IGNORE INTO ingredients (id_ingredient, id_restaurant, nom, prix_supplement) VALUES
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
(11, 1, 'Double Fromage Cheddar', 4.00),
(12, 2, 'Sauce Algérienne', 0.00),
(13, 2, 'Sauce Samouraï', 0.00),
(14, 2, 'Extra Mozzarella', 3.00);

-- Sandwichs & Formules
INSERT IGNORE INTO sandwichs (id_sandwich, id_restaurant, nom, prix, description, disponible, image_url, badge, categorie) VALUES
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
(21, 1, 'Eau Minérale 50cl', 5.00, 'Bouteille d''eau minérale naturelle pure', 1, 'https://glovo.dhmedia.io/image/menus-glovo/products/c144bd809d69d0aa7abc37d8e995003fb299c5e0b99c5de69318a58bafa206b1?t=W3sicmVzaXplIjp7Im1vZGUiOiJmaXQiLCJ3aWR0aCI6NTAwLCJoZWlnaHQiOjUwMH19XQ==', 'EAU', 'Boissons'),
(22, 2, 'Tacos Poulet Mariné', 35.00, 'Tacos double sauce avec frites et fromage fondu', 1, 'https://images.unsplash.com/photo-1550547660-d9450f859349?w=500', 'TOP', 'Tacos'),
(23, 2, 'Panini Viande Hachée', 28.00, 'Panini croustillant à la viande hachée assaisonnée', 1, 'https://images.unsplash.com/photo-1550547660-d9450f859349?w=500', 'FAVORI', 'Paninis');
