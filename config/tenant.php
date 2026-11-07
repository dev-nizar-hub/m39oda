<?php
/**
 * RestoOS Cloud - Moteur Universel de Résolution de Tenant Multi-Restaurants
 * Prend en charge :
 * 1. Sous-domaines (ex: ba-mbarek.restoos.ma ou al-baraka.m39oda.free.nf)
 * 2. Paramètres d'URL (?r=slug ou ?r=id)
 * 3. Sessions & Cookies persistants
 * 4. Fallback automatique vers l'établissement par défaut
 */

declare(strict_types=1);

function resolveTenant(?PDO $pdo): array
{
    $tenant = null;

    // If no DB connection is available, return the emergency fallback immediately
    if (!$pdo instanceof PDO) {
        return [
            'id_restaurant' => 1,
            'nom'           => "M3i9dat Ba M'Barek",
            'slug'          => 'ba-mbarek',
            'ville'         => 'Meknès',
            'adresse'       => 'Rue Kénitra, Hamria',
            'devise'        => 'DH',
            'statut'        => 'ACTIF',
            'logo_url'      => null,
        ];
    }

    // 1. Détection par Sous-Domaine HTTP_HOST
    $host = strtolower(trim($_SERVER['HTTP_HOST'] ?? ''));
    // Enlever le port éventuel (ex: localhost:8080)
    $hostWithoutPort = explode(':', $host)[0];
    $parts = explode('.', $hostWithoutPort);

    // Si nous avons au moins 3 parties (ex: ba-mbarek.m39oda.free.nf ou slug.restoos.ma) ou 2 sur localhost (ex: slug.localhost)
    if (count($parts) >= 2) {
        $potentialSubdomain = $parts[0];
        $ignoredSubdomains = ['www', 'm39oda', 'localhost', 'app', 'api', 'admin', 'mail'];

        if (!in_array($potentialSubdomain, $ignoredSubdomains, true)) {
            try {
                $stmtSub = $pdo->prepare("SELECT * FROM restaurants WHERE slug = ? AND statut != 'SUSPENDU' LIMIT 1");
                $stmtSub->execute([$potentialSubdomain]);
                $tenant = $stmtSub->fetch(PDO::FETCH_ASSOC) ?: null;
            } catch (Throwable $e) {}
        }
    }

    // 2. Détection par Paramètre d'URL Explicite
    if (!$tenant) {
        $paramTenant = trim((string)($_GET['r'] ?? $_GET['tenant'] ?? $_GET['restaurant'] ?? $_GET['slug'] ?? ''));
        if ($paramTenant !== '') {
            try {
                if (is_numeric($paramTenant)) {
                    $stmt = $pdo->prepare("SELECT * FROM restaurants WHERE id_restaurant = ? AND statut != 'SUSPENDU' LIMIT 1");
                    $stmt->execute([(int)$paramTenant]);
                    $tenant = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
                } else {
                    $stmt = $pdo->prepare("SELECT * FROM restaurants WHERE slug = ? AND statut != 'SUSPENDU' LIMIT 1");
                    $stmt->execute([strtolower($paramTenant)]);
                    $tenant = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
                }
            } catch (Throwable $e) {}
        }
    }

    // 3. Détection par Session Active
    if (!$tenant && !empty($_SESSION['restaurant_id']) && is_numeric($_SESSION['restaurant_id'])) {
        try {
            $stmt = $pdo->prepare("SELECT * FROM restaurants WHERE id_restaurant = ? LIMIT 1");
            $stmt->execute([(int)$_SESSION['restaurant_id']]);
            $tenant = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable $e) {}
    }

    // 4. Détection par Cookie Persistant
    if (!$tenant && !empty($_COOKIE['restoos_tenant_id']) && is_numeric($_COOKIE['restoos_tenant_id'])) {
        try {
            $stmt = $pdo->prepare("SELECT * FROM restaurants WHERE id_restaurant = ? AND statut != 'SUSPENDU' LIMIT 1");
            $stmt->execute([(int)$_COOKIE['restoos_tenant_id']]);
            $tenant = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable $e) {}
    }

    // 5. Fallback : Premier établissement actif
    if (!$tenant) {
        try {
            $tenant = $pdo->query("SELECT * FROM restaurants WHERE statut != 'SUSPENDU' ORDER BY id_restaurant ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable $e) {}
    }

    // 6. Fallback d'urgence absolu (si la base est vide ou hors-ligne)
    if (!$tenant) {
        $tenant = [
            'id_restaurant' => 1,
            'nom'           => "M3i9dat Ba M'Barek",
            'slug'          => 'ba-mbarek',
            'ville'         => 'Meknès',
            'adresse'       => 'Rue Kénitra, Hamria',
            'devise'        => 'DH',
            'statut'        => 'ACTIF',
            'logo_url'      => null
        ];
    }

    // Synchronisation de la session
    $_SESSION['restaurant_id']   = (int)$tenant['id_restaurant'];
    $_SESSION['restaurant_nom']  = $tenant['nom'];
    $_SESSION['restaurant_slug'] = $tenant['slug'];

    return $tenant;
}

