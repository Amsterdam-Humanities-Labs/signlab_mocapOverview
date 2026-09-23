<?php
/**
 * Access control for mocapOverview.
 *
 * Pages: a password form, remembered in a PHP session (requirePagePassword()).
 * API:   an API key (X-API-Key header or ?key=) or that same page session, so the
 *        playground on /mocapOverview/api/ works without pasting a key (requireApiAccess()).
 *
 * Only the bcrypt hash of the password is stored. To change it:
 *   php -r 'echo password_hash("new password", PASSWORD_DEFAULT), "\n";'
 * API keys live in data/api_keys.json (not web-readable); see apiKeys().
 */

const PAGE_PASSWORD_HASH = '$2y$10$V30OHE1WiiigskT2NlA/HeJoUY59rlZcToCYbpACR.b1suzmvPLs.';
const API_KEYS_FILE = __DIR__ . '/../data/api_keys.json';

function startPageSession(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_name('mocapOverview');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/mocapOverview/', 'secure' => !empty($_SERVER['HTTPS']),
                               'httponly' => true, 'samesite' => 'Lax']);
    session_start();
}

function hasPageSession(): bool {
    startPageSession();
    return !empty($_SESSION['ok']);
}

/** Show the password form and exit unless the visitor has unlocked the pages. */
function requirePagePassword(): void {
    startPageSession();
    if (isset($_GET['logout'])) {
        $_SESSION = [];
        session_destroy();
        header('Location: ./');
        exit;
    }
    $error = false;
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['password'])) {
        if (password_verify($_POST['password'], PAGE_PASSWORD_HASH)) {
            session_regenerate_id(true);
            $_SESSION['ok'] = true;
            header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
            exit;
        }
        $error = true;
        usleep(500000);
    }
    if (!empty($_SESSION['ok'])) return;

    http_response_code($error ? 401 : 200);
    ?><!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Mocap Dataset Overview</title>
<style>
:root { --bg: #f6f5f1; --panel: #fff; --ink: #1c1d1f; --muted: #6b6d72; --line: #e2e0d9; --accent: #2c5e8f; --err: #b3261e; }
@media (prefers-color-scheme: dark) { :root { --bg: #151618; --panel: #1d1f22; --ink: #e8e7e3; --muted: #9a9ca1; --line: #303236; --accent: #7fb0e0; --err: #f2b8b5; } }
body { margin: 0; min-height: 100vh; display: grid; place-items: center; background: var(--bg); color: var(--ink); font: 15px/1.5 system-ui, sans-serif; padding: 16px; box-sizing: border-box; }
form { background: var(--panel); border: 1px solid var(--line); border-radius: 8px; padding: 24px; width: 100%; max-width: 340px; }
h1 { font-size: 19px; margin: 0 0 4px; } p { color: var(--muted); margin: 0 0 16px; font-size: 14px; }
input, button { font: inherit; width: 100%; box-sizing: border-box; padding: 9px 11px; border-radius: 6px; }
input { border: 1px solid var(--line); background: var(--bg); color: var(--ink); margin-bottom: 10px; }
button { border: 0; background: var(--accent); color: #fff; cursor: pointer; font-weight: 600; }
.err { color: var(--err); font-size: 13.5px; margin: -4px 0 10px; }
</style></head><body>
<form method="post">
  <h1>Mocap dataset overview</h1>
  <p>Enter the password to continue.</p>
  <input type="password" name="password" autocomplete="current-password" autofocus required aria-label="Password">
  <?php if ($error): ?><div class="err">Wrong password.</div><?php endif; ?>
  <button type="submit">Open</button>
</form>
</body></html><?php
    exit;
}

/** @return array<int, array{key: string, label: string, created: string}> */
function apiKeys(): array {
    if (!file_exists(API_KEYS_FILE)) {
        // First use: create a default key.
        $data = ['keys' => [['key' => bin2hex(random_bytes(20)), 'label' => 'default', 'created' => date('Y-m-d')]]];
        if (@file_put_contents(API_KEYS_FILE, json_encode($data, JSON_PRETTY_PRINT), LOCK_EX) === false) return [];
    }
    $data = json_decode(@file_get_contents(API_KEYS_FILE), true);
    return is_array($data['keys'] ?? null) ? $data['keys'] : [];
}

/** Returns the auth method used ('key' or 'session'); sends 401 JSON and exits otherwise. */
function requireApiAccess(): string {
    $given = $_SERVER['HTTP_X_API_KEY'] ?? ($_GET['key'] ?? '');
    if ($given !== '') {
        foreach (apiKeys() as $k) {
            if (hash_equals($k['key'], $given)) return 'key';
        }
    } elseif (hasPageSession()) {
        return 'session';
    }
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'unauthorized',
                      'message' => 'Send a valid API key in the X-API-Key header or the key parameter. '
                                 . 'See https://signcollect.nl/mocapOverview/api/']);
    exit;
}
