<?php
declare(strict_types=1);

/** @var PDO $pdo */
$pdo = require __DIR__ . '/db.php';

try {
    // Check if columns exist
    $columns = $pdo->query("SHOW COLUMNS FROM sandwichs")->fetchAll(PDO::FETCH_COLUMN);

    if (!in_array('image_url', $columns)) {
        $pdo->exec("ALTER TABLE sandwichs ADD COLUMN image_url VARCHAR(255) NULL AFTER description");
        echo "Added column 'image_url'\n";
    }
    if (!in_array('badge', $columns)) {
        $pdo->exec("ALTER TABLE sandwichs ADD COLUMN badge VARCHAR(50) NULL AFTER image_url");
        echo "Added column 'badge'\n";
    }
    if (!in_array('categorie', $columns)) {
        $pdo->exec("ALTER TABLE sandwichs ADD COLUMN categorie VARCHAR(50) NULL AFTER badge");
        echo "Added column 'categorie'\n";
    }

    // Update sandwiches with verified high-res food images and categories
    $updates = [
        [
            'id' => 1,
            'image' => 'https://images.unsplash.com/photo-1550547660-d9450f859349?w=500&auto=format&fit=crop&q=80',
            'badge' => 'Spécialité Maison',
            'categorie' => 'Ma3qouda'
        ],
        [
            'id' => 2,
            'image' => 'https://images.unsplash.com/photo-1525059696034-4967a8e1dca2?w=500&auto=format&fit=crop&q=80',
            'badge' => 'Best-Seller',
            'categorie' => 'Poulet'
        ],
        [
            'id' => 3,
            'image' => 'https://images.unsplash.com/photo-1553909489-cd47e0907980?w=500&auto=format&fit=crop&q=80',
            'badge' => '100% Bœuf',
            'categorie' => 'Kefta'
        ],
        [
            'id' => 4,
            'image' => 'https://images.unsplash.com/photo-1568901346375-23c9450c58cd?w=500&auto=format&fit=crop&q=80',
            'badge' => 'Le Suprême',
            'categorie' => 'Ma3qouda'
        ],
        [
            'id' => 5,
            'image' => 'https://images.unsplash.com/photo-1528735602780-2552fd46c7af?w=500&auto=format&fit=crop&q=80',
            'badge' => 'Fraîcheur',
            'categorie' => 'Classiques'
        ],
        [
            'id' => 6,
            'image' => 'https://images.unsplash.com/photo-1627308595229-7830a5c91f9f?w=500&auto=format&fit=crop&q=80',
            'badge' => 'Épicé & Grillé',
            'categorie' => 'Classiques'
        ]
    ];

    $stmt = $pdo->prepare("UPDATE sandwichs SET image_url = :img, badge = :badge, categorie = :cat WHERE id_sandwich = :id");
    foreach ($updates as $u) {
        $stmt->execute([
            'img' => $u['image'],
            'badge' => $u['badge'],
            'cat' => $u['categorie'],
            'id' => $u['id']
        ]);
    }

    echo "Sandwiches metadata updated successfully!\n";

} catch (Exception $e) {
    echo "Update error: " . $e->getMessage() . "\n";
    exit(1);
}

