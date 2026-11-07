<?php
/**
 * Configuration Base de Données pour InfinityFree / Hébergement Web
 * 
 * Instructions pour InfinityFree :
 * 1. Renommez ce fichier en "db_config.php" (dans le dossier config/)
 * 2. Remplissez les identifiants fournis dans votre panneau InfinityFree (vPanel)
 */

return [
    'host'    => 'sqlXXX.infinityfree.com',     // Ex: sql301.infinityfree.com (Hôte MySQL InfinityFree)
    'port'    => 3306,                          // Port standard MySQL
    'dbname'  => 'if0_XXXXXXXX_sandwicherie',   // Nom de votre base de données créée dans vPanel
    'charset' => 'utf8mb4',
    'user'    => 'if0_XXXXXXXX',                // Nom d'utilisateur MySQL InfinityFree
    'pass'    => 'VotreMotDePasseVPanel',       // Mot de passe de votre compte InfinityFree
];

