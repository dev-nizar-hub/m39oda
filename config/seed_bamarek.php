<?php
/**
 * Migration Script: Seed exact M3i9dat Ba M'Barek Glovo Menu & Product Images
 */
declare(strict_types=1);

/** @var PDO $pdo */
$pdo = require __DIR__ . '/../config/db.php';

echo "Connected successfully to database.\n";

// Disable foreign key checks temporarily
$pdo->exec("SET FOREIGN_KEY_CHECKS = 0");

$pdo->exec("TRUNCATE TABLE details_ingredients");
$pdo->exec("TRUNCATE TABLE details_commande");
$pdo->exec("TRUNCATE TABLE historique_statuts");
$pdo->exec("TRUNCATE TABLE commandes");
$pdo->exec("TRUNCATE TABLE sandwichs");
$pdo->exec("TRUNCATE TABLE ingredients");

$pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

echo "Tables cleared.\n";

$sandwichs = json_decode("[{\"nom\": \"Komplis (Le Complet)\", \"prix\": 19.0, \"description\": \"Le l\u00e9gendaire complet de Ba M'Barek : Ma\u00e2kouda dor\u00e9e + \u0152uf + Fromage fondant + Lanchon \u00e9minc\u00e9\", \"badge\": \"\u2b50 BEST-SELLER\", \"categorie\": \"Ma3qouda\", \"image_url\": \"https://glovo.dhmedia.io/image/menus-glovo/products/28eff7a6930351245d3f044a5b645c295814746ee37daec0a8cc2318ead85184?t=W3sicmVzaXplIjp7Im1vZGUiOiJmaXQiLCJ3aWR0aCI6NTAwLCJoZWlnaHQiOjUwMH19XQ==\"}, {\"nom\": \"Complet Sans \u0152uf\", \"prix\": 18.0, \"description\": \"Double dose de Ma\u00e2kouda croustillante + Fromage fondu + Lanchon savoureux\", \"badge\": \"\ud83d\udd25 POPULAIRE\", \"categorie\": \"Ma3qouda\", \"image_url\": \"https://glovo.dhmedia.io/image/global-menu-service/GV_MA/vendor/616633/PRODUCT/f979a44b-e39e-4920-bfce-a83dd0a49c89.jpg?t=W3sicmVzaXplIjp7Im1vZGUiOiJmaXQiLCJ3aWR0aCI6NTAwLCJoZWlnaHQiOjUwMH19XQ==\"}, {\"nom\": \"Omelette Ma\u00e2kouda & Lanchon\", \"prix\": 17.0, \"description\": \"Omelette moelleuse pr\u00e9par\u00e9e sur plancha avec galettes de Ma\u00e2kouda et d\u00e9s de lanchon\", \"badge\": \"\ud83c\udf73 OMELETTE\", \"categorie\": \"Ma3qouda\", \"image_url\": \"https://glovo.dhmedia.io/image/menus-glovo/products/857ec95ab8134e772b0564d1de6087674bfdf5c6354f8b7db1c30d8fe09a1c8a?t=W3sicmVzaXplIjp7Im1vZGUiOiJmaXQiLCJ3aWR0aCI6NTAwLCJoZWlnaHQiOjUwMH19XQ==\"}, {\"nom\": \"Ma\u00e2kouda & Lanchon\", \"prix\": 15.0, \"description\": \"Galettes de Ma\u00e2kouda traditionnelles chaudes avec tranches de lanchon snack\u00e9\", \"badge\": \"TRADITION\", \"categorie\": \"Ma3qouda\", \"image_url\": \"https://glovo.dhmedia.io/image/global-menu-service/GV_MA/vendor/616633/product/884fb838-4f51-4b15-b018-b0daf87c283c.jpg?t=W3sicmVzaXplIjp7Im1vZGUiOiJmaXQiLCJ3aWR0aCI6NTAwLCJoZWlnaHQiOjUwMH19XQ==\"}, {\"nom\": \"Omelette Ma\u00e2kouda & Fromage\", \"prix\": 15.0, \"description\": \"Omelette minute garnie de Ma\u00e2kouda et de fromage La Vache qui Rit fondant\", \"badge\": \"\ud83e\uddc0 EXTRA CHEESE\", \"categorie\": \"Ma3qouda\", \"image_url\": \"https://glovo.dhmedia.io/image/menus-glovo/products/4197d49207dbd64cd2b6f3941e671d8a79de013a058710317c0421af1a204f78?t=W3sicmVzaXplIjp7Im1vZGUiOiJmaXQiLCJ3aWR0aCI6NTAwLCJoZWlnaHQiOjUwMH19XQ==\"}, {\"nom\": \"Ma\u00e2kouda & Fromage\", \"prix\": 14.0, \"description\": \"Le duo classique : Ma\u00e2kouda croustillante aux \u00e9pices de Mekn\u00e8s et fromage fondu\", \"badge\": \"GOURMAND\", \"categorie\": \"Ma3qouda\", \"image_url\": \"https://glovo.dhmedia.io/image/global-menu-service/GV_MA/vendor/616633/product/9ceb2140-fec9-4403-9a88-c116d3871c4d.jpg?t=W3sicmVzaXplIjp7Im1vZGUiOiJmaXQiLCJ3aWR0aCI6NTAwLCJoZWlnaHQiOjUwMH19XQ==\"}, {\"nom\": \"Omelette Ma\u00e2kouda\", \"prix\": 12.0, \"description\": \"Sandwich savoureux \u00e0 l'omelette fra\u00eeche et Ma\u00e2kouda bien dor\u00e9e\", \"badge\": \"L\u00c9GER\", \"categorie\": \"Ma3qouda\", \"image_url\": \"https://glovo.dhmedia.io/image/menus-glovo/products/6edf6b4f696e2aa1ebe9682d9a7bf035eecea5d8f28cf2839dbd50619e457cd0?t=W3sicmVzaXplIjp7Im1vZGUiOiJmaXQiLCJ3aWR0aCI6NTAwLCJoZWlnaHQiOjUwMH19XQ==\"}, {\"nom\": \"Ma\u00e2kouda 3adi (Classique)\", \"prix\": 10.0, \"description\": \"La recette originelle de Ba M'Barek depuis des d\u00e9cennies dans du bon pain croustillant\", \"badge\": \"\ud83e\udd54 AUTHENTIQUE\", \"categorie\": \"Ma3qouda\", \"image_url\": \"https://glovo.dhmedia.io/image/global-menu-service/GV_MA/vendor/616633/PRODUCT/98339b3f-c395-4d4b-a954-a9719c416e1a.png?t=W3sicmVzaXplIjp7Im1vZGUiOiJmaXQiLCJ3aWR0aCI6NTAwLCJoZWlnaHQiOjUwMH19XQ==\"}, {\"nom\": \"Portion M3i9dat (Seule)\", \"prix\": 2.0, \"description\": \"Galette de Ma\u00e2kouda artisanale individuelle, croustillante \u00e0 l'ext\u00e9rieur et fondante \u00e0 c\u0153ur\", \"badge\": \"SNACK\", \"categorie\": \"Ma3qouda\", \"image_url\": \"https://glovo.dhmedia.io/image/menus-glovo/products/8ff108ccced73380eecb1484038fb509fb855f06ae65046de7ac43f228aa40a7?t=W3sicmVzaXplIjp7Im1vZGUiOiJmaXQiLCJ3aWR0aCI6NTAwLCJoZWlnaHQiOjUwMH19XQ==\"}, {\"nom\": \"Combo Complet\", \"prix\": 29.0, \"description\": \"1 Sandwich Komplis + 1 Boisson fra\u00eeche au choix + 2 galettes de M3i9dat offertes\", \"badge\": \"\ud83d\udd25 TOP DEAL\", \"categorie\": \"Combos\", \"image_url\": \"https://glovo.dhmedia.io/image/global-menu-service/GV_MA/vendor/616633/product/32d82f11-160b-41fb-88bb-bd3794101d06.jpg?t=W3sicmVzaXplIjp7Im1vZGUiOiJmaXQiLCJ3aWR0aCI6NTAwLCJoZWlnaHQiOjUwMH19XQ==\"}, {\"nom\": \"Combo Lincheon\", \"prix\": 25.0, \"description\": \"1 Sandwich Ma\u00e2kouda Lanchon + 1 Boisson fra\u00eeche + 2 M3i9dat croustillantes\", \"badge\": \"MENU\", \"categorie\": \"Combos\", \"image_url\": \"https://glovo.dhmedia.io/image/global-menu-service/GV_MA/vendor/926989/product/40862126135/3870606d-4bcb-42c6-afc3-ecb39f4f456a.jpg?t=W3sicmVzaXplIjp7Im1vZGUiOiJmaXQiLCJ3aWR0aCI6NTAwLCJoZWlnaHQiOjUwMH19XQ==\"}, {\"nom\": \"Combo Fromage\", \"prix\": 24.0, \"description\": \"1 Sandwich Ma\u00e2kouda Fromage + 1 Boisson fra\u00eeche + 2 M3i9dat croustillantes\", \"badge\": \"MENU\", \"categorie\": \"Combos\", \"image_url\": \"https://glovo.dhmedia.io/image/global-menu-service/GV_MA/vendor/926989/product/40862126331/818dd562-f2fc-4e49-8150-a14abc790a41.jpg?t=W3sicmVzaXplIjp7Im1vZGUiOiJmaXQiLCJ3aWR0aCI6NTAwLCJoZWlnaHQiOjUwMH19XQ==\"}, {\"nom\": \"Combo Normal\", \"prix\": 21.0, \"description\": \"1 Sandwich Ma\u00e2kouda 3adi + 1 Boisson fra\u00eeche + 2 M3i9dat croustillantes\", \"badge\": \"\u00c9CO\", \"categorie\": \"Combos\", \"image_url\": \"https://glovo.dhmedia.io/image/global-menu-service/GV_MA/vendor/926989/product/40877264983/9c4d500f-61c4-4af6-a9e8-2a7e6ad20a54.jpg?t=W3sicmVzaXplIjp7Im1vZGUiOiJmaXQiLCJ3aWR0aCI6NTAwLCJoZWlnaHQiOjUwMH19XQ==\"}, {\"nom\": \"Duo Complet (Pour 2)\", \"prix\": 43.0, \"description\": \"1 Sandwich Complet + 1 Complet Sans \u0152uf + 1 Grande Boisson fra\u00eeche\", \"badge\": \"\ud83d\udc65 DUO\", \"categorie\": \"Duo\", \"image_url\": \"https://glovo.dhmedia.io/image/global-menu-service/GV_MA/vendor/616633/product/5f0cc299-7360-4442-9d2d-35b02ad705f9.jpg?t=W3sicmVzaXplIjp7Im1vZGUiOiJmaXQiLCJ3aWR0aCI6NTAwLCJoZWlnaHQiOjUwMH19XQ==\"}, {\"nom\": \"Duo Lincheon (Pour 2)\", \"prix\": 37.0, \"description\": \"1 Ma\u00e2kouda Lanchon + 1 Omelette Ma\u00e2kouda Lanchon + 1 Grande Boisson fra\u00eeche\", \"badge\": \"\ud83d\udc65 DUO\", \"categorie\": \"Duo\", \"image_url\": \"https://glovo.dhmedia.io/image/global-menu-service/GV_MA/vendor/616633/product/82a55f09-7bf1-450c-b926-f321d2c8d94a.jpg?t=W3sicmVzaXplIjp7Im1vZGUiOiJmaXQiLCJ3aWR0aCI6NTAwLCJoZWlnaHQiOjUwMH19XQ==\"}, {\"nom\": \"Duo Fromage (Pour 2)\", \"prix\": 35.0, \"description\": \"1 Ma\u00e2kouda Fromage + 1 Omelette Ma\u00e2kouda Fromage + 1 Grande Boisson fra\u00eeche\", \"badge\": \"\ud83d\udc65 DUO\", \"categorie\": \"Duo\", \"image_url\": \"https://glovo.dhmedia.io/image/global-menu-service/GV_MA/vendor/616633/product/a14a5ba3-e4c0-4087-902b-c593ee6969b9.jpg?t=W3sicmVzaXplIjp7Im1vZGUiOiJmaXQiLCJ3aWR0aCI6NTAwLCJoZWlnaHQiOjUwMH19XQ==\"}, {\"nom\": \"Duo Normal (Pour 2)\", \"prix\": 30.0, \"description\": \"1 Ma\u00e2kouda 3adi + 1 Omelette Ma\u00e2kouda + 1 Grande Boisson fra\u00eeche\", \"badge\": \"\ud83d\udc65 DUO\", \"categorie\": \"Duo\", \"image_url\": \"https://glovo.dhmedia.io/image/global-menu-service/GV_MA/vendor/616633/product/631c9fa5-0daf-42d9-b8aa-8e01a71a2ec6.jpg?t=W3sicmVzaXplIjp7Im1vZGUiOiJmaXQiLCJ3aWR0aCI6NTAwLCJoZWlnaHQiOjUwMH19XQ==\"}, {\"nom\": \"Pulpy Orange\", \"prix\": 9.0, \"description\": \"Jus d'orange Pulpy avec vraie pulpe de fruit rafra\u00eechissante\", \"badge\": \"JUS\", \"categorie\": \"Boissons\", \"image_url\": \"https://glovo.dhmedia.io/image/global-menu-service/GV_MA/vendor/616633/PRODUCT/f8861a1f-4f42-4913-8ae4-e2d86d47e402.png?t=W3sicmVzaXplIjp7Im1vZGUiOiJmaXQiLCJ3aWR0aCI6NTAwLCJoZWlnaHQiOjUwMH19XQ==\"}, {\"nom\": \"Coca-Cola 33cl\", \"prix\": 7.0, \"description\": \"Canette Coca-Cola Original bien fra\u00eeche\", \"badge\": \"SODA\", \"categorie\": \"Boissons\", \"image_url\": \"https://glovo.dhmedia.io/image/global-menu-service/GV_MA/vendor/926989/product/40713631524/29968bc9-604a-4397-8854-0795ad483002.jpg?t=W3sicmVzaXplIjp7Im1vZGUiOiJmaXQiLCJ3aWR0aCI6NTAwLCJoZWlnaHQiOjUwMH19XQ==\"}, {\"nom\": \"Hawaii Tropical 33cl\", \"prix\": 7.0, \"description\": \"La saveur tropicale marocaine culte bien glac\u00e9e\", \"badge\": \"SODA\", \"categorie\": \"Boissons\", \"image_url\": \"https://glovo.dhmedia.io/image/global-menu-service/GV_MA/vendor/616633/product/40713821960/459215f6-66d9-444d-9653-e797facd0ad9.jpg?t=W3sicmVzaXplIjp7Im1vZGUiOiJmaXQiLCJ3aWR0aCI6NTAwLCJoZWlnaHQiOjUwMH19XQ==\"}, {\"nom\": \"Eau Min\u00e9rale 50cl\", \"prix\": 5.0, \"description\": \"Bouteille d'eau min\u00e9rale naturelle pure\", \"badge\": \"EAU\", \"categorie\": \"Boissons\", \"image_url\": \"https://glovo.dhmedia.io/image/menus-glovo/products/c144bd809d69d0aa7abc37d8e995003fb299c5e0b99c5de69318a58bafa206b1?t=W3sicmVzaXplIjp7Im1vZGUiOiJmaXQiLCJ3aWR0aCI6NTAwLCJoZWlnaHQiOjUwMH19XQ==\"}]", true);

