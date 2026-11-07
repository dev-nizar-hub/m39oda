<?php
/**
 * RestoOS Cloud - Contrôle d'Accès & Sessions (RBAC)
 * Vérifie l'authentification et les privilèges de rôle : CAISSIER, PREPARATEUR, ADMIN, SUPERADMIN
 *
 * Security hardening:
 *  - demo_bypass removed (was a critical authentication bypass)
 *  - Session lifetime enforced (1 hour inactivity)
 *  - getTenantRestaurantId() restricts restaurant_id override to SUPERADMIN only
 *  - CSRF token generation/validation helpers
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    // Enforce secure session settings before starting
    ini_set('session.gc_maxlifetime', '3600');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    session_set_cookie_params([
        'lifetime' => 3600,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// Enforce inactivity timeout (1 hour)
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > 3600) {
    $_SESSION = [];
    session_destroy();
    session_start();
}
if (isset($_SESSION['user_id'])) {
    $_SESSION['last_activity'] = time();
}

/**
 * Generates (once per session) or returns the existing CSRF token.
 */
function getCsrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Validates a CSRF token submitted via POST.
 * Terminates with 403 on mismatch.
 */
function verifyCsrfToken(): void
{
    $submitted = $_POST['csrf_token'] ?? '';
    if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $submitted)) {
        http_response_code(403);
        exit('Requête invalide : jeton de sécurité manquant ou expiré. Rechargez la page.');
    }
}

/**
 * Exige une session active et facultativement un ensemble de rôles autorisés.
 * Redirige vers login.php en cas de défaut d'accréditation.
 *
 * @param array<string>|null $allowedRoles Ex: ['CAISSIER', 'ADMIN']
 */
function requireRole(?array $allowedRoles = null): array
{
    // demo_bypass has been removed — it was a critical authentication bypass.
    // Any URL like ?demo_bypass=superadmin no longer grants access.

    if (!isset($_SESSION['user_id']) || empty($_SESSION['user_role'])) {
        header('Location: login.php?msg=session_required');
        exit();
    }

    $currentRole = strtoupper((string)$_SESSION['user_role']);

    // SUPERADMIN always has universal access across all workspaces
    if ($currentRole === 'SUPERADMIN') {
        return [
            'id'              => (int)$_SESSION['user_id'],
            'nom'             => (string)$_SESSION['user_nom'],
            'role'            => 'SUPERADMIN',
            'restaurant_id'   => isset($_SESSION['restaurant_id']) ? (int)$_SESSION['restaurant_id'] : null,
            'restaurant_nom'  => $_SESSION['restaurant_nom'] ?? 'Plateforme Centrale',
            'restaurant_slug' => $_SESSION['restaurant_slug'] ?? 'central',
            'restaurant_plan' => $_SESSION['restaurant_plan'] ?? 'PREMIUM'
        ];
    }

    if ($allowedRoles !== null && !in_array($currentRole, array_map('strtoupper', $allowedRoles), true)) {
        // Redirige vers son propre espace de travail plutôt qu'une erreur brute
        $redirect = match ($currentRole) {
            'PREPARATEUR' => 'cuisine.php',
            'ADMIN'       => 'commandes.php',
            'SUPERADMIN'  => 'superadmin.php',
            default       => 'index.php'
        };
        header('Location: ' . $redirect . '?msg=unauthorized');
        exit();
    }

    return [
        'id'              => (int)$_SESSION['user_id'],
        'nom'             => (string)$_SESSION['user_nom'],
        'role'            => $currentRole,
        'restaurant_id'   => isset($_SESSION['restaurant_id']) ? (int)$_SESSION['restaurant_id'] : 1,
        'restaurant_nom'  => $_SESSION['restaurant_nom'] ?? "M3i9dat Ba M'Barek",
        'restaurant_slug' => $_SESSION['restaurant_slug'] ?? 'ba-mbarek',
        'restaurant_plan' => $_SESSION['restaurant_plan'] ?? 'PRO'
    ];
}

/**
 * Récupère l'utilisateur connecté courant sans redirection.
 */
function getCurrentUser(): ?array
{
    if (!isset($_SESSION['user_id'])) {
        return null;
    }
    return [
        'id'              => (int)$_SESSION['user_id'],
        'nom'             => (string)$_SESSION['user_nom'],
        'role'            => (string)$_SESSION['user_role'],
        'restaurant_id'   => isset($_SESSION['restaurant_id']) ? (int)$_SESSION['restaurant_id'] : null,
        'restaurant_nom'  => $_SESSION['restaurant_nom'] ?? null,
        'restaurant_slug' => $_SESSION['restaurant_slug'] ?? null,
    ];
}

/**
 * Retourne l'ID du restaurant actif du tenant courant.
 * Only SUPERADMIN may override via ?restaurant_id= GET param (IDOR fix).
 * All other roles are locked to their session restaurant.
 */
function getTenantRestaurantId(): int
{
    $currentRole = strtoupper((string)($_SESSION['user_role'] ?? ''));

    // Only SUPERADMIN is allowed to switch tenants via URL
    if ($currentRole === 'SUPERADMIN') {
        if (isset($_GET['restaurant_id']) && is_numeric($_GET['restaurant_id'])) {
            return (int)$_GET['restaurant_id'];
        }
    }

    // All other roles: always use their session-bound restaurant
    if (isset($_SESSION['restaurant_id']) && is_numeric($_SESSION['restaurant_id'])) {
        return (int)$_SESSION['restaurant_id'];
    }
    return 1;
}

/**
 * Vérifie si l'utilisateur courant est SUPERADMIN
 */
function isSuperAdmin(): bool
{
    return (isset($_SESSION['user_role']) && strtoupper((string)$_SESSION['user_role']) === 'SUPERADMIN');
}

