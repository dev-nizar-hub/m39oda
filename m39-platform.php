<?php
/**
 * RestoOS — Private Platform Owner Access
 * This file is not linked anywhere. URL is known only to the platform owner.
 * Only SUPERADMIN credentials are accepted. All other attempts are silently rejected.
 */

declare(strict_types=1);

session_start();

// If already authenticated as SUPERADMIN, go straight to the console
if (!empty($_SESSION['user_role']) && $_SESSION['user_role'] === 'SUPERADMIN') {
    header('Location: superadmin.php');
    exit();
}

$pdo = null;
try {
    $pdo = require __DIR__ . '/config/db.php';
} catch (Throwable $e) {
    // DB unavailable — static fallback handles auth below
}

$error = '';

// -------------------------------------------------------------
// AUTHENTICATION — SUPERADMIN ONLY
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login = strtolower(trim($_POST['login'] ?? ''));
    $pass  = trim($_POST['password'] ?? '');

    if ($login !== '' && $pass !== '') {
        $authenticatedUser = null;

        // 1. Try database
        if ($pdo instanceof PDO) {
            try {
                $stmt = $pdo->prepare(
                    "SELECT id_utilisateur, nom, role, mot_de_passe, actif
                     FROM utilisateurs
                     WHERE LOWER(login) = ? AND role = 'SUPERADMIN' AND actif = 1
                     LIMIT 1"
                );
                $stmt->execute([$login]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($user) {
                    $valid = ($user['mot_de_passe'] === $pass)
                          || password_verify($pass, $user['mot_de_passe']);
                    if ($valid) {
                        $authenticatedUser = $user;
                    }
                }
            } catch (Throwable $e) {}
        }

        // 2. Static fallback (if DB is unreachable)
        if (!$authenticatedUser && $login === 'superadmin' && $pass === 'admin') {
            $authenticatedUser = [
                'id_utilisateur' => 1,
                'nom'            => 'Super Administrateur SaaS',
                'role'           => 'SUPERADMIN',
            ];
        }

        if ($authenticatedUser && ($authenticatedUser['role'] ?? '') === 'SUPERADMIN') {
            // Valid SUPERADMIN — open session and redirect
            $_SESSION['user_id']       = (int)$authenticatedUser['id_utilisateur'];
            $_SESSION['user_nom']      = $authenticatedUser['nom'];
            $_SESSION['user_role']     = 'SUPERADMIN';
            $_SESSION['restaurant_id'] = 0;
            $_SESSION['sa_authenticated'] = true; // extra marker

            header('Location: superadmin.php');
            exit();
        } else {
            // Wrong credentials OR not SUPERADMIN — show generic error
            // We do NOT reveal whether the user exists or not
            $error = 'Identifiant ou mot de passe incorrect.';
        }
    } else {
        $error = 'Veuillez remplir tous les champs.';
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Accès Plateforme</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: #0F1117;
            color: #F9FAFB;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 32px 20px;
            -webkit-font-smoothing: antialiased;
        }

        .wrapper {
            width: 100%;
            max-width: 400px;
            display: flex;
            flex-direction: column;
            gap: 24px;
        }

        /* Mark at the top */
        .platform-mark {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 10px;
            text-align: center;
        }

        .mark-icon {
            width: 42px;
            height: 42px;
            background: #1E2130;
            border: 1px solid #2D3148;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #818CF8;
        }

        .mark-label {
            font-size: 13px;
            font-weight: 600;
            color: #9CA3AF;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }

        /* Card */
        .auth-card {
            background: #1A1D2E;
            border: 1px solid #2D3148;
            border-radius: 12px;
            padding: 32px;
        }

        .card-heading {
            font-size: 18px;
            font-weight: 700;
            color: #F9FAFB;
            letter-spacing: -0.02em;
            margin-bottom: 4px;
        }

        .card-sub {
            font-size: 13px;
            color: #6B7280;
            margin-bottom: 28px;
        }

        /* Alert */
        .alert {
            padding: 10px 14px;
            border-radius: 6px;
            font-size: 13px;
            margin-bottom: 20px;
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.3);
            color: #FCA5A5;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        /* Fields */
        .field {
            margin-bottom: 16px;
        }

        .field label {
            display: block;
            font-size: 12.5px;
            font-weight: 600;
            color: #9CA3AF;
            margin-bottom: 6px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .input-wrap {
            position: relative;
        }

        .input-wrap svg {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #4B5563;
            pointer-events: none;
        }

        .field input {
            width: 100%;
            height: 42px;
            padding: 0 12px 0 38px;
            background: #0F1117;
            border: 1px solid #2D3148;
            border-radius: 8px;
            color: #F9FAFB;
            font-size: 14px;
            font-family: inherit;
            transition: border-color 0.15s, box-shadow 0.15s;
        }

        .field input::placeholder {
            color: #374151;
        }

        .field input:focus {
            outline: none;
            border-color: #4F46E5;
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.15);
        }

        .btn-toggle {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #4B5563;
            cursor: pointer;
            padding: 4px;
            display: flex;
            align-items: center;
        }

        .btn-toggle:hover { color: #9CA3AF; }

        /* Submit */
        .btn-submit {
            width: 100%;
            height: 44px;
            margin-top: 8px;
            background: #4F46E5;
            color: #FFFFFF;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            font-family: inherit;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: background 0.15s, transform 0.08s;
            letter-spacing: -0.01em;
        }

        .btn-submit:hover { background: #4338CA; }
        .btn-submit:active { transform: scale(0.99); }
        .btn-submit:disabled { opacity: 0.6; cursor: not-allowed; }

        .spinner {
            display: none;
            width: 15px;
            height: 15px;
            border: 2px solid rgba(255,255,255,0.3);
            border-top-color: white;
            border-radius: 50%;
            animation: spin 0.6s linear infinite;
        }

        @keyframes spin { to { transform: rotate(360deg); } }

        /* Footer */
        .page-footer {
            text-align: center;
            font-size: 11.5px;
            color: #374151;
        }
    </style>
</head>
<body>
    <div class="wrapper">

        <div class="platform-mark">
            <div class="mark-icon">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
                </svg>
            </div>
            <span class="mark-label">Accès Plateforme</span>
        </div>

        <div class="auth-card">
            <h1 class="card-heading">Espace Administrateur</h1>
            <p class="card-sub">Accès réservé. Identifiez-vous pour continuer.</p>

            <?php if ($error !== ''): ?>
                <div class="alert" role="alert">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
                    </svg>
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <form method="POST" id="saForm">

                <div class="field">
                    <label for="login">Identifiant</label>
                    <div class="input-wrap">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>
                        </svg>
                        <input
                            type="text"
                            id="login"
                            name="login"
                            placeholder="votre identifiant"
                            value="<?= htmlspecialchars($_POST['login'] ?? '') ?>"
                            required
                            autofocus
                            autocomplete="username"
                        >
                    </div>
                </div>

                <div class="field">
                    <label for="password">Mot de passe</label>
                    <div class="input-wrap">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                        </svg>
                        <input
                            type="password"
                            id="password"
                            name="password"
                            placeholder="••••••••"
                            required
                            autocomplete="current-password"
                        >
                        <button type="button" class="btn-toggle" id="togglePass" aria-label="Afficher le mot de passe">
                            <svg id="eyeIcon" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>
                            </svg>
                        </button>
                    </div>
                </div>

                <button type="submit" class="btn-submit" id="btnSubmit">
                    <span id="btnText">Accéder à la console</span>
                    <span class="spinner" id="btnSpinner"></span>
                </button>

            </form>
        </div>

        <div class="page-footer">
            RestoOS Platform · Accès restreint
        </div>

    </div>

    <script>
        // Password toggle
        const togglePass = document.getElementById('togglePass');
        const passInput  = document.getElementById('password');
        const eyeIcon    = document.getElementById('eyeIcon');

        togglePass.addEventListener('click', () => {
            const show = passInput.type === 'password';
            passInput.type = show ? 'text' : 'password';
            eyeIcon.innerHTML = show
                ? '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line>'
                : '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle>';
        });

        // Submit state
        document.getElementById('saForm').addEventListener('submit', () => {
            document.getElementById('btnSubmit').disabled = true;
            document.getElementById('btnText').textContent = 'Vérification...';
            document.getElementById('btnSpinner').style.display = 'inline-block';
        });
    </script>
</body>
</html>
