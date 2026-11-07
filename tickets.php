<?php
/**
 * M39ODA - Standalone Dual-Ticket Printer & Reprint View
 * Thermique papier : Ticket Client + Bon de Cuisine (RG04 / F03)
 */

declare(strict_types=1);

// SECURITY: Requires authenticated session
require_once __DIR__ . '/config/auth.php';
requireRole(['CAISSIER', 'ADMIN', 'SUPERADMIN']);

/** @var PDO $pdo */
$pdo = require_once __DIR__ . '/config/db.php';

require_once __DIR__ . '/config/tenant.php';
$restaurant = resolveTenant($pdo);
$idRestaurant = (int)($restaurant['id_restaurant'] ?? 1);

$isZReport = (isset($_GET['report']) && strtolower($_GET['report']) === 'z');

if ($isZReport) {
    $dateJour = $_GET['date'] ?? date('Y-m-d');
    $stmtStats = $pdo->prepare(
        "SELECT 
            COUNT(id_commande) AS total_tickets,
            COALESCE(SUM(montant_total), 0) AS ca_total,
            COALESCE(SUM(CASE WHEN statut = 'SERVIE' THEN montant_total ELSE 0 END), 0) AS ca_encaisse,
            COALESCE(SUM(CASE WHEN statut = 'EN_PREPARATION' THEN montant_total ELSE 0 END), 0) AS ca_en_cours,
            MIN(numero_commande) AS premier_ticket,
            MAX(numero_commande) AS dernier_ticket
         FROM commandes 
         WHERE id_restaurant = :rid AND DATE(date_commande) = :date"
    );
    $stmtStats->execute([':rid' => $idRestaurant, ':date' => $dateJour]);
    $zStats = $stmtStats->fetch() ?: [];

    $stmtProducts = $pdo->prepare(
        "SELECT s.nom, SUM(d.quantite) AS qte_vendue, SUM(d.prix_unitaire * d.quantite) AS ca_produit
         FROM details_commande d
         JOIN sandwichs s ON d.id_sandwich = s.id_sandwich
         JOIN commandes c ON d.id_commande = c.id_commande
         WHERE c.id_restaurant = :rid AND DATE(c.date_commande) = :date
         GROUP BY s.id_sandwich
         ORDER BY qte_vendue DESC, ca_produit DESC"
    );
    $stmtProducts->execute([':rid' => $idRestaurant, ':date' => $dateJour]);
    $zProducts = $stmtProducts->fetchAll();

    $zTotalTickets = (int)($zStats['total_tickets'] ?? 0);
    $zCaTotal      = (float)($zStats['ca_total'] ?? 0.0);
    $zCaEncaisse   = (float)($zStats['ca_encaisse'] ?? 0.0);
    $zPanierMoyen  = $zTotalTickets > 0 ? round($zCaTotal / $zTotalTickets, 2) : 0.0;
    $zHash         = strtoupper(substr(hash('sha256', $dateJour . $zCaTotal . $zTotalTickets . $restaurant['nom']), 0, 16));

    $commande = null;
    $details  = [];
    $dateFmt  = date('d/m/Y', strtotime($dateJour));
    $heureFmt = date('H:i:s');
} else {
    $id     = isset($_GET['id'])     ? (int)$_GET['id']               : 0;
    $numero = isset($_GET['numero']) ? trim((string)$_GET['numero'])   : '';

    if ($id <= 0 && empty($numero)) {
        http_response_code(400);
        die('Paramètre manquant : ?id=X, ?numero=X ou ?report=z requis.');
    }

    // 1. Fetch Order
    $params = [];
    if ($id > 0) {
        $where  = 'c.id_commande = :id';
        $params = ['id' => $id];
    } else {
        $where  = 'c.numero_commande = :num';
        $params = ['num' => $numero];
    }

    $stmt = $pdo->prepare(
        "SELECT c.*, u.nom AS nom_caissier,
                r.nom AS restaurant_nom, r.ville AS restaurant_ville,
                r.adresse AS restaurant_adresse, r.telephone AS restaurant_telephone,
                r.devise AS restaurant_devise
         FROM commandes c
         LEFT JOIN utilisateurs u ON c.id_caissier = u.id_utilisateur
         LEFT JOIN restaurants r ON c.id_restaurant = r.id_restaurant
         WHERE $where LIMIT 1"
    );
    $stmt->execute($params);
    $commande = $stmt->fetch();

    if (!$commande) {
        http_response_code(404);
        die('Commande introuvable.');
    }

    // 2. Fetch line items
    $stmtDet = $pdo->prepare(
        "SELECT d.*, s.nom AS nom_sandwich
         FROM details_commande d
         JOIN sandwichs s ON d.id_sandwich = s.id_sandwich
         WHERE d.id_commande = :cmd ORDER BY d.id_detail ASC"
    );
    $stmtDet->execute(['cmd' => $commande['id_commande']]);
    $details = $stmtDet->fetchAll();

    // 3. Fetch preferences per line
    $stmtPrefs = $pdo->prepare(
        "SELECT di.*, i.nom AS nom_ingredient
         FROM details_ingredients di
         JOIN ingredients i ON di.id_ingredient = i.id_ingredient
         WHERE di.id_detail = :det"
    );
    foreach ($details as &$d) {
        $stmtPrefs->execute(['det' => $d['id_detail']]);
        $d['preferences'] = $stmtPrefs->fetchAll();
    }
    unset($d);

    // Formatted date
    $dateObj  = new DateTime($commande['date_commande']);
    $dateFmt  = $dateObj->format('d/m/Y');
    $heureFmt = $dateObj->format('H:i:s');
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $isZReport ? 'Rapport Z — Clôture Journalière' : ('Tickets — Commande N° ' . htmlspecialchars((string)($commande['numero_commande'] ?? ''))) ?></title>
    <link rel="icon" type="image/svg+xml" href="assets/img/favicon.svg">
    <link rel="alternate icon" href="favicon.ico">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif;
            background: #e5e7eb;
            min-height: 100vh;
            padding: 32px 20px 60px;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 24px;
        }

        /* Action bar */
        .action-bar {
            display: flex;
            gap: 12px;
            align-items: center;
            flex-wrap: wrap;
            justify-content: center;
        }

        .btn-action {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 10px 20px;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 800;
            cursor: pointer;
            text-decoration: none;
            border: none;
            transition: all 0.15s;
        }

        .btn-back {
            background: #fff;
            color: #1c1917;
            border: 1.5px solid #d6d3d1;
        }

        .btn-back:hover { background: #f5f5f4; }

        .btn-print {
            background: #1c1917;
            color: #fff;
            box-shadow: 0 4px 12px rgba(0,0,0,0.2);
        }

        .btn-print:hover {
            background: #292524;
            transform: translateY(-1px);
            box-shadow: 0 6px 16px rgba(0,0,0,0.3);
        }

        /* Wrapper holding both tickets side by side */
        .dual-tickets-wrapper {
            display: flex;
            gap: 28px;
            justify-content: center;
            flex-wrap: wrap;
        }

        /* ================================================
           THE THERMAL TICKET — Paper texture feel
           ================================================ */
        .thermal-ticket {
            background: #FDFDF8;
            width: 300px;
            padding: 0;
            border-radius: 4px;
            font-family: 'Courier New', Courier, monospace;
            font-size: 12.5px;
            line-height: 1.5;
            color: #111;
            position: relative;
            /* Torn edge top and bottom via SVG mask */
            box-shadow:
                0 2px 4px rgba(0,0,0,0.12),
                0 8px 24px rgba(0,0,0,0.1),
                inset 0 0 0 1px rgba(0,0,0,0.04);
            overflow: hidden;
        }

        /* Simulate paper grain */
        .thermal-ticket::after {
            content: '';
            position: absolute;
            inset: 0;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='200' height='200'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.75' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='200' height='200' filter='url(%23n)' opacity='0.03'/%3E%3C/svg%3E");
            pointer-events: none;
            z-index: 0;
        }

        .ticket-inner { position: relative; z-index: 1; padding: 22px 20px; }

        .thermal-ticket.ticket-cuisine {
            background: #FFFFF8;
            border-left: 5px solid #dc2626;
        }

        /* Serrated top edge */
        .ticket-serrated-top {
            height: 10px;
            background: repeating-linear-gradient(
                90deg,
                #e5e7eb 0px,
                #e5e7eb 8px,
                #FDFDF8 8px,
                #FDFDF8 16px
            );
        }

        .ticket-cuisine .ticket-serrated-top {
            background: repeating-linear-gradient(
                90deg,
                #e5e7eb 0px,
                #e5e7eb 8px,
                #FFFFF8 8px,
                #FFFFF8 16px
            );
        }

        /* Serrated bottom edge */
        .ticket-serrated-bottom {
            height: 10px;
            background: repeating-linear-gradient(
                90deg,
                #e5e7eb 0px,
                #e5e7eb 8px,
                #FDFDF8 8px,
                #FDFDF8 16px
            );
        }

        .ticket-cuisine .ticket-serrated-bottom {
            background: repeating-linear-gradient(
                90deg,
                #e5e7eb 0px,
                #e5e7eb 8px,
                #FFFFF8 8px,
                #FFFFF8 16px
            );
        }

        /* Header */
        .ticket-header {
            text-align: center;
            padding-bottom: 12px;
            border-bottom: 1.5px dashed #bbb;
            margin-bottom: 14px;
        }

        .ticket-brand {
            font-size: 16px;
            font-weight: 900;
            letter-spacing: 1.5px;
            text-transform: uppercase;
        }

        .ticket-tagline {
            font-size: 11px;
            color: #555;
            margin-top: 2px;
        }

        .ticket-address {
            font-size: 10.5px;
            color: #777;
        }

        /* Order number badge */
        .ticket-order-badge {
            margin: 12px 0 8px;
            padding: 8px;
            background: #111;
            color: #fff;
            font-size: 18px;
            font-weight: 900;
            letter-spacing: 1px;
            border-radius: 3px;
        }

        .ticket-order-badge.cuisine {
            background: #dc2626;
            font-size: 20px;
            padding: 10px;
        }

        .ticket-meta-row {
            font-size: 11px;
            color: #555;
        }

        /* Body */
        .ticket-body { margin-bottom: 12px; }

        .ticket-item-row {
            display: flex;
            justify-content: space-between;
            align-items: baseline;
            font-weight: 700;
            font-size: 13px;
            padding: 4px 0;
        }

        .ticket-item-name { flex: 1; padding-right: 8px; }

        .ticket-item-price {
            white-space: nowrap;
            font-weight: 900;
        }

        .ticket-pref-line {
            font-size: 11px;
            padding-left: 14px;
            padding-bottom: 2px;
        }

        .pref-retirer { color: #b91c1c; font-weight: 700; }
        .pref-ajouter-payant { color: #15803d; font-weight: 700; }
        .pref-ajouter-gratuit { color: #0369a1; }
        .pref-note { color: #92400e; font-style: italic; background: #fef3c7; padding: 1px 4px; border-radius: 2px; }

        .ticket-separator {
            border: none;
            border-top: 1.5px dashed #bbb;
            margin: 12px 0;
        }

        /* Item block separator */
        .item-block-sep {
            border: none;
            border-top: 1px dotted #ccc;
            margin: 6px 0;
        }

        /* Total */
        .ticket-total {
            display: flex;
            justify-content: space-between;
            font-size: 15px;
            font-weight: 900;
            padding: 8px 0 4px;
        }

        /* Footer */
        .ticket-footer {
            text-align: center;
            padding-top: 10px;
            border-top: 1.5px dashed #bbb;
            font-size: 11px;
            color: #555;
            line-height: 1.6;
        }

        /* Kitchen item block */
        .kitch-item-block {
            margin-bottom: 10px;
            padding-bottom: 8px;
            border-bottom: 1.5px dotted #bbb;
        }

        .kitch-item-block:last-child { border-bottom: none; }

        .kitch-item-name {
            font-size: 15px;
            font-weight: 900;
            text-transform: uppercase;
        }

        .kitch-qty-badge {
            display: inline-block;
            background: #111;
            color: #fff;
            font-size: 13px;
            font-weight: 900;
            width: 24px;
            height: 24px;
            line-height: 24px;
            text-align: center;
            border-radius: 4px;
            margin-right: 6px;
        }

        .kitch-pref {
            font-size: 12px;
            font-weight: 800;
            padding: 2px 0 2px 28px;
        }

        .kitch-pref.omit { color: #b91c1c; }
        .kitch-pref.extra { color: #15803d; }
        .kitch-pref.sauce { color: #0369a1; }
        .kitch-pref.note  { background: #fef08a; padding: 2px 6px; border-radius: 2px; font-style: italic; }

        /* Print */
        @media print {
            body { background: #fff !important; padding: 0 !important; gap: 0 !important; }
            .action-bar { display: none !important; }
            .dual-tickets-wrapper {
                flex-direction: row;
                gap: 24px;
                page-break-inside: avoid;
            }
        }
    </style>
</head>
<body>

    <!-- Action Bar -->
    <div class="action-bar">
        <a href="index.php" class="btn-action btn-back">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:middle; margin-right:4px;"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>Terminal Caisse
        </a>
        <a href="commandes.php" class="btn-action btn-back">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:middle; margin-right:4px;"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>Historique
        </a>
        <button onclick="window.print()" class="btn-action btn-print">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:middle; margin-right:6px;"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>Imprimer les Deux Tickets
        </button>
    </div>

    <?php if ($isZReport): ?>
        <!-- ====================================================
             TICKET THERMIQUE : RAPPORT Z (CLÔTURE DE CAISSE)
             ==================================================== -->
        <div class="dual-tickets-wrapper" id="printableTicketsContainer" style="justify-content:center;">
            <div class="thermal-ticket" style="max-width:380px; width:100%;">
                <div class="ticket-serrated-top"></div>
                <div class="ticket-inner">

                    <div class="ticket-header" style="border-bottom:2px dashed #000; padding-bottom:12px;">
                        <div class="ticket-brand" style="font-size:20px;"><?= htmlspecialchars($restaurant['nom'] ?? "RestoOS") ?></div>
                        <div class="ticket-tagline" style="font-size:11px;"><?= htmlspecialchars(($restaurant['adresse'] ? $restaurant['adresse'] . ' • ' : '') . ($restaurant['ville'] ?? 'Maroc')) ?></div>
                        <div style="margin:10px 0 4px; font-size:16px; font-weight:900; background:#000; color:#fff; padding:4px 6px; letter-spacing:1px;">
                            *** RAPPORT Z - FIN DE JOURNÉE ***
                        </div>
                        <div class="ticket-meta-row" style="font-size:12px; font-weight:bold;">Date Clôture : <?= $dateFmt ?> <?= $heureFmt ?></div>
                        <div class="ticket-meta-row" style="font-size:11px;">Réf. Certificat : #<?= $zHash ?></div>
                    </div>

                    <div class="ticket-body" style="padding-top:12px;">
                        <div style="font-size:13px; font-weight:900; text-transform:uppercase; margin-bottom:8px; border-bottom:1px solid #000; padding-bottom:3px;">
                            1. Activité Caisse
                        </div>
                        <div class="ticket-item-row" style="font-size:12px;">
                            <span>Premier Ticket Journée :</span>
                            <strong>N° <?= htmlspecialchars((string)($zStats['premier_ticket'] ?? '-')) ?></strong>
                        </div>
                        <div class="ticket-item-row" style="font-size:12px;">
                            <span>Dernier Ticket Journée :</span>
                            <strong>N° <?= htmlspecialchars((string)($zStats['dernier_ticket'] ?? '-')) ?></strong>
                        </div>
                        <div class="ticket-item-row" style="font-size:12px;">
                            <span>Nombre Total Commandes :</span>
                            <strong><?= $zTotalTickets ?></strong>
                        </div>
                        <div class="ticket-item-row" style="font-size:12px;">
                            <span>Panier Moyen par Client :</span>
                            <strong><?= number_format((float)$zPanierMoyen, 2) ?> DH</strong>
                        </div>

                        <hr class="ticket-separator" style="margin:12px 0;">

                        <div style="font-size:13px; font-weight:900; text-transform:uppercase; margin-bottom:8px; border-bottom:1px solid #000; padding-bottom:3px;">
                            2. Totaux Financiers (MAD)
                        </div>
                        <div class="ticket-item-row" style="font-size:13px;">
                            <span>Chiffre d'Affaires Brut :</span>
                            <span style="font-weight:900;"><?= number_format((float)$zCaTotal, 2) ?> DH</span>
                        </div>
                        <div class="ticket-item-row" style="font-size:13px;">
                            <span>Espèces Encaissées (Servi) :</span>
                            <span style="font-weight:900;"><?= number_format((float)$zCaEncaisse, 2) ?> DH</span>
                        </div>
                        <div class="ticket-item-row" style="font-size:12px; color:#555;">
                            <span>En cours de préparation :</span>
                            <span><?= number_format((float)($zStats['ca_en_cours'] ?? 0), 2) ?> DH</span>
                        </div>

                        <hr class="ticket-separator" style="margin:12px 0;">

                        <div style="font-size:13px; font-weight:900; text-transform:uppercase; margin-bottom:8px; border-bottom:1px solid #000; padding-bottom:3px;">
                            3. Ventes par Produit
                        </div>
                        <?php if (empty($zProducts)): ?>
                            <div style="font-size:11px; text-align:center; padding:10px 0;">Aucune vente enregistrée aujourd'hui.</div>
                        <?php else: ?>
                            <?php foreach ($zProducts as $zp): ?>
                                <div class="ticket-item-row" style="font-size:11.5px; padding:3px 0;">
                                    <span><?= (int)$zp['qte_vendue'] ?>x <?= htmlspecialchars($zp['nom']) ?></span>
                                    <span><?= number_format((float)$zp['ca_produit'], 2) ?> DH</span>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <hr class="ticket-separator" style="margin:16px 0 12px; border-top:2px solid #000;">

                    <div style="text-align:center; margin-top:10px; font-size:11px;">
                        <div style="font-weight:900; font-size:12px; text-transform:uppercase;">Signature Caissier / Gérant :</div>
                        <div style="height:45px; border-bottom:1px solid #888; margin:8px 20px 10px;"></div>
                        <div style="font-size:10px; color:#666;">CERTIFIÉ CONFORME AUX ENREGISTREMENTS SYSTÈME POS</div>
                        <div style="font-size:9px; color:#999; margin-top:4px;"><?= htmlspecialchars($restaurant['nom'] ?? 'RestoOS') ?> • RestoOS Cloud v2.6</div>
                    </div>

                </div>
                <div class="ticket-serrated-bottom"></div>
            </div>
        </div>

    <?php else: ?>

        <!-- Dual Tickets -->
        <div class="dual-tickets-wrapper" id="printableTicketsContainer">

            <!-- ======= TICKET CLIENT ======= -->
            <div class="thermal-ticket">
                <div class="ticket-serrated-top"></div>
                <div class="ticket-inner">

                    <div class="ticket-header">
                        <div class="ticket-brand"><?= htmlspecialchars($commande['restaurant_nom'] ?? "M3i9dat Ba M'Barek") ?></div>
                        <div class="ticket-tagline">Spécialités Traditionnelles • <?= htmlspecialchars($commande['restaurant_ville'] ?? 'Maroc') ?></div>
                        <?php if (!empty($commande['restaurant_adresse'])): ?>
                            <div class="ticket-address"><?= htmlspecialchars($commande['restaurant_adresse']) ?></div>
                        <?php elseif (!empty($commande['restaurant_telephone'])): ?>
                            <div class="ticket-address">Tél : <?= htmlspecialchars($commande['restaurant_telephone']) ?></div>
                        <?php endif; ?>
                        <div class="ticket-order-badge">COMMANDE N° <?= htmlspecialchars($commande['numero_commande']) ?></div>
                        <div class="ticket-meta-row">Date : <?= $dateFmt ?></div>
                        <div class="ticket-meta-row">Heure : <?= $heureFmt ?></div>
                        <div class="ticket-meta-row">Caissier : <?= htmlspecialchars($commande['nom_caissier'] ?? 'Caisse 1') ?></div>
                    </div>

                    <div class="ticket-body">
                        <?php foreach ($details as $i => $item): ?>
                            <?php if ($i > 0): ?><hr class="item-block-sep"><?php endif; ?>
                            <?php $lineTotal = $item['prix_unitaire'] * $item['quantite']; ?>
                            <div class="ticket-item-row">
                                <span class="ticket-item-name"><?= (int)$item['quantite'] ?>x <?= htmlspecialchars($item['nom_sandwich']) ?></span>
                                <span class="ticket-item-price"><?= number_format((float)$lineTotal, 2) ?> DH</span>
                            </div>
                            <?php foreach ($item['preferences'] as $p): ?>
                                <?php if ($p['action_ingredient'] === 'RETIRER'): ?>
                                    <div class="ticket-pref-line pref-retirer">— Sans <?= htmlspecialchars($p['nom_ingredient']) ?></div>
                                <?php elseif ((float)$p['prix_applique'] > 0): ?>
                                    <div class="ticket-pref-line pref-ajouter-payant">+ <?= htmlspecialchars($p['nom_ingredient']) ?> (+<?= number_format((float)$p['prix_applique'], 2) ?> DH)</div>
                                <?php else: ?>
                                    <div class="ticket-pref-line pref-ajouter-gratuit">+ <?= htmlspecialchars($p['nom_ingredient']) ?></div>
                                <?php endif; ?>
                            <?php endforeach; ?>
                            <?php if (!empty($item['commentaire'])): ?>
                                <div class="ticket-pref-line pref-note">* <?= htmlspecialchars($item['commentaire']) ?></div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>

                    <hr class="ticket-separator">

                    <div class="ticket-total">
                        <span>TOTAL PAYÉ (TTC) :</span>
                        <span><?= number_format((float)$commande['montant_total'], 2) ?> DH</span>
                    </div>

                    <div class="ticket-footer">
                        <div>Merci de votre confiance !</div>
                        <div>Conservez ce ticket pour retirer votre</div>
                        <div>commande N° <?= htmlspecialchars($commande['numero_commande']) ?></div>
                        <div style="margin-top:8px; font-size:10px;">— Bonne dégustation ! —</div>
                    </div>

                </div>
                <div class="ticket-serrated-bottom"></div>
            </div>

            <!-- ======= BON DE CUISINE ======= -->
            <div class="thermal-ticket ticket-cuisine">
                <div class="ticket-serrated-top"></div>
                <div class="ticket-inner">

                    <div class="ticket-header">
                        <div class="ticket-brand" style="color:#dc2626;">BON DE CUISINE — <?= strtoupper(htmlspecialchars($commande['restaurant_nom'] ?? "BA M'BAREK")) ?></div>
                        <div class="ticket-tagline" style="color:#888;">Poste Préparation &amp; Plancha (<?= htmlspecialchars($commande['restaurant_ville'] ?? 'Maroc') ?>)</div>
                        <div class="ticket-order-badge cuisine">COMMANDE N° <?= htmlspecialchars($commande['numero_commande']) ?></div>
                        <div class="ticket-meta-row">Heure saisie : <?= $heureFmt ?></div>
                        <div class="ticket-meta-row" style="font-weight:900;">Statut : <?= htmlspecialchars($commande['statut']) ?></div>
                    </div>

                    <div class="ticket-body">
                        <?php foreach ($details as $item): ?>
                            <div class="kitch-item-block">
                                <div class="kitch-item-name">
                                    <span class="kitch-qty-badge"><?= (int)$item['quantite'] ?></span>
                                    <?= strtoupper(htmlspecialchars($item['nom_sandwich'])) ?>
                                </div>
                                <?php foreach ($item['preferences'] as $p): ?>
                                    <?php if ($p['action_ingredient'] === 'RETIRER'): ?>
                                        <div class="kitch-pref omit">[SANS] : <?= strtoupper(htmlspecialchars($p['nom_ingredient'])) ?></div>
                                    <?php elseif ((float)$p['prix_applique'] > 0): ?>
                                        <div class="kitch-pref extra">[+EXTRA] : <?= strtoupper(htmlspecialchars($p['nom_ingredient'])) ?></div>
                                    <?php else: ?>
                                        <div class="kitch-pref sauce">[SAUCE] : <?= htmlspecialchars($p['nom_ingredient']) ?></div>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                                <?php if (!empty($item['commentaire'])): ?>
                                    <div class="kitch-pref note">[NOTE] : <?= htmlspecialchars($item['commentaire']) ?></div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <hr class="ticket-separator">

                    <div class="ticket-footer" style="font-weight:900; font-size:13px; letter-spacing:0.5px; color:#000;">
                        COMMANDE À PRÉPARER<br>
                        <span style="font-size:10px; font-weight:600; color:#888;">Cochez et marquez PRÊTE sur le KDS</span>
                    </div>

                </div>
                <div class="ticket-serrated-bottom"></div>
            </div>

        </div>

    <?php endif; ?>

    <script src="assets/js/thermal-printer.js"></script>
    <script>
        // Check auto-print if requested
        if (new URLSearchParams(window.location.search).get('autoprint') === '1') {
            window.addEventListener('DOMContentLoaded', () => {
                setTimeout(() => window.print(), 300);
            });
        }
    </script>
</body>
</html>
