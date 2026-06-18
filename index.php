<?php
// index.php — Osiris Engine: Login gate
//
// Simple API-key login. On success, sets a session flag and redirects to
// log.php. log.php (via auth_guard.php) checks that flag on every load and
// bounces back here if it's missing — so the dashboard can only be reached
// with a valid session, not just by knowing the URL.
//
// The session persists until explicit logout (no auto-expiry timer here);
// see logout.php.

session_start();
require_once 'auth_config.php';

$error = '';

// Already logged in? Skip straight to the dashboard.
if (!empty($_SESSION['osiris_authenticated']) && $_SESSION['osiris_authenticated'] === true) {
    header('Location: log.php');
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        http_response_code(403);
        die("CSRF token validation failed.");
    }

    $submittedKey = $_POST['api_key'] ?? '';

    // hash_equals() is timing-safe; a plain === comparison here would leak
    // timing information that could help an attacker guess the key
    // character-by-character.
    if (is_string($submittedKey) && $submittedKey !== '' && hash_equals(OSIRIS_API_KEY, $submittedKey)) {
        // Prevent session fixation: issue a fresh session ID on privilege change.
        session_regenerate_id(true);
        $_SESSION['osiris_authenticated'] = true;
        header('Location: log.php');
        exit;
    } else {
        $error = 'Invalid API key.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Osiris — Login</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        :root {
            --bg:       #0d0d0f;
            --surface:  #141416;
            --surface2: #1a1a1e;
            --border:   rgba(255,255,255,0.07);
            --border2:  rgba(255,255,255,0.12);
            --txt1:     #f0eff4;
            --txt2:     #8a8a9a;
            --txt3:     #55556a;
            --accent:   #c084fc;
            --red:      #f87171;
            --radius:   12px;
            --radius-sm:8px;
        }
        body {
            background: var(--bg); color: var(--txt1); font-family: 'Inter', sans-serif;
            font-size: 14px; line-height: 1.6; min-height: 100vh;
            display: flex; align-items: center; justify-content: center; padding: 24px;
        }
        .login-card {
            width: 100%; max-width: 360px; background: var(--surface);
            border: 1px solid var(--border); border-radius: var(--radius);
            padding: 28px 26px;
        }
        .login-header { text-align: center; margin-bottom: 22px; }
        .login-header h1 { font-size: 19px; font-weight: 600; }
        .login-header p { font-size: 12px; color: var(--txt3); margin-top: 4px; letter-spacing: .05em; text-transform: uppercase; }
        .form-group { display: flex; flex-direction: column; gap: 6px; margin-bottom: 16px; }
        .form-group label { font-size: 11px; color: var(--txt2); font-weight: 500; }
        .form-group input {
            height: 40px; background: var(--surface2); border: 1px solid var(--border2);
            border-radius: var(--radius-sm); color: var(--txt1); padding: 0 14px;
            font-size: 13px; font-family: 'JetBrains Mono', monospace; width: 100%; outline: none;
        }
        .form-group input:focus { border-color: var(--accent); }
        .btn {
            display: inline-flex; align-items: center; justify-content: center; gap: 6px;
            height: 40px; padding: 0 18px; border-radius: var(--radius-sm); font-size: 13px;
            font-weight: 500; border: 1px solid; cursor: pointer; font-family: 'Inter', sans-serif; width: 100%;
        }
        .btn:active { transform: scale(.98); }
        .btn-accent { background: rgba(192,132,252,.12); border-color: rgba(192,132,252,.3); color: var(--accent); }
        .alert {
            padding: 10px 14px; border-radius: var(--radius-sm); font-size: 12px;
            border: 1px solid rgba(248,113,113,.25); background: rgba(248,113,113,.07);
            color: var(--red); margin-bottom: 16px;
        }
    </style>
</head>
<body>
    <div class="login-card">
        <div class="login-header">
            <h1>Osiris Engine</h1>
            <p>Authentication required</p>
        </div>

        <?php if ($error): ?>
        <div class="alert"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <form method="POST" action="index.php">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            <div class="form-group">
                <label for="api_key">API key</label>
                <input type="password" name="api_key" id="api_key" autocomplete="off" autofocus required>
            </div>
            <button type="submit" class="btn btn-accent">Unlock dashboard</button>
        </form>
    </div>
</body>
</html>