$stmtSandwich = $pdo->prepare(
    "INSERT INTO sandwichs (nom, prix, description, disponible, image_url, badge, categorie)
     VALUES (:nom, :prix, :description, 1, :image_url, :badge, :categorie)"
);

foreach ($sandwichs as $s) {
    $stmtSandwich->execute([
        ':nom' => $s['nom'],
        ':prix' => $s['prix'],
        ':description' => $s['description'],
        ':image_url' => $s['image_url'],
        ':badge' => $s['badge'],
        ':categorie' => $s['categorie']
    ]);
    echo "Inserted Sandwich: " . $s['nom'] . " (" . $s['prix'] . " DH)\n";
}

$ingredients = json_decode("[{\"nom\": \"Sauce Harissa Maison\", \"prix_supplement\": 0.0}, {\"nom\": \"Sauce Tomate Cumin\", \"prix_supplement\": 0.0}, {\"nom\": \"Sauce Mayonnaise\", \"prix_supplement\": 0.0}, {\"nom\": \"Sauce Blanche Ail\", \"prix_supplement\": 0.0}, {\"nom\": \"Cumin & Sel Tradition\", \"prix_supplement\": 0.0}, {\"nom\": \"Olives Vertes \u00c9minc\u00e9es\", \"prix_supplement\": 0.0}, {\"nom\": \"Extra Fromage Fondu\", \"prix_supplement\": 2.0}, {\"nom\": \"Extra \u0152uf sur Plancha\", \"prix_supplement\": 2.0}, {\"nom\": \"Extra Lanchon Snack\u00e9\", \"prix_supplement\": 3.0}, {\"nom\": \"Extra Galette M3i9da\", \"prix_supplement\": 2.0}, {\"nom\": \"Double Fromage Cheddar\", \"prix_supplement\": 4.0}]", true);

$stmtIng = $pdo->prepare(
    "INSERT INTO ingredients (nom, prix_supplement)
     VALUES (:nom, :prix)"
);

foreach ($ingredients as $ing) {
    $stmtIng->execute([
        ':nom' => $ing['nom'],
        ':prix' => $ing['prix_supplement']
    ]);
    echo "Inserted Ingredient: " . $ing['nom'] . " (" . $ing['prix_supplement'] . " DH)\n";
}

echo "\n=== MIGRATION COMPLETE: M3i9dat Ba M'Barek Menu Active! ===\n";
