# M3i9dat Ba M'Barek — Système de Caisse & Gestion de Commandes

> **Projet académique & commercial** — Spécialiste de la véritable Maâkouda artisanale de Meknès (Rue Kenitra, Hamria)  
> Terminal POS + KDS Cuisine + Écran Public TV + Historique + Authentification Multi-Postes

---

## Aperçu du Projet

M39ODA est un système de caisse et de gestion de commandes complet pour une sandwicherie artisanale, développé en **PHP 8 natif**, MySQL/MariaDB, JavaScript Vanilla et CSS3. Aucun framework.

### Fonctionnalités

| Écran | Fichier | Rôle |
|---|---|---|
| 🔐 Connexion | `login.php` | Authentification par rôle (Caissier / Cuisine / Admin) |
| 🛒 Terminal Caisse | `index.php` | Saisie des commandes, catalogue avec boxes, personnalisation Atelier |
| 👨‍🍳 Cuisine KDS | `cuisine.php` | Affichage temps réel des commandes, marquer Prête / Servie |
| 📺 Écran Public | `ecran.php` | Callboard TV — En Préparation vs Prêtes au Comptoir |
| 📋 Historique | `commandes.php` | Tableau de bord CA, filtres date/statut/recherche, impression |
| 🖨️ Tickets | `tickets.php` | Réimpression tickets thermiques Client + Bon de Cuisine |

### Règles de Gestion Implémentées

- **RG01** — Panier non vide obligatoire avant validation  
- **RG02** — Minimum 1 sandwich par commande  
- **RG03** — Prix recalculé côté serveur (pas de confiance au client)  
- **RG04** — Double ticket généré à chaque commande (Client + Cuisine)  
- **RG05** — Numéro de commande séquentiel (1, 2, 3...)  
- **RG06** — Statuts : `EN_PREPARATION` → `PRETE` → `SERVIE`  
- **RG07** — Saut de statut interdit (EN_PREPARATION → SERVIE bloqué, HTTP 409)  
- **RG08** — Polling temps réel (cuisine 3s, écran public 2.5s)  
- **RG09** — Horodatage de chaque transition dans `historique_statuts`  

---

## Installation Locale (XAMPP)

### Prérequis

- XAMPP avec Apache + MariaDB actifs
- PHP 8.0+
- MariaDB sur port 3307 (ou 3306)

### Étapes

```bash
# 1. Cloner / déposer dans le répertoire web
xcopy m39oda C:\xampp\htdocs\m39oda /E

# 2. Créer la base de données
# Ouvrir HeidiSQL → exécuter config/database.sql

# 3. (Optionnel) Vérifier la migration
# Ouvrir http://localhost/m39oda/config/migrate.php

# 4. Accéder à l'application
# http://localhost/m39oda/
```

### Configuration Base de Données

Le fichier [`config/db.php`](config/db.php) gère automatiquement la connexion :
- Locale : `localhost:3307`, user `root`, sans mot de passe
- Production : utilise `config/db_config.php` (copier depuis `db_config.sample.php`)

---

## Comptes de Démonstration

| Rôle | Login | Mot de Passe | Accès |
|---|---|---|---|
| 🛒 Caissier | `karim` | `caisse1` | Terminal Caisse |
| 👨‍🍳 Cuisine | `said` | `cuisine1` | KDS Cuisine |
| 📊 Admin | `admin` | `admin` | Historique & Tableau de Bord |

---

## Déploiement InfinityFree

Voir [`DEPLOY_INFINITYFREE.md`](DEPLOY_INFINITYFREE.md) pour les instructions complètes.

**Points critiques :**
- Le host MySQL n'est pas `localhost` sur InfinityFree — utiliser le host fourni dans le panneau
- Créer `config/db_config.php` depuis `config/db_config.sample.php`
- Supprimer `config/migrate.php` et `config/update_schema.php` en production

---

## Stack Technique

```
Backend  : PHP 8 (natif, sans framework)
Database : MariaDB 10.4 / MySQL 8
Frontend : HTML5, CSS3 (custom, sans framework UI)
JS       : Vanilla ES2020 (fetch, async/await, Web Audio API)
Polling  : setInterval AJAX (pas de WebSocket nécessaire)
```

---

## Structure des Fichiers

```
m39oda/
├── index.php           ← Terminal Caisse (POS)
├── login.php           ← Portail d'accès
├── cuisine.php         ← Écran Cuisine KDS
├── ecran.php           ← Écran Public TV
├── commandes.php       ← Tableau de Bord Admin
├── tickets.php         ← Impression tickets
├── api/
│   ├── catalog.php         ← GET  : catalogue sandwichs + ingrédients
│   ├── creer_commande.php  ← POST : créer une commande (RG01-RG05)
│   ├── get_commandes.php   ← GET  : lister commandes actives
│   └── changer_statut.php  ← POST : avancer statut (RG06, RG07)
├── assets/
│   ├── css/pos.css     ← Stylesheet principal
│   └── js/pos.js       ← Contrôleur POS
├── config/
│   ├── db.php          ← Connexion PDO (auto-detect local/prod)
│   ├── database.sql    ← Schéma complet + données initiales
│   └── db_config.sample.php
└── docs/
    └── RAPPORT_PROJET_UML.md   ← UML, MERISE, Règles de Gestion
```

---

## Documentation Académique

Le rapport complet avec diagrammes UML, MERISE MCD/MLD et argumentaire jury est disponible dans [`docs/RAPPORT_PROJET_UML.md`](docs/RAPPORT_PROJET_UML.md).

---

*Développé pour concours académique DI — objectif 1ère place.*

