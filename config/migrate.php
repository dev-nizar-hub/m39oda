<?php
$host = '127.0.0.1';
$port = 3307;
$user = 'root';
$pass = '';

try {
    echo "Connecting to MySQL/MariaDB on $host:$port...\n";
    $pdo = new PDO("mysql:host=$host;port=$port;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);

    $sqlFile = __DIR__ . '/database.sql';
    if (!file_exists($sqlFile)) {
        throw new Exception("SQL file not found at $sqlFile");
    }

    $sql = file_get_contents($sqlFile);
    echo "Executing database.sql...\n";
    $pdo->exec($sql);

    echo "Verifying tables in database 'sandwicherie':\n";
    $pdo->exec("USE sandwicherie");
    $stmt = $pdo->query("SHOW TABLES");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tables as $t) {
        $count = $pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
        echo "  - Table `$t`: $count rows\n";
    }

    echo "\n=== MIGRATION COMPLETE & VERIFIED ===\n";
} catch (Exception $e) {
    echo "Migration Failed: " . $e->getMessage() . "\n";
    exit(1);
}

