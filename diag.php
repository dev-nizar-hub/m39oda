<?php
declare(strict_types=1);

// SECURITY: Diagnostic page — SuperAdmin only. No unauthenticated access.
require_once __DIR__ . '/config/auth.php';
requireRole(['SUPERADMIN']);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

header('Content-Type: text/html; charset=utf-8');

echo "<h2>Diagnostic Serveur &amp; Base de Données InfinityFree</h2>";

// 1. Host information
echo "<strong>HTTP_HOST:</strong> " . htmlspecialchars($_SERVER['HTTP_HOST'] ?? 'unknown') . "<br>";
echo "<strong>PHP Version:</strong> " . PHP_VERSION . "<br>";

// 2. Database Connection Test (via shared db.php — no credentials duplicated here)
try {
    $pdo = require __DIR__ . '/config/db.php';
    echo "<p style='color:green;'><strong>✓ Connexion PDO réussie</strong></p>";

    // 3. Check existing tables
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    echo "<strong>Tables existantes (" . count($tables) . ") :</strong> " . htmlspecialchars(implode(', ', $tables)) . "<br>";

    if (empty($tables)) {
        echo "<p style='color:red;'><strong>Attention : La base de données est vide !</strong></p>";
        echo "<p><a href='install.php' style='padding:10px 20px; background:#c2410c; color:#fff; text-decoration:none; font-weight:bold; border-radius:8px;'>Lancer l'installation (install.php)</a></p>";
    } else {
        echo "<p style='color:green;'><strong>Toutes les tables sont présentes !</strong></p>";
        $users = $pdo->query("SELECT id_utilisateur, login, role FROM utilisateurs")->fetchAll();
        echo "<strong>Utilisateurs enregistrés :</strong><pre>" . htmlspecialchars(print_r($users, true)) . "</pre>";
    }

} catch (Throwable $e) {
    // Error shown to SuperAdmin only — safe
    echo "<p style='color:red;'><strong>✗ Erreur :</strong> " . htmlspecialchars($e->getMessage()) . "</p>";
}
