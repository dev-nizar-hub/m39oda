# 🚀 Guide de Déploiement InfinityFree — M39ODA Sandwicherie

Ce guide explique pas à pas comment déployer le projet **M39ODA** sur l'hébergement gratuit **InfinityFree** sans aucune erreur.

---

## 1. Création de la Base de Données sur InfinityFree

1. Connectez-vous à votre espace [InfinityFree](https://www.infinityfree.com/) et ouvrez le **Control Panel (vPanel)** de votre compte.
2. Cliquez sur **MySQL Databases**.
3. Dans le champ **Create New Database**, entrez `sandwicherie` puis cliquez sur **Create Database**.
4. Notez les informations fournies sur la page :
   - **MySQL Host Name** (ex: `sql200.infinityfree.com`)
   - **MySQL Database Name** (ex: `if0_38123456_sandwicherie`)
   - **MySQL User Name** (ex: `if0_38123456`)
   - **MySQL Password** (le mot de passe de votre compte InfinityFree)
   - **MySQL Port** : `3306`

---

## 2. Importation du Schéma SQL dans phpMyAdmin

1. Dans le vPanel InfinityFree, cliquez sur **phpMyAdmin** à côté de votre base de données.
2. Une fois dans phpMyAdmin, cliquez sur votre base dans le menu de gauche.
3. Allez dans l'onglet **Importer (Import)** en haut.
4. Cliquez sur **Parcourir (Browse)** et sélectionnez le fichier :
   ```
   config/database.sql
   ```
5. Cliquez sur le bouton **Exécuter (Go)** en bas.
   > Les 7 tables (`utilisateurs`, `sandwichs`, `ingredients`, `commandes`, `details_commande`, `details_ingredients`, `historique_statuts`) et les données de test sont créées automatiquement.

---

## 3. Configuration de la Connexion (`db_config.php`)

1. Dans le dossier `config/`, faites une copie de `db_config.sample.php` et nommez-la `db_config.php`.
2. Ouvrez `config/db_config.php` et renseignez vos identifiants InfinityFree :

```php
<?php
return [
    'host'    => 'sqlXXX.infinityfree.com',     // Votre MySQL Host
    'port'    => 3306,
    'dbname'  => 'if0_XXXXXXXX_sandwicherie',   // Votre nom de base
    'charset' => 'utf8mb4',
    'user'    => 'if0_XXXXXXXX',                // Votre nom d'utilisateur
    'pass'    => 'VotreMotDePasse',             // Votre mot de passe vPanel
];
```

> **Note :** Le fichier `config/db.php` détecte automatiquement la présence de `db_config.php`. En local sous XAMPP, il continue d'utiliser le port `3307` automatiquement.

---

## 4. Envoi des Fichiers par FTP (FileZilla)

1. Téléchargez et lancez **FileZilla** (ou utilisez le Gestionnaire de fichiers Web d'InfinityFree).
2. Connectez-vous avec vos identifiants FTP (disponibles sur votre tableau de bord InfinityFree) :
   - **Hôte** : `ftpupload.net`
   - **Identifiant** : `if0_XXXXXXXX`
   - **Mot de passe** : Votre mot de passe de compte
   - **Port** : `21`
3. Ouvrez le dossier distant :
   ```
   /htdocs/
   ```
4. Transférez tout le contenu de `c:\xampp\htdocs\m39oda\` directement dans `/htdocs/`.
   - `index.php` doit se trouver directement à la racine de `/htdocs/`.

---

## 5. Accès et Vérification en Ligne

Une fois le transfert terminé, votre application est opérationnelle en ligne sur votre domaine ou sous-domaine InfinityFree :

* **Terminal Caisse (POS)** : `http://votre-domaine.epizy.com/index.php`
* **Écran Cuisine (KDS)** : `http://votre-domaine.epizy.com/cuisine.php`
* **Écran d'Appel Client** : `http://votre-domaine.epizy.com/ecran.php`
* **Historique des Commandes** : `http://votre-domaine.epizy.com/commandes.php`

---

## 💡 Astuces & Dépannage InfinityFree

* **Erreur 500 ou BDD inaccessible** : Vérifiez que le nom d'hôte MySQL dans `config/db_config.php` commence bien par `sql...` et n'est **jamais** `localhost` (sur InfinityFree, MySQL est hébergé sur un serveur distant dédié).
* **Affichage public** : L'écran `ecran.php` s'actualise toutes les 2.5 secondes par requête AJAX légère (compatible avec les quotas gratuits d'InfinityFree).

