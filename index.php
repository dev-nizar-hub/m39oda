<?php
/**
 * M3i9dat Ba M'Barek - Terminal Caisse POS
 * Design : Boîtes gourmandes tactiles & artisanales (100% Human-crafted, No AI clichés)
 */

declare(strict_types=1);

require_once __DIR__ . '/config/auth.php';
$currentUser = requireRole(['CAISSIER', 'ADMIN', 'SUPERADMIN']);

/** @var PDO $pdo */
$pdo = require_once __DIR__ . '/config/db.php';

// Resolve active tenant restaurant (Subdomains, Params, Sessions)
require_once __DIR__ . '/config/tenant.php';
$isSuperAdmin = ($currentUser['role'] === 'SUPERADMIN');

if (!$isSuperAdmin && !empty($currentUser['id_restaurant'])) {
    $idRestaurant = (int)$currentUser['id_restaurant'];
    $_SESSION['restaurant_id'] = $idRestaurant;
    $stmtResto = $pdo->prepare("SELECT * FROM restaurants WHERE id_restaurant = ?");
    $stmtResto->execute([$idRestaurant]);
    $restaurant = $stmtResto->fetch(PDO::FETCH_ASSOC) ?: ['nom' => "Restaurant", 'ville' => 'Maroc', 'devise' => 'DH'];
} else {
    $restaurant = resolveTenant($pdo);
    $idRestaurant = (int)$restaurant['id_restaurant'];
}

// Fetch all restaurants if superadmin
$allRestaurants = [];
if ($isSuperAdmin) {
    $allRestaurants = $pdo->query("SELECT id_restaurant, nom, slug, ville FROM restaurants ORDER BY id_restaurant ASC")->fetchAll();
}

// Fetch active cashiers for operator select (scoped to this restaurant)
$caissiers = [];
try {
    $stmt = $pdo->prepare("SELECT id_utilisateur, nom FROM utilisateurs WHERE (id_restaurant = ? OR role = 'SUPERADMIN') AND role IN ('CAISSIER', 'ADMIN', 'SUPERADMIN') AND actif = 1");
    $stmt->execute([$idRestaurant]);
    $caissiers = $stmt->fetchAll();
    if (empty($caissiers)) {
        $caissiers = [['id_utilisateur' => $currentUser['id'], 'nom' => $currentUser['nom']]];
    }
} catch (Exception $e) {
    $caissiers = [['id_utilisateur' => $currentUser['id'], 'nom' => $currentUser['nom']]];
}

// Fond de Caisse (Opening Cash Float)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_fond_caisse') {
    $fond = (float)($_POST['montant_fond'] ?? 0);
    $_SESSION['fond_caisse_' . $idRestaurant] = $fond;
    header("Location: index.php?msg=fond_saved");
    exit;
}
$fondCaisse = (float)($_SESSION['fond_caisse_' . $idRestaurant] ?? 200.00);

// Today's cash total
$stmtTodayCash = $pdo->prepare("SELECT COALESCE(SUM(montant_total), 0) FROM commandes WHERE id_restaurant = ? AND DATE(date_commande) = CURDATE() AND statut = 'SERVIE'");
$stmtTodayCash->execute([$idRestaurant]);
$todayCashEarned = (float)$stmtTodayCash->fetchColumn();
$theoriqueDrawer = $fondCaisse + $todayCashEarned;
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="theme-color" content="#c2410c">
    <title><?= htmlspecialchars($restaurant['nom']) ?> — Terminal Caisse</title>
    <link rel="icon" type="image/svg+xml" href="assets/img/favicon.svg">
    <link rel="alternate icon" href="favicon.ico">
    <link rel="stylesheet" href="assets/css/pos.css?v=<?= time() ?>">
