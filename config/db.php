<?php
/**
 * Database Connection - Native PHP PDO
 * Auto-detects Local Environment (XAMPP / HeidiSQL) vs Production (InfinityFree / cPanel)
 */

declare(strict_types=1);

// Default configuration with environment fallbacks
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$isLocal = ($host === 'localhost' || str_starts_with($host, '127.0.0.1') || (php_sapi_name() === 'cli' && !getenv('INFINITYFREE_ENV')));

if ($isLocal) {
    // Local HeidiSQL / MySQL 8.0 on port 3306
    $dbConfig = [
        'host'    => '127.0.0.1',
        'port'    => 3306,
        'dbname'  => 'sandwicherie',
        'charset' => 'utf8mb4',
        'user'    => 'root',
        'pass'    => 'My$QL8!Secure@Pass#2026',
    ];
} else {
    // InfinityFree Production Environment (sql213.infinityfree.com)
    $dbConfig = [
        'host'    => 'sql213.infinityfree.com',
        'port'    => 3306,
        'dbname'  => 'if0_43349106_sandwicherie',
        'charset' => 'utf8mb4',
        'user'    => 'if0_43349106',
        'pass'    => 'BQZdIvz76fm3cr',
    ];
}

// Allow explicit override if db_config.php exists
$customConfigFile = __DIR__ . '/db_config.php';
if (file_exists($customConfigFile)) {
    $dbConfig = array_merge($dbConfig, require $customConfigFile);
}

try {
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $dbConfig['host'],
        $dbConfig['port'],
        $dbConfig['dbname'],
        $dbConfig['charset']
    );

    $pdo = new PDO($dsn, $dbConfig['user'], $dbConfig['pass'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

    // Auto-guérison et alignement automatique du schéma SaaS si nécessaire
    require_once __DIR__ . '/ensure_schema.php';
    ensureSaaSSchema($pdo);
} catch (PDOException $e) {
    // Log the real error server-side only — never expose DB details to the browser
    error_log('[RestoOS] DB connection failed: ' . $e->getMessage());

    // If this is an API call, return generic JSON
    if (str_contains($_SERVER['REQUEST_URI'] ?? '', '/api/')) {
        http_response_code(503);
        die(json_encode([
            'success' => false,
            'message' => 'Service temporairement indisponible. Veuillez réessayer.'
        ], JSON_UNESCAPED_UNICODE));
    }

    // Otherwise render a generic, non-revealing error page
    http_response_code(503);
    die(<<<HTML
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Service indisponible — RestoOS</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f5f5f4; color: #1c1917; padding: 40px 20px; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; }
        .error-card { background: #fff; max-width: 480px; width: 100%; border-radius: 16px; border: 1.5px solid #fca5a5; padding: 32px; box-shadow: 0 10px 30px rgba(0,0,0,0.06); text-align: center; }
        h1 { color: #b91c1c; font-size: 20px; margin-top: 0; }
        p { font-size: 14px; line-height: 1.6; color: #555; }
        a { display: inline-block; background: #c2410c; color: #fff; padding: 10px 22px; border-radius: 10px; text-decoration: none; font-weight: bold; margin-top: 12px; }
    </style>
</head>
<body>
    <div class="error-card">
        <h1>⚠ Service temporairement indisponible</h1>
        <p>Impossible de joindre la base de données.<br>Veuillez patienter quelques instants et réessayer.</p>
        <a href="login.php">Retour à l'accueil</a>
    </div>
</body>
</html>
HTML);
}

return $pdo;