</head>
<body>

    <!-- Header Navigation -->
    <header class="pos-header">
        <div class="brand-section">
            <div class="brand-badge">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M4 11h16a3 3 0 0 0 0-6H4a3 3 0 0 0 0 6z"/>
                    <path d="M4 15h16a3 3 0 0 1 0 6H4a3 3 0 0 1 0-6z"/>
                    <line x1="4" y1="13" x2="20" y2="13"/>
                </svg>
            </div>
            <div>
                <h1 class="brand-title"><?= htmlspecialchars($restaurant['nom']) ?></h1>
                <div class="brand-subtitle">Spécialités Traditionnelles • <?= htmlspecialchars($restaurant['ville'] ?? 'Maroc') ?></div>
            </div>
        </div>

        <div class="header-right">
            <?php if ($isSuperAdmin && !empty($allRestaurants)): ?>
                <!-- Custom Tenant Dropdown -->
                <div class="custom-dropdown-container" id="tenantDropdownContainer">
                    <button type="button" class="tenant-switcher-pill" onclick="toggleCustomDropdown('tenantDropdownMenu', event)" title="Sélecteur d'établissement multi-tenant">
                        <span class="tenant-switcher-tag">TENANT</span>
                        <span class="tenant-current-name"><?= htmlspecialchars($restaurant['nom']) ?></span>
                        <svg class="custom-select-arrow" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg>
                    </button>

                    <div class="custom-dropdown-menu" id="tenantDropdownMenu">
                        <div class="dropdown-header-title">
                            <span>Établissements Tenants</span>
                            <span class="dropdown-pill-badge"><?= count($allRestaurants) ?> restos</span>
                        </div>
                        <div class="dropdown-list">
                            <?php foreach ($allRestaurants as $r): ?>
                                <?php 
                                    $isThisRest = ($r['id_restaurant'] === $idRestaurant);
                                    $rInitials = strtoupper(substr(preg_replace('/[^a-zA-Z0-9]/', '', $r['nom']), 0, 2)) ?: 'RT';
                                ?>
                                <a href="index.php?switch_restaurant=<?= $r['id_restaurant'] ?>" 
                                   class="dropdown-item <?= $isThisRest ? 'is-selected' : '' ?>">
                                    <div class="dropdown-item-avatar" style="background:#FDF2F8; color:#DB2777; border-color:#FBCFE8;"><?= $rInitials ?></div>
                                    <div class="dropdown-item-info">
                                        <div class="dropdown-item-name"><?= htmlspecialchars($r['nom']) ?></div>
                                        <div class="dropdown-item-sub"><?= htmlspecialchars($r['ville'] ?? 'Maroc') ?> &bull; Actif</div>
                                    </div>
                                    <?php if ($isThisRest): ?>
                                        <div class="dropdown-item-check">
                                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#DB2777" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                                        </div>
                                    <?php endif; ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php
                // Resolve active operator name
                $selectedCaissierId = (int)$currentUser['id'];
                $selectedCaissierNom = $currentUser['nom'];
                foreach ($caissiers as $c) {
                    if ((int)$c['id_utilisateur'] === $selectedCaissierId) {
                        $selectedCaissierNom = $c['nom'];
                        break;
                    }
                }
            ?>
            <!-- Custom Operator Dropdown -->
            <div class="custom-dropdown-container" id="operatorDropdownContainer">
                <button type="button" class="operator-pill" id="operatorPillBtn" onclick="toggleCustomDropdown('operatorDropdownMenu', event)" title="Opérateur de caisse actif">
                    <div class="operator-avatar">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/>
                            <circle cx="12" cy="7" r="4"/>
                        </svg>
                        <span class="online-indicator"></span>
                    </div>
                    <span class="operator-current-name" id="currentOperatorName"><?= htmlspecialchars($selectedCaissierNom) ?></span>
                    <svg class="custom-select-arrow" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg>
                </button>

                <!-- Hidden input for backwards-compatibility with pos.js payload submission -->
                <input type="hidden" id="selectOperator" value="<?= (int)$selectedCaissierId ?>">

                <!-- Floating Custom Operator Menu -->
                <div class="custom-dropdown-menu" id="operatorDropdownMenu">
                    <div class="dropdown-header-title">
                        <span>Changer d'Opérateur</span>
                        <span class="dropdown-pill-badge"><?= count($caissiers) ?> actifs</span>
                    </div>
                    <div class="dropdown-list">
                        <?php foreach ($caissiers as $c): ?>
                            <?php 
                                $isThisSelected = ((int)$c['id_utilisateur'] === $selectedCaissierId);
                                $cInitials = strtoupper(substr(preg_replace('/[^a-zA-Z0-9]/', '', $c['nom']), 0, 2)) ?: 'OP';
                            ?>
                            <div class="dropdown-item <?= $isThisSelected ? 'is-selected' : '' ?>" 
                                 data-id="<?= (int)$c['id_utilisateur'] ?>"
                                 data-name="<?= htmlspecialchars($c['nom']) ?>"
                                 onclick="selectOperatorOption(<?= (int)$c['id_utilisateur'] ?>, '<?= htmlspecialchars(addslashes($c['nom'])) ?>')">
                                <div class="dropdown-item-avatar"><?= $cInitials ?></div>
                                <div class="dropdown-item-info">
                                    <div class="dropdown-item-name"><?= htmlspecialchars($c['nom']) ?></div>
                                    <div class="dropdown-item-sub">Session Caisse active</div>
                                </div>
                                <div class="dropdown-item-check">
                                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#10B981" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <div class="live-clock-badge">
                <span class="live-pulse-dot"></span>
                <span id="liveClockBadge">--:--:--</span>
            </div>

            <?php if ($isSuperAdmin): ?>
                <a href="superadmin.php" class="btn-header-link btn-header-saas" title="Tour de Contrôle SaaS">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
                    <span>Tour SaaS</span>
                </a>
            <?php endif; ?>

            <button type="button" class="btn-header-link btn-header-fond" onclick="openFondModal()" title="Gérer le Fond de Caisse">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="4" width="20" height="16" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/><circle cx="12" cy="15" r="2"/></svg>
                <span>Fond : <strong><?= number_format($fondCaisse, 0) ?> DH</strong></span>
            </button>

            <a href="menu_admin.php" class="btn-header-link" title="Studio Menu & Recettes">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
                <span>Studio Menu</span>
            </a>

            <a href="commandes.php" class="btn-header-link" title="Consulter l'historique des commandes">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                    <polyline points="14 2 14 8 20 8"/>
                    <line x1="16" y1="13" x2="8" y2="13"/>
                    <line x1="16" y1="17" x2="8" y2="17"/>
                </svg>
                <span>Historique</span>
            </a>

            <button type="button" class="btn-header-link" id="btnToggleFullscreen" onclick="toggleTabletFullscreen()" title="Basculer en Plein Écran (Mode Kiosque Tablette)">
                <svg id="iconEnterFullscreen" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M8 3H5a2 2 0 0 0-2 2v3m18 0V5a2 2 0 0 0-2-2h-3m0 18h3a2 2 0 0 0 2-2v-3M3 16v3a2 2 0 0 0 2 2h3"/>
                </svg>
                <svg id="iconExitFullscreen" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" style="display:none;">
                    <path d="M8 3v3a2 2 0 0 1-2 2H3m18 0h-3a2 2 0 0 1-2-2V3m0 18v-3a2 2 0 0 1 2-2h3M3 16h3a2 2 0 0 1 2 2v3"/>
                </svg>
                <span id="labelFullscreen">Plein Écran</span>
            </button>

            <a href="login.php?action=logout" class="btn-header-link btn-header-logout" title="Fermer la session">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
                    <polyline points="16 17 21 12 16 7"/>
                    <line x1="21" y1="12" x2="9" y2="12"/>
                </svg>
                <span>Quitter</span>
            </a>
        </div>
    </header>

    <!-- Main Workspace -->
    <main class="pos-workspace">
        
        <!-- Left Panel: Sandwich Catalog -->
        <section class="catalog-panel">
            
            <!-- Toolbar: Category chips & Search input -->
            <div class="catalog-toolbar">
                <nav class="category-nav" id="categoryNav">
                    <!-- Dynamic Category Chips injected by pos.js -->
                </nav>

                <div class="search-box-wrap">
                    <span class="search-icon-inside">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="11" cy="11" r="8"/>
                            <line x1="21" y1="21" x2="16.65" y2="16.65"/>
                        </svg>
                    </span>
                    <input type="text" id="searchField" class="search-field" placeholder="Rechercher un sandwich..." autocomplete="off">
                </div>
            </div>

            <!-- Scrollable Products Grid Area -->
            <div class="catalog-grid-scroll">
                <div class="sandwich-boxes-grid" id="sandwichBoxesGrid">
                    <!-- Food Boxes injected here by pos.js -->
                </div>
            </div>

        </section>

        <!-- Right Panel: Cart & Ticket Slip -->
        <aside class="pos-cart-panel">
            
            <div class="cart-top-bar">
                <div class="cart-heading">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/>
                        <line x1="3" y1="6" x2="21" y2="6"/>
                        <path d="M16 10a4 4 0 0 1-8 0"/>
                    </svg>
                    <span>Bon de Commande</span>
                    <span class="cart-count-badge" id="cartCountBadge">0</span>
                </div>
                <button type="button" class="btn-reset-cart" id="btnResetCart" title="Vider le panier en cours">
                    Vider
                </button>
            </div>

            <!-- Scrollable Cart Line Items -->
            <div class="cart-items-scroll" id="cartItemsScroll">
                <div class="empty-cart-view" id="emptyCartView">
                    <div class="empty-cart-icon" style="color:var(--text-muted); opacity:0.4;">
                        <svg width="52" height="52" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="12" cy="12" r="10"/>
                            <path d="M8 12h8"/>
                            <path d="M12 8v8"/>
                        </svg>
                    </div>
                    <div style="font-weight: 800; font-size:15px; color:var(--charcoal);">Aucun sandwich sélectionné</div>
                    <div style="font-size: 12.5px; line-height: 1.4;">Cliquez sur un sandwich pour choisir la recette ou personnaliser les ingrédients.</div>
                </div>
            </div>

            <!-- Order Total & Checkout Button -->
            <div class="cart-checkout-footer">
                <div class="bill-row">
                    <span>Nombre d'articles :</span>
                    <span id="totalItemsCount">0 article(s)</span>
                </div>
                <div class="bill-row hide-on-tablet">
                    <span>Devise de règlement :</span>
                    <span>Dirham Marocain (MAD)</span>
                </div>
                <div class="bill-row grand-total">
                    <span>TOTAL À PAYER :</span>
                    <span class="grand-total-val" id="grandTotalDisplay">0.00 DH</span>
                </div>

                <button type="button" class="btn-validate-order" id="btnValidateOrder" disabled>
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="20 6 9 17 4 12"/>
                    </svg>
                    <span>VALIDER LA COMMANDE</span>
                </button>
            </div>

        </aside>

    </main>

    <!-- Modal 1: Atelier Customizer ("L'Atelier Sandwich") -->
    <div class="modal-backdrop" id="modalCustomizer">
        <div class="modal-frame">
            
            <!-- Hero bar with sandwich photo & title -->
            <div class="modal-hero-bar">
                <img src="" id="modalItemThumb" class="modal-thumb" alt="Sandwich">
                <div class="modal-meta">
                    <div class="modal-item-title" id="modalItemTitle">Nom du Sandwich</div>
                    <div class="modal-item-baseprice" id="modalItemBasePrice">Prix de base : 0.00 DH</div>
                </div>
                <button type="button" class="modal-close-icon" id="btnCloseCustomizer">&times;</button>
            </div>

            <!-- Scrollable Ingredients & Preferences Choice -->
            <div class="modal-scroll-body">
                
                <!-- Quantity Stepper -->
                <div style="display:flex; justify-content:space-between; align-items:center; background:#f5f5f4; border:1px solid var(--border-color); padding:10px 16px; border-radius:12px;">
                    <span style="font-weight:800; font-size:14px; color:var(--charcoal);">Quantité :</span>
                    <div style="display:flex; align-items:center; gap:10px;">
                        <button type="button" class="btn-step" id="btnMinusQty" style="width:32px; height:32px; font-size:16px;">-</button>
                        <span id="modalQtyValue" style="font-size:16px; font-weight:900; min-width:24px; text-align:center;">1</span>
                        <button type="button" class="btn-step" id="btnPlusQty" style="width:32px; height:32px; font-size:16px;">+</button>
                    </div>
                </div>

                <!-- Section A: Retraits (Sans...) -->
                <div>
                    <div class="atelier-section-heading">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="12" cy="12" r="10"/>
                            <line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/>
                        </svg>
                        Ingrédients à Retirer (Omission)
                    </div>
                    <div class="ingredients-tiles-grid" id="gridRetraitsTiles"></div>
                </div>

                <!-- Section B: Sauces Gratuites -->
                <div>
                    <div class="atelier-section-heading">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#0284c7" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M12 2.69l5.66 5.66a8 8 0 1 1-11.31 0z"/>
                        </svg>
                        Choix des Sauces (Gratuit)
                    </div>
                    <div class="ingredients-tiles-grid" id="gridSaucesTiles"></div>
                </div>

                <!-- Section C: Extras & Suppléments Payants -->
                <div>
                    <div class="atelier-section-heading">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#15803d" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="12" cy="12" r="10"/>
                            <line x1="12" y1="8" x2="12" y2="16"/>
                            <line x1="8" y1="12" x2="16" y2="12"/>
                        </svg>
                        Suppléments &amp; Extras Gourmands
                    </div>
                    <div class="ingredients-tiles-grid" id="gridSupplementsTiles"></div>
                </div>

                <!-- Section D: Consignes Spéciales -->
                <div>
                    <div class="atelier-section-heading">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#d97706" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
                            <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
                        </svg>
                        Consigne Spéciale de Préparation
                    </div>
                    <input type="text" id="modalSpecialNote" class="search-field" style="border-radius:10px;" placeholder="Ex: Pain bien doré, sans sel, emballage à part..." maxlength="120">
                </div>

            </div>

            <!-- Modal Bottom Bar -->
            <div class="modal-bottom-bar">
                <div class="modal-price-indicator">
                    Sous-total : <span class="modal-live-total" id="modalLiveTotal">0.00 DH</span>
                </div>
                <button type="button" class="btn-add-to-cart-confirm" id="btnConfirmAddToCart">
                    Ajouter au Bon ➔
                </button>
            </div>

        </div>
    </div>

    <!-- Modal 2: Dual Tickets (Ticket Client + Bon Cuisine RG04) -->
    <div class="modal-backdrop" id="modalTickets">
        <div class="modal-frame" style="max-width: 680px;">
            
            <div class="modal-hero-bar" style="background:#f0fdf4; border-bottom:1.5px solid #bbf7d0;">
                <div style="color:var(--olive);">
                    <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                        <polyline points="22 4 12 14.01 9 11.01"/>
                    </svg>
                </div>
                <div class="modal-meta">
                    <div class="modal-item-title" style="color:var(--olive);">Commande Enregistrée avec Succès !</div>
                    <div class="modal-item-baseprice">Génération Conforme RG04 : Ticket Client & Bon Cuisine</div>
                </div>
                <button type="button" class="modal-close-icon" id="btnCloseTicketsModal">&times;</button>
            </div>

            <div class="modal-scroll-body" style="background:#f5f5f4; padding:18px;">
                <div class="dual-tickets-wrap" id="printableTicketsContainer">
                    
                    <!-- Ticket Client -->
                    <div class="thermal-slip" id="ticketClientBox">
                        <!-- Injected by pos.js -->
                    </div>

                    <!-- Ticket Cuisine -->
                    <div class="thermal-slip kitchen" id="ticketKitchenBox">
                        <!-- Injected by pos.js -->
                    </div>

                </div>
            </div>

            <div class="modal-bottom-bar" style="background:#ffffff;">
                <button type="button" class="btn-box-quick" id="btnNewOrderFromTickets" style="padding:10px 18px;">
                    + Nouvelle Commande
                </button>
                <button type="button" class="btn-box-customize" id="btnPrintDualTickets" style="background:var(--charcoal); padding:10px 20px;">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:middle; margin-right:6px;"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>Imprimer les Deux Tickets
                </button>
            </div>

        </div>
    </div>

    <!-- Modal 3: Encaissement & Rendu Monnaie (Cash Register Calculator) -->
    <div class="modal-backdrop" id="modalPaiement">
        <div class="modal-frame" style="max-width: 480px;">
            <div class="modal-hero-bar" style="background:#fff7ed; border-bottom:1.5px solid #fed7aa;">
                <div style="color:var(--terracotta);">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="2" y="4" width="20" height="16" rx="2"/>
                        <line x1="2" y1="10" x2="22" y2="10"/>
                    </svg>
                </div>
                <div class="modal-meta">
                    <div class="modal-item-title" style="color:var(--charcoal);">Règlement &amp; Rendu Monnaie</div>
                    <div class="modal-item-baseprice">Saisie rapide espèces et calcul de caisse</div>
                </div>
                <button type="button" class="modal-close-icon" id="btnClosePaiementModal">&times;</button>
            </div>

            <div class="modal-scroll-body" style="padding:24px;">
                <div style="background:#f8fafc; border:1.5px solid #e2e8f0; border-radius:14px; padding:18px; text-align:center; margin-bottom:20px;">
                    <div style="font-size:12px; font-weight:800; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.5px;">Montant Total Commande</div>
                    <div style="font-size:36px; font-weight:900; color:var(--charcoal); margin-top:4px;" id="payModalTotal">0.00 DH</div>
                </div>

                <div style="margin-bottom:16px;">
                    <label style="display:block; font-size:12px; font-weight:800; text-transform:uppercase; color:#444; margin-bottom:6px;">Espèces Reçues du Client (DH)</label>
                    <input type="number" id="inputCashReceived" step="1" min="0" placeholder="0.00" class="search-field" style="font-size:22px; font-weight:900; text-align:center; padding:12px; border-radius:12px;" autofocus>
                </div>

                <!-- Quick Banknote Pills -->
                <div style="display:grid; grid-template-columns: repeat(4, 1fr); gap:8px; margin-bottom:20px;">
                    <button type="button" class="cat-chip" style="justify-content:center; font-weight:800;" onclick="window.setQuickCash('exact')">Exact</button>
                    <button type="button" class="cat-chip" style="justify-content:center; font-weight:800;" onclick="window.setQuickCash(20)">20 DH</button>
                    <button type="button" class="cat-chip" style="justify-content:center; font-weight:800;" onclick="window.setQuickCash(50)">50 DH</button>
                    <button type="button" class="cat-chip" style="justify-content:center; font-weight:800;" onclick="window.setQuickCash(100)">100 DH</button>
                    <button type="button" class="cat-chip" style="justify-content:center; font-weight:800; grid-column:span 2;" onclick="window.setQuickCash(200)">Billet 200 DH</button>
                    <button type="button" class="cat-chip" style="justify-content:center; font-weight:800; grid-column:span 2;" onclick="window.setQuickCash(300)">300 DH</button>
                </div>

                <!-- Change Calculation Result Box -->
                <div id="boxChangeResult" style="background:#ecfdf5; border:1.5px solid #a7f3d0; border-radius:14px; padding:16px; text-align:center;">
                    <div style="font-size:11.5px; font-weight:800; color:#047857; text-transform:uppercase;">Monnaie à Rendre au Client</div>
                    <div style="font-size:32px; font-weight:900; color:#065f46; margin-top:2px;" id="valChangeToReturn">0.00 DH</div>
                </div>
            </div>

            <div class="modal-bottom-bar" style="background:#ffffff; padding:16px 24px;">
                <button type="button" class="btn-box-quick" id="btnCancelPaiement" style="padding:12px 18px;">
                    Annuler (Échap)
                </button>
                <button type="button" class="btn-add-to-cart-confirm" id="btnConfirmPaiementAndPrint" style="padding:12px 24px; font-size:14px;">
                    Encaisser &amp; Imprimer ➔
                </button>
            </div>
        </div>
    </div>

    <!-- Modal 4: Fond de Caisse (Opening Cash Float) -->
    <div class="modal-backdrop" id="modalFondCaisse" style="display:none; align-items:center; justify-content:center; z-index:1200;">
        <div class="modal-frame" style="max-width: 420px; background:#ffffff; border-radius:14px; overflow:hidden; box-shadow:0 25px 50px -12px rgba(0,0,0,0.25);">
            <div class="modal-hero-bar" style="background:#f0fdf4; border-bottom:1px solid #bbf7d0; padding:18px 22px; display:flex; justify-content:space-between; align-items:center;">
                <div>
                    <div style="font-size:16px; font-weight:800; color:#15803d;">Fond de Caisse &amp; Tiroir</div>
                    <div style="font-size:12px; color:#64748b; margin-top:2px;">Contrôle de monnaie au démarrage du shift</div>
                </div>
                <button type="button" onclick="closeFondModal()" style="background:none; border:none; font-size:24px; cursor:pointer; color:#64748b;">&times;</button>
            </div>
            <form method="POST" style="padding:22px;">
                <input type="hidden" name="action" value="save_fond_caisse">
                <div style="margin-bottom:16px;">
                    <label style="font-size:12.5px; font-weight:700; color:#1e293b; display:block; margin-bottom:6px;">Monnaie de départ (Fond initial en DH) *</label>
                    <input type="number" step="10" min="0" name="montant_fond" value="<?= $fondCaisse ?>" required style="width:100%; height:44px; border:1px solid #cbd5e1; border-radius:8px; padding:0 12px; font-size:17px; font-weight:800; color:#0f172a; box-sizing:border-box;">
                </div>
                <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:14px; margin-bottom:20px; font-size:13px; color:#475569;">
                    <div style="display:flex; justify-content:space-between; margin-bottom:6px;">
                        <span>+ Encaissé aujourd'hui :</span>
                        <strong style="color:#0f172a;"><?= number_format($todayCashEarned, 2) ?> DH</strong>
                    </div>
                    <div style="display:flex; justify-content:space-between; font-weight:800; color:#15803d; border-top:1px dashed #cbd5e1; padding-top:6px;">
                        <span>= Total Théorique Tiroir :</span>
                        <span><?= number_format($theoriqueDrawer, 2) ?> DH</span>
                    </div>
                </div>
                <button type="submit" style="width:100%; height:44px; background:#15803d; color:#ffffff; border:none; border-radius:8px; font-size:14px; font-weight:700; cursor:pointer;">
                    Enregistrer le Fond de Caisse
                </button>
            </form>
        </div>
    </div>

    <!-- Tenant Context for JavaScript -->
    <script>
        window.TENANT_RESTAURANT_ID = <?= $idRestaurant ?>;
        function openFondModal() {
            const m = document.getElementById('modalFondCaisse');
            if (m) { m.style.display = 'flex'; }
        }
        function closeFondModal() {
            const m = document.getElementById('modalFondCaisse');
            if (m) { m.style.display = 'none'; }
        }

        // Custom Dropdown Controller
        function toggleCustomDropdown(menuId, event) {
            if (event) event.stopPropagation();
            const targetMenu = document.getElementById(menuId);
            if (!targetMenu) return;
            const isCurrentlyShown = targetMenu.classList.contains('show');
            document.querySelectorAll('.custom-dropdown-menu').forEach(m => m.classList.remove('show'));
            if (!isCurrentlyShown) {
                targetMenu.classList.add('show');
            }
        }

        function selectOperatorOption(id, name) {
            const hiddenInput = document.getElementById('selectOperator');
            if (hiddenInput) hiddenInput.value = id;
            const nameEl = document.getElementById('currentOperatorName');
            if (nameEl) nameEl.textContent = name;

            const items = document.querySelectorAll('#operatorDropdownMenu .dropdown-item');
            items.forEach(it => {
                if (parseInt(it.getAttribute('data-id'), 10) === id) {
                    it.classList.add('is-selected');
                } else {
                    it.classList.remove('is-selected');
                }
            });

            const menu = document.getElementById('operatorDropdownMenu');
            if (menu) menu.classList.remove('show');
        }

        document.addEventListener('click', function(e) {
            if (!e.target.closest('.custom-dropdown-container')) {
                document.querySelectorAll('.custom-dropdown-menu').forEach(m => m.classList.remove('show'));
            }
        });

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                document.querySelectorAll('.custom-dropdown-menu').forEach(m => m.classList.remove('show'));
            }
        });
    </script>

    <!-- JavaScript Controller -->
    <script src="assets/js/pos.js?v=<?= time() ?>"></script>
</body>
</html>
