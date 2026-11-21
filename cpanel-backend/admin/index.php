<?php
/**
 * Content admin for palashroy.me
 * Login-protected editor that reads/writes the `content` MySQL table.
 * Security: PDO prepared statements, password_hash/verify, CSRF tokens,
 * login lockout, HttpOnly+Secure+SameSite session cookie.
 */
declare(strict_types=1);
require __DIR__ . '/../api/db.php';

// ---- secure session ----
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => true,
    'httponly' => true,
    'samesite' => 'Strict',
]);
session_start();

function csrf(): string
{
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}
function require_csrf(string $token): void
{
    if (!hash_equals($_SESSION['csrf'] ?? '', $token)) {
        http_response_code(403);
        echo json_encode(['error' => 'Bad CSRF token — reload the page.']);
        exit;
    }
}
function is_logged_in(): bool { return !empty($_SESSION['admin']); }
function json_out($data): void { header('Content-Type: application/json'); echo json_encode($data); exit; }

// ================= Maintenance mode =================
// Apache (.htaccess) does the work on every request without PHP: while the flag file
// <web root>/.maintenance exists, visitors get /maintenance.html with HTTP 503 + Retry-After.
// The admin switches it here, and sets a bypass cookie (pr_maint_bypass) so the owner still sees the real site.
const MAINT_COOKIE = 'pr_maint_bypass';
const MAINT_PRIVATE_ROW = '_private_maintenance'; // not in ALLOWED_SECTIONS, so the public API never returns it
const MAINT_DEFAULT_MSG = 'The site is undergoing maintenance — back soon.';

function maint_root(): string
{
    $root = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
    if ($root === '' || !is_dir($root)) $root = dirname(__DIR__); // admin/ lives directly in the web root
    return $root;
}
function maint_flag_on(): bool { return is_file(maint_root() . '/.maintenance'); }

// Random bypass token, kept in a private DB row (created on first use).
function maint_token(): string
{
    try {
        $st = db()->prepare("SELECT data FROM content WHERE section = ? LIMIT 1");
        $st->execute([MAINT_PRIVATE_ROW]);
        $row = $st->fetch();
        $d = $row ? json_decode((string)$row['data'], true) : null;
        if (is_array($d) && isset($d['token']) && is_string($d['token']) && preg_match('/^[a-f0-9]{48}$/', $d['token'])) return $d['token'];
        $tok = bin2hex(random_bytes(24));
        db()->prepare("INSERT INTO content (section,data) VALUES (?,?) ON DUPLICATE KEY UPDATE data=VALUES(data)")
            ->execute([MAINT_PRIVATE_ROW, json_encode(['token' => $tok, 'created' => gmdate('c')])]);
        return $tok;
    } catch (Throwable $e) {
        return '';
    }
}
// A fresh token each time maintenance is switched ON and at logout, so a copied bypass cookie
// never opens a later maintenance window. Returns the new token ('' on a database error).
function maint_rotate_token(): string
{
    try {
        $tok = bin2hex(random_bytes(24));
        db()->prepare("INSERT INTO content (section,data) VALUES (?,?) ON DUPLICATE KEY UPDATE data=VALUES(data)")
            ->execute([MAINT_PRIVATE_ROW, json_encode(['token' => $tok, 'created' => gmdate('c')])]);
        return $tok;
    } catch (Throwable $e) {
        return '';
    }
}
// Marker file /.maint-ok-<token>: .htaccess lets a bypass cookie through only when the file
// for its token exists. It exists only while maintenance is on.
function maint_ok_files(): array { return glob(maint_root() . '/.maint-ok-*') ?: []; }
function maint_sync_ok_file(bool $on): void
{
    $tok = $on ? maint_token() : '';
    $want = $tok !== '' ? maint_root() . '/.maint-ok-' . $tok : '';
    foreach (maint_ok_files() as $f) { if ($f !== $want) @unlink($f); }
    if ($want !== '' && !is_file($want)) @file_put_contents($want, '');
}
function maint_clear_cookie(): void
{
    if (headers_sent()) return;
    setcookie(MAINT_COOKIE, '', ['expires' => 1, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax']);
}
function maint_set_cookie(): void
{
    if (headers_sent()) return;
    $tok = maint_token();
    if ($tok === '') return;
    if (($_COOKIE[MAINT_COOKIE] ?? '') === $tok) return;
    setcookie(MAINT_COOKIE, $tok, [
        'expires' => time() + 60 * 60 * 24 * 30, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax',
    ]);
}

// Text shown on the maintenance page: the owner's message (or the default), HTML-escaped.
function maint_esc(string $x): string { return htmlspecialchars($x, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function maint_msg_html(string $message): string { return maint_esc(trim($message) !== '' ? trim($message) : MAINT_DEFAULT_MSG); }
function maint_until_html(string $until): string { return trim($until) !== '' ? 'Expected back: ' . maint_esc(trim($until)) . '.' : ''; }
// Replace the text between <!--NAME--> and <!--/NAME--> (first occurrence). Returns null when the region is missing.
function maint_region(string $html, string $name, string $text): ?string
{
    $re = '#<!--' . $name . '-->.*?<!--/' . $name . '-->#s';
    if (!preg_match($re, $html)) return null;
    $out = preg_replace_callback($re, fn() => '<!--' . $name . '-->' . $text . '<!--/' . $name . '-->', $html, 1);
    return is_string($out) ? $out : null;
}
// Write the message into the maintenance.html generated by the website build. Supported marker regions:
//   <!--MAINT-MESSAGE-->…<!--/MAINT-MESSAGE--> and <!--MAINT-UNTIL-->…<!--/MAINT-UNTIL--> (current build), or
//   <!--MAINT-MSG-->…<!--/MAINT-MSG--> (message and time together). If the file is missing, a plain page is written.
function maint_write_page(string $message, string $until): bool
{
    $path = maint_root() . '/maintenance.html';
    $msg = maint_msg_html($message);
    $unt = maint_until_html($until);
    $html = is_file($path) ? (string)file_get_contents($path) : '';
    $done = null;
    if ($html !== '' && ($m = maint_region($html, 'MAINT-MESSAGE', $msg)) !== null) {
        $u = maint_region($m, 'MAINT-UNTIL', $unt);
        $done = $u ?? (maint_region($html, 'MAINT-MESSAGE', trim($msg . ' ' . $unt)) ?? $m);
    } elseif ($html !== '' && ($m = maint_region($html, 'MAINT-MSG', trim($msg . ' ' . $unt))) !== null) {
        $done = $m;
    } elseif ($html !== '' && strpos($html, '<!--MAINT-MSG-->') !== false) {
        // Single marker: the default text runs from the marker to the next tag.
        $r = preg_replace_callback('#<!--MAINT-MSG-->[^<]*#', fn() => '<!--MAINT-MSG-->' . trim($msg . ' ' . $unt) . '<!--/MAINT-MSG-->', $html, 1);
        if (is_string($r)) $done = $r;
    }
    if ($done !== null) {
        $html = $done;
    } else {
        $email = 'palash.roy@usask.ca';
        try {
            $st = db()->prepare("SELECT data FROM content WHERE section = 'footer' LIMIT 1");
            $st->execute();
            $f = ($r = $st->fetch()) ? json_decode((string)$r['data'], true) : null;
            if (is_array($f) && !empty($f['email']) && filter_var($f['email'], FILTER_VALIDATE_EMAIL)) $email = $f['email'];
        } catch (Throwable $e) {}
        $e = maint_esc($email);
        $html = "<!doctype html>\n<html lang=\"en\"><head><meta charset=\"utf-8\"><meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">"
            . "<meta name=\"robots\" content=\"noindex\"><title>Maintenance | Palash Ranjan Roy</title>"
            . "<style>:root{color-scheme:light dark}body{margin:0;font:17px/1.6 system-ui,sans-serif;background:#FAFAF7;color:#1A1A1A;display:flex;min-height:100vh;align-items:center;justify-content:center;padding:16px}"
            . "main{max-width:34rem}h1{font:600 30px/1.2 Georgia,serif;margin:0 0 12px}a{color:#1F4E79}"
            . "@media(prefers-color-scheme:dark){body{background:#121417;color:#E8E6E1}a{color:#8DB4DC}}</style></head>"
            . "<body><main><h1>Palash Ranjan Roy</h1><p><!--MAINT-MESSAGE-->" . $msg . "<!--/MAINT-MESSAGE--></p>"
            . "<p><!--MAINT-UNTIL-->" . $unt . "<!--/MAINT-UNTIL--></p>"
            . "<p>Email: <a href=\"mailto:$e\">$e</a></p></main></body></html>\n";
    }
    if (!is_string($html)) return false;
    $tmp = $path . '.tmp-' . bin2hex(random_bytes(4));
    if (@file_put_contents($tmp, $html) === false) return false;
    @chmod($tmp, 0644);
    if (!@rename($tmp, $path)) { @unlink($tmp); return false; }
    return true;
}

$action = $_GET['action'] ?? '';

// ---- logout ----
if ($action === 'logout') {
    maint_clear_cookie(); // the preview cookie belongs to the signed-in owner only
    if (is_logged_in()) {
        // Any copy of the old cookie stops working at once: new token, marker file follows it.
        maint_rotate_token();
        maint_sync_ok_file(maint_flag_on());
    }
    $_SESSION = [];
    session_destroy();
    header('Location: index.php');
    exit;
}

// ---- login ----
if ($action === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = trim($_POST['username'] ?? '');
    $pass = (string)($_POST['password'] ?? '');
    require_csrf($_POST['csrf'] ?? '');
    // Verify the CAPTCHA before touching the database (blocks bots + brute force).
    $captchaOk = isset($_SESSION['captcha']) && hash_equals((string)$_SESSION['captcha'], trim((string)($_POST['captcha'] ?? '')));
    unset($_SESSION['captcha'], $_SESSION['captcha_q']);
    if (!$captchaOk) {
        $_SESSION['login_error'] = 'Incorrect answer to the verification question.';
        header('Location: index.php');
        exit;
    }
    $err = 'Invalid username or password.';
    try {
        $stmt = db()->prepare("SELECT * FROM admin_users WHERE username = ? LIMIT 1");
        $stmt->execute([$user]);
        $row = $stmt->fetch();
        $now = time();
        if ($row && (int)$row['locked_until'] > $now) {
            $err = 'Too many attempts. Try again in a few minutes.';
        } elseif ($row && password_verify($pass, $row['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['admin'] = $row['username'];
            db()->prepare("UPDATE admin_users SET failed_attempts=0, locked_until=0 WHERE id=?")->execute([$row['id']]);
            maint_set_cookie(); // lets the owner see the real site while maintenance mode is on
            header('Location: index.php');
            exit;
        } elseif ($row) {
            $fa = (int)$row['failed_attempts'] + 1;
            $lock = $fa >= 5 ? $now + 600 : 0; // lock 10 min after 5 fails
            db()->prepare("UPDATE admin_users SET failed_attempts=?, locked_until=? WHERE id=?")->execute([$fa, $lock, $row['id']]);
        }
    } catch (Throwable $e) {
        $err = 'Server error. Check config.php / database.';
    }
    $_SESSION['login_error'] = $err;
    header('Location: index.php');
    exit;
}

// ---- save a section (AJAX, logged-in only) ----
if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!is_logged_in()) { http_response_code(401); json_out(['error' => 'Not logged in']); }
    // Decode as objects (not assoc arrays) so empty {} values are stored as {} rather than [].
    $body = json_decode((string)file_get_contents('php://input'));
    if (!is_object($body)) { $body = new stdClass(); }
    require_csrf(isset($body->csrf) && is_string($body->csrf) ? $body->csrf : '');
    $section = isset($body->section) && is_string($body->section) ? $body->section : '';
    if (!in_array($section, ALLOWED_SECTIONS, true)) { http_response_code(400); json_out(['error' => 'Unknown section']); }
    if (!property_exists($body, 'data')) { http_response_code(400); json_out(['error' => 'Invalid data']); }
    $json = json_encode($body->data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) { http_response_code(400); json_out(['error' => 'Invalid data']); }
    try {
        db()->prepare("INSERT INTO content (section,data) VALUES (?,?) ON DUPLICATE KEY UPDATE data=VALUES(data)")
            ->execute([$section, $json]);
        json_out(['ok' => true]);
    } catch (Throwable $e) {
        http_response_code(500); json_out(['error' => 'Database error']);
    }
}

// ---- full content backup (logged-in only) ----
// POST index.php?action=backup with JSON body {"csrf": TOKEN} -> JSON snapshot of every content row.
// (POST keeps the CSRF token out of URLs and server logs; the page turns the reply into a file download.)
// Rows are decoded as objects (not arrays) so empty {} values survive a backup/restore round trip.
if ($action === 'backup' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!is_logged_in()) { http_response_code(401); json_out(['error' => 'Not logged in']); }
    $body = json_decode((string)file_get_contents('php://input'), true);
    require_csrf(is_array($body) ? (string)($body['csrf'] ?? '') : '');
    $sections = new stdClass();
    try {
        foreach (db()->query("SELECT section, data FROM content ORDER BY section")->fetchAll() as $r) {
            if (!in_array((string)$r['section'], ALLOWED_SECTIONS, true)) continue; // private rows (e.g. the bypass token) stay out
            $sections->{(string)$r['section']} = json_decode((string)$r['data']);
        }
    } catch (Throwable $e) {
        http_response_code(500); json_out(['error' => 'Database error']);
    }
    $out = json_encode(
        ['format' => 'palashroy-content-backup', 'version' => 1, 'exported_at' => gmdate('c'), 'sections' => $sections],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
    );
    if ($out === false) { http_response_code(500); json_out(['error' => 'Could not encode backup']); }
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo $out;
    exit;
}

// ---- visitor-country statistics (AJAX, logged-in only) ----
// POST index.php?action=visitors with JSON {"csrf"} -> per-country counts for three ranges.
// POST index.php?action=visitors_reset with JSON {"csrf"} -> deletes all counts.
// Counting happens in api/visit.php; the data stays private (the public endpoint only gives
// the number of countries, and only while the website line is switched on).
if (($action === 'visitors' || $action === 'visitors_reset') && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!is_logged_in()) { http_response_code(401); json_out(['error' => 'Not logged in']); }
    $body = json_decode((string)file_get_contents('php://input'), true);
    require_csrf(is_array($body) && is_string($body['csrf'] ?? null) ? $body['csrf'] : '');
    require_once __DIR__ . '/../api/visitlib.php';
    header('Cache-Control: no-store');
    try {
        $db = db();
        visit_ensure_tables($db);
        if ($action === 'visitors_reset') {
            $db->exec("DELETE FROM visit_stats");
            $db->exec("DELETE FROM visit_seen");
            $db->exec("DELETE FROM visit_salt");
            json_out(['ok' => true]);
        }
        visit_purge_old($db); // yesterday's key and hashes, if no visit has come in today yet
        $today = visit_today();
        $ranges = [
            'd30' => gmdate('Y-m-d', strtotime($today . ' -29 days')),
            'm12' => gmdate('Y-m-d', strtotime($today . ' -12 months +1 day')),
            'all' => '1000-01-01',
        ];
        $names = require __DIR__ . '/../api/countrynames.php';
        $out = ['ok' => true, 'today' => $today, 'since' => null, 'ranges' => [], 'names' => []];
        $out['since'] = $db->query("SELECT MIN(day) FROM visit_stats")->fetchColumn() ?: null;
        $st = $db->prepare("SELECT country, SUM(hits) AS n FROM visit_stats WHERE day >= ? GROUP BY country ORDER BY n DESC, country");
        foreach ($ranges as $k => $from) {
            $st->execute([$from]);
            $rows = []; $total = 0;
            foreach ($st->fetchAll() as $r) {
                $cc = (string)$r['country']; $n = (int)$r['n'];
                $rows[] = [$cc, $n]; $total += $n;
                if (!isset($out['names'][$cc])) $out['names'][$cc] = is_array($names) && isset($names[$cc]) ? $names[$cc] : $cc;
            }
            $out['ranges'][$k] = ['from' => $k === 'all' ? $out['since'] : $from, 'total' => $total, 'countries' => count($rows), 'rows' => $rows];
        }
        json_out($out);
    } catch (Throwable $e) {
        http_response_code(500); json_out(['error' => 'Database error']);
    }
}

// ---- live citation metrics (AJAX, logged-in only) ----
// POST index.php?action=metrics_status with JSON {"csrf", "refresh": bool}
//   -> {"ok", "auto", "data", "fetched", "tried", "error"}: what api/metrics.php serves (see api/metricslib.php).
//   "refresh": true asks OpenAlex now (only in automatic mode), instead of waiting for the daily refresh.
if ($action === 'metrics_status' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!is_logged_in()) { http_response_code(401); json_out(['error' => 'Not logged in']); }
    $body = json_decode((string)file_get_contents('php://input'), true);
    require_csrf(is_array($body) && is_string($body['csrf'] ?? null) ? $body['csrf'] : '');
    header('Cache-Control: no-store');
    if (!is_file(__DIR__ . '/../api/metricslib.php')) json_out(['ok' => false, 'error' => 'api/metricslib.php is not on the server']);
    require_once __DIR__ . '/../api/metricslib.php';
    try { $auto = metrics_auto_on(db()); } catch (Throwable $e) { $auto = false; }
    $c = ($auto && ($body['refresh'] ?? false) === true) ? metrics_get(true) : metrics_read_cache();
    json_out(['ok' => true, 'auto' => $auto, 'data' => $c['data'], 'fetched' => $c['fetched'], 'tried' => $c['tried'], 'error' => $c['error']]);
}

// ---- maintenance mode on/off (AJAX, logged-in only) ----
// POST index.php?action=maintenance with JSON {"csrf", "on": bool, "message": str, "until": str}.
// Writes maintenance.html, then creates/deletes the .maintenance flag, then records the state in settings.maintenance.
if ($action === 'maintenance' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!is_logged_in()) { http_response_code(401); json_out(['error' => 'Not logged in']); }
    $body = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($body)) $body = [];
    require_csrf(is_string($body['csrf'] ?? null) ? $body['csrf'] : '');
    $on      = ($body['on'] ?? false) === true;
    $message = is_string($body['message'] ?? null) ? trim($body['message']) : '';
    $until   = is_string($body['until'] ?? null) ? trim($body['until']) : '';
    $message = preg_replace('/\s+/u', ' ', $message) ?? '';
    $until   = preg_replace('/\s+/u', ' ', $until) ?? '';
    $len = fn(string $x): int => function_exists('mb_strlen') ? mb_strlen($x, 'UTF-8') : strlen($x);
    if ($len($message) > 500) { http_response_code(400); json_out(['error' => 'Message is too long (max 500 characters)']); }
    if ($len($until) > 80)    { http_response_code(400); json_out(['error' => 'Expected-back text is too long (max 80 characters)']); }

    $root = maint_root();
    if (!is_writable($root)) { http_response_code(500); json_out(['error' => 'The web root is not writable, so the switch cannot be changed. Check folder permissions.']); }
    if (!maint_write_page($message, $until)) { http_response_code(500); json_out(['error' => 'Could not write maintenance.html']); }
    $flag = $root . '/.maintenance';
    if ($on) {
        // New token for every ON switch; this response re-issues the owner's cookie (maint_set_cookie
        // below sees the changed value) and maint_sync_ok_file removes the old marker.
        if (!maint_flag_on()) maint_rotate_token();
        maint_sync_ok_file(true); // before the flag, so the owner is never locked out
        if (@file_put_contents($flag, 'on since ' . gmdate('c') . "\n") === false) { http_response_code(500); json_out(['error' => 'Could not create the .maintenance flag file']); }
    } elseif (is_file($flag) && !@unlink($flag)) {
        http_response_code(500); json_out(['error' => 'Could not delete the .maintenance flag file']);
    } else {
        maint_sync_ok_file(false);
    }

    $state = ['on' => $on, 'message' => $message, 'until' => $until, 'changed' => gmdate('c')];
    try {
        // Merge into the stored settings so no other setting is touched.
        $st = db()->prepare("SELECT data FROM content WHERE section = 'settings' LIMIT 1");
        $st->execute();
        $row = $st->fetch();
        $settings = $row ? json_decode((string)$row['data']) : null;
        if (!is_object($settings)) $settings = new stdClass();
        $settings->maintenance = (object)$state;
        $sj = json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($sj === false) throw new RuntimeException('encode');
        db()->prepare("INSERT INTO content (section,data) VALUES ('settings',?) ON DUPLICATE KEY UPDATE data=VALUES(data)")
            ->execute([$sj]);
    } catch (Throwable $e) {
        http_response_code(500);
        json_out(['error' => 'The switch was changed (' . ($on ? 'ON' : 'OFF') . '), but saving its state in the database failed.', 'flag' => is_file($flag)]);
    }
    maint_set_cookie();
    json_out(['ok' => true, 'state' => $state, 'flag' => is_file($flag)]);
}

// ---- change password (AJAX, logged-in only) ----
if ($action === 'changepw' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!is_logged_in()) { http_response_code(401); json_out(['error' => 'Not logged in']); }
    $body = json_decode(file_get_contents('php://input'), true);
    require_csrf($body['csrf'] ?? '');
    $cur = (string)($body['current'] ?? '');
    $new = (string)($body['new'] ?? '');
    if (strlen($new) < 10) { http_response_code(400); json_out(['error' => 'New password must be at least 10 characters']); }
    try {
        $stmt = db()->prepare("SELECT * FROM admin_users WHERE username = ? LIMIT 1");
        $stmt->execute([$_SESSION['admin']]);
        $row = $stmt->fetch();
        if (!$row || !password_verify($cur, $row['password_hash'])) {
            http_response_code(403); json_out(['error' => 'Current password is incorrect']);
        }
        db()->prepare("UPDATE admin_users SET password_hash = ? WHERE id = ?")
            ->execute([password_hash($new, PASSWORD_DEFAULT), $row['id']]);
        json_out(['ok' => true]);
    } catch (Throwable $e) {
        http_response_code(500); json_out(['error' => 'Database error']);
    }
}

// ================= LOGIN PAGE =================
if (!is_logged_in()) {
    $err = $_SESSION['login_error'] ?? '';
    unset($_SESSION['login_error']);
    // Fresh CAPTCHA for this login attempt.
    $ca = random_int(1, 9); $cb = random_int(1, 9);
    $_SESSION['captcha'] = (string)($ca + $cb);
    $_SESSION['captcha_q'] = "$ca + $cb = ?";
    $hasGD = function_exists('imagecreatetruecolor');
    ?><!doctype html><html lang="en"><head><meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">
    <title>Admin Login</title>
    <style>body{font-family:system-ui,sans-serif;background:#0c0d16;color:#e8e8ef;display:flex;min-height:100vh;align-items:center;justify-content:center;margin:0}
    form{background:#181a22;border:1px solid #2d324b;padding:32px;border-radius:12px;width:320px}
    h1{font-size:20px;margin:0 0 20px}input{width:100%;box-sizing:border-box;padding:10px;margin-bottom:12px;background:#0c0d16;border:1px solid #2d324b;border-radius:8px;color:#fff}
    button{width:100%;padding:11px;background:linear-gradient(90deg,#35c7ff,#ff4081);border:0;border-radius:8px;color:#fff;font-weight:600;cursor:pointer}
    .err{color:#ff6b6b;font-size:13px;margin-bottom:12px}
    label{display:block;font-size:12px;color:#a2a5b9;margin:0 0 6px}
    .cap{display:flex;align-items:center;gap:10px;margin-bottom:12px}
    .cap img{border:1px solid #2d324b;border-radius:6px}
    .cap .q{font-size:15px;font-weight:600}
    .cap input{margin-bottom:0}</style></head><body>
    <form method="post" action="index.php?action=login">
      <h1>Content Admin</h1>
      <?php if ($err): ?><div class="err"><?= htmlspecialchars($err) ?></div><?php endif; ?>
      <input type="hidden" name="csrf" value="<?= csrf() ?>">
      <input name="username" placeholder="Username" autocomplete="username" required autofocus>
      <input name="password" type="password" placeholder="Password" autocomplete="current-password" required>
      <label>Verification</label>
      <div class="cap">
        <?php if ($hasGD): ?>
          <img src="captcha.php?<?= time() ?>" width="150" height="46" alt="verification question">
        <?php else: ?>
          <span class="q"><?= "$ca + $cb = ?" ?></span>
        <?php endif; ?>
        <input name="captcha" inputmode="numeric" autocomplete="off" placeholder="Answer" required>
      </div>
      <button type="submit">Log in</button>
    </form></body></html><?php
    exit;
}

// ================= EDITOR =================
$content = [];
try {
    foreach (db()->query("SELECT section, data FROM content")->fetchAll() as $r) {
        $content[$r['section']] = json_decode($r['data'], true);
    }
} catch (Throwable $e) { $content = []; }
unset($content[MAINT_PRIVATE_ROW]);
maint_set_cookie(); // (re)issue the bypass cookie for the signed-in owner before any output
if (maint_flag_on()) maint_sync_ok_file(true); // keep the marker for the current token
$maintState = [
    'flag'     => maint_flag_on(),
    'writable' => is_writable(maint_root()),
    'page'     => is_file(maint_root() . '/maintenance.html'),
];

// Content migrations shipped with the backend (admin/migrations/*.json, not web-served).
// Applied from the Maintenance panel; decoded as objects so {} stays {}.
$migrations = [];
foreach ((glob(__DIR__ . '/migrations/*.json') ?: []) as $mf) {
    $m = json_decode((string)file_get_contents($mf));
    if (is_object($m) && isset($m->operations) && is_array($m->operations)) {
        $migrations[] = ['file' => basename($mf), 'data' => $m];
    }
}
$migrationsJson = json_encode($migrations, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
if ($migrationsJson === false) $migrationsJson = '[]';

// field schemas: which keys each section's items have, and which are long text / images
$schemas = [
    'publications' => [
        'title'=>'text','authors'=>'text','venue'=>'text','year'=>'text','pages'=>'text','award'=>'text','doi'=>'text','video'=>'video','description'=>'area','preprint'=>'pdf','bibtex'=>'area',
        // 2026-11 fields (optional): paper pages, search/LLM indexes and the publication list use them
        'slug'=>'text','type'=>'select','venue_short'=>'text','arxiv'=>'text','authors_full'=>'lines','abstract'=>'area','problem'=>'area',
        'relevant_to'=>'lines','keywords'=>'lines','pdf'=>'pdf','code_url'=>'text','tool_url'=>'text','artifact_url'=>'text','dataset_url'=>'text',
        // round 2: homepage "Featured publications" + companion entries (e.g. a poster shown under its paper)
        'selected'=>'bool','companion_of'=>'text',
        // citation details: the paper page's citation tags (Highwire), venue line and JSON-LD use them
        'venue_full'=>'text','track'=>'text','publisher'=>'text','volume'=>'text','issue'=>'text',
        'first_page'=>'text','last_page'=>'text','published_date'=>'text','issn'=>'text',
        // round 3: talk slides + video (shown only when set), research-area tags, co-first authors, thesis handle
        'slides_url'=>'slides','tags'=>'tags','ml_topics'=>'mltags','equal_contribution'=>'csv','handle'=>'text','tool_name'=>'text',
        // thesis details (paper page, citation tags, JSON-LD)
        'institution'=>'text','dissertation_name'=>'text','in_support_of'=>'text','degree'=>'text',
        // round 3 (§22-23): your role, venue ranking, keyword override
        'my_role'=>'text','venue_rank'=>'text','display_keywords'=>'csv',
    ],
    'highlights'   => ['title'=>'text','year'=>'text','date'=>'text','kind'=>'select','organization'=>'text','description'=>'area','link'=>'text','paper_slug'=>'text','image'=>'image','pinned'=>'bool'],
    'news'         => ['date'=>'text','icon'=>'text','title'=>'text','description'=>'area','url'=>'url','paper_slug'=>'text','links'=>'inlinks','tag'=>'select','pinned'=>'bool'],
    'media'        => ['outlet'=>'text','date'=>'text','title'=>'text','url'=>'url'],
    'gallery'      => ['image'=>'image','title'=>'text','description'=>'area','altText'=>'text','caption'=>'area','featuresOwner'=>'tri','date'=>'text','creditText'=>'text'],
    'leadership'   => ['period'=>'text','date'=>'text','role'=>'text','organization'=>'text','place'=>'text','link'=>'text','description'=>'area','highlights'=>'lines','order'=>'text','id'=>'text','aboutOnly'=>'bool','pinned'=>'bool'],
    'service'      => ['period'=>'text','date'=>'text','role'=>'text','description'=>'area','details'=>'area','venue'=>'text','venues'=>'text','category'=>'select','under'=>'text','link'=>'text','order'=>'text','pinned'=>'bool'],
    'references'   => ['name'=>'text','title'=>'text','image'=>'image','text'=>'area'],
    'blog'         => ['title'=>'text','slug'=>'text','date'=>'text','tags'=>'text','summary'=>'area','cover'=>'image','body'=>'area','draft'=>'bool'],
    'faq'          => ['q'=>'text','a'=>'area'], // q = question, a = answer (same keys as src/content/faq.json)
];
// Server upload limits (cPanel > MultiPHP INI Editor), shown next to the upload buttons.
$uploadLimits = ['upload_max_filesize' => (string)ini_get('upload_max_filesize'), 'post_max_size' => (string)ini_get('post_max_size')];
?><!doctype html><html lang="en"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">
<title>Content Admin · palashroy.me</title>
<style>
:root{color-scheme:dark}
*{box-sizing:border-box}
body{font-family:system-ui,sans-serif;background:#0c0d16;color:#e8e8ef;margin:0}
.shell{display:flex;min-height:100vh}
/* Sidebar */
.sidebar{width:250px;flex-shrink:0;background:#12131c;border-right:1px solid #23263a;display:flex;flex-direction:column;position:sticky;top:0;height:100vh}
.brand{padding:18px 20px;font-weight:700;font-size:16px;color:#7ec8e3;border-bottom:1px solid #23263a;display:flex;align-items:center;gap:8px}
.brand small{display:block;color:#6b6f85;font-weight:400;font-size:11px;margin-top:2px}
.side-scroll{flex:1;overflow-y:auto;padding:10px 0}
.grp{padding:14px 20px 6px;font-size:11px;letter-spacing:.08em;text-transform:uppercase;color:#5b6079}
.navlink{display:flex;align-items:center;gap:10px;padding:9px 20px;color:#c7cad8;text-decoration:none;font-size:14px;cursor:pointer;border-left:3px solid transparent}
.navlink:hover{background:#181a26}
.navlink.active{background:#181a26;border-left-color:#35c7ff;color:#fff;font-weight:600}
.navlink .ic{width:20px;text-align:center}
.side-foot{border-top:1px solid #23263a;padding:12px 20px}
.side-foot .who{font-size:12px;color:#6b6f85;margin-bottom:8px}
.logout{display:block;text-align:center;padding:9px;background:#181a22;border:1px solid #2d324b;border-radius:8px;color:#e8e8ef;text-decoration:none;font-size:13px}
.logout:hover{border-color:#ff6b6b;color:#ff6b6b}
/* Main */
main{flex:1;min-width:0}
.topbar{position:sticky;top:0;z-index:5;background:#0c0d16;border-bottom:1px solid #23263a;padding:16px 28px;display:flex;align-items:center;gap:12px}
.topbar h1{font-size:19px;margin:0}
.topbar .hint{color:#6b6f85;font-size:13px;margin-left:auto}
.wrap{max-width:860px;margin:0 auto;padding:22px 28px 90px}
.menu-btn{display:none;background:#181a22;border:1px solid #2d324b;color:#fff;padding:7px 10px;border-radius:8px;cursor:pointer}
/* Cards + fields */
.item{background:#181a22;border:1px solid #2d324b;border-radius:10px;padding:16px;margin-bottom:14px}
h2{font-size:16px;margin:0 0 4px}
.lead{color:#a2a5b9;font-size:13px;margin:0 0 16px}
label{display:block;font-size:12px;color:#a2a5b9;margin:10px 0 4px;text-transform:capitalize}
input,textarea,select{width:100%;padding:8px;background:#0c0d16;border:1px solid #2d324b;border-radius:6px;color:#fff;font-family:inherit;font-size:14px}
[data-doc] label,[data-sw] label,[data-sk] label,[data-mm] label,[data-r] label,[data-vis] label{text-transform:none}
textarea{min-height:70px;resize:vertical}
.row{display:flex;gap:6px;margin-bottom:8px}
.item-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:4px;gap:8px}
.item-head b{font-size:13px;color:#7ec8e3}
.acts{display:flex;gap:6px;flex-shrink:0}
.mv{background:#2d324b;padding:4px 10px;font-size:15px;line-height:1}
button{background:#2d324b;border:0;color:#fff;padding:8px 14px;border-radius:8px;cursor:pointer;font-size:13px}
button.primary{background:linear-gradient(90deg,#35c7ff,#ff4081);font-weight:600}
button.del{background:#3a1420;color:#ff6b6b}
button.up{background:#1c2b3a;color:#7ec8e3;white-space:nowrap}
.bar{display:flex;gap:10px;margin:18px 0 6px;position:sticky;bottom:0;background:#0c0d16;padding:12px 0}
.toast{position:fixed;bottom:20px;left:50%;transform:translateX(-50%);background:#181a22;border:1px solid #2d324b;padding:10px 18px;border-radius:8px;opacity:0;transition:.3s;z-index:50;pointer-events:none}
.toast.show{opacity:1}
img.thumb{max-height:70px;border-radius:6px;margin-top:8px;display:block;border:1px solid #2d324b}
.uprow{display:flex;gap:6px}
.uprow input[data-k]{flex:1}
small{color:#6b6f85;display:block;margin-top:6px}
code{background:#0c0d16;border:1px solid #2d324b;border-radius:4px;padding:1px 5px;font-size:12px;color:#8fd0ec}
.imgfield{margin-top:2px}
@media(max-width:760px){
  .sidebar{position:fixed;left:-260px;z-index:40;transition:left .2s;box-shadow:0 0 40px rgba(0,0,0,.5)}
  .sidebar.open{left:0}
  .menu-btn{display:inline-block}
}
</style></head><body>
<div class="shell">
  <aside class="sidebar" id="sidebar">
    <div class="brand">Content Admin<small>palashroy.me</small></div>
    <div class="side-scroll"><nav id="nav"></nav></div>
    <div class="side-foot">
      <div class="who">Signed in as <b><?= htmlspecialchars($_SESSION['admin']) ?></b></div>
      <a class="logout" href="index.php?action=logout">Log out</a>
    </div>
  </aside>
  <main>
    <div class="topbar">
      <button class="menu-btn" onclick="document.getElementById('sidebar').classList.toggle('open')">☰</button>
      <h1 id="pageTitle">Home</h1>
      <span class="hint">Changes go live within ~1 minute of saving</span>
    </div>
    <div class="wrap" id="app"></div>
  </main>
</div>
<div class="toast" id="toast"></div>
<script>
const CSRF = <?= json_encode(csrf()) ?>;
const SCHEMAS = <?= json_encode($schemas) ?>;
const DATA = <?= json_encode($content, JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
const LABELS = {publications:'Publications',highlights:'Awards & Honors',news:'News & Milestones',media:'In the News',gallery:'Photos',leadership:'Leadership',service:'Academic Service',references:'References',blog:'Blog Posts',faq:'FAQ'};
const MIGRATIONS = <?= $migrationsJson ?>;
const MAINT = <?= json_encode($maintState) ?>;
const UPLOAD_LIMITS = <?= json_encode($uploadLimits) ?>; // PHP limits on this server // live state of the maintenance switch on the server

// Sidebar navigation groups
const NAV = [
  {group:'Content', items:[
    {id:'hero',label:'Home / Hero'},
    {id:'about',label:'Biography'},
    {id:'headings',label:'Sections & Headings'},
    {id:'publications',label:'Publications'},
    {id:'research',label:'Research Statement & Themes'},
    {id:'documents',label:'Documents (CV, statements)'},
    {id:'software',label:'Tools & Benchmarks'},
    {id:'skills',label:'Skills & Methods'},
    {id:'highlights',label:'Awards & Honors'},
    {id:'news',label:'News & Milestones'},
    {id:'media',label:'In the News'},
    {id:'gallery',label:'Photos'},
    {id:'leadership',label:'Leadership'},
    {id:'service',label:'Academic Service'},
    {id:'references',label:'References'},
    {id:'consultation',label:'Collaboration'},
    {id:'blog',label:'Blog Posts'},
    {id:'faq',label:'FAQ'},
  ]},
  {group:'Site', items:[
    {id:'layout',label:'Page Layout'},
    {id:'custom',label:'Custom Sections'},
    {id:'socials',label:'Social Links'},
    {id:'footer',label:'Footer'},
    {id:'settings',label:'Site Settings'},
    {id:'metrics',label:'Research Metrics'},
    {id:'visitors',label:'Visitors'},
    {id:'maintenance',label:'Maintenance Mode & Backup'},
  ]},
  {group:'Account', items:[
    {id:'password',label:'Change Password'},
  ]},
];
const TITLES = {};
NAV.forEach(g=>g.items.forEach(i=>TITLES[i.id]=i.label));

let current = 'hero';

// Page-layout (section order) editing
// Homepage sections of the 2026-11 design, in their fixed order (the website uses this order
// for built-in sections; only custom sections can be moved, and they appear before Contact).
const SECTION_LABELS = {research:'Research',publications:'Publications',software:'Tools & Benchmarks',experience:'Experience (education, teaching, positions, service, Skills & Methods)',awards:'Awards & Honors',news:'News',blog:'From the blog (latest posts on the homepage)',contact:'Contact'};
const DEFAULT_ORDER = ['research','publications','software','experience','awards','news','blog','contact'];
// Older keys still found in saved layouts; the website maps them onto the sections above.
const LEGACY_SECTIONS = {about:1,portfolio:1,leadership:1,service:1,highlights:1,media:1,pictures:1,references:1,consultation:1};
// Same rule as the website (App.tsx SOURCES): a section is hidden when its own key is hidden,
// or when all of its older keys are.
const SECTION_SOURCES = {research:['about'],publications:['portfolio'],software:['software'],experience:['about','leadership','service'],awards:['highlights'],news:['news'],blog:['blog'],contact:['consultation','contact']};
// Custom (user-added) sections
// Ids are only ever "custom-[a-z0-9-]"; anything else (e.g. from a crafted backup) is ignored,
// since ids end up in markup and handlers.
const CUSTOM_ID_RE=/^custom-[a-z0-9-]{1,40}$/;
const validCustom=(arr)=>(Array.isArray(arr)?arr:[]).filter(c=>c && typeof c.id==='string' && CUSTOM_ID_RE.test(c.id));
let customSections = validCustom(DATA.customSections);
// Label for any section id (built-in or custom)
function sectionLabel(id){ if(SECTION_LABELS[id]) return SECTION_LABELS[id]; const c=customSections.find(x=>x.id===id); return c ? ((c.title||c.nav||'Custom section')) : id; }
function knownSection(id){ return !!SECTION_LABELS[id] || !!LEGACY_SECTIONS[id] || customSections.some(c=>c.id===id); }
// Homepage order. A layout saved by this admin starts with the marker "v2" and orders every
// section (built-in and custom); an older saved order only ordered custom sections, so the
// recommended built-in order is used for those. Contact is always last.
function normLayout(ids){
  const ok=id=>id!=='contact' && (DEFAULT_ORDER.includes(id) || customSections.some(c=>c.id===id));
  const out=[...new Set(ids.filter(ok))];
  DEFAULT_ORDER.forEach(id=>{ if(id!=='contact' && !out.includes(id)) out.push(id); });
  customSections.forEach(c=>{ if(!out.includes(c.id)) out.push(c.id); });
  return [...out,'contact'];
}
let layoutOrder = (()=>{
  const saved=Array.isArray(DATA.sectionOrder)?DATA.sectionOrder:[];
  if(saved[0]==='v2') return normLayout(saved.slice(1));
  return normLayout([...DEFAULT_ORDER, ...saved.filter(id=>customSections.some(c=>c.id===id))]);
})();
const layoutForSave=()=>['v2', ...normLayout(layoutOrder)];
let hiddenSet = new Set(Array.isArray(DATA.sectionsHidden) ? DATA.sectionsHidden.filter(knownSection) : []);

// Sections whose headings/descriptions are editable, in page order
const HSECTIONS = [
  {id:'about',label:'Research (homepage section)'},
  {id:'portfolio',label:'Publications'},
  {id:'latest',label:'Latest (short list near the top; switch and count under Site Settings)'},
  {id:'software',label:'Tools & Benchmarks'},
  {id:'skills',label:'Skills & Methods (end of Experience)'},
  {id:'experience',label:'Experience (education, teaching, research positions, service)'},
  {id:'leadership',label:'University governance & leadership (inside Experience)'},
  {id:'service',label:'Reviewing (inside Experience; the title of the Reviewing line. The older title "Reviewing & Service" shows as "Reviewing")'},
  {id:'highlights',label:'Awards & Honors'},
  {id:'news',label:'News'},
  {id:'blog',label:'Blog (menu, /blog/ page and homepage teaser)'},
  {id:'contact',label:'Contact'},
  {id:'documents',label:'Documents (title = the "Documents" heading in Contact)'},
  {id:'photos',label:'Photos page (/photos/)'},
  {id:'faq',label:'Research FAQ page (/faq/)'},
  {id:'aboutPage',label:'About page (/about/)'},
  {id:'media',label:'In the News (older design)'},
  {id:'pictures',label:'Gallery (older design)'},
  {id:'references',label:'References (/about/ shows the title and the note below; the individual references are not shown in the 2026-11 design)'},
  {id:'consultation',label:'Collaboration (older design)'},
];
// Where each section's "Description" (subtitle) appears; the other sections do not show one.
const SUBTITLE_USE = {
  blog:'Description (shown under the title on the /blog/ page)',
  references:'Description (shown in the References note on /about/)',
  contact:'Description (shown in Contact only when Collaboration → Description is empty)',
  photos:'Description (intro line on /photos/; empty = the default line)',
  faq:'Description (intro line on /faq/; empty = the default line)',
};
// Extra heading fields some sections have.
const HEXTRA = {
  about:[['themesTitle','Heading above the theme cards (empty = "Research themes")'],['alsoLabel','Label before the other papers under the cards (empty = "Also")']],
  portfolio:[['selectedTitle','Heading above the featured papers'],['otherTitle','Heading above the other papers'],['allLinkText','Link to /publications/ (empty = "All publications with abstracts and BibTeX")']],
  experience:[['educationTitle','Education heading (empty = "Education")'],['teachingTitle','Teaching heading (empty = "Teaching & Mentoring")'],['positionsTitle','Positions heading (empty = "Research & Professional Positions")'],['talksTitle','Talks heading (empty = "Invited Talks")']],
  highlights:[['scholarshipsTitle','Scholarships heading (empty = "Scholarships")']],
  news:[['olderTitle','Label of the collapsed older items (empty = "Older news")']],
  blog:[['teaserTitle','Heading of the homepage blog teaser'],['allPostsText','Link under the teaser (empty = "All posts")']],
  contact:[['profilesTitle','Profiles heading (empty = "Profiles")'],['formTitle','Form heading (empty = "Send a message")']],
  latest:[['allLabel','Link text after the list (goes to News)']],
};
// Current live values — used to prefill the editor so a first save never blanks the site.
const DEFAULT_HEADINGS = {"about": {"nav": "Research", "badge": "", "title": "Research", "subtitle": ""}, "portfolio": {"nav": "Publications", "badge": "", "title": "Publications", "subtitle": "", "selectedTitle": "Featured publications", "otherTitle": "Other publications"}, "news": {"nav": "News", "badge": "", "title": "News", "subtitle": ""}, "leadership": {"nav": "Leadership", "badge": "", "title": "University Governance & Leadership", "subtitle": ""}, "service": {"nav": "Service", "badge": "", "title": "Reviewing & Service", "subtitle": ""}, "highlights": {"nav": "Awards", "badge": "", "title": "Awards & Honors", "subtitle": ""}, "media": {"nav": "Media", "badge": "", "title": "Media Coverage", "subtitle": ""}, "pictures": {"nav": "Gallery", "badge": "", "title": "Gallery", "subtitle": ""}, "references": {"nav": "References", "badge": "", "title": "References", "subtitle": "References are available on request.", "note": "Colleagues and mentors include [Dr. Kevin Schneider](https://artsandscience.usask.ca/profile/KSchneider), [Dr. Chanchal Roy](https://clones.usask.ca/), [Dr. Farouq Al-Omari](https://www.tru.ca/science/departments/engineering/Faculty.html), [Dr. Banani Roy](https://www.cs.usask.ca/people/faculty%20profiles/banani-roy.php) and [Dr. Cody Phillips](https://www.qut.edu.au/about/our-people/academic-profiles/cody.phillips).", "mentors": ""}, "contact": {"nav": "Contact", "badge": "", "title": "Contact", "subtitle": ""}, "consultation": {"nav": "Collaborate", "badge": "", "title": "Collaboration", "subtitle": ""}, "blog": {"nav": "Blog", "badge": "", "title": "Blog", "subtitle": "Research notes and paper walkthroughs.", "teaserTitle": "From the blog"}, "experience": {"nav": "Experience", "badge": "", "title": "Experience", "subtitle": ""}, "software": {"nav": "Tools & Benchmarks", "badge": "", "title": "Tools & Benchmarks", "subtitle": ""}, "skills": {"nav": "Skills & Methods", "badge": "", "title": "Skills & Methods", "subtitle": ""}, "documents": {"nav": "Documents", "badge": "", "title": "Documents", "subtitle": ""}, "photos": {"nav": "Photos", "badge": "", "title": "Photos", "subtitle": ""}, "faq": {"nav": "Research FAQ", "badge": "", "title": "Research FAQ", "subtitle": ""}, "aboutPage": {"nav": "About", "badge": "", "title": "About", "subtitle": ""}, "latest": {"nav": "Latest", "badge": "", "title": "Latest", "subtitle": "", "allLabel": "All news"}};

const FMT_HINT = `<p class="lead" style="background:#12131c;border:1px solid #23263a;border-radius:8px;padding:10px 12px"><b style="color:#7ec8e3">Formatting:</b> link = <code>[text](https://link.com)</code> — put the URL in <b>(round brackets)</b>. Bold = <code>**word**</code>, italic = <code>*word*</code>. A bare address like <code>srlab.usask.ca</code> becomes a link automatically. Works in descriptions, paragraphs, hero text and section subtitles.</p>`;
const GEN_NOTE = `<p class="lead" style="background:#12131c;border:1px solid #23263a;border-radius:8px;padding:10px 12px"><b style="color:#7ec8e3">When changes show:</b> the homepage and blog read the database, so they update within about a minute of saving. The generated pages (<code>/publications/</code>, paper pages, <code>/faq/</code>, <code>/photos/</code>, <code>/about/</code>, static blog pages, <code>llms.txt</code>, <code>sitemap.xml</code>) refresh the next time the site is rebuilt and uploaded.</p>`;
function toast(msg){const t=document.getElementById('toast');t.textContent=msg;t.classList.add('show');setTimeout(()=>t.classList.remove('show'),2200);}
const escA = v => (v==null?'':String(v)).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/"/g,'&quot;');
function v(id){ const el=document.getElementById(id); return el?el.value.trim():''; }
const app = ()=>document.getElementById('app');

function renderNav(){
  document.getElementById('nav').innerHTML = NAV.map(g=>
    `<div class="grp">${g.group}</div>` + g.items.map(i=>
      `<a class="navlink ${i.id===current?'active':''}" onclick="switchTo('${i.id}')">${i.label}</a>`
    ).join('')
  ).join('');
}
window.switchTo = s => {
  current = s;
  document.getElementById('pageTitle').textContent = TITLES[s] || s;
  document.getElementById('sidebar').classList.remove('open');
  renderNav(); renderSection();
};

async function postSection(section,data,label){
  try{ const r=await fetch('index.php?action=save',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({csrf:CSRF,section,data})}); const j=await r.json(); toast(j.ok?(label+' saved ✓'):('Error: '+(j.error||'failed'))); return j.ok; }catch(e){ toast('Network error'); return false; }
}

// ---- file uploads (images + resume) ----
async function uploadFile(file, kind, cb, name){
  const fd=new FormData(); fd.append('csrf',CSRF); fd.append('kind',kind); fd.append('file',file); if(name) fd.append('name',name);
  toast('Uploading…');
  try{
    const r=await fetch('upload.php',{method:'POST',body:fd});
    const j=await r.json();
    if(j.ok){ cb(j.url); toast('Uploaded ✓'); } else toast('Error: '+(j.error||'upload failed'));
  }catch(e){ toast('Upload failed'); }
}
window.onPickBodyImage=(fi)=>{
  const f=fi.files[0]; if(!f) return;
  const box=fi.closest('.blogimg');
  const cap=(box.querySelector('.bi_cap').value||'').trim();
  const ta=fi.closest('.item').querySelector('textarea[data-k="body"]');
  uploadFile(f,'image',url=>{
    const md=`\n![${cap}](${url})\n`;
    const start=(ta.selectionStart!=null)?ta.selectionStart:ta.value.length;
    const end=(ta.selectionEnd!=null)?ta.selectionEnd:start;
    ta.value = ta.value.slice(0,start) + md + ta.value.slice(end);
    ta.focus(); ta.selectionStart=ta.selectionEnd=start+md.length;
    box.querySelector('.bi_cap').value='';
    mark();
  });
  fi.value='';
};
window.onPickImage=(fi)=>{
  const f=fi.files[0]; if(!f) return;
  const wrap=fi.closest('.imgfield');
  const txt=wrap.querySelector('input[data-k]');
  uploadFile(f,'image',url=>{
    txt.value=url;
    let thumb=wrap.querySelector('img.thumb');
    if(!thumb){ thumb=document.createElement('img'); thumb.className='thumb'; wrap.appendChild(thumb); }
    thumb.src=url;
  });
  fi.value='';
};
window.onPickMedia=(fi,kind)=>{
  const f=fi.files[0]; if(!f) return;
  const wrap=fi.closest('.imgfield');
  const txt=wrap.querySelector('input[data-k]');
  const it=fi.closest('.item'); const t=it && it.querySelector('input[data-k="slug"]');
  uploadFile(f,kind,url=>{ txt.value=url; mark(); },(t&&t.value?t.value:'')+'-'+kind);
  fi.value='';
};
window.onPickPdf=(fi)=>{
  const f=fi.files[0]; if(!f) return;
  const wrap=fi.closest('.imgfield');
  const txt=wrap.querySelector('input[data-k]');
  uploadFile(f,'pdf',url=>{ txt.value=url; });
  fi.value='';
};

// Fetch an article's title from its URL and fill in the Title (and Outlet, if empty).
window.fetchTitle=async(btn)=>{
  const item=btn.closest('.item');
  const urlInput=btn.parentNode.querySelector('input[data-k]');
  const url=(urlInput.value||'').trim();
  if(!url){ toast('Enter the article URL first'); return; }
  toast('Fetching title…');
  try{
    const r=await fetch('fetchtitle.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({csrf:CSRF,url})});
    const j=await r.json();
    if(!j.ok){ toast('Error: '+(j.error||'could not fetch')); return; }
    const t=item.querySelector('input[data-k="title"]'); if(t){ t.value=j.title; }
    const o=item.querySelector('input[data-k="outlet"]'); if(o && !o.value.trim() && j.siteName){ o.value=j.siteName; }
    toast('Title fetched ✓');
  }catch(e){ toast('Network error'); }
};

// ---- Page Layout ----
function sectionOff(id){ return hiddenSet.has(id) || (SECTION_SOURCES[id] ? SECTION_SOURCES[id].every(k=>hiddenSet.has(k)) : false); }
function renderLayout(){
  layoutOrder=normLayout(layoutOrder);
  const movable=layoutOrder.filter(id=>id!=='contact');
  const row=(id,label,acts,off)=>`<div class="item" style="display:flex;justify-content:space-between;align-items:center;gap:10px;${off?'opacity:.5':''}">`+
    `<b style="color:${off?'#6b6f85':'#7ec8e3'}">${escA(label)}${off?' <span style="font-weight:400;font-size:12px">(hidden)</span>':''}</b><span class="acts">${acts}</span></div>`;
  const showBtn=(id,off)=>`<button class="mv" title="${off?'Show on website':'Hide from website'}" data-id="${escA(id)}" onclick="toggleHidden(this.dataset.id)">${off?'Show':'Hide'}</button>`;
  app().innerHTML = `<h2>Page Layout</h2><p class="lead">Reorder and show/hide homepage sections. Use the arrows to move a section (built-in or custom) up or down; the top menu follows the same order. The hero is always first and Contact always last. Hidden sections keep their content, so you can bring them back anytime. A section with no content (for example News with no items) is left out automatically. The recommended order for academic and industry readers is Research · Publications · Tools &amp; Benchmarks · Experience · Awards · News · Blog.</p>`+
    `<div>`+
    movable.map((id,i)=>{ const isCustom=!SECTION_LABELS[id]; const off=isCustom?hiddenSet.has(id):sectionOff(id); return row(id,(isCustom?'Custom: ':'')+sectionLabel(id),showBtn(id,off)+
      `<button class="mv" title="Move up" aria-label="Move ${escA(sectionLabel(id))} up" data-id="${escA(id)}" onclick="moveSection(this.dataset.id,-1)" ${i===0?'disabled':''}>↑</button>`+
      `<button class="mv" title="Move down" aria-label="Move ${escA(sectionLabel(id))} down" data-id="${escA(id)}" onclick="moveSection(this.dataset.id,1)" ${i===movable.length-1?'disabled':''}>↓</button>`,off); }).join('')+
    (()=>{ const off=sectionOff('contact'); return row('contact',SECTION_LABELS.contact,showBtn('contact',off),off); })()+
    `</div>`+
    `<p class="lead" style="margin-top:12px">Photos (gallery) are shown on the separate <b>/photos/</b> page, not on the homepage, so they have no switch here. The References note (Sections &amp; Headings → References) is shown on <b>/about/</b>; the individual references (testimonials) are not shown in the 2026-11 design.</p>`+
    `<div class="bar"><button class="primary" onclick="saveLayout()">Save Layout</button> <button onclick="resetLayout()">Reset to recommended order</button></div>`;
}
window.moveSection=(id,dir)=>{
  const list=normLayout(layoutOrder).filter(x=>x!=='contact');
  const i=list.indexOf(id), j=i+dir; if(i<0||j<0||j>=list.length) return;
  [list[i],list[j]]=[list[j],list[i]];
  layoutOrder=normLayout(list); renderLayout();
};
window.resetLayout=()=>{
  if(!confirm('Put the built-in sections back in the recommended order? (Custom sections go after them; nothing is shown or hidden. Click Save Layout to keep it.)')) return;
  layoutOrder=normLayout([...DEFAULT_ORDER, ...customSections.map(c=>c.id)]); renderLayout();
};
window.toggleHidden=(id)=>{
  if(!sectionOff(id)){ hiddenSet.add(id); renderLayout(); return; }
  hiddenSet.delete(id);
  // Un-hide the older keys that still hide it, but never the shared "about" key for Experience
  // (that would also show Research); removing leadership/service is enough there.
  const src=(SECTION_SOURCES[id]||[]).filter(k=>!(id!=='research' && k==='about' && (SECTION_SOURCES[id]||[]).length>1));
  if(sectionOff(id)) src.forEach(k=>hiddenSet.delete(k));
  renderLayout();
};
window.saveLayout=async()=>{
  try{
    const body=(section,data)=>fetch('index.php?action=save',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({csrf:CSRF,section,data})}).then(r=>r.json());
    const ord=layoutForSave();
    const [a,b]=await Promise.all([body('sectionOrder',ord), body('sectionsHidden',[...hiddenSet])]);
    if(a.ok) DATA.sectionOrder=ord; if(b.ok) DATA.sectionsHidden=[...hiddenSet];
    toast((a.ok&&b.ok)?'Layout & visibility saved ✓':('Error: '+((a.error||b.error)||'failed')));
  }catch(e){ toast('Network error'); }
};

// ---- Site settings ----
const SLIDER_SECTIONS=[['portfolio','Publications'],['leadership','Leadership'],['service','Academic Service'],['highlights','Achievements'],['pictures','Pictures'],['references','References']];
function chk(id,on,label,help){ return `<label style="display:flex;align-items:center;gap:8px;text-transform:none;font-size:14px;color:#e8e8ef;margin-top:10px"><input type="checkbox" id="${id}" style="width:auto" ${on?'checked':''}> ${label}</label>`+(help?`<small>${help}</small>`:''); }
function renderSettings(){
  const s = Object.assign({sliderSeconds:11,resumeUrl:'',sliderPerSection:{},themePrompt:true,blogInNav:true}, DATA.settings||{});
  const per = s.sliderPerSection||{};
  app().innerHTML = `<h2>Site Settings</h2><p class="lead">Switches for the whole site. Maintenance mode is under <b>Maintenance Mode &amp; Backup</b>.</p>`+
    `<div class="item"><div class="item-head"><b>Navigation and display</b></div>`+
    chk('st_bloginnav', s.blogInNav!==false, 'Show "Blog" in the top menu', 'On by default. The /blog/ page shows "Posts coming soon" until you publish a post (a post is published when its draft box is unticked).')+
    chk('st_themeprompt', s.themePrompt!==false, 'Ask first-time visitors to choose light or dark display', 'A small, dismissible prompt in the bottom corner. It never shows again once a visitor chooses or closes it; the menu toggle is always there.')+
    `</div>`+
    `<div class="item"><div class="item-head"><b>Hero links</b></div>`+
    `<label for="st_herolinks">Profile links under your name, in order (comma-separated platform names from Social Links; "email" = your email)</label><input id="st_herolinks" value="${escA((Array.isArray(s.heroLinks)&&s.heroLinks.length?s.heroLinks:['scholar','linkedin','github','email']).join(', '))}" placeholder="scholar, linkedin, github, email">`+
    `<small>Each link uses the URL and label from <b>Social Links</b> (hidden links are skipped). Known names: ${PLATFORMS.join(', ')}.</small>`+
    `</div>`+
    `<div class="item"><div class="item-head"><b>Latest (short list near the top of the homepage)</b></div>`+
    chk('st_showlatest', s.showLatest!==false, 'Show the "Latest" list', 'Dated one-line updates next to your photo (on phones: right after the hero links). Only pinned items appear at the top: tick "Pin to top (Latest)" on a News, Award, Leadership or Academic Service item. Leave all unpinned to hide the strip.')+
    `<label for="st_latestcount">Maximum number of pinned items shown (1 to 3)</label><input id="st_latestcount" type="number" min="1" max="3" value="${escA(Math.min(3,Math.max(1,parseInt(s.latestCount,10)||2)))}">`+
    `</div>`+
    `<div class="item"><div class="item-head"><b>Research-area tags</b></div>`+
    `<p class="lead" style="margin:4px 0">The tags you can tick on each publication. The short tag is shown on the website; the long label (optional) explains an abbreviation, e.g. AI4SE = AI for Software Engineering. Renaming a tag here does not rename it on papers that already use it.</p>`+
    `<div id="st_tags">`+tagVocab().map(tagRow).join('')+`</div>`+
    `<button onclick="document.getElementById('st_tags').insertAdjacentHTML('beforeend',tagRow({tag:'',label:''}))">+ Add tag</button>`+
    `</div>`+
    `<div class="item"><div class="item-head"><b>AI/ML topics</b></div>`+
    `<p class="lead" style="margin:4px 0">The second set of tags on each publication, so machine-learning readers find your papers. On the website they are merged with the research-area tags into one keyword row per paper; they are kept apart in the /publications/ filter, the search data (JSON-LD) and llms.txt. Same rules as the research-area tags.</p>`+
    `<div id="st_mltags">`+mlVocab().map(tagRow).join('')+`</div>`+
    `<button onclick="document.getElementById('st_mltags').insertAdjacentHTML('beforeend',tagRow({tag:'',label:''}))">+ Add topic</button>`+
    `</div>`+
    `<div class="item"><div class="item-head"><b>Topic order for search data and llms.txt</b></div>`+
    `<label for="st_areatags">Research areas, in order (comma-separated tags from the research-area list)</label><input id="st_areatags" value="${escA((Array.isArray(s.areaTags)&&s.areaTags.length?s.areaTags:DEFAULT_AREA_TAGS).join(', '))}">`+
    `<label for="st_mlareatags">AI/ML topics, in order (comma-separated topics from the AI/ML list)</label><input id="st_mlareatags" value="${escA((Array.isArray(s.mlAreaTags)&&s.mlAreaTags.length?s.mlAreaTags:DEFAULT_ML_AREA_TAGS).join(', '))}">`+
    `<small>Not shown as rows on the homepage (the 2026-11 design has none). These lists set the order of the research areas and AI/ML topics used for the llms.txt groupings and your profile's "knowsAbout" search data; they refresh on the next rebuild. Narrow tags (e.g. Benchmark, Tool) can stay off these lists and still work as tags and filters.</small>`+
    `</div>`+
    `<div class="item imgfield">`+
    `<label>Resume / CV (PDF), older setting</label>`+
    `<div class="uprow"><input data-k="resume" id="st_resume" value="${escA(s.resumeUrl)}" placeholder="/uploads/resume.pdf"><button type="button" class="up" onclick="this.nextElementSibling.click()">Upload PDF</button><input type="file" accept="application/pdf" hidden onchange="onPickResume(this)"></div>`+
    (s.resumeUrl?`<small>Current: <a href="${escA(s.resumeUrl)}" target="_blank" rel="noopener" style="color:#7ec8e3">${escA(s.resumeUrl)}</a></small>`:'')+
    `<small>Prefer <b>Documents (CV, statements)</b>. This link is used as the CV only when Documents has no CV link.</small>`+
    `</div>`+
    `<details class="item"><summary style="cursor:pointer;color:#a2a5b9">Older design settings (not used by the 2026-11 design)</summary>`+
    `<label>Default slider speed — seconds between slides</label><input id="st_slider" type="number" min="2" max="60" value="${escA(s.sliderSeconds||11)}">`+
    SLIDER_SECTIONS.map(([id,label])=>`<label>${label}</label><input class="st_per" data-sec="${id}" type="number" min="2" max="60" placeholder="default (${s.sliderSeconds||11})" value="${escA(per[id]||'')}">`).join('')+
    chk('st_yearchart', s.showYearChart!==false, 'Show the "Publications per year" bar chart')+
    chk('st_graph', s.showResearchGraph!==false, 'Show the "What I work on" research graph')+
    `</details>`+
    `<div class="bar"><button class="primary" onclick="saveSettings()">Save Settings</button></div>`;
}
function readVocabRows(sel){ const seen={}; return [...document.querySelectorAll(sel+' [data-tg]')].map(r=>({tag:r.querySelector('[data-tgt]').value.trim(),label:r.querySelector('[data-tgl]').value.trim()})).filter(t=>t.tag && !seen[t.tag] && (seen[t.tag]=1)); }
// "A, B (x, y), C" -> known names first (longest match), then plain comma split for the rest.
function splitKnown(txt, known){
  const out=[]; let rest=String(txt||'');
  known.slice().sort((a,b)=>b.length-a.length).forEach(k=>{ const i=rest.toLowerCase().indexOf(k.toLowerCase()); if(k && i>=0){ out.push([i,k]); rest=rest.slice(0,i)+' '.repeat(k.length)+rest.slice(i+k.length); } });
  rest.split(',').forEach(x=>{ const t=x.trim(); if(t) out.push([String(txt).indexOf(t),t]); });
  const seen={}; return out.sort((a,b)=>a[0]-b[0]).map(x=>x[1]).filter(t=>!seen[t.toLowerCase()] && (seen[t.toLowerCase()]=1));
}
function tagRow(t){ t=t||{}; return `<div class="row" data-tg style="align-items:center;gap:8px"><input data-tgt value="${escA(t.tag)}" placeholder="Tag, e.g. AI4SE" style="width:170px"><input data-tgl value="${escA(t.label)}" placeholder="Long label (optional)" style="flex:1"><button class="mv" title="Move up" onclick="moveEl(this,'[data-tg]',-1)">↑</button><button class="mv" title="Move down" onclick="moveEl(this,'[data-tg]',1)">↓</button><button class="del" onclick="if(confirm('Remove this tag from the list? Papers that use it keep it until you untick it.')) this.closest('[data-tg]').remove()">✕</button></div>`; }
window.onPickResume=(fi)=>{ const f=fi.files[0]; if(!f) return; uploadFile(f,'resume',url=>{ document.getElementById('st_resume').value=url; },'cv'); fi.value=''; };
window.saveSettings=()=>{
  const per={};
  document.querySelectorAll('.st_per').forEach(i=>{ const n=parseInt(i.value); if(n>0) per[i.dataset.sec]=n; });
  // Merge into the stored settings so keys this page doesn't show (e.g. maintenance) are kept.
  const base=(DATA.settings && typeof DATA.settings==='object' && !Array.isArray(DATA.settings)) ? DATA.settings : {};
  const data=Object.assign({}, base, {sliderSeconds:parseInt(document.getElementById('st_slider').value)||11,sliderPerSection:per,resumeUrl:v('st_resume'),
    showYearChart:document.getElementById('st_yearchart').checked,showResearchGraph:document.getElementById('st_graph').checked,
    blogInNav:document.getElementById('st_bloginnav').checked,themePrompt:document.getElementById('st_themeprompt').checked,
    heroLinks:(()=>{ const seen={}; return v('st_herolinks').split(',').map(x=>x.trim().toLowerCase()).filter(x=>x && PLATFORMS.indexOf(x)>=0 && !seen[x] && (seen[x]=1)); })(),
    showLatest:document.getElementById('st_showlatest').checked,
    latestCount:Math.min(3,Math.max(1,parseInt(document.getElementById('st_latestcount').value,10)||2)),
    tagVocabulary:readVocabRows('#st_tags'), mlTopicVocabulary:readVocabRows('#st_mltags'),
    // A comma inside a tag (e.g. "Task Arithmetic (TIES, DARE)") is not split: entries are matched against the lists.
    areaTags:splitKnown(v('st_areatags'), readVocabRows('#st_tags').map(t=>t.tag)),
    mlAreaTags:splitKnown(v('st_mlareatags'), readVocabRows('#st_mltags').map(t=>t.tag))});
  DATA.settings=data; postSection('settings',data,'Settings');
};

// ---- Sections & Headings ----
function renderHeadings(){
  const H = DATA.headings || {};
  let html = `<h2>Sections & Headings</h2><p class="lead">Edit the title and description shown above each section, plus its <b>menu label</b>. The website uses the same menu labels on every page (homepage, blog, publications, FAQ, photos). The 2026-11 design does not show badges. Hide or reorder homepage sections under <b>Page Layout</b>; turn the Blog menu item on or off under <b>Site Settings</b>.</p>`+FMT_HINT+GEN_NOTE;
  HSECTIONS.forEach(s=>{
    const h = Object.assign({}, DEFAULT_HEADINGS[s.id]||{}, H[s.id]||{});
    html += `<div class="item"><div class="item-head"><b>${s.label}</b></div>`+
      `<label>Menu label (top navigation)</label><input id="hd_${s.id}_nav" value="${escA(h.nav)}">`+
      `<label>Badge (small pill above the title)</label><input id="hd_${s.id}_badge" value="${escA(h.badge)}">`+
      `<label>Title (big heading)</label><input id="hd_${s.id}_title" value="${escA(h.title)}">`+
      `<label>${SUBTITLE_USE[s.id]||'Description (not shown in the 2026-11 design)'}</label><textarea id="hd_${s.id}_subtitle">${escA(h.subtitle)}</textarea>`;
    (HEXTRA[s.id]||[]).forEach(([k,lab])=>{ html += `<label>${lab}</label><input id="hd_${s.id}_${k}" value="${escA(h[k])}">`; });
    if(s.id==='references'){
      html += `<label>Extra italic note</label><textarea id="hd_references_note">${escA(h.note)}</textarea>`+
        `<label>Mentors line ("I have had the privilege to work with…")</label><textarea id="hd_references_mentors">${escA(h.mentors)}</textarea>`;
    }
    html += `</div>`;
  });
  html += `<div class="bar"><button class="primary" onclick="saveHeadings()">Save Sections & Headings</button></div>`;
  app().innerHTML = html;
}
window.saveHeadings=()=>{
  // Start from the stored headings so keys this editor doesn't list are kept.
  const out=Object.assign({}, (DATA.headings && typeof DATA.headings==='object' && !Array.isArray(DATA.headings)) ? DATA.headings : {});
  HSECTIONS.forEach(s=>{
    const o=Object.assign({}, (out[s.id] && typeof out[s.id]==='object') ? out[s.id] : {}, {nav:v('hd_'+s.id+'_nav'),badge:v('hd_'+s.id+'_badge'),title:v('hd_'+s.id+'_title'),subtitle:v('hd_'+s.id+'_subtitle')});
    (HEXTRA[s.id]||[]).forEach(([k])=>{ o[k]=v('hd_'+s.id+'_'+k); });
    if(s.id==='references'){ o.note=v('hd_references_note'); o.mentors=v('hd_references_mentors'); }
    out[s.id]=o;
  });
  DATA.headings=out; postSection('headings',out,'Sections & Headings');
};

// ---- Research graph (any number of nodes) ----
function rgNodes(){
  if(Array.isArray(DATA.research&&DATA.research.nodes)) return DATA.research.nodes.map(n=> typeof n==='string'?{label:n,main:false}:{label:n.label||'',main:!!n.main});
  const r=DATA.research||{};
  const hubs=Array.isArray(r.hubs)?r.hubs:['Code Clones','Large Language Models'];
  const leaves=Array.isArray(r.leaves)?r.leaves:['Clone Detection','Cross-language Clones','Green AI','Clone Refactoring'];
  return [...hubs.map(l=>({label:l,main:true})), ...leaves.map(l=>({label:l,main:false}))];
}
function rgRow(n){ n=n||{}; return `<div class="row" data-rg style="align-items:center;gap:8px"><input data-rgl value="${escA(n.label)}" placeholder="Topic label" style="flex:1"><label style="display:flex;align-items:center;gap:5px;margin:0;text-transform:none;white-space:nowrap;color:#7ec8e3;font-size:13px"><input type="checkbox" data-rgm ${n.main?'checked':''} style="width:auto"> Main</label><button class="del" onclick="this.closest('[data-rg]').remove()">✕</button></div>`; }
function rtRow(t){ t=t||{}; const pl=Array.isArray(t.paper_slugs)&&t.paper_slugs.length?t.paper_slugs:t.papers; const ps=Array.isArray(pl)?pl.join(', '):String(pl||'');
  const kw=Array.isArray(t.keywords)?t.keywords.join(', '):String(t.keywords||'');
  const known=['id','title','label','papers','paper_slugs','summary','keywords','key_result']; const extra={}; Object.keys(t).forEach(k=>{ if(known.indexOf(k)<0) extra[k]=t[k]; });
  return `<div class="item" data-rt data-id="${escA(t.id||'')}" data-extra="${escA(JSON.stringify(extra))}"><label>Theme (card title)</label><input data-rtt value="${escA(t.title||t.label||'')}" placeholder="e.g. Clone detection in the LLM era">`+
    `<label>Summary: one sentence under the title (faithful to the papers' abstracts)</label><input data-rts value="${escA(t.summary||'')}" placeholder="e.g. Do clone detectors and benchmarks hold up when code is written by AI?">`+
    `<label>Key result (optional, one line, numbers exactly as in the published abstract)</label><input data-rtr value="${escA(t.key_result||'')}" placeholder="e.g. Up to 81% lower CO2 emissions while keeping about 98% of clone-detection accuracy.">`+
    `<label>Keyword chips: 3 or 4, comma-separated (plain text, not links)</label><input data-rtk value="${escA(kw)}" placeholder="e.g. Code Clones, Program Comprehension, LLM-Generated Code">`+
    `<label>Paper slugs (comma-separated; each becomes a short venue link on the card, e.g. "ICSME '25")</label><input data-rtp value="${escA(ps)}" placeholder="e.g. gptclonebench, mergese">`+
    `<button class="del" onclick="this.closest('[data-rt]').remove()">Remove theme</button></div>`; }
function renderResearch(){
  const R=DATA.research||{};
  const also=Array.isArray(R.also)?R.also.join(', '):'';
  app().innerHTML = `<h2>Research statement</h2><p class="lead">Two or three sentences at the top of the homepage Research section: the questions you work on, your approach and why it matters. Don't repeat the hero introduction (labs and supervisor are named there).</p>`+FMT_HINT+
    `<div class="item"><textarea id="rs_statement" style="min-height:110px">${escA(R.statement)}</textarea></div>`+
    `<h2 style="margin-top:28px">Research themes</h2><p class="lead">Shown as theme cards in the Research section of the homepage (2 per row on wide screens) and on /about/, and in /publications/ ("By theme"). List each theme's papers by their slug (the "slug" field in Publications, e.g. <code>gptclonebench</code>). The /about/ and /publications/ pages refresh on the next rebuild.</p>`+
    `<div id="rtRows">`+(Array.isArray(R.themes)?R.themes:[]).map(rtRow).join('')+`</div>`+
    `<button onclick="addRtRow()">+ Add theme</button>`+
    `<label style="margin-top:16px">"Also" line: other papers (slugs, comma-separated)</label><input id="rs_also" value="${escA(also)}" placeholder="e.g. llms-threat-programming-platforms, paper-author-ranking">`+
    `<div class="bar"><button class="primary" onclick="saveResearch()">Save Research</button></div>`+
    `<details class="item" style="margin-top:20px"><summary style="cursor:pointer;color:#a2a5b9">Research graph (older design only)</summary>`+
    `<p class="lead">A network diagram of your research. Add as many topics as you like. Tick <b>Main</b> for the big central circles (usually 1–3); the rest become smaller nodes that orbit and auto-connect to the nearest main topic. Keep labels short so they fit.</p>`+
    `<div id="rgNodes">`+rgNodes().map(rgRow).join('')+`</div>`+
    `<button onclick="addRgNode()">+ Add topic</button>`+
    `<small>Saved together with the statement and themes.</small></details>`;
}
window.addRgNode=()=>{ document.getElementById('rgNodes').insertAdjacentHTML('beforeend', rgRow({})); };
window.addRtRow=()=>{ document.getElementById('rtRows').insertAdjacentHTML('beforeend', rtRow({})); };
window.saveResearch=()=>{
  const nodes=[...document.querySelectorAll('#rgNodes [data-rg]')].map(r=>({label:r.querySelector('[data-rgl]').value.trim(), main:r.querySelector('[data-rgm]').checked})).filter(n=>n.label);
  const themes=[...document.querySelectorAll('#rtRows [data-rt]')].map(r=>{
    const title=r.querySelector('[data-rtt]').value.trim();
    const papers=r.querySelector('[data-rtp]').value.split(/[,\n]/).map(x=>x.trim()).filter(Boolean);
    const id=r.getAttribute('data-id')||title.toLowerCase().replace(/[^a-z0-9]+/g,'-').replace(/^-+|-+$/g,'');
    let extra={}; try{ extra=JSON.parse(r.getAttribute('data-extra')||'{}')||{}; }catch(_){ extra={}; }
    const q=(sel)=>{ const el=r.querySelector(sel); return el?el.value.trim():''; };
    const keywords=q('[data-rtk]').split(',').map(x=>x.trim()).filter(Boolean);
    const t=Object.assign(extra,{id,title,summary:q('[data-rts]'),key_result:q('[data-rtr]'),keywords,papers});
    ['summary','key_result'].forEach(k=>{ if(!t[k]) delete t[k]; }); if(!t.keywords.length) delete t.keywords;
    return t;
  }).filter(t=>t.title);
  // Keep any other keys (older hubs/leaves) so nothing is lost.
  const also=v('rs_also').split(/[,\n]/).map(x=>x.trim()).filter(Boolean);
  const data=Object.assign({}, DATA.research||{}, {nodes, themes, statement:v('rs_statement'), also}); DATA.research=data; postSection('research',data,'Research');
};

// ---- Documents (CV, statements): {items:[{label,url,kind}]} ----
const DOC_KINDS=[['cv','CV'],['resume','Resume'],['research_statement','Research statement'],['teaching_statement','Teaching statement'],['diversity_statement','Diversity statement'],['other','Other']];
const DEFAULT_DOCUMENTS={items:[{label:'CV',url:'',kind:'cv'},{label:'Research Statement',url:'',kind:'research_statement'},{label:'Teaching Statement',url:'',kind:'teaching_statement'}]};
function listOf(sec,key,def){ const d=DATA[sec]; if(d && Array.isArray(d[key])) return d[key]; if(Array.isArray(d)) return d; return def[key]; }
function docRow(d){
  d=d||{label:'',url:'',kind:'other'};
  const opts=DOC_KINDS.map(([k,l])=>`<option value="${k}" ${d.kind===k?'selected':''}>${l}</option>`).join('');
  return `<div class="item imgfield" data-doc>`+
    `<div class="item-head"><b>${escA(d.label||'Document')}</b><span class="acts"><button class="mv" title="Move up" onclick="moveEl(this,'[data-doc]',-1)">↑</button><button class="mv" title="Move down" onclick="moveEl(this,'[data-doc]',1)">↓</button><button class="del" onclick="if(confirm('Remove this document from the list?')) this.closest('[data-doc]').remove()">Remove</button></span></div>`+
    `<label>Button label</label><input data-df="label" value="${escA(d.label)}" placeholder="e.g. CV">`+
    `<label>Kind</label><select data-df="kind">${opts}</select>`+
    `<label>File link (leave empty to hide the button)</label>`+
    `<div class="uprow"><input data-df="url" data-k="url" value="${escA(d.url)}" placeholder="/uploads/… (upload a PDF) or https://…"><button type="button" class="up" onclick="this.nextElementSibling.click()">Upload PDF</button><input type="file" accept="application/pdf" hidden onchange="onPickDoc(this)"></div>`+
    (d.url?`<small>Current: <a href="${escA(d.url)}" target="_blank" rel="noopener" style="color:#7ec8e3">${escA(d.url)}</a></small>`:'<small>No file yet: this button is hidden on the website.</small>')+
    `</div>`;
}
function renderDocuments(){
  app().innerHTML=`<h2>Documents</h2><p class="lead">Buttons for your CV and statements. They appear in the hero and Contact section; the CV also appears in the top menu and footer. A button shows only when its file link is filled in. Upload a PDF (max 20 MB) or paste a link.</p>`+
    (DATA.documents?'':`<p class="lead" style="color:#e0b867">Documents are not in the database yet; the rows below are the defaults. Saving stores them.</p>`)+
    `<div id="docList">`+listOf('documents','items',DEFAULT_DOCUMENTS).map(docRow).join('')+`</div>`+
    `<button onclick="document.getElementById('docList').insertAdjacentHTML('beforeend',docRow())">+ Add document</button>`+
    `<div class="bar"><button class="primary" onclick="saveDocuments()">Save Documents</button></div>`+
    `<small>If no CV link is set here, the older <b>Site Settings → Resume / CV</b> link is used for the CV button.</small>`;
}
window.onPickDoc=(fi)=>{
  const f=fi.files[0]; if(!f) return;
  const row=fi.closest('[data-doc]'); const txt=row.querySelector('[data-df="url"]');
  const lab=(row.querySelector('[data-df="label"]').value||row.querySelector('[data-df="kind"]').value||'document');
  uploadFile(f,'pdf',url=>{ txt.value=url; },lab);
  fi.value='';
};
window.moveEl=(btn,sel,dir)=>{ const el=btn.closest(sel); if(dir<0){ const p=el.previousElementSibling; if(p&&p.matches(sel)) el.parentNode.insertBefore(el,p); } else { const n=el.nextElementSibling; if(n&&n.matches(sel)) el.parentNode.insertBefore(n,el); } };
window.saveDocuments=()=>{
  const items=[...document.querySelectorAll('#docList [data-doc]')].map(el=>{
    const g=k=>{ const n=el.querySelector('[data-df="'+k+'"]'); return n?n.value.trim():''; };
    return {label:g('label'),url:g('url'),kind:g('kind')||'other'};
  }).filter(d=>d.label||d.url);
  const bad=items.find(d=>d.url && !/^(\/|https:\/\/)/i.test(d.url));
  if(bad){ toast('Links must start with / or https://'); return; }
  const data=Object.assign({}, (DATA.documents && !Array.isArray(DATA.documents))?DATA.documents:{}, {items});
  DATA.documents=data; postSection('documents',data,'Documents');
};

// ---- Software & Data: {items:[{name,description,url,code_url,paper_slug,kind}]} ----
const SW_KINDS=['tool','benchmark','dataset'];
// Shipped with the website build; shown until the section exists in the database, so a first save keeps it.
const DEFAULT_SOFTWARE = {"items": [{"name": "MergeSE", "description": "Open-source CLI and web tool for training-free merging of Hugging Face encoder checkpoints, with inspect, merge, evaluate and export steps for software engineering classification tasks.", "url": "https://mergese.usask.ca", "code_url": "https://github.com/srlabUsask/MergeSE", "artifact_url": "https://doi.org/10.5281/zenodo.21797312", "paper_slug": "mergese", "publication_kind": "tool", "kind": "tool"}, {"name": "GPTCloneBench", "description": "Benchmark of 37,149 true semantic clone pairs, 19,288 false semantic pairs and 20,770 cross-language clone pairs in Java, C, C# and Python, built from SemanticCloneBench and GPT-3.", "url": "", "code_url": "https://github.com/srlabUsask/GPTCloneBench", "dataset_url": "https://doi.org/10.5281/zenodo.8259987", "paper_slug": "gptclonebench", "publication_kind": "benchmark", "kind": "benchmark"}]};
const DEFAULT_SKILLS = {"groups": [{"group": "Research methods", "items": ["Code clone detection and clone benchmark construction", "Model merging (Task Arithmetic, TIES, DARE-TIES, WUDI, PCB)", "Model compression (neural architecture search, structured pruning, quantization, knowledge distillation)", "Empirical software engineering studies", "Prompt engineering for code generation"]}, {"group": "Machine learning", "items": ["Python", "PyTorch", "Hugging Face Transformers and Datasets", "Pre-trained code models (CodeBERT, UniXcoder)", "ONNX export", "Optuna", "CodeCarbon"]}, {"group": "Tools and infrastructure", "items": ["Flask", "Docker", "JavaScript", "Clone detectors: NiCad, PMD/CPD, Simian, Deckard, CloneWorks, StoneDetector"]}]};
function pubSlugs(){ return (Array.isArray(DATA.publications)?DATA.publications:[]).map(p=>({slug:p.slug||'',title:p.title||''})).filter(p=>p.slug); }
function swRow(x){
  x=x||{publication_kind:'tool'};
  const pk=x.publication_kind||x.kind||'tool';
  const kinds=SW_KINDS.map(k=>`<option value="${k}" ${pk===k?'selected':''}>${k} paper</option>`).join('')+(SW_KINDS.indexOf(pk)<0?`<option value="${escA(pk)}" selected>${escA(pk)} (older value)</option>`:'');
  const slugs=pubSlugs(); const known=slugs.some(p=>p.slug===x.paper_slug);
  const popts=`<option value="">(choose the paper; required)</option>`+slugs.map(p=>`<option value="${escA(p.slug)}" ${p.slug===x.paper_slug?'selected':''}>${escA(p.slug)}: ${escA(p.title.slice(0,70))}</option>`).join('')+
    ((x.paper_slug && !known)?`<option value="${escA(x.paper_slug)}" selected>${escA(x.paper_slug)} (not found in Publications)</option>`:'');
  return `<div class="item" data-sw>`+
    `<div class="item-head"><b>${escA(x.name||'New entry')}</b><span class="acts"><button class="mv" title="Move up" onclick="moveEl(this,'[data-sw]',-1)">↑</button><button class="mv" title="Move down" onclick="moveEl(this,'[data-sw]',1)">↓</button><button class="del" onclick="if(confirm('Remove this entry?')) this.closest('[data-sw]').remove()">Remove</button></span></div>`+
    `<label>Name</label><input data-wf="name" value="${escA(x.name)}">`+
    `<label>Its own paper (required: only items that have their own tool/benchmark/dataset publication are shown)</label><select data-wf="paper_slug">${popts}</select>`+
    `<label>Kind of paper</label><select data-wf="publication_kind">${kinds}</select>`+
    `<label>Description (one or two factual sentences)</label><textarea data-wf="description">${escA(x.description)}</textarea>`+
    `<label>Tool website (optional)</label><input data-wf="url" value="${escA(x.url)}" placeholder="https://…">`+
    `<label>Code repository (optional)</label><input data-wf="code_url" value="${escA(x.code_url)}" placeholder="https://github.com/…">`+
    `<label>Dataset (optional, e.g. a Zenodo DOI link)</label><input data-wf="dataset_url" value="${escA(x.dataset_url)}" placeholder="https://doi.org/10.5281/zenodo.…">`+
    `<label>Archived release / artifact (optional, e.g. a Zenodo DOI link)</label><input data-wf="artifact_url" value="${escA(x.artifact_url)}" placeholder="https://doi.org/10.5281/zenodo.…">`+
    `</div>`;
}
function renderSoftware(){
  app().innerHTML=`<h2>Tools &amp; Benchmarks</h2><p class="lead">The homepage <b>Tools &amp; Benchmarks</b> section. <b>Only add items that have their own tool/benchmark/dataset publication</b> (for example MergeSE, an ASE 2026 Tool Track paper, and GPTCloneBench, an ICSME 2023 benchmark paper). Code, replication packages and artifacts of other papers belong on the paper itself (Publications: code_url, artifact_url, dataset_url, tool_url). An item whose paper is not in Publications is not shown.</p>`+GEN_NOTE+
    (DATA.software?'':`<p class="lead" style="color:#e0b867">Tools &amp; Benchmarks is not in the database yet. The rows below are the list built into the website; saving stores them.</p>`)+
    `<div id="swList">`+listOf('software','items',DEFAULT_SOFTWARE).map(swRow).join('')+`</div>`+
    `<button onclick="document.getElementById('swList').insertAdjacentHTML('beforeend',swRow())">+ Add entry</button>`+
    `<div class="bar"><button class="primary" onclick="saveSoftware()">Save Tools &amp; Benchmarks</button></div>`;
}
window.saveSoftware=()=>{
  const items=[...document.querySelectorAll('#swList [data-sw]')].map(el=>{
    const g=k=>{ const n=el.querySelector('[data-wf="'+k+'"]'); return n?n.value.trim():''; };
    const pk=g('publication_kind')||'tool';
    const o={name:g('name'),description:g('description'),url:g('url'),code_url:g('code_url'),dataset_url:g('dataset_url'),artifact_url:g('artifact_url'),paper_slug:g('paper_slug'),publication_kind:pk,kind:pk};
    Object.keys(o).forEach(k=>{ if(o[k]==='' && ['dataset_url','artifact_url'].indexOf(k)>=0) delete o[k]; });
    return o;
  }).filter(x=>x.name);
  const noPaper=items.find(x=>!x.paper_slug);
  if(noPaper){ toast('Choose the paper for "'+noPaper.name+'" (required)'); return; }
  const badUrl=items.find(x=>['url','code_url','dataset_url','artifact_url'].some(k=>x[k] && !/^https?:\/\//i.test(x[k])));
  if(badUrl){ toast('Links must start with https:// ("'+badUrl.name+'")'); return; }
  const data=Object.assign({}, (DATA.software && !Array.isArray(DATA.software))?DATA.software:{}, {items});
  DATA.software=data; postSection('software',data,'Tools & Benchmarks');
};

// ---- Methods & tools: {groups:[{group,items[]}]} ----
function skRow(g){
  g=g||{group:'',items:[]};
  return `<div class="item" data-sk>`+
    `<div class="item-head"><b>${escA(g.group||'New group')}</b><span class="acts"><button class="mv" title="Move up" onclick="moveEl(this,'[data-sk]',-1)">↑</button><button class="mv" title="Move down" onclick="moveEl(this,'[data-sk]',1)">↓</button><button class="del" onclick="if(confirm('Remove this group?')) this.closest('[data-sk]').remove()">Remove</button></span></div>`+
    `<label>Group name</label><input data-kf="group" value="${escA(g.group)}" placeholder="e.g. Research methods">`+
    `<label>Items, one per line</label><textarea data-kf="items" style="min-height:120px">${escA((Array.isArray(g.items)?g.items:[]).join('\n'))}</textarea>`+
    `</div>`;
}
function renderSkills(){
  app().innerHTML=`<h2>Skills &amp; Methods</h2><p class="lead">Shown as a small <b>Skills &amp; Methods</b> block at the end of <b>Experience</b> (the heading is editable under Sections &amp; Headings). List only methods and tools your papers and code repositories show you used; reviewers check. The homepage shows up to 3 groups.</p>`+
    (DATA.skills?'':`<p class="lead" style="color:#e0b867">Skills &amp; Methods are not in the database yet. The groups below are the list built into the website; saving stores them.</p>`)+
    `<div id="skList">`+listOf('skills','groups',DEFAULT_SKILLS).map(skRow).join('')+`</div>`+
    `<button onclick="document.getElementById('skList').insertAdjacentHTML('beforeend',skRow())">+ Add group</button>`+
    `<div class="bar"><button class="primary" onclick="saveSkills()">Save Skills &amp; Methods</button></div>`;
}
window.saveSkills=()=>{
  const groups=[...document.querySelectorAll('#skList [data-sk]')].map(el=>({
    group:el.querySelector('[data-kf="group"]').value.trim(),
    items:el.querySelector('[data-kf="items"]').value.split(/\r?\n/).map(x=>x.trim()).filter(Boolean)
  })).filter(g=>g.group && g.items.length);
  const data=Object.assign({}, (DATA.skills && !Array.isArray(DATA.skills))?DATA.skills:{}, {groups});
  DATA.skills=data; postSection('skills',data,'Skills & Methods');
};

// ---- Custom sections (user-added, block-based) ----
function normBlocks(s){
  if(Array.isArray(s.blocks)) return s.blocks;
  if(s.type==='cards') return [{type:'cards',cards:s.cards||[]}];
  if(s.body!==undefined) return [{type:'text',text:s.body||''}];
  return [{type:'text',text:''}];
}
function collectCustom(){
  const out=[];
  document.querySelectorAll('#customList > [data-cs]').forEach(el=>{
    const g=(k)=>{ const n=el.querySelector('[data-f="'+k+'"]'); return n?n.value.trim():''; };
    const blocks=[...el.querySelectorAll('[data-block]')].map(bl=>{
      const t=bl.dataset.btype;
      const bg=(k)=>{ const n=bl.querySelector('[data-bf="'+k+'"]'); return n?n.value.trim():''; };
      if(t==='image') return {type:'image', image:bg('image'), caption:bg('caption')};
      if(t==='cards') return {type:'cards', cards:[...bl.querySelectorAll('[data-card]')].map(cr=>{const cg=(k)=>{const n=cr.querySelector('[data-cf="'+k+'"]');return n?n.value.trim():'';};return {title:cg('title'),description:cg('description'),link:cg('link'),image:cg('image')};}).filter(c=>c.title||c.description||c.image)};
      return {type:'text', text:bg('text')};
    }).filter(b=> b.type==='text'?b.text : b.type==='image'?b.image : (b.cards&&b.cards.length));
    out.push({ id:el.dataset.id, nav:g('nav'), badge:g('badge'), title:g('title'), subtitle:g('subtitle'), blocks });
  });
  return out;
}
function cardEditor(c){
  c=c||{};
  return `<div class="item" data-card style="background:#0c0d16">`+
    `<div class="item-head"><b>Card</b><span class="acts"><button class="del" onclick="this.closest('[data-card]').remove()">Remove</button></span></div>`+
    `<label>Title</label><input data-cf="title" value="${escA(c.title)}">`+
    `<label>Description</label><textarea data-cf="description">${escA(c.description)}</textarea>`+
    `<label>Link (optional)</label><input data-cf="link" value="${escA(c.link)}" placeholder="https://…">`+
    `<div class="imgfield"><label>Image (optional)</label>`+
    `<div class="uprow"><input data-cf="image" data-k="image" value="${escA(c.image)}" oninput="mark()"><button type="button" class="up" onclick="this.nextElementSibling.click()">Upload</button><input type="file" accept="image/*" hidden onchange="onPickImage(this)"></div>`+
    (c.image?`<img class="thumb" src="${escA(c.image)}">`:'')+`</div></div>`;
}
function blockEditor(b){
  b=b||{type:'text'};
  const head=(name)=>`<div class="item-head"><b>${name}</b><span class="acts"><button class="mv" title="Move up" onclick="moveBlock(this,-1)">↑</button><button class="mv" title="Move down" onclick="moveBlock(this,1)">↓</button><button class="del" onclick="this.closest('[data-block]').remove()">Remove</button></span></div>`;
  if(b.type==='image'){
    return `<div class="item" data-block data-btype="image" style="background:#12131c">`+head('Image block')+
      `<div class="imgfield"><div class="uprow"><input data-bf="image" data-k="image" value="${escA(b.image)}" placeholder="Upload an image" oninput="mark()"><button type="button" class="up" onclick="this.nextElementSibling.click()">Upload</button><input type="file" accept="image/*" hidden onchange="onPickImage(this)"></div>${b.image?`<img class="thumb" src="${escA(b.image)}">`:''}</div>`+
      `<label>Caption (optional)</label><input data-bf="caption" value="${escA(b.caption)}"></div>`;
  }
  if(b.type==='cards'){
    return `<div class="item" data-block data-btype="cards" style="background:#12131c">`+head('Cards block')+
      `<div class="cardlist">`+((b.cards||[]).map(cardEditor).join(''))+`</div><button onclick="addCard(this)">+ Add card</button></div>`;
  }
  return `<div class="item" data-block data-btype="text" style="background:#12131c">`+head('Text block')+
    `<textarea data-bf="text" style="min-height:120px">${escA(b.text)}</textarea></div>`;
}
function sectionEditor(s){
  return `<div class="item" data-cs data-id="${escA(s.id)}">`+
    `<div class="item-head"><b>Section</b><span class="acts"><button class="del" data-id="${escA(s.id)}" onclick="removeCustom(this.dataset.id)">Remove section</button></span></div>`+
    `<label>Menu label (top nav)</label><input data-f="nav" value="${escA(s.nav)}">`+
    `<label>Badge (small pill above title)</label><input data-f="badge" value="${escA(s.badge)}">`+
    `<label>Title</label><input data-f="title" value="${escA(s.title)}">`+
    `<label>Description (subtitle)</label><textarea data-f="subtitle">${escA(s.subtitle)}</textarea>`+
    `<h3 style="margin:14px 0 6px;color:#7ec8e3;font-size:14px">Content blocks</h3>`+
    `<div class="blocks">`+normBlocks(s).map(blockEditor).join('')+`</div>`+
    `<div style="display:flex;gap:8px;margin-top:8px"><button onclick="addBlock(this,'text')">+ Text</button><button onclick="addBlock(this,'image')">+ Image</button><button onclick="addBlock(this,'cards')">+ Cards</button></div>`+
    `</div>`;
}
function renderCustom(){
  app().innerHTML = `<h2>Custom Sections</h2><p class="lead">Build your own sections (e.g. Teaching, Talks, Projects) by stacking <b>text</b>, <b>image</b> and <b>card</b> blocks in any order. They appear on the site and in <b>Page Layout</b>, where you can reorder or hide them like any built-in section.</p>`+FMT_HINT+
    `<div style="margin-bottom:14px"><button onclick="addCustom()">+ Add section</button></div>`+
    `<div id="customList">`+ (customSections.length ? customSections.map(sectionEditor).join('') : `<p class="lead">No custom sections yet — add one above.</p>`) +`</div>`+
    `<div class="bar"><button class="primary" onclick="saveCustom()">Save Custom Sections</button></div>`;
}
window.addCard=(btn)=>{ btn.previousElementSibling.insertAdjacentHTML('beforeend', cardEditor({})); };
window.addBlock=(btn,type)=>{ btn.closest('[data-cs]').querySelector('.blocks').insertAdjacentHTML('beforeend', blockEditor({type})); };
window.moveBlock=(btn,dir)=>{ const bl=btn.closest('[data-block]'); if(dir<0){ const p=bl.previousElementSibling; if(p&&p.matches('[data-block]')) bl.parentNode.insertBefore(bl,p); } else { const n=bl.nextElementSibling; if(n&&n.matches('[data-block]')) bl.parentNode.insertBefore(n,bl); } };
window.addCustom=()=>{ customSections=collectCustom(); const id='custom-'+Date.now().toString(36)+Math.random().toString(36).slice(2,6); customSections.push({id,nav:'',badge:'',title:'',subtitle:'',blocks:[{type:'text',text:''}]}); renderCustom(); };
window.removeCustom=(id)=>{ customSections=collectCustom().filter(s=>s.id!==id); renderCustom(); };
window.saveCustom=async()=>{
  customSections=collectCustom();
  layoutOrder=normLayout(layoutOrder);
  const valid=new Set([...Object.keys(SECTION_LABELS), ...Object.keys(LEGACY_SECTIONS), ...customSections.map(s=>s.id)]);
  hiddenSet=new Set([...hiddenSet].filter(id=>valid.has(id)));
  try{
    const body=(section,data)=>fetch('index.php?action=save',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({csrf:CSRF,section,data})}).then(r=>r.json());
    const [a,b,c]=await Promise.all([body('customSections',customSections), body('sectionOrder',layoutForSave()), body('sectionsHidden',[...hiddenSet])]);
    DATA.customSections=customSections; DATA.sectionOrder=layoutForSave();
    toast((a.ok&&b.ok&&c.ok)?'Custom sections saved ✓':('Error: '+((a.error||b.error||c.error)||'failed')));
  }catch(e){ toast('Network error'); }
};

// ---- Social links (editable, add/hide/reorder) ----
const PLATFORMS=['linkedin','github','scholar','orcid','dblp','researchgate','instagram','facebook','x','twitter','youtube','email','website'];
function socialRow(l){
  l=l||{platform:'linkedin',label:'',url:'',hidden:false};
  const opts=PLATFORMS.map(p=>`<option value="${p}" ${l.platform===p?'selected':''}>${p}</option>`).join('');
  return `<div class="item" data-soc style="${l.hidden?'opacity:.55':''}">`+
    `<div class="row" style="align-items:center;gap:8px">`+
    `<select data-sf="platform" style="width:130px">${opts}</select>`+
    `<input data-sf="label" value="${escA(l.label)}" placeholder="Label (e.g. LinkedIn)" style="flex:1">`+
    `<button class="mv" title="Move up" onclick="moveSoc(this,-1)">↑</button>`+
    `<button class="mv" title="Move down" onclick="moveSoc(this,1)">↓</button>`+
    `<button class="mv" title="Show/Hide" onclick="toggleSoc(this)">${l.hidden?'Show':'Hide'}</button>`+
    `<button class="del" onclick="this.closest('[data-soc]').remove()">✕</button>`+
    `</div>`+
    `<input data-sf="url" value="${escA(l.url)}" placeholder="https://… (or mailto: for email)" style="margin-top:8px">`+
    `<input type="hidden" data-sf="hidden" value="${l.hidden?'1':''}">`+
    `</div>`;
}
// Baked defaults — shown the first time this editor is opened (before a socials row
// exists in the DB), so a first Save can't wipe the links the live site already ships.
const DEFAULT_SOCIALS=[{"platform": "scholar", "label": "Google Scholar", "url": "https://scholar.google.com/citations?user=Vy_sw5UAAAAJ&hl=en", "hidden": false}, {"platform": "linkedin", "label": "LinkedIn", "url": "https://www.linkedin.com/in/palashranjanroy/", "hidden": false}, {"platform": "github", "label": "GitHub", "url": "https://github.com/Roy101", "hidden": false}, {"platform": "orcid", "label": "ORCID", "url": "https://orcid.org/0000-0001-9470-4233", "hidden": false}, {"platform": "dblp", "label": "dblp", "url": "https://dblp.org/pid/355/4465.html", "hidden": false}, {"platform": "researchgate", "label": "ResearchGate", "url": "https://www.researchgate.net/profile/Palash_Roy", "hidden": false}, {"platform": "x", "label": "X", "url": "https://x.com/Palash16355210", "hidden": false}, {"platform": "instagram", "label": "Instagram", "url": "https://www.instagram.com/checkoutroy/?hl=en", "hidden": true}, {"platform": "facebook", "label": "Facebook", "url": "https://www.facebook.com/pial.roy.3705/", "hidden": true}];
function socLinks(){ return (DATA.socials && Array.isArray(DATA.socials.links)) ? DATA.socials.links : DEFAULT_SOCIALS; }
function renderSocials(){
  app().innerHTML = `<h2>Social Links</h2><p class="lead">Profile links with small monochrome logos in the Contact section, the footer, the blog and the generated pages. Pick a platform (sets the logo), add a label and URL. Reorder with the arrows, or <b>Hide</b> any link (hidden links appear nowhere, including search data). Facebook and Instagram show only when not hidden. The hero shows the links named under <b>Site Settings → Hero links</b> (by default Google Scholar, LinkedIn, GitHub and Email).</p>`+GEN_NOTE+
    `<div id="socList">`+(socLinks().length?socLinks().map(socialRow).join(''):'')+`</div>`+
    `<button onclick="addSoc()">+ Add social link</button>`+
    `<div class="bar"><button class="primary" onclick="saveSocials()">Save Social Links</button></div>`;
}
window.addSoc=()=>{ document.getElementById('socList').insertAdjacentHTML('beforeend', socialRow({platform:'linkedin',label:'',url:'',hidden:false})); };
window.moveSoc=(btn,dir)=>{ const el=btn.closest('[data-soc]'); if(dir<0){const p=el.previousElementSibling; if(p&&p.matches('[data-soc]')) el.parentNode.insertBefore(el,p);} else {const n=el.nextElementSibling; if(n&&n.matches('[data-soc]')) el.parentNode.insertBefore(n,el);} };
window.toggleSoc=(btn)=>{ const el=btn.closest('[data-soc]'); const h=el.querySelector('[data-sf="hidden"]'); const now=h.value!=='1'; h.value=now?'1':''; el.style.opacity=now?'.55':''; btn.textContent=now?'Show':'Hide'; };
window.saveSocials=()=>{
  const links=[...document.querySelectorAll('#socList [data-soc]')].map(el=>{
    const g=(k)=>{ const n=el.querySelector('[data-sf="'+k+'"]'); return n?n.value.trim():''; };
    return { platform:g('platform'), label:g('label'), url:g('url'), hidden: el.querySelector('[data-sf="hidden"]').value==='1' };
  }).filter(l=>l.url);
  const data={links}; DATA.socials=data; postSection('socials',data,'Social links');
};

// ---- Footer ----
const DEFAULT_FOOTER = {"name": "Palash Ranjan Roy", "tagline": "PhD Researcher in Computer Science · University of Saskatchewan", "email": "palash.roy@usask.ca", "showSocials": true, "copyright": "© 2026 Palash Ranjan Roy · All rights reserved"};
function renderFooter(){
  const f = Object.assign({}, DEFAULT_FOOTER, DATA.footer||{});
  app().innerHTML = `<h2>Footer</h2><p class="lead">The footer at the bottom of every page.</p>`+
    `<div class="item">`+
    `<label>Name</label><input id="ft_name" value="${escA(f.name)}">`+
    `<label>Tagline (not shown in the 2026-11 design)</label><input id="ft_tagline" value="${escA(f.tagline)}">`+
    `<label>Email (optional)</label><input id="ft_email" value="${escA(f.email)}">`+
    `<label>Copyright line</label><input id="ft_copyright" value="${escA(f.copyright)}">`+
    `<label style="display:flex;align-items:center;gap:8px;text-transform:none;font-size:14px;color:#e8e8ef;margin-top:12px"><input type="checkbox" id="ft_showsoc" style="width:auto" ${f.showSocials!==false?'checked':''}> Show social icons in the footer</label>`+
    `</div>`+
    `<div class="bar"><button class="primary" onclick="saveFooter()">Save Footer</button></div>`+
    `<small>Edit the icons themselves under <b>Social Links</b>.</small>`;
}
window.saveFooter=()=>{
  const data={name:v('ft_name'),tagline:v('ft_tagline'),email:v('ft_email'),copyright:v('ft_copyright'),showSocials:document.getElementById('ft_showsoc').checked};
  DATA.footer=data; postSection('footer',data,'Footer');
};

// ---- Collaboration / consultation call-to-action ----
const DEFAULT_CONSULTATION = {"description": "The best way to reach me is by email. You can also use the form below.", "points": [], "buttonLabel": "Get in touch", "bookingUrl": "", "email": "palash.roy@usask.ca"};
function renderConsultation(){
  const c = Object.assign({}, DEFAULT_CONSULTATION, DATA.consultation||{});
  if(!Array.isArray(c.points)) c.points = [];
  app().innerHTML = `<h2>Collaboration</h2><p class="lead">In the 2026-11 design only the <b>Description</b> is shown: it is the one line under your email in the Contact section. Keep it neutral (no topic list). The booking link, button and bullet points are used only by the older design.</p>`+FMT_HINT+
    `<div class="item">`+
    `<label>Description</label><textarea id="cn_desc">${escA(c.description)}</textarea>`+
    `<label>Booking link (Calendly / Cal.com / etc.)</label><input id="cn_url" value="${escA(c.bookingUrl)}" placeholder="https://calendly.com/…  (leave blank to use email)">`+
    `<label>Email for requests <span style="color:#8a8a95;font-weight:400">— used when no booking link above; the button opens the visitor's email to this address</span></label><input id="cn_email" value="${escA(c.email)}" placeholder="palash.roy@usask.ca">`+
    `<label>Button label</label><input id="cn_btn" value="${escA(c.buttonLabel)}">`+
    `</div>`+
    `<h3 style="margin:14px 0 6px;color:#7ec8e3;font-size:14px">What you offer (bullet points)</h3>`+
    `<div id="cn_points">`+(c.points||[]).map(p=>`<div class="row" data-r><input value="${escA(p)}" style="flex:1"><button class="del" onclick="this.parentNode.remove()">✕</button></div>`).join('')+`</div><button onclick="addCnPoint()">+ Add point</button>`+
    `<div class="bar"><button class="primary" onclick="saveConsultation()">Save Collaboration</button></div>`;
}
window.addCnPoint=()=>{const d=document.getElementById('cn_points');const el=document.createElement('div');el.className='row';el.setAttribute('data-r','');el.innerHTML='<input style="flex:1"><button class="del" onclick="this.parentNode.remove()">✕</button>';d.appendChild(el);};
window.saveConsultation=()=>{
  const points=[...document.querySelectorAll('#cn_points [data-r] input')].map(i=>i.value.trim()).filter(Boolean);
  const data={description:v('cn_desc'),points,buttonLabel:v('cn_btn'),bookingUrl:v('cn_url'),email:v('cn_email').trim()};
  DATA.consultation=data; postSection('consultation',data,'Collaboration');
};

// ---- Visitors (aggregate visitor countries; data from index.php?action=visitors) ----
const VIS = {data:null, range:'d30', err:''};
const VIS_RANGES = [['d30','Last 30 days'],['m12','Last 12 months'],['all','All time']];
function visLine(){ const s=(DATA.settings && DATA.settings.visitorLine && typeof DATA.settings.visitorLine==='object') ? DATA.settings.visitorLine : {};
  return {show:s.show===true, min:Math.max(1,parseInt(s.min,10)||10), text:(typeof s.text==='string' && s.text.trim()) ? s.text.trim() : 'Visitors from {n} countries'}; }
async function visLoad(){
  VIS.err='';
  try{
    const r=await fetch('index.php?action=visitors',{method:'POST',headers:{'Content-Type':'application/json'},credentials:'same-origin',cache:'no-store',body:JSON.stringify({csrf:CSRF})});
    const j=await r.json(); if(!j.ok) throw new Error(j.error||'failed'); VIS.data=j;
  }catch(e){ VIS.data=null; VIS.err=String(e.message||e); }
  if(current==='visitors') renderVisitors();
}
function renderVisitors(){
  const d=VIS.data, L=visLine();
  const rg=d && d.ranges ? d.ranges[VIS.range] : null;
  let html=`<h2>Visitors</h2><p class="lead">Roughly where your visitors come from, by country. Counted on the homepage and blog: one visit per network per day.</p>`;
  html+=`<div class="item" data-vis><div class="item-head"><b>Countries</b><div class="acts" role="group" aria-label="Time range">`+
    VIS_RANGES.map(([k,l])=>`<button type="button" aria-pressed="${VIS.range===k}" style="${VIS.range===k?'background:#35c7ff;color:#0c0d16;font-weight:600':''}" onclick="visRange('${k}')">${l}</button>`).join('')+`</div></div>`;
  if(!d && !VIS.err) html+=`<p class="lead" role="status">Loading…</p>`;
  else if(VIS.err) html+=`<p class="lead" style="color:#ff6b6b" role="alert">Could not load the statistics (${escA(VIS.err)}).</p>`;
  else {
    html+=`<div style="display:flex;gap:28px;flex-wrap:wrap;margin:8px 0 14px"><div><div style="font-size:26px;font-weight:700" data-vis-total>${rg.total.toLocaleString('en')}</div><small style="margin:0">visits</small></div>`+
      `<div><div style="font-size:26px;font-weight:700" data-vis-countries>${rg.countries.toLocaleString('en')}</div><small style="margin:0">countries</small></div></div>`;
    if(!rg.rows.length) html+=`<p class="lead">No visits counted in this period yet.</p>`;
    else {
      const max=rg.rows[0][1]||1;
      html+=`<table style="width:100%;border-collapse:collapse;font-size:14px"><thead><tr style="color:#a2a5b9;text-align:left"><th scope="col" style="padding:6px 4px;font-weight:600">Country</th><th scope="col" style="padding:6px 4px;font-weight:600;text-align:right">Visits</th><th scope="col" style="padding:6px 4px;font-weight:600;width:40%">Share</th></tr></thead><tbody>`+
        rg.rows.map(([cc,n])=>{ const pct=rg.total?Math.round(n*1000/rg.total)/10:0; return `<tr style="border-top:1px solid #23263a"><td style="padding:6px 4px">${escA(d.names[cc]||cc)}</td><td style="padding:6px 4px;text-align:right;font-variant-numeric:tabular-nums">${n.toLocaleString('en')}</td>`+
          `<td style="padding:6px 4px"><div style="display:flex;align-items:center;gap:8px"><div style="flex:1;background:#0c0d16;border:1px solid #23263a;border-radius:4px;height:10px;overflow:hidden" aria-hidden="true"><div style="height:100%;width:${Math.max(1,Math.round(n*100/max))}%;background:#35c7ff"></div></div><span style="min-width:46px;text-align:right;color:#a2a5b9;font-variant-numeric:tabular-nums">${pct}%</span></div></td></tr>`; }).join('')+
        `</tbody></table>`;
    }
    html+=`<small>${d.since?`Counting since ${escA(d.since)} (UTC days).`:'Nothing counted yet. Counting starts when the new backend files are on the server.'}</small>`;
  }
  html+=`</div>`;
  html+=`<div class="item" data-vis><div class="item-head"><b>Line on the website</b></div>`+
    chk('vis_show', L.show, 'Show "visitors from N countries" on the website', 'One small line in the footer of the homepage and blog, with N = all-time number of countries. Off by default.')+
    `<label for="vis_min">Minimum number of countries before the line appears</label><input id="vis_min" type="number" min="1" max="250" value="${escA(L.min)}">`+
    `<label for="vis_text">Text ({n} is replaced by the number)</label><input id="vis_text" maxlength="80" value="${escA(L.text)}" placeholder="Visitors from {n} countries">`+
    `<small>Visitors may see a change up to an hour later (the number is cached by browsers for one hour).</small>`+
    `<div style="margin-top:14px"><button class="primary" onclick="visSave()">Save</button></div></div>`;
  html+=`<div class="item" data-vis><div class="item-head"><b>Privacy</b></div>`+
    `<p class="lead" style="margin:4px 0">Only a date, a country and a count are stored. The country comes from the time zone the visitor's browser reports (for example America/Regina = Canada), not from the IP address, so VPN users or people abroad may be counted in another country. This counter uses no cookies, stores no IP addresses or browser details in the statistics, and sends nothing to outside services. To count each network once per day, a keyed hash of a shortened IP address (the network part only: IPv4 /24, IPv6 /48) is kept for the current day only, under a random key that is deleted with the hashes the next day; both can appear in that day's database backups. Visitors with Do Not Track or Global Privacy Control switched on, and known bots, are not counted.</p>`+
    `<small>Because this counter stores nothing personal, it does not need a cookie banner of its own. (Google Analytics, which the website also loads, is separate and does set cookies.) If you like, add one sentence about the counter to your site, for example: "Visits are counted by country only, from the browser's time zone; the counter stores no cookies or IP addresses."</small></div>`;
  html+=`<div class="item" data-vis style="border-color:#7a2b36"><div class="item-head"><b>Reset statistics</b></div><p class="lead" style="margin:4px 0">Deletes every count. This cannot be undone.</p>`+
    `<button class="del" onclick="visReset()">Reset statistics</button></div>`;
  app().innerHTML=html;
  if(!d && !VIS.err && !VIS.loading){ VIS.loading=true; visLoad().finally(()=>{ VIS.loading=false; }); }
}
window.visRange=k=>{ VIS.range=k; renderVisitors(); };
window.visSave=()=>{
  const base=(DATA.settings && typeof DATA.settings==='object' && !Array.isArray(DATA.settings)) ? DATA.settings : {};
  const text=(v('vis_text')||'Visitors from {n} countries').slice(0,80);
  if(text.indexOf('{n}')<0){ toast('The text needs {n} where the number goes'); return; }
  const data=Object.assign({}, base, {visitorLine:{show:document.getElementById('vis_show').checked, min:Math.min(250,Math.max(1,parseInt(v('vis_min'),10)||10)), text}});
  DATA.settings=data; postSection('settings',data,'Visitor line');
};
window.visReset=async()=>{
  if(!window.confirm('Reset visitor statistics?\n\nEvery country count is deleted. This cannot be undone.')) return;
  try{
    const r=await fetch('index.php?action=visitors_reset',{method:'POST',headers:{'Content-Type':'application/json'},credentials:'same-origin',body:JSON.stringify({csrf:CSRF})});
    const j=await r.json(); if(!j.ok){ toast('Error: '+(j.error||'failed')); return; }
    toast('Statistics reset ✓'); VIS.data=null; renderVisitors();
  }catch(e){ toast('Network error'); }
};

// ---- Metrics ----
function renderMetrics(){
  const m = DATA.metrics || {citations:0,hIndex:0,works:0};
  const auto = m.auto===true;
  app().innerHTML = `<h2>Research Metrics</h2><p class="lead">The citation numbers shown in your hero and Publications section.</p>`+
    `<div class="item">`+
    `<label style="display:flex;align-items:center;gap:8px;text-transform:none;font-size:14px;color:#e8e8ef"><input type="checkbox" id="m_auto" style="width:auto" onchange="renderMetricsMode()" ${auto?'checked':''}> Update automatically from OpenAlex (via your ORCID)</label>`+
    `<small>Automatic: the website updates the numbers itself once a day from OpenAlex; no rebuild needed. OpenAlex usually reports <b>fewer</b> citations than Google Scholar. Leave it off to show your own Google Scholar numbers.</small>`+
    `<div id="m_live" style="${auto?'':'display:none'};margin-top:12px"><div id="m_live_box" aria-live="polite"><small>Loading the current OpenAlex numbers…</small></div></div>`+
    `<div id="m_manual" style="${auto?'display:none':''};margin-top:12px">`+
    `<label>Citations</label><input id="m_citations" type="number" value="${escA(m.citations??'')}">`+
    `<label>h-index</label><input id="m_hIndex" type="number" value="${escA(m.hIndex??'')}">`+
    `<label>Works / publications count</label><input id="m_works" type="number" value="${escA(m.works??'')}">`+
    `<label>Source (named in the metrics line, e.g. Google Scholar or OpenAlex)</label><input id="m_source" value="${escA(m.source||'Google Scholar')}">`+
    `<label>Profile link (where the numbers come from)</label><input id="m_profile" value="${escA(m.profileUrl||'https://scholar.google.com/citations?user=Vy_sw5UAAAAJ&hl=en')}">`+
    `<label>Numbers as of (YYYY-MM-DD; shown as "updated Mon YYYY"). Set to today; change it if you copied the numbers earlier. Last saved: ${escA(m.updated||'never')}</label><input id="m_updated" value="${escA(new Date().toISOString().slice(0,10))}" placeholder="YYYY-MM-DD">`+
    `</div></div>`+
    `<div class="bar"><button class="primary" onclick="saveMetrics()">Save Metrics</button></div>`+
    `<small>Manual tip: open your Google Scholar profile and copy the "Cited by" total and h-index here.</small>`;
  loadMetricsLive(false);
}
window.renderMetricsMode=()=>{ const on=document.getElementById('m_auto').checked; document.getElementById('m_manual').style.display = on?'none':''; document.getElementById('m_live').style.display = on?'':'none'; };
// Current numbers served by api/metrics.php (cached on the server, refreshed once a day).
async function loadMetricsLive(refresh){
  const box=document.getElementById('m_live_box'); if(!box) return;
  if(refresh) box.innerHTML='<small>Asking OpenAlex…</small>';
  let j=null;
  try{ const r=await fetch('index.php?action=metrics_status',{method:'POST',headers:{'Content-Type':'application/json'},credentials:'same-origin',cache:'no-store',body:JSON.stringify({csrf:CSRF,refresh:!!refresh})}); j=await r.json(); }catch(e){ j=null; }
  const b=document.getElementById('m_live_box'); if(!b) return;
  const when=t=>t?new Date(t*1000).toLocaleString():'never';
  if(!j||!j.ok){ b.innerHTML='<small>Could not read the live numbers'+(j&&j.error?': '+escA(j.error):'')+'.</small>'; return; }
  if(j.auto && !j.data && !j.tried && !refresh){ loadMetricsLive(true); return; } // first use: fetch once now
  const d=j.data;
  let h = d ? `<p style="margin:0 0 6px">Shown on the website now: <b>${escA(String(d.citations))}</b> citations · h-index <b>${escA(String(d.hIndex))}</b> · ${escA(String(d.works))} works <small>(numbers changed ${escA(d.updated||'?')})</small></p>`
            : `<p style="margin:0 0 6px">No OpenAlex numbers fetched yet; the website shows the numbers from the last build until the first fetch.</p>`;
  h += `<small>Last fetched from OpenAlex: ${escA(when(j.fetched))}.`+(j.error?` Last attempt (${escA(when(j.tried))}) failed: ${escA(j.error)}; the last good numbers stay on the website.`:'')+(j.auto?'':' Save with automatic on to start the daily updates.')+`</small>`;
  if(j.auto) h += `<div class="bar" style="margin-top:8px"><button type="button" onclick="loadMetricsLive(true)">Check OpenAlex now</button></div>`;
  b.innerHTML=h;
}
window.loadMetricsLive=loadMetricsLive;
window.saveMetrics=()=>{
  const prev = DATA.metrics||{};
  const auto = document.getElementById('m_auto').checked;
  const data = auto
    ? { auto:true, source:'OpenAlex' }
    : Object.assign({}, prev, {
        auto:false,
        citations: parseInt(document.getElementById('m_citations').value)||0,
        hIndex: parseInt(document.getElementById('m_hIndex').value)||0,
        works: parseInt(document.getElementById('m_works').value)||0,
        source: v('m_source') || 'Google Scholar',
        profileUrl: v('m_profile') || 'https://scholar.google.com/citations?user=Vy_sw5UAAAAJ&hl=en',
        updated: /^\d{4}-\d{2}(-\d{2})?$/.test(v('m_updated')) ? v('m_updated') : new Date().toISOString().slice(0,10)
      });
  DATA.metrics = data; postSection('metrics',data,'Metrics').then(()=>loadMetricsLive(false));
};

// ---- Change password ----
function renderPassword(){
  app().innerHTML = `<h2>Change Password</h2><p class="lead">Update your admin login password.</p>`+
    `<div class="item">`+
    `<label>Current password</label><input id="pw_cur" type="password" autocomplete="current-password">`+
    `<label>New password (10+ characters)</label><input id="pw_new" type="password" autocomplete="new-password">`+
    `<label>Confirm new password</label><input id="pw_new2" type="password" autocomplete="new-password">`+
    `</div>`+
    `<div class="bar"><button class="primary" onclick="savePassword()">Update Password</button></div>`;
}
window.savePassword=async()=>{
  const cur=document.getElementById('pw_cur').value, nw=document.getElementById('pw_new').value, nw2=document.getElementById('pw_new2').value;
  if(nw.length<10){toast('New password must be 10+ characters');return;}
  if(nw!==nw2){toast('New passwords do not match');return;}
  try{
    const r=await fetch('index.php?action=changepw',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({csrf:CSRF,current:cur,new:nw})});
    const j=await r.json();
    if(j.ok){ toast('Password updated ✓'); ['pw_cur','pw_new','pw_new2'].forEach(id=>document.getElementById(id).value=''); }
    else toast('Error: '+(j.error||'failed'));
  }catch(e){toast('Network error');}
};

// ---- Hero ----
const DEFAULT_HERO = {"greeting": "", "name": "Palash Ranjan Roy", "line2": "PhD Researcher in Computer Science · University of Saskatchewan", "line3": "", "navHome": "Home", "badge": "", "description": "I study code clones (how they are detected, refactored and generated), model merging, and efficient large language models for software engineering. I am a PhD researcher in the [Software Research Lab (SRLab)](https://srlab.usask.ca/) and the [Interactive Software Engineering and Analytics (iSE) Lab](https://ise.usask.ca/) at the University of Saskatchewan, supervised by Dr. Kevin Schneider.", "awardText": "ACM SIGSOFT Distinguished Paper Award", "awardMeta": "FSE 2026 · Carbon-Taxed Transformers", "pills": [], "stats": [{"value": "10", "label": "Publications"}, {"value": "97", "label": "Citations"}], "highlights": [{"text": "ACM SIGSOFT Distinguished Paper (FSE 2026)", "url": "/publications/carbon-taxed-transformers/"}, {"text": "Best Doctoral Proposal Award (ICSME 2025)", "url": "/publications/jit-inclusive-clone-refactoring/"}, {"text": "2 papers at ASE 2026", "url": ""}, {"text": "Reviewer for IEEE TSE and ACM TOSEM", "url": ""}]};
function renderHero(){
  const h = DATA.hero || DEFAULT_HERO;
  app().innerHTML = `<h2>Home / Hero</h2><p class="lead">The top banner of your website.</p>`+FMT_HINT+
    `<div class="item">`+
    `<small>In the 2026-11 design the greeting, name, title line 3, Home menu label, photo badge, role pills and stats are not shown (the name is fixed as "Palash Ranjan Roy" so search engines see one consistent name); leave them as they are.</small>`+
    `<label>Greeting (not shown in the 2026-11 design)</label><input id="h_greeting" value="${escA(h.greeting)}">`+
    `<label>Name (not shown: the page always uses "Palash Ranjan Roy")</label><input id="h_name" value="${escA(h.name)}">`+
    `<label>Title line (shown under your name)</label><input id="h_line2" value="${escA(h.title||h.titleLine||h.line2)}">`+
    `<label>Expected PhD completion (optional, e.g. 2027-08; shown as "expected Aug 2027"). The Education PhD row's "expected" field wins when both are set.</label><input id="h_expected" value="${escA(h.expected)}">`+
    `<label>Title line 3 (not shown in the 2026-11 design)</label><input id="h_line3" value="${escA(h.line3)}">`+
    `<label>Menu label for Home (not shown in the 2026-11 design)</label><input id="h_navhome" value="${escA(h.navHome||'Home')}">`+
    `<label>Profile photo</label><div class="imgfield"><div class="uprow"><input data-k="image" id="h_photo" value="${escA(h.photo||'')}" placeholder="Upload, or leave blank for the default"><button type="button" class="up" onclick="this.nextElementSibling.click()">Upload</button><input type="file" accept="image/*" hidden onchange="onPickImage(this)"></div>${h.photo?`<img class="thumb" src="${escA(h.photo)}">`:''}</div>`+
    `<label>Photo badge (the small tag on your photo)</label><input id="h_badge" value="${escA(h.badge)}">`+
    `<label>Short description</label><textarea id="h_desc">${escA(h.description)}</textarea>`+
    `<label>Highlights line under the introduction: one fact per line, written as text | link (the link is optional). Shown as one quiet line, e.g. "ACM SIGSOFT Distinguished Paper (FSE 2026) | /publications/carbon-taxed-transformers/". When this is empty, the award line below is shown instead.</label><textarea id="h_highlights" style="min-height:90px">${escA((Array.isArray(h.highlights)?h.highlights:[]).map(x=>typeof x==='string'?x:[x&&x.text||'',x&&x.url||''].filter(Boolean).join(' | ')).join('\n'))}</textarea>`+
    `<label>Award badge text (shown only when the highlights line is empty; leave empty to hide)</label><input id="h_award" value="${escA(h.awardText)}">`+
    `<label>Award detail (e.g. FSE 2026)</label><input id="h_awardmeta" value="${escA(h.awardMeta)}">`+
    `</div>`+
    `<h3 style="margin:16px 0 6px;color:#7ec8e3;font-size:14px">Role pills</h3><div id="h_pills">`+(h.pills||[]).map(p=>`<div class="row" data-r><input value="${escA(p)}" style="flex:1"><button class="del" onclick="this.parentNode.remove()">✕</button></div>`).join('')+`</div><button onclick="addRow('h_pills',1)">+ Add pill</button>`+
    `<h3 style="margin:16px 0 6px;color:#7ec8e3;font-size:14px">Stats</h3><div id="h_stats">`+(h.stats||[]).map(s=>`<div class="row" data-r><input placeholder="Value" value="${escA(s.value)}" style="width:90px"><input placeholder="Label" value="${escA(s.label)}" style="flex:1"><button class="del" onclick="this.parentNode.remove()">✕</button></div>`).join('')+`</div><button onclick="addRow('h_stats',2)">+ Add stat</button>`+
    `<div class="bar"><button class="primary" onclick="saveHero()">Save Home</button></div>`;
}
window.addRow=(id,cols)=>{const d=document.getElementById(id);const el=document.createElement('div');el.className='row';el.setAttribute('data-r','');el.innerHTML=(cols===2?'<input placeholder="Value" style="width:90px"><input placeholder="Label" style="flex:1">':'<input style="flex:1">')+'<button class="del" onclick="this.parentNode.remove()">✕</button>';d.appendChild(el);};
window.saveHero=()=>{
  const pills=[...document.querySelectorAll('#h_pills [data-r]')].map(r=>r.querySelector('input').value.trim()).filter(Boolean);
  const stats=[...document.querySelectorAll('#h_stats [data-r]')].map(r=>{const ins=r.querySelectorAll('input');return {value:ins[0].value.trim(),label:ins[1].value.trim()};}).filter(s=>s.value||s.label);
  // Merge into the saved hero so keys this form does not show are kept. The title line is
  // stored in every key the website reads (title / titleLine win over line2).
  const prev=(DATA.hero && typeof DATA.hero==='object' && !Array.isArray(DATA.hero)) ? DATA.hero : {};
  const line=v('h_line2');
  const highlights=(document.getElementById('h_highlights')?document.getElementById('h_highlights').value:'').split(/\r?\n/).map(l=>l.split('|').map(x=>x.trim())).filter(a=>a[0]).map(([text,url])=>({text,url:url||''}));
  const data=Object.assign({}, prev, {highlights,greeting:v('h_greeting'),name:v('h_name'),line2:line,line3:v('h_line3'),navHome:v('h_navhome'),photo:v('h_photo'),badge:v('h_badge'),description:v('h_desc'),awardText:v('h_award'),awardMeta:v('h_awardmeta'),expected:v('h_expected'),pills,stats});
  if('title' in prev) data.title=line; if('titleLine' in prev) data.titleLine=line;
  DATA.hero=data; postSection('hero',data,'Home');
};

// ---- Biography ----
const DEFAULT_ABOUT = {"paragraphs": ["Palash Ranjan Roy (published as Palash Roy and Palash R. Roy) is a PhD researcher in Computer Science at the University of Saskatchewan, Canada. His research is on code clones (how they are detected, refactored and generated), model merging, and efficient large language models for software engineering. He works in the [Software Research Lab (SRLab)](https://srlab.usask.ca/) and the [Interactive Software Engineering and Analytics (iSE) Lab](https://ise.usask.ca/) under the supervision of Dr. Kevin Schneider, and expects to complete his PhD in August 2028.", "His papers have appeared at FSE, ASE, ICSME, ESEM and IWSC. Carbon-Taxed Transformers, a compression pipeline for language models used in software engineering (FSE 2026), received an ACM SIGSOFT Distinguished Paper Award. He has also received the GSA Research Excellence Award (STEM) and the Department of Computer Science Best Thesis Award (M.Sc.) at the University of Saskatchewan.", "From 2025 to 2026, Palash was President of the University of Saskatchewan Graduate Students' Association, representing more than 4,500 graduate students. He has served on the University Senate, the Provost Search Committee, and several College of Graduate and Postdoctoral Studies and award committees.", "Originally from Bangladesh, Palash completed his undergraduate studies at BRAC University before moving to Canada for his graduate studies."], "glance": [{"icon": "", "text": "PhD Researcher in Computer Science, University of Saskatchewan"}, {"icon": "", "text": "Member of the Software Research Lab (SRLab) and the iSE Lab, supervised by Dr. Kevin Schneider"}, {"icon": "", "text": "Works on code clones, model merging and efficient large language models for software engineering"}, {"icon": "", "text": "ACM SIGSOFT Distinguished Paper Award, FSE 2026 (Carbon-Taxed Transformers)"}, {"icon": "", "text": "Two papers at ASE 2026, including the MergeSE tool"}, {"icon": "", "text": "President, Graduate Students' Association (2025-26)"}, {"icon": "", "text": "Saskatoon, Canada; originally from Bangladesh"}], "education": [{"degree": "Ph.D. in Computer Science", "place": "University of Saskatchewan | 2024 - Present", "note": "In Progress", "expected": "Aug 2028", "supervisor": "Dr. Kevin Schneider"}, {"degree": "M.Sc. in Computer Science", "place": "University of Saskatchewan | 2022 - 2024", "note": "Degree conferred June 2025", "thesis_slug": "msc-thesis-llms-code-similarity", "supervisor": "Dr. Kevin Schneider and Dr. Banani Roy"}, {"degree": "B.Sc. in Computer Science and Engineering", "place": "BRAC University | 2018 - 2022", "note": "Completed", "thesis_title": "A Study on Paper and Author Ranking", "supervisor": "Dr. Mohammad Kaykobad"}], "expNote": "", "experience": [{"role": "Course co-redesign and guest lecturing, Advanced Software Engineering", "place": "University of Saskatchewan", "description": "Co-redesigned the syllabus with Prof. Chanchal K. Roy and two students to cover AI for software engineering and software engineering for AI; lectures in the course.", "category": "teaching", "order": 1}, {"role": "Research Mentor, M.Sc. and junior Ph.D. students", "place": "Software Research Lab (SRLab) and iSE Lab, University of Saskatchewan | Present", "description": "Mentoring four M.Sc. students and junior Ph.D. students on their research.", "category": "teaching", "order": 2}, {"role": "Lead Graduate Teaching Assistant", "place": "Department of Computer Science, University of Saskatchewan | 2022 - Present", "description": "Tutorials, labs, grading and coordination.", "category": "teaching", "order": 4}, {"role": "Graduate Peer Mentor (PAL)", "place": "University Library, University of Saskatchewan | 2026 - Present", "description": "Peer Assisted Learning: small-group workshops and one-to-one meetings for new graduate students.", "category": "teaching", "order": 5}, {"role": "Research Technician (Software Engineer & Systems Analyst)", "place": "Department of Computer Science, University of Saskatchewan | 2024 - Present", "description": "Research systems, lab support, grant-writing support and SOAR CREATE coordination.", "category": "research", "order": 1}, {"role": "Assistant Lab Manager", "place": "Software Research Lab (SRLab) and Interactive Software Engineering and Analytics (iSE) Lab, University of Saskatchewan", "description": "Maintains the CFI-funded SOAR research computing facility, hosts lab research prototypes as web services (e.g. mergese.usask.ca) and supports grant writing.", "category": "research", "order": 2}, {"role": "Assistant Program Coordinator", "place": "NSERC SOAR CREATE training program, University of Saskatchewan", "description": "", "category": "research", "order": 3}, {"role": "Visiting Research Student", "place": "University of Saskatchewan | May 2022 - Aug 2022", "description": "Built software engineering research tools and published research on code clones and large language models.", "category": "research"}], "inTheNews": [{"title": "Celebrating Excellence: Computer Science Professor and Graduate Students Receive GSA Awards", "url": "https://www.cs.usask.ca/news/2025/celebrating-excellence-computer-science-professor-and-graduate-students-receive-gsa-awards.php", "publisher": "Department of Computer Science, University of Saskatchewan", "date": "2025-04-08", "kind": "article"}, {"title": "Graduate Student Award Recipients", "url": "https://www.cs.usask.ca/news/2024/graduate-student-award-recipients.php", "publisher": "Department of Computer Science, University of Saskatchewan", "date": "2024-07-29", "kind": "article"}, {"title": "USask ‘clones’ researcher wins international award", "url": "https://news.usask.ca/articles/colleges/2023/usask-clones-researcher-wins-international-award.php", "publisher": "University of Saskatchewan (USask News)", "date": "2023-10-16", "kind": "article"}, {"title": "SRLab members", "url": "https://srlab.usask.ca/members/", "publisher": "Software Research Lab (SRLab), University of Saskatchewan", "date": "", "kind": "profile"}, {"title": "iSE Lab team", "url": "https://ise.usask.ca/team/", "publisher": "Interactive Software Engineering and Analytics (iSE) Lab, University of Saskatchewan", "date": "", "kind": "profile"}]};
function renderAbout(){
  const a = DATA.about || DEFAULT_ABOUT;
  app().innerHTML = `<h2>Biography</h2><p class="lead">Profile photo, biography paragraphs (shown on the <code>/about/</code> page, not the homepage), education and experience (homepage Experience section). The "At a Glance" list and the intro note are used only by the older design; leave the note empty so roles are not listed twice.</p>`+FMT_HINT+GEN_NOTE+
    `<div class="item imgfield"><label>Profile photo</label><div class="uprow"><input data-k="image" id="a_photo" value="${escA(a.photo||'')}" placeholder="Upload, or leave blank for the default"><button type="button" class="up" onclick="this.nextElementSibling.click()">Upload</button><input type="file" accept="image/*" hidden onchange="onPickImage(this)"></div>${a.photo?`<img class="thumb" src="${escA(a.photo)}">`:''}</div>`+
    `<h3 style="margin:8px 0 6px;color:#7ec8e3;font-size:14px">Paragraphs</h3><div id="a_paras">`+(a.paragraphs||[]).map(p=>`<div class="row" data-r style="align-items:flex-start"><textarea style="flex:1">${escA(p)}</textarea><button class="del" onclick="this.parentNode.remove()">✕</button></div>`).join('')+`</div><button onclick="addPara()">+ Add paragraph</button>`+
    `<h3 style="margin:16px 0 6px;color:#7ec8e3;font-size:14px">At a Glance</h3><div id="a_glance">`+(a.glance||[]).map(g=>`<div class="row" data-r><input placeholder="Icon" value="${escA(g.icon)}" style="width:60px"><input placeholder="Text" value="${escA(g.text)}" style="flex:1"><button class="del" onclick="this.parentNode.remove()">✕</button></div>`).join('')+`</div><button onclick="addGlance()">+ Add item</button>`+
    `<h3 style="margin:16px 0 6px;color:#7ec8e3;font-size:14px">Education</h3><small style="margin-bottom:6px">"Expected completion" (e.g. <i>Aug 2028</i>) is shown after your title in the hero and on the PhD row ("2024 – Aug 2028 (expected)"). Leave it empty to hide it.</small><div id="a_edu">`+(a.education||[]).map(e=>eduRow(e)).join('')+`</div><button onclick="addEdu()">+ Add education</button>`+
    `<h3 style="margin:16px 0 6px;color:#7ec8e3;font-size:14px">Experience</h3>`+
    `<label>Intro note</label><textarea id="a_expnote">${escA(a.expNote)}</textarea>`+
    `<small>Each role should appear once on the site: list a role here <b>or</b> under Leadership / Academic Service, not both. "Group" decides where it appears in Experience.</small>`+
    `<div id="a_exp" style="margin-top:8px">`+(a.experience||[]).map(x=>expRow(x)).join('')+`</div><button onclick="addExp()">+ Add experience</button>`+
    `<h3 style="margin:16px 0 6px;color:#7ec8e3;font-size:14px">In the news</h3><small>Pages by others about you, listed on <code>/about/</code> under "In the news". <b>News article</b> items (with a date) are also given to search engines as news coverage of you; <b>Lab profile page</b> items (e.g. the SRLab members page) are links only. Use the article's own headline and date. Changes appear on <code>/about/</code> after the next rebuild.</small>`+
    `<div id="a_news" style="margin-top:8px">`+(Array.isArray(a.inTheNews)?a.inTheNews:[]).map(n=>newsRow(n)).join('')+`</div><button onclick="addNewsRow()">+ Add page</button>`+
    `<div class="bar"><button class="primary" onclick="saveAbout()">Save Biography</button></div>`;
}
function eduRow(e){ e=e||{}; const known=['degree','place','note','expected','thesis_slug','supervisor','thesis_title']; const extra={}; Object.keys(e).forEach(k=>{ if(known.indexOf(k)<0) extra[k]=e[k]; });
  return `<div class="item" data-r data-extra="${escA(JSON.stringify(extra))}"><label>Degree</label><input placeholder="Degree" value="${escA(e.degree)}"><label>Place | Years</label><input placeholder="Place | Years" value="${escA(e.place)}"><label>Note (optional)</label><input placeholder="Note (optional)" value="${escA(e.note)}"><label>Expected completion (optional, e.g. Aug 2028): shown in the hero title line and on this row</label><input placeholder="Expected completion (optional, e.g. Aug 2028)" value="${escA(e.expected)}"><label>Thesis (optional): slug of the thesis in Publications, e.g. msc-thesis-llms-code-similarity; shown as "Thesis: title" on this row</label><input placeholder="Thesis slug (optional)" value="${escA(e.thesis_slug)}"><label>Thesis title (optional, when the thesis is not in Publications)</label><input placeholder="Thesis title (optional)" value="${escA(e.thesis_title)}"><label>Supervisor(s) (optional, e.g. Dr. Kevin Schneider and Dr. Banani Roy)</label><input placeholder="Supervisor(s) (optional)" value="${escA(e.supervisor)}"><button class="del" onclick="this.parentNode.remove()">Remove</button></div>`; }
const EXP_CATS=[['research','Research & Professional Positions'],['teaching','Teaching & Mentoring']];
function expRow(x){ x=x||{}; const opts=EXP_CATS.map(([k,l])=>`<option value="${k}" ${(x.category||'research')===k?'selected':''}>${l}</option>`).join('');
  const known=['role','place','description','category','order']; const extra={}; Object.keys(x).forEach(k=>{ if(known.indexOf(k)<0) extra[k]=x[k]; });
  return `<div class="item" data-r data-extra="${escA(JSON.stringify(extra))}"><input placeholder="Role" value="${escA(x.role)}"><input placeholder="Place | Years" value="${escA(x.place)}"><textarea placeholder="Description (one line)">${escA(x.description)}</textarea><label>Group</label><select data-cat>${opts}</select><label>Position in its group (optional: 1 = first; empty = by date)</label><input data-ord placeholder="e.g. 1" value="${escA(x.order==null?'':x.order)}" style="width:120px"><button class="del" onclick="this.parentNode.remove()">Remove</button></div>`; }
const NEWS_KINDS=[['article','News article'],['profile','Lab profile page']];
function newsRow(n){ n=n||{}; const known=['title','url','publisher','date','kind']; const extra={}; Object.keys(n).forEach(k=>{ if(known.indexOf(k)<0) extra[k]=n[k]; });
  const opts=NEWS_KINDS.map(([k,l])=>`<option value="${k}" ${(n.kind||'article')===k?'selected':''}>${l}</option>`).join('');
  return `<div class="item" data-r data-extra="${escA(JSON.stringify(extra))}"><label>Headline (as on the page)</label><input data-nk="title" placeholder="Headline" value="${escA(n.title)}"><label>Link</label><input data-nk="url" placeholder="https://…" value="${escA(n.url)}"><label>Publisher</label><input data-nk="publisher" placeholder="e.g. Department of Computer Science, University of Saskatchewan" value="${escA(n.publisher)}"><label>Date (YYYY-MM-DD; needed for news articles)</label><input data-nk="date" placeholder="2025-04-08" value="${escA(n.date)}" style="width:160px"><label>Kind</label><select data-nk="kind">${opts}</select><button class="del" onclick="this.parentNode.remove()">Remove</button></div>`; }
window.addNewsRow=()=>{ document.getElementById('a_news').insertAdjacentHTML('beforeend', newsRow({})); };
window.addEdu=()=>{ document.getElementById('a_edu').insertAdjacentHTML('beforeend', eduRow({})); };
window.addExp=()=>{ document.getElementById('a_exp').insertAdjacentHTML('beforeend', expRow({})); };
window.addPara=()=>{const d=document.getElementById('a_paras');const el=document.createElement('div');el.className='row';el.setAttribute('data-r','');el.style.alignItems='flex-start';el.innerHTML='<textarea style="flex:1"></textarea><button class="del" onclick="this.parentNode.remove()">✕</button>';d.appendChild(el);};
window.addGlance=()=>{const d=document.getElementById('a_glance');const el=document.createElement('div');el.className='row';el.setAttribute('data-r','');el.innerHTML='<input placeholder="Icon" style="width:60px"><input placeholder="Text" style="flex:1"><button class="del" onclick="this.parentNode.remove()">✕</button>';d.appendChild(el);};
window.saveAbout=()=>{
  const paragraphs=[...document.querySelectorAll('#a_paras [data-r]')].map(r=>r.querySelector('textarea').value.trim()).filter(Boolean);
  const glance=[...document.querySelectorAll('#a_glance [data-r]')].map(r=>{const ins=r.querySelectorAll('input');return {icon:ins[0].value.trim(),text:ins[1].value.trim()};}).filter(g=>g.text);
  const education=[...document.querySelectorAll('#a_edu [data-r]')].map(r=>{const ins=r.querySelectorAll('input');let extra={};try{extra=JSON.parse(r.getAttribute('data-extra')||'{}')||{};}catch(_){extra={};}const e=Object.assign({},extra,{degree:ins[0].value.trim(),place:ins[1].value.trim(),note:ins[2].value.trim()});const ex=ins[3]?ins[3].value.trim():'';if(ex)e.expected=ex;else if(/^ph\.?d/i.test(e.degree))e.expected='';const th=ins[4]?ins[4].value.trim():'';if(th)e.thesis_slug=th;else delete e.thesis_slug;const tt=ins[5]?ins[5].value.trim():'';if(tt)e.thesis_title=tt;else delete e.thesis_title;const sv=ins[6]?ins[6].value.trim():'';if(sv)e.supervisor=sv;else delete e.supervisor;return e;}).filter(e=>e.degree);
  const experience=[...document.querySelectorAll('#a_exp [data-r]')].map(r=>{const ins=r.querySelectorAll('input');const ta=r.querySelector('textarea');const c=r.querySelector('[data-cat]');let extra={};try{extra=JSON.parse(r.getAttribute('data-extra')||'{}')||{};}catch(_){extra={};}const o=Object.assign(extra,{role:ins[0].value.trim(),place:ins[1].value.trim(),description:ta?ta.value.trim():'',category:c?c.value:'research'});const od=r.querySelector('[data-ord]');const n=od?od.value.trim():'';if(n!==''&&isFinite(Number(n)))o.order=Number(n);else delete o.order;return o;}).filter(x=>x.role);
  // Keep keys this page doesn't show (e.g. statement), so a save never drops them.
  const base=(DATA.about && typeof DATA.about==='object' && !Array.isArray(DATA.about)) ? DATA.about : {};
  const inTheNews=[...document.querySelectorAll('#a_news [data-r]')].map(r=>{let extra={};try{extra=JSON.parse(r.getAttribute('data-extra')||'{}')||{};}catch(_){extra={};}const g=k=>{const el=r.querySelector('[data-nk="'+k+'"]');return el?el.value.trim():'';};return Object.assign(extra,{title:g('title'),url:g('url'),publisher:g('publisher'),date:g('date'),kind:g('kind')||'article'});}).filter(n=>n.title&&n.url);
  const bad=inTheNews.find(n=>!/^https?:\/\//i.test(n.url)||(n.date&&!/^\d{4}-\d{2}-\d{2}$/.test(n.date))||(n.kind==='article'&&!n.date));
  if(bad){ alert('In the news: "'+bad.title+'" needs a link starting with https:// and, for a news article, a date as YYYY-MM-DD.'); return; }
  const data=Object.assign({}, base, {photo:v('a_photo'),paragraphs,glance,education,expNote:v('a_expnote'),experience,inTheNews});
  DATA.about=data; postSection('about',data,'Biography');
};

// ---- Generic list sections (publications, gallery, …) ----
// Short help shown next to a field's name in the list editors.
const FIELD_HINTS = {
  publications:{
    doi:'bare DOI, e.g. 10.1145/3797075',
    preprint:'legacy PDF link (older site versions); use "pdf" for new uploads',
    pdf:'same-site PDF; only if the publisher allows posting it (IEEE: accepted manuscript only)',
    slug:'short stable id used in the paper page URL, e.g. gptclonebench — do not change once published',
    type:'kind of publication (Doctoral Symposium and Thesis get their own label on the website)',
    venue_short:'e.g. ICSME 2025',
    arxiv:'arXiv id only, e.g. 2308.13963',
    authors_full:'full names, one per line, in published order',
    abstract:'official abstract',
    problem:'one factual sentence: the problem the paper addresses',
    relevant_to:'topics this paper is relevant to, one per line (topics may contain commas)',
    keywords:'one per line',
    code_url:'source code repository URL',
    tool_url:'live tool / demo URL',
    artifact_url:'replication package, e.g. https://doi.org/10.5281/zenodo.…',
    dataset_url:'dataset URL',
    selected:'show under "Featured publications" on the homepage (aim for 4 to 6)',
    companion_of:'slug of the main paper, for a poster or companion entry shown as a sub-line under it',
    venue_full:'full venue name, e.g. Proceedings of the ACM on Software Engineering (paper page venue line, citation tags)',
    track:'e.g. Research Track, Tool Track, Doctoral Symposium',
    publisher:'e.g. IEEE, ACM (BibTeX and paper page)',
    volume:'journal/proceedings volume (citation_volume)',
    issue:'issue number (citation_issue)',
    first_page:'first page (citation_firstpage)',
    last_page:'last page (citation_lastpage)',
    published_date:'YYYY-MM-DD publication date (citation_publication_date; also the sitemap date)',
    issn:'ISSN of the journal or proceedings (paper page JSON-LD)',
    slides_url:'presentation slides: upload a PDF or PPTX, or paste a SpeakerDeck / SlideShare / Google Slides link. The Slides link shows only when this is filled in',
    video:'presentation video: upload an MP4 or WebM, or paste a YouTube / Vimeo link. The Video link shows only when this is filled in',
    tags:'software engineering research areas (pick from the list; edit the list under Site Settings)',
    ml_topics:'AI/ML topics (pick from the list; edit the list under Site Settings); merged with the tags into one keyword row on the website',
    equal_contribution:'co-first authors who contributed equally: full names as in authors_full, comma-separated (e.g. Ajmain Inqiad Alam, Palash Ranjan Roy). Each gets an asterisk; the author order stays as published',
    handle:'thesis repository link, e.g. https://hdl.handle.net/10388/16429 (a thesis needs this instead of a DOI)',
    tool_name:'short name of the tool in tool_url, e.g. CLEAR (the link reads "Tool (CLEAR)")',
    institution:'thesis only: awarding university, e.g. University of Saskatchewan',
    dissertation_name:'thesis only: kind of thesis, e.g. M.Sc. thesis',
    in_support_of:'thesis only: the degree it was written for, e.g. M.Sc. in Computer Science',
    degree:'thesis only, optional: short degree name shown before "thesis" on the homepage, e.g. M.Sc. (leave empty for the default)',
    my_role:'optional: your role on this paper, one short line (shown as "Role: …" under selected papers, on /publications/ and the paper page)',
    venue_rank:'optional: venue ranking shown after the venue, e.g. CORE A*',
    display_keywords:'optional: the keywords shown under this paper, comma-separated, used exactly as written (empty = picked automatically from tags and ml_topics, at most 4 on lists)'
  },
  media:{ url:'article link. An article whose link equals an award\'s link becomes that award\'s "Press" link (its title names the link but is not shown as a row); change both links together', title:'article title (shown in News only when its link matches no award)', outlet:'publication or website, e.g. USask News' },
  references:{ text:'not shown in the 2026-11 design (kept for older designs)', image:'not shown in the 2026-11 design', name:'not shown in the 2026-11 design', title:'not shown in the 2026-11 design' },
  faq:{ q:'question', a:'answer: plain and factual; links as [text](url)' },
  gallery:{ altText:'describes the photo for screen readers and search; name Palash Ranjan Roy when he is in it', caption:'optional caption under the photo on /photos/ (third person, e.g. "Palash Ranjan Roy presenting…"); when empty the alt text is used',  featuresOwner:'Palash is in this photo (search engines link the photo to his profile)', creditText:'optional photo credit, e.g. the photographer or "University of Saskatchewan" (shown on /photos/ and in search data)', date:'year or month, e.g. 2026 or Jun 2026; leave empty if unsure' },
  highlights:{ image:'not shown in the 2026-11 design (kept for older designs)', description:'shown for nominations on /about/; awards are listed as one line (title, organisation, year) and do not show it', year:'year shown in Awards & Honors, e.g. 2026', date:'optional exact date YYYY-MM-DD, used to sort the Latest list', kind:'award, or nomination (nominations appear on /about/ only)', link:'press article or award page (shown as "Press")', paper_slug:'slug of the paper this award is for (links the award to its paper page), e.g. llm-clone-generation-iwsc; leave empty if the award is not for a paper', pinned:'Pin to top (Latest): only pinned items appear in the Latest list near the top of the homepage; leave all unpinned to hide it' },
  news:{ links:'optional links inside the title, one per line: text | link | screen-reader label (the text must appear in the title). Use this to link each paper an item names, e.g. "research track | /publications/…/"; when set, the item has no single link', description:'not shown on the website (News rows are one line: date, title and link)', paper_slug:'optional: slug of the paper this item is about (links the item to its paper page)', date:'YYYY-MM-DD (or YYYY-MM, or a year): sorts the list and is shown as "Mon YYYY"', tag:'small label shown next to the item', pinned:'Pin to top (Latest): only pinned items appear in the Latest list near the top of the homepage; leave all unpinned to hide it' },
  leadership:{ description:'optional one line under the role, e.g. Elected; represented more than 4,500 graduate students', highlights:'optional impact bullets, one per line (keep to about four)', order:'optional position within Leadership & Service (1 = first); empty = by date', id:'short id, e.g. gsa; committees in Academic Service with the same "under" value are listed under this role', aboutOnly:'show only on the /about/ page', date:'optional start date YYYY-MM-DD, used to sort the Latest list', pinned:'Pin to top (Latest): only pinned items appear in the Latest list near the top of the homepage; leave all unpinned to hide it' },
  service:{ details:'optional longer text shown only on /about/ and in llms-full.txt; the homepage shows only the description (keep that to one line)', order:'optional position within its Experience group (1 = first); empty = by date', category:'decides the group in Experience (Reviewing shows as one line: role — venues)', under:'id of the Leadership role this committee belongs to, e.g. gsa', period:'shown as written, e.g. Feb 2026 or 2023 – 2026; for a role held several times, list every period in this one row', date:'optional exact date YYYY-MM-DD (e.g. the day of a talk), used to sort the Latest list', link:'optional link (e.g. a talk page or recording)', pinned:'Pin to top (Latest): only pinned items appear in the Latest list near the top of the homepage; leave all unpinned to hide it' },
  blog:{ draft:'ticked = draft: hidden from the blog list, the homepage teaser and the sitemap (the direct link still works for previewing)', slug:'URL part, e.g. my-first-post → /blog/my-first-post/', date:'YYYY-MM-DD', tags:'comma-separated' }
};
// Choices for 'select' fields. A value not in the list (typed by hand earlier) is kept as an extra option.
const SELECT_OPTS = {
  publications:{ type:[['conference','Conference paper'],['journal','Journal article'],['workshop','Workshop paper'],['poster','Poster'],['doctoral_symposium','Doctoral Symposium paper'],['thesis','Thesis']] },
  highlights:{ kind:[['award','Award (Awards & Honors)'],['scholarship','Scholarship (Awards & Honors → Scholarships)'],['nomination','Nomination (/about/ only)']] },
  news:{ tag:[['','Automatic'],['Paper','Paper'],['Talk','Talk'],['Release','Release'],['Press','Press'],['News','News']] },
  service:{ category:[['governance','University governance (under a Leadership role)'],['organizing','Organizing / service (Leadership & Service)'],['reviewing','Reviewing'],['talk','Invited talk'],['supervision','Supervision (Teaching & Mentoring)']] },
};
// Research-area tags (settings.tagVocabulary); this list is used until the vocabulary is saved.
const DEFAULT_TAG_VOCAB = [{"tag":"Software Maintenance & Evolution","label":""},{"tag":"Program Comprehension","label":""},{"tag":"Code Clones","label":""},{"tag":"Refactoring","label":""},{"tag":"Empirical SE","label":"Empirical Software Engineering"},{"tag":"AI4SE","label":"AI for Software Engineering"},{"tag":"SE4AI","label":"Software Engineering for AI"},{"tag":"Green & Sustainable SE","label":""},{"tag":"Model Merging","label":""},{"tag":"OOD Generalization","label":"Out-of-Distribution Generalization"},{"tag":"Benchmark","label":""},{"tag":"Tool","label":""},{"tag":"Scientometrics","label":""}];
const DEFAULT_ML_VOCAB = ["Model Merging","Task Arithmetic (TIES, DARE)","Out-of-Distribution Generalization","Multi-Task Learning","Code Language Models","Hugging Face Tooling","Model Compression","Pruning","Quantization","Knowledge Distillation","Efficient & Green LLMs","LLM-Generated Code","Large Language Models","LLM Evaluation","Benchmarks & Datasets","Prompting","Prompting (Few-Shot)"].map(tag=>({tag,label:''}));
const DEFAULT_AREA_TAGS = ["Software Maintenance & Evolution","Program Comprehension","Code Clones","Refactoring","Empirical SE","AI4SE","SE4AI","Green & Sustainable SE"];
const DEFAULT_ML_AREA_TAGS = ["Model Merging","Model Compression","Out-of-Distribution Generalization","Code Language Models","LLM-Generated Code","Efficient & Green LLMs"];
function tagVocab(){ const v=DATA.settings && Array.isArray(DATA.settings.tagVocabulary) ? DATA.settings.tagVocabulary : DEFAULT_TAG_VOCAB; return v.filter(t=>t && String(t.tag||'').trim()); }
function mlVocab(){ const v=DATA.settings && Array.isArray(DATA.settings.mlTopicVocabulary) ? DATA.settings.mlTopicVocabulary : DEFAULT_ML_VOCAB; return v.filter(t=>t && String(t.tag||'').trim()); }
const LIMIT_NOTE = () => `This server accepts uploads up to ${escA(UPLOAD_LIMITS.upload_max_filesize||'?')} (post_max_size ${escA(UPLOAD_LIMITS.post_max_size||'?')}); raise both in cPanel > MultiPHP INI Editor for larger files.`;
const SINGULAR = {publications:'Publication',highlights:'Award',news:'News item',media:'Article',gallery:'Photo',leadership:'Role',service:'Service item',references:'Reference',blog:'Blog Post',faq:'Question'};
const isTrue = x => x===true || /^(yes|true|1|on)$/i.test(String(x==null?'':x).trim());
function fieldLabel(section,key){
  const h = FIELD_HINTS[section] && FIELD_HINTS[section][key];
  return key + (h ? ` <span style="text-transform:none;color:#6b6f85">— ${escA(h)}</span>` : '');
}
function fieldHtml(section, idx, key, type, val){
  if(type==='select'){
    const opts=((SELECT_OPTS[section]||{})[key])||[];
    const cur=val==null?'':String(val);
    const known=opts.some(([k])=>k===cur);
    const o=opts.map(([k,l])=>`<option value="${escA(k)}" ${k===cur?'selected':''}>${escA(l)}</option>`).join('')+
      ((!known)?`<option value="${escA(cur)}" selected>${cur?escA(cur)+' (current value)':'(empty)'}</option>`:'');
    return `<label>${fieldLabel(section,key)}</label><select data-k="${key}" onchange="mark()" style="width:100%;padding:8px;background:#0c0d16;border:1px solid #2d324b;border-radius:6px;color:#fff">${o}</select>`;
  }
  if(type==='tags' || type==='mltags'){
    const cur=(Array.isArray(val)?val:String(val==null?'':val).split(type==='mltags'?/[;\n]/:',')).map(x=>String(x).trim()).filter(Boolean);
    const vocab=type==='mltags'?mlVocab():tagVocab(); const names=vocab.map(t=>t.tag);
    const extra=cur.filter(t=>names.indexOf(t)<0);
    const box=(t,label,on,unknown)=>`<label style="display:inline-flex;align-items:center;gap:6px;text-transform:none;font-size:13px;color:#e8e8ef;margin:4px 14px 4px 0"><input type="checkbox" value="${escA(t)}" ${on?'checked':''} style="width:auto" onchange="mark()"> ${escA(t)}${label?` <span style="color:#6b6f85">(${escA(label)})</span>`:''}${unknown?' <span style="color:#e0b867">(not in the tag list)</span>':''}</label>`;
    // data-order keeps the saved order of the ticked tags (the website picks keywords in that order).
    return `<label>${fieldLabel(section,key)}</label><div data-k="${key}" data-t="tags" data-order="${escA(JSON.stringify(cur))}" style="padding:4px 0">`+
      vocab.map(t=>box(t.tag,t.label,cur.indexOf(t.tag)>=0,false)).join('')+extra.map(t=>box(t,'',true,true)).join('')+`</div>`;
  }
  if(type==='slides' || type==='video'){
    const v0=val==null?'':String(val);
    const acc=type==='slides' ? '.pdf,.pptx,application/pdf,application/vnd.openxmlformats-officedocument.presentationml.presentation' : '.mp4,.webm,video/mp4,video/webm';
    const btn=type==='slides' ? 'Upload PDF / PPTX' : 'Upload MP4 / WebM';
    const max=type==='slides' ? '50 MB' : '200 MB';
    return `<div class="imgfield"><label>${fieldLabel(section,key)}</label>`+
      `<div class="uprow"><input data-k="${key}" value="${escA(v0)}" placeholder="${type==='slides'?'/uploads/… or https://speakerdeck.com/…':'/uploads/… or https://www.youtube.com/watch?v=…'}" oninput="mark()"><button type="button" class="up" onclick="this.nextElementSibling.click()">${btn}</button><input type="file" accept="${acc}" hidden onchange="onPickMedia(this,'${type}')"><button type="button" class="del" title="Clear: the link disappears from the website" onclick="const i=this.closest('.uprow').querySelector('input[data-k]');i.value='';mark()">Clear</button></div>`+
      (v0?`<small>Current: <a href="${escA(v0)}" target="_blank" rel="noopener" style="color:#7ec8e3">${escA(v0)}</a></small>`:`<small>Empty: no ${type==='slides'?'Slides':'Video'} link is shown for this paper.</small>`)+
      `<small>Max ${max}. ${LIMIT_NOTE()}</small></div>`;
  }
  if(type==='tri'){
    // yes / no / automatic: "no" is stored as false, "automatic" leaves the key out.
    const cur = val===true || /^(true|yes|1)$/i.test(String(val??'')) ? 'yes' : (val===false || /^(false|no|0)$/i.test(String(val??'')) ? 'no' : '');
    const o=(x,l)=>`<option value="${x}" ${cur===x?'selected':''}>${l}</option>`;
    return `<label>${fieldLabel(section,key)}</label><select data-k="${key}" data-t="tri" onchange="mark()" style="width:100%;padding:8px;background:#0c0d16;border:1px solid #2d324b;border-radius:6px;color:#fff">${o('','Automatic (yes if the title, description or alt text names Palash)')}${o('yes','Yes, he is in this photo')}${o('no','No, he is not in this photo')}</select>`;
  }
  if(type==='bool'){
    const h = FIELD_HINTS[section] && FIELD_HINTS[section][key];
    return `<label style="display:flex;align-items:center;gap:8px;text-transform:none;font-size:14px;color:#e8e8ef;margin-top:12px"><input type="checkbox" data-k="${key}" data-t="bool" style="width:auto" ${isTrue(val)?'checked':''} onchange="mark()"> ${escA(key)}${h?` <span style="color:#6b6f85;font-size:12px">— ${escA(h)}</span>`:''}</label>`;
  }
  if(type==='inlinks'){
    const txt=(Array.isArray(val)?val:[]).map(l=>typeof l==='string'?l:[l&&l.text||'',l&&l.url||'',l&&l.label||''].filter((x,i)=>x||i<2).join(' | ')).join('\n');
    return `<label>${fieldLabel(section,key)}</label><textarea data-k="${key}" data-t="inlinks" oninput="mark()" placeholder="research track | /publications/…/ | ASE 2026 research track paper: …">${escA(txt)}</textarea>`;
  }
  if(type==='csv' || type==='lines'){
    const sep = type==='csv' ? ', ' : '\n';
    const txt = Array.isArray(val) ? val.join(sep) : (val == null ? '' : String(val));
    if(type==='csv') return `<label>${fieldLabel(section,key)}</label><input data-k="${key}" data-t="csv" value="${escA(txt)}" oninput="mark()">`;
    return `<label>${fieldLabel(section,key)}</label><textarea data-k="${key}" data-t="lines" oninput="mark()">${escA(txt)}</textarea>`;
  }
  val = val == null ? '' : (typeof val === 'object' ? JSON.stringify(val) : String(val));
  if(FIELD_HINTS[section] && FIELD_HINTS[section][key] && type!=='url' && type!=='pdf' && type!=='image'){
    // plain text / textarea with a hint
    if(type==='area') return `<label>${fieldLabel(section,key)}</label><textarea data-k="${key}" oninput="mark()">${escA(val)}</textarea>`;
    return `<label>${fieldLabel(section,key)}</label><input data-k="${key}" value="${escA(val)}" oninput="mark()">`;
  }
  if(type==='area'){
    let extra='';
    if(section==='blog' && key==='body'){
      extra = `<div class="blogimg" style="margin:6px 0 2px;padding:8px 10px;border:1px dashed #33334a;border-radius:8px;background:#161622">`+
        `<div class="uprow" style="align-items:center">`+
        `<input type="text" class="bi_cap" placeholder="Caption (optional)" style="flex:1">`+
        `<button type="button" class="up" onclick="this.parentNode.querySelector('.bi_file').click()">Upload &amp; insert image</button>`+
        `<input type="file" class="bi_file" accept="image/*" hidden onchange="onPickBodyImage(this)">`+
        `</div>`+
        `<small>Uploads the image and drops <code>![caption](url)</code> at your cursor — the caption shows under the image. Paste a YouTube or PDF link on its own line to embed those too.</small>`+
        `</div>`;
    }
    return `<label>${key}</label>${extra}<textarea data-k="${key}" oninput="mark()">${escA(val)}</textarea>`;
  }
  if(type==='image'){
    const thumb = val ? `<img class="thumb" src="${escA(val)}">` : '';
    return `<div class="imgfield"><label>${key}</label>`+
      `<div class="uprow"><input data-k="${key}" value="${escA(val)}" oninput="mark()"><button type="button" class="up" onclick="this.nextElementSibling.click()">Upload</button><input type="file" accept="image/*" hidden onchange="onPickImage(this)"></div>`+
      `${thumb}</div>`;
  }
  if(type==='url'){
    return `<label>${key} (article link)</label>`+
      `<div class="uprow"><input data-k="${key}" value="${escA(val)}" placeholder="https://…" oninput="mark()"><button type="button" class="up" onclick="fetchTitle(this)">Fetch title</button></div>`+
      `<small>Paste the article link, then click Fetch title to auto-fill the Title field from the page.</small>`;
  }
  if(type==='pdf'){
    return `<div class="imgfield"><label>${(FIELD_HINTS[section] && FIELD_HINTS[section][key]) ? fieldLabel(section,key) : (key+' — upload a PDF, or paste a link')}</label>`+
      `<div class="uprow"><input data-k="${key}" value="${escA(val)}" placeholder="Upload a PDF, or paste an arXiv / DOI link" oninput="mark()"><button type="button" class="up" onclick="this.nextElementSibling.click()">Upload PDF</button><input type="file" accept="application/pdf" hidden onchange="onPickPdf(this)"></div>`+
      (val?`<small>Current: <a href="${escA(val)}" target="_blank" style="color:#7ec8e3">${escA(val)}</a></small>`:'')+`</div>`;
  }
  return `<label>${key}</label><input data-k="${key}" value="${escA(val)}" oninput="mark()">`;
}
function renderSection(){
  if(current==='hero'){ renderHero(); return; }
  if(current==='about'){ renderAbout(); return; }
  if(current==='headings'){ renderHeadings(); return; }
  if(current==='research'){ renderResearch(); return; }
  if(current==='custom'){ renderCustom(); return; }
  if(current==='socials'){ renderSocials(); return; }
  if(current==='footer'){ renderFooter(); return; }
  if(current==='consultation'){ renderConsultation(); return; }
  if(current==='layout'){ renderLayout(); return; }
  if(current==='settings'){ renderSettings(); return; }
  if(current==='metrics'){ renderMetrics(); return; }
  if(current==='password'){ renderPassword(); return; }
  if(current==='maintenance'){ renderMaintenance(); return; }
  if(current==='visitors'){ VIS.data=null; VIS.err=''; renderVisitors(); return; }
  if(current==='documents'){ renderDocuments(); return; }
  if(current==='software'){ renderSoftware(); return; }
  if(current==='skills'){ renderSkills(); return; }
  const items = Array.isArray(DATA[current]) ? DATA[current] : [];
  let note = '';
  if(current==='faq'){
    note = `<p class="lead">Questions and answers shown on the website's <code>/faq/</code> page (and in its search data). Keep answers factual, and name other people's work where it is relevant.</p>`+FMT_HINT;
    if(!Array.isArray(DATA.faq)) note += `<p class="lead" style="color:#e0b867">The FAQ is not in the database yet, so the website uses the FAQ built into its last build. To copy that FAQ here, apply the redesign update under <b>Maintenance &amp; Backup</b>. Saving this page first would replace it with only the questions below.</p>`;
  }
  if(current==='publications'){
    note = `<p class="lead">Only list published or accepted papers. Link a same-site PDF only when the publisher allows it; for IEEE papers upload the accepted manuscript, not the IEEE Xplore PDF. Replication packages (Zenodo) go in <code>artifact_url</code> on their paper, not as separate entries. Tick <b>selected</b> for the papers to feature first on the homepage; every other paper appears once under "Other publications". <b>Slides</b> and <b>Video</b> links appear only when filled in (use Clear to remove one). Tags come from the list under <b>Site Settings</b>. A thesis needs a <code>handle</code> link instead of a DOI.</p>`+GEN_NOTE;
  }
  if(current==='gallery'){
    note = `<p class="lead">Photos for the <code>/photos/</code> page (not shown on the homepage). Use factual titles and descriptions; don't rename image files that are already in use.</p>`+GEN_NOTE;
  }
  if(current==='highlights'){
    note = `<p class="lead">Dated one-line awards on the homepage, newest first. Put the press article in <b>link</b> instead of adding it to In the News, and don't repeat an award in News.</p>`;
  }
  if(current==='references'){
    note = `<p class="lead" style="color:#e0b867">Not shown in the 2026-11 design. The individual references below are kept for older designs only; /about/ shows just the References title and note (Sections &amp; Headings → References).</p>`;
  }
  if(current==='media'){
    note = `<p class="lead">News articles about you. An article whose link equals an award's link (Awards &amp; Honors → link) becomes that award's <b>Press</b> link; its title then names the link but is not shown as its own row, so change both links together. Other articles appear in News (newest 5, then "Older news").</p>`;
  }
  if(current==='news'){
    note = `<p class="lead">Short updates (papers, talks, releases). The homepage shows the newest 5; older items sit in a collapsed "Older news" list under them. It skips anything already listed under Awards &amp; Honors or Experience, so don't add awards or roles here. Tick <b>pinned</b> (Pin to top) to show an item in the <b>Latest</b> list near the top of the homepage (Awards, Leadership and Academic Service items can be pinned too). Only pinned items appear at the top; leave all unpinned to hide the strip. Pinned news is not repeated in the News section.</p>`;
  }
  if(current==='blog'){
    note = `<p class="lead">Posts appear at <code>/blog/</code> and in the homepage teaser (latest 2). New posts show immediately; their static pages and sitemap entries are added on the next rebuild.</p>`+FMT_HINT;
  }
  app().innerHTML = `<h2>${LABELS[current]}</h2><p class="lead">Add, edit, reorder or remove items. Use ↑ ↓ to change the order.</p>` + note +
    `<div id="items">` + items.map((it,i)=>itemHtml(current,i,it)).join('') + `</div>` +
    `<div class="bar"><button onclick="addItem()">+ Add ${SINGULAR[current]||'item'}</button>` +
    `<button class="primary" onclick="save()">Save ${LABELS[current]}</button></div>`;
}
function itemHtml(section,i,it){
  const schema = SCHEMAS[section];
  const fields = Object.entries(schema).map(([k,t])=>fieldHtml(section,i,k,t,it[k])).join('');
  const title = it.title||it.name||it.role||it.q||it.date||('Item '+(i+1));
  return `<div class="item" data-i="${i}"><div class="item-head"><b>${((i+1)+'. '+title).replace(/</g,'&lt;')}</b>`+
    `<span class="acts">`+
    `<button class="mv" title="Move up" onclick="moveItem(${i},-1)">↑</button>`+
    `<button class="mv" title="Move down" onclick="moveItem(${i},1)">↓</button>`+
    `<button class="del" onclick="delItem(${i})">Remove</button></span></div>${fields}</div>`;
}
function collect(){
  const schema = SCHEMAS[current] || {};
  const src = Array.isArray(DATA[current]) ? DATA[current] : [];
  return [...document.querySelectorAll('#items > .item')].map(el=>{
    const o={};
    // Keep fields this form doesn't show (e.g. venue_full, published_date), so a save never drops them.
    const orig = src[parseInt(el.dataset.i,10)];
    if(orig && typeof orig==='object'){
      Object.keys(orig).forEach(k=>{ if(!Object.prototype.hasOwnProperty.call(schema,k)) o[k]=orig[k]; });
    }
    el.querySelectorAll('[data-k]').forEach(inp=>{
      const t=inp.dataset.t;
      if(t==='bool'){ if(inp.checked) o[inp.dataset.k]=true; return; }
      if(t==='tri'){ if(inp.value==='yes') o[inp.dataset.k]=true; else if(inp.value==='no') o[inp.dataset.k]=false; return; }
      if(t==='tags'){ let ord=[]; try{ ord=JSON.parse(inp.getAttribute('data-order')||'[]')||[]; }catch(_){ ord=[]; }
        const rank=v=>{ const i=ord.indexOf(v); return i<0?1e6:i; };
        const arr=[...inp.querySelectorAll('input[type=checkbox]')].filter(c=>c.checked).map(c=>c.value).map((v,i)=>[v,i]).sort((a,b)=>(rank(a[0])-rank(b[0]))||(a[1]-b[1])).map(x=>x[0]);
        if(arr.length) o[inp.dataset.k]=arr; return; }
      if(t==='inlinks'){
        const arr=inp.value.split(/\r?\n/).map(x=>x.split('|').map(y=>y.trim())).filter(a=>a[0]&&a[1]).map(([text,url,label])=>label?{text,url,label}:{text,url});
        if(arr.length) o[inp.dataset.k]=arr;
        return;
      }
      if(t==='csv' || t==='lines'){
        const arr=inp.value.split(t==='csv' ? ',' : /\r?\n/).map(x=>x.trim()).filter(Boolean);
        if(arr.length) o[inp.dataset.k]=arr;
        return;
      }
      const val=inp.value.trim(); if(val!=='') o[inp.dataset.k]=(inp.dataset.k==='order' && isFinite(Number(val))) ? Number(val) : val;
    });
    return o;
  });
}
window.addItem = ()=>{ DATA[current]=collect(); DATA[current].push({}); renderSection(); };
window.delItem = i=>{ if(!confirm('Remove this item? (It is only deleted when you click Save.)')) return; DATA[current]=collect(); DATA[current].splice(i,1); renderSection(); };
window.moveItem = (i,dir)=>{ DATA[current]=collect(); const j=i+dir; if(j<0||j>=DATA[current].length) return; const a=DATA[current]; [a[i],a[j]]=[a[j],a[i]]; renderSection(); };
window.mark = ()=>{};
window.save = ()=>{
  const items=collect();
  if(current==='publications'){
    const bad=items.find(p=>['slides_url','video','handle','pdf','preprint'].some(k=>p[k] && !/^(\/|https:\/\/|http:\/\/)/i.test(p[k])));
    if(bad){ toast('Links must start with / or https:// ('+String(bad.title||'').slice(0,40)+')'); return; }
    const dup={}; const d=items.find(p=>p.slug && (dup[p.slug]=(dup[p.slug]||0)+1)>1);
    if(d){ toast('Two papers use the slug "'+d.slug+'"; each slug must be unique'); return; }
  }
  DATA[current]=items; postSection(current,DATA[current],LABELS[current]);
};

// ================= Maintenance: backup, restore, content migrations =================
// A migration (admin/migrations/*.json) is a list of operations:
//   set_fields          {section, fields:{"path.to.key":{from,to}}, base_if_missing}
//   patch_items         {section, match_by:[keys], update:[{match,set:{k:{from,to} or {find,replace}}}], remove:[{match}], add:[{index,item}], base_if_missing}
//   upsert_publications {section:'publications', items, corrections:{slug:{k:{from,to}}}, remove_matching:[text], remove_title_sha256:[{words,sha256}], sort, clear_links_to:[paths], base_if_missing}
//   (patch_items also takes clear_links_to: link fields pointing at a listed same-site file are removed,
//    even when you wrote them yourself, because the file is no longer on the site.)
//   replace_items       {section, list_key?, match_by, replace:[{label,match_any,match_prefix,with}], add:[{item,before,index}], base_if_missing}
//   (patch_items and replace_items take list_key when the list is inside an object section, e.g. software.items)
//   set_hidden          {add:[section ids]}
//   seed_if_missing     {section, data}
// "from" is the value the website shipped with. A change is pre-selected when the current
// database value still equals "from" (or both are empty); otherwise it is a conflict, left
// unselected. An empty value where "from" was not empty counts as a conflict: it was cleared
// on purpose (by you or by a later update), so an older update must not fill it back in.
// Applied updates are recorded in settings.appliedMigrations; the list marks them, preselects
// the first update not applied yet, and asks before an older update is applied after a newer one.
// Writes go through the normal authenticated save endpoint, one section at a time, after a
// full backup has been downloaded.
const maint = {snapshot:null, plan:null, mig:0, restore:null, busy:false, log:[]};
// Synchronous SHA-256 of a UTF-8 string, hex (used by remove_title_sha256).
function mSha256(str){
  const K=[0x428a2f98,0x71374491,0xb5c0fbcf,0xe9b5dba5,0x3956c25b,0x59f111f1,0x923f82a4,0xab1c5ed5,0xd807aa98,0x12835b01,0x243185be,0x550c7dc3,0x72be5d74,0x80deb1fe,0x9bdc06a7,0xc19bf174,0xe49b69c1,0xefbe4786,0x0fc19dc6,0x240ca1cc,0x2de92c6f,0x4a7484aa,0x5cb0a9dc,0x76f988da,0x983e5152,0xa831c66d,0xb00327c8,0xbf597fc7,0xc6e00bf3,0xd5a79147,0x06ca6351,0x14292967,0x27b70a85,0x2e1b2138,0x4d2c6dfc,0x53380d13,0x650a7354,0x766a0abb,0x81c2c92e,0x92722c85,0xa2bfe8a1,0xa81a664b,0xc24b8b70,0xc76c51a3,0xd192e819,0xd6990624,0xf40e3585,0x106aa070,0x19a4c116,0x1e376c08,0x2748774c,0x34b0bcb5,0x391c0cb3,0x4ed8aa4a,0x5b9cca4f,0x682e6ff3,0x748f82ee,0x78a5636f,0x84c87814,0x8cc70208,0x90befffa,0xa4506ceb,0xbef9a3f7,0xc67178f2];
  const b=Array.from(new TextEncoder().encode(str)); const l=b.length*8;
  b.push(0x80); while(b.length%64!==56) b.push(0);
  for(let i=7;i>=0;i--) b.push(i>3?0:(l>>>(i*8))&255);
  let H=[0x6a09e667,0xbb67ae85,0x3c6ef372,0xa54ff53a,0x510e527f,0x9b05688c,0x1f83d9ab,0x5be0cd19];
  const r=(x,n)=>(x>>>n)|(x<<(32-n)); const W=new Array(64);
  for(let o=0;o<b.length;o+=64){
    for(let t=0;t<16;t++) W[t]=(b[o+4*t]<<24)|(b[o+4*t+1]<<16)|(b[o+4*t+2]<<8)|b[o+4*t+3];
    for(let t=16;t<64;t++){ const s0=r(W[t-15],7)^r(W[t-15],18)^(W[t-15]>>>3), s1=r(W[t-2],17)^r(W[t-2],19)^(W[t-2]>>>10); W[t]=(W[t-16]+s0+W[t-7]+s1)|0; }
    let [a,b2,c,d,e,f,g,h]=H;
    for(let t=0;t<64;t++){ const S1=r(e,6)^r(e,11)^r(e,25), ch=(e&f)^(~e&g), t1=(h+S1+ch+K[t]+W[t])|0, S0=r(a,2)^r(a,13)^r(a,22), mj=(a&b2)^(a&c)^(b2&c), t2=(S0+mj)|0; h=g; g=f; f=e; e=(d+t1)|0; d=c; c=b2; b2=a; a=(t1+t2)|0; }
    H=[(H[0]+a)|0,(H[1]+b2)|0,(H[2]+c)|0,(H[3]+d)|0,(H[4]+e)|0,(H[5]+f)|0,(H[6]+g)|0,(H[7]+h)|0];
  }
  return H.map(x=>(x>>>0).toString(16).padStart(8,'0')).join('');
}
function mClone(x){ return x===undefined ? undefined : JSON.parse(JSON.stringify(x)); }
function mStable(x){
  if(Array.isArray(x)) return '['+x.map(mStable).join(',')+']';
  if(x && typeof x==='object') return '{'+Object.keys(x).sort().map(k=>JSON.stringify(k)+':'+mStable(x[k])).join(',')+'}';
  return JSON.stringify(x===undefined ? null : x);
}
function mEq(a,b){ return mStable(a)===mStable(b); }
function mEmpty(x){ return x===undefined || x===null || x==='' || (Array.isArray(x) && x.length===0); }
function mObj(x){ return !!x && typeof x==='object' && !Array.isArray(x); }
function mNormT(s){ return String(s==null?'':s).toLowerCase().replace(/[^a-z0-9]/g,''); }
function mNormDoi(d){ return String(d==null?'':d).trim().replace(/^https?:\/\/(dx\.)?doi\.org\//i,'').toLowerCase(); }
function mGet(o,path){ return path.split('.').reduce((a,k)=>mObj(a) ? a[k] : undefined, o); }
function mSet(o,path,val){
  const ks=path.split('.'); let a=o;
  for(let i=0;i<ks.length-1;i++){ if(!mObj(a[ks[i]])) a[ks[i]]={}; a=a[ks[i]]; }
  const last=ks[ks.length-1];
  if(val===undefined || val===null) delete a[last]; else a[last]=mClone(val);
}
function mShow(x){
  if(x===undefined || x===null) return '(empty)';
  const s = typeof x==='string' ? x : JSON.stringify(x);
  return s==='' ? '(empty)' : s;
}
function mStamp(){ const d=new Date(); const p=n=>String(n).padStart(2,'0'); return d.getFullYear()+p(d.getMonth()+1)+p(d.getDate())+'-'+p(d.getHours())+p(d.getMinutes())+p(d.getSeconds()); }
function mDownload(obj,name){
  const blob=new Blob([JSON.stringify(obj,null,2)],{type:'application/json'});
  const url=URL.createObjectURL(blob); const a=document.createElement('a');
  a.href=url; a.download=name; document.body.appendChild(a); a.click();
  setTimeout(()=>{ URL.revokeObjectURL(url); a.remove(); },1500);
}
async function mFetchSnapshot(){
  const r=await fetch('index.php?action=backup',{method:'POST',headers:{'Content-Type':'application/json'},credentials:'same-origin',cache:'no-store',body:JSON.stringify({csrf:CSRF})});
  const j=await r.json();
  if(!r.ok || !j || !mObj(j.sections)) throw new Error((j&&j.error)||('HTTP '+r.status));
  return j;
}
async function mSave(section,data){
  try{
    const r=await fetch('index.php?action=save',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({csrf:CSRF,section,data})});
    const j=await r.json(); return j.ok ? {ok:true} : {ok:false,error:j.error||('HTTP '+r.status)};
  }catch(e){ return {ok:false,error:'Network error'}; }
}

// ---- build a plan: one group per operation, each with selectable changes and an apply function
function mFindItem(list,matchBy,match){
  for(let i=0;i<list.length;i++){
    const it=list[i]; if(!mObj(it)) continue;
    if(matchBy.every(k=>mNormT(it[k])===mNormT(match[k]))) return i;
  }
  return -1;
}
// Same-site paths a migration has deleted: any link field ending in one of them goes.
function mDeadLinks(op,list,keys,changes,sec){
  const dead=(op.clear_links_to||[]).map(p=>String(p).toLowerCase());
  if(!dead.length || !Array.isArray(list)) return;
  const norm=u=>{ let s=String(u==null?'':u).trim().replace(/^https?:\/\/(www\.)?palashroy\.me/i,'').replace(/[?#].*$/,''); try{ s=decodeURI(s); }catch(e){} return s.toLowerCase(); };
  list.forEach((it,i)=>{
    if(!mObj(it)) return;
    keys.forEach(k=>{
      const now=it[k]; if(typeof now!=='string' || !now.trim()) return;
      const p=norm(now);
      if(!dead.some(d=>p===d || p.endsWith(d))) return;
      if(changes.some(c=>c.idx===i && c.key===k)) return; // already handled by a listed correction
      changes.push({kind:'change',label:'Remove link to deleted file from '+sec+' #'+(i+1)+' ('+String(it.title||'').slice(0,60)+') → '+k,from:now,to:null,expected:now,checked:true,idx:i,key:k});
    });
  });
}
function mStatus(cur,from){ return (mEq(cur,from) || (mEmpty(cur) && mEmpty(from))) ? 'change' : 'conflict'; }
// patch_items on one list (a top-level array section, or obj[list_key]).
function mPlanPatch(op,list,where,changes,g,put,sec){
  const mb=op.match_by||['title'];
  (op.update||[]).forEach(u=>{
    const i=mFindItem(list,mb,u.match);
    if(i<0){
      // Already updated earlier (the match keys now hold their new values)? Then skip quietly.
      const after={}; mb.forEach(k=>{ after[k]=(u.set&&u.set[k]) ? u.set[k].to : u.match[k]; });
      if(mFindItem(list,mb,after)<0) g.info.push('Not found (skipped): '+mb.map(k=>u.match[k]).join(' / '));
      return;
    }
    Object.keys(u.set||{}).forEach(k=>{
      const f=u.set[k], now=list[i][k];
      if(typeof f.find==='string'){
        // Text edit inside a longer field: replace one phrase, keep the rest (even if you edited it).
        const txt=typeof now==='string' ? now : '';
        if(txt.indexOf(f.find)<0){ if(txt.indexOf(f.replace)<0) g.info.push(where+' #'+(i+1)+' → '+k+': phrase not found (skipped): '+f.find.slice(0,80)); return; }
        changes.push({kind:'change',label:where+' #'+(i+1)+' ('+String(list[i][mb[0]]||'').slice(0,60)+') → '+k+' (one phrase)',from:now,to:txt.split(f.find).join(f.replace),expected:now,checked:true,idx:i,key:k});
        return;
      }
      if(mEq(now,f.to) || (mEmpty(now)&&mEmpty(f.to))) return;
      const st=mStatus(now,f.from);
      changes.push({kind:st,label:where+' #'+(i+1)+' ('+String(list[i][mb[0]]||'').slice(0,60)+') → '+k,from:now,to:f.to,expected:f.from,checked:st==='change',idx:i,key:k});
    });
  });
  (op.remove||[]).forEach(r=>{
    const i=mFindItem(list,mb,r.match);
    if(i<0) return;
    changes.push({kind:'remove',label:'Remove from '+where+': '+String(list[i][mb[0]]||r.label||''),from:list[i],to:undefined,checked:true,idx:i});
  });
  (op.add||[]).forEach(a=>{
    if(mFindItem(list,mb,a.item)>=0) return; // already there
    changes.push({kind:'add',label:'Add to '+where+': '+String(a.item[mb[0]]||a.item.title||''),from:undefined,to:a.item,checked:true,index:a.index});
  });
  mDeadLinks(op,list,['link','url','image','pdf'],changes,sec);
  g.apply=(st)=>{
    const l=mClone(list), drop={};
    changes.forEach(c=>{ if(!c.checked) return;
      if(c.key!==undefined){ if(c.to===undefined||c.to===null) delete l[c.idx][c.key]; else l[c.idx][c.key]=mClone(c.to); }
      else if(c.kind==='remove') drop[c.idx]=true;
    });
    const out=l.filter((_,i)=>!drop[i]);
    changes.filter(c=>c.checked && c.kind==='add').sort((a,b)=>a.index-b.index).forEach(c=>{ out.splice(Math.min(Math.max(0,c.index|0),out.length),0,mClone(c.to)); });
    put(st,out);
  };
  return g;
}
// replace_items: swap old entries for new ones, and add entries at a position.
//   replace: [{label, match_any:[{key:value}], match_prefix:{key:'starts with'}, with:[items]}]
//     every entry matching one of match_any (normalized equality) AND every match_prefix is
//     replaced, in place, by the "with" items that are not in the list yet (by match_by).
//     Nothing matches and the "with" items are already there: done before, skipped quietly.
//   add: [{item, before:{key:value}, index}]  inserted before the first entry matching
//     "before" (else at index, else at the end); skipped when already there (by match_by).
function mPlanReplace(op,list,where,changes,g,put){
  const mb=op.match_by||['title'];
  const has=(it)=>mFindItem(list,mb,it)>=0;
  const pre=(it,mp)=>Object.keys(mp||{}).every(k=>mNormT(it[k]).indexOf(mNormT(mp[k]))===0);
  const taken={};
  (op.replace||[]).forEach(r=>{
    const alts=Array.isArray(r.match_any)&&r.match_any.length ? r.match_any : [r.match||{}];
    const idxs=[];
    list.forEach((it,i)=>{
      if(!mObj(it) || taken[i]) return;
      const eq=alts.some(m=>Object.keys(m).every(k=>mNormT(it[k])===mNormT(m[k])));
      if(eq && pre(it,r.match_prefix)) idxs.push(i);
    });
    const missing=(r.with||[]).filter(w=>!has(w));
    if(!idxs.length){
      if(missing.length) g.info.push('Not found (skipped): '+(r.label||JSON.stringify(alts[0])));
      return;
    }
    idxs.forEach(i=>{ taken[i]=true; });
    changes.push({kind:'replace',label:(r.label||('Replace in '+where))+' ('+idxs.length+' → '+missing.length+' entr'+(missing.length===1?'y':'ies')+')',
      from:idxs.map(i=>list[i]),to:missing,checked:true,idxs});
  });
  (op.add||[]).forEach(a=>{
    if(has(a.item)) return;
    changes.push({kind:'add',label:'Add to '+where+': '+String(a.item[mb[0]]||a.item.title||''),from:undefined,to:a.item,checked:true,add:a});
  });
  g.apply=(st)=>{
    const out=[], first={}, drop={};
    changes.forEach(c=>{ if(c.checked && c.idxs){ first[c.idxs[0]]=c; c.idxs.forEach(i=>{ drop[i]=true; }); } });
    list.forEach((it,i)=>{
      if(first[i]) first[i].to.forEach(w=>out.push(mClone(w)));
      if(!drop[i]) out.push(mClone(it));
    });
    changes.forEach(c=>{
      if(!c.checked || !c.add) return;
      const a=c.add; let at=-1;
      if(mObj(a.before)){ const keys=Object.keys(a.before); at=out.findIndex(it=>mObj(it) && keys.every(k=>mNormT(it[k])===mNormT(a.before[k]))); }
      if(at<0 && typeof a.index==='number') at=Math.min(Math.max(0,a.index),out.length);
      if(at<0) out.push(mClone(c.to)); else out.splice(at,0,mClone(c.to));
    });
    put(st,out);
  };
  return g;
}
function mPlanOp(op,snap){
  const sec=op.section, cur=snap[sec], changes=[];
  const g={op:op.op, section:sec, changes, apply:null, info:[]};
  const create=(base,why)=>{ changes.push({kind:'create',label:why,from:undefined,to:base,checked:true}); g.apply=(st)=>{ if(changes[0].checked) st[sec]=mClone(base); }; };

  if(op.op==='set_fields'){
    if(!mObj(cur)){ create(op.base_if_missing,'Section "'+sec+'" is not in the database yet: create it from the new content'); return g; }
    Object.keys(op.fields||{}).forEach(path=>{
      const f=op.fields[path], now=mGet(cur,path);
      if(mEq(now,f.to) || (mEmpty(now)&&mEmpty(f.to))) return;
      const st=f.force ? 'change' : mStatus(now,f.from); // force: placeholder text that must go
      changes.push({kind:st,label:sec+' → '+path,from:now,to:f.to,expected:f.from,checked:st==='change',path});
    });
    g.apply=(st)=>{ const o=mObj(st[sec])?st[sec]:mClone(cur); changes.forEach(c=>{ if(c.checked) mSet(o,c.path,c.to); }); st[sec]=o; };
    return g;
  }

  if(op.op==='patch_items' || op.op==='replace_items'){
    // list_key: the list lives inside an object section (e.g. software.items, about.education).
    const lk=op.list_key;
    if(lk){
      if(!mObj(cur)){ create(op.base_if_missing,'Section "'+sec+'" is not in the database yet: create it from the new content'); return g; }
      if(!Array.isArray(cur[lk])){
        const base=mObj(op.base_if_missing) && Array.isArray(op.base_if_missing[lk]) ? op.base_if_missing[lk] : [];
        changes.push({kind:'create',label:'"'+sec+'.'+lk+'" is not in the database yet: create it from the new content',from:undefined,to:base,checked:true});
        g.apply=(st)=>{ if(!changes[0].checked) return; const o=mObj(st[sec])?st[sec]:mClone(cur); o[lk]=mClone(base); st[sec]=o; };
        return g;
      }
    } else if(!Array.isArray(cur)){ create(op.base_if_missing,'Section "'+sec+'" is not in the database yet: create it from the new content'); return g; }
    const list0=lk ? cur[lk] : cur;
    const where=lk ? sec+'.'+lk : sec;
    // Write the result back: an object section keeps its other keys (and earlier operations' results).
    const put=(st,out)=>{ if(!lk){ st[sec]=out; return; } const o=mObj(st[sec])?st[sec]:mClone(cur); o[lk]=out; st[sec]=o; };
    if(op.op==='replace_items'){ mPlanReplace(op,list0,where,changes,g,put); return g; }
    return mPlanPatch(op,list0,where,changes,g,put,sec);
  }
  if(op.op==='upsert_publications'){
    if(!Array.isArray(cur)){ create(op.base_if_missing,'Publications are not in the database yet: create them from the new list'); return g; }
    const corrAll=op.corrections||{}, used={};
    (op.items||[]).forEach(n=>{
      const corr=corrAll[n.slug]||{};
      let i=-1;
      const nd=mNormDoi(n.doi);
      for(let j=0;j<cur.length && i<0;j++){ if(!used[j] && nd && mNormDoi(cur[j].doi)===nd) i=j; }
      for(let j=0;j<cur.length && i<0;j++){ if(!used[j] && n.slug && cur[j].slug===n.slug) i=j; }
      const titles=[mNormT(n.title)]; if(corr.title && corr.title.from) titles.push(mNormT(corr.title.from));
      for(let j=0;j<cur.length && i<0;j++){ if(!used[j] && titles.indexOf(mNormT(cur[j].title))>=0) i=j; }
      if(i<0){ changes.push({kind:'add',label:'Add paper: '+n.title+' ('+n.year+')',from:undefined,to:n,checked:true,order:(op.items||[]).indexOf(n)}); return; }
      used[i]=true;
      const e=cur[i], fills=[], keys=Object.keys(n); let kept=0;
      Object.keys(corr).forEach(k=>{ if(keys.indexOf(k)<0) keys.push(k); });
      keys.forEach(k=>{
        if(Object.prototype.hasOwnProperty.call(corr,k)){
          const to=corr[k].to, now=e[k];
          if(mEq(now,to) || (mEmpty(now)&&mEmpty(to))) return;
          const st=mStatus(now,corr[k].from);
          changes.push({kind:st,label:'Correct "'+String(e.title).slice(0,60)+'" → '+k,from:now,to:to,expected:corr[k].from,checked:st==='change',idx:i,key:k});
        } else if(mEmpty(e[k]) && !mEmpty(n[k])) fills.push(k);
        else if(!mEq(e[k],n[k])) kept++;
      });
      if(fills.length) changes.push({kind:'fill',label:'Fill '+fills.length+' empty field(s) on "'+String(e.title).slice(0,60)+'": '+fills.join(', '),from:undefined,to:fills.reduce((o,k)=>{o[k]=n[k];return o;},{}),checked:true,idx:i,fills});
      if(kept) g.info.push('"'+String(e.title).slice(0,60)+'": kept '+kept+' field(s) you wrote yourself.');
    });
    mDeadLinks(op,cur,['preprint','pdf','video','artifact_url','dataset_url','code_url','tool_url'],changes,sec);
    const pats=(op.remove_matching||[]).map(p=>String(p).toLowerCase());
    // remove_title_sha256: [{words:n, sha256}] matches a run of n title words (lower case,
    // split on anything but a-z/0-9, joined by one space) by its SHA-256, so a migration
    // file can drop a row without naming its title.
    const hashed=(op.remove_title_sha256||[]).filter(h=>h && h.sha256 && (h.words|0)>0);
    const titleHit=(title)=>{
      if(!hashed.length) return false;
      const w=String(title||'').toLowerCase().normalize('NFKD').split(/[^a-z0-9]+/).filter(Boolean);
      return hashed.some(h=>{ const n=h.words|0; for(let k=0;k+n<=w.length;k++){ if(mSha256(w.slice(k,k+n).join(' '))===String(h.sha256).toLowerCase()) return true; } return false; });
    };
    cur.forEach((e,i)=>{
      const t=String((e&&e.title)||'').toLowerCase();
      if(pats.some(p=>t.indexOf(p)>=0) || titleHit(e&&e.title)) changes.push({kind:'remove',label:'Remove (not published or accepted): '+e.title,from:e,to:undefined,checked:true,idx:i});
    });
    const yr=p=>parseInt(p&&p.year,10)||0;
    const sortedNow=cur.every((p,i)=>i===0 || yr(cur[i-1])>=yr(p));
    if(op.sort==='year_desc' && (!sortedNow || changes.some(c=>c.kind==='add'))) changes.push({kind:'change',label:'Sort publications by year, newest first (order within a year is kept)',from:undefined,to:undefined,checked:true,sort:true});
    g.apply=(st)=>{
      const list=mClone(cur), drop={};
      changes.forEach(c=>{ if(!c.checked || c.idx===undefined) return;
        if(c.kind==='remove'){ drop[c.idx]=true; return; }
        if(c.fills){ c.fills.forEach(k=>{ list[c.idx][k]=mClone(c.to[k]); }); return; }
        if(c.key!==undefined){ if(c.to===undefined||c.to===null) delete list[c.idx][c.key]; else list[c.idx][c.key]=mClone(c.to); }
      });
      let out=list.filter((_,i)=>!drop[i]);
      // Insert each new paper just before the next paper that follows it in the update's own list.
      const later=(c)=>(op.items||[]).slice(c.order+1).map(x=>mNormDoi(x.doi)).filter(Boolean);
      changes.filter(c=>c.checked && c.kind==='add').sort((a,b)=>b.order-a.order).forEach(c=>{
        const ds=later(c); let at=-1;
        for(let j=0;j<out.length && at<0;j++){ if(ds.indexOf(mNormDoi(out[j].doi))>=0) at=j; }
        if(at<0) out.push(mClone(c.to)); else out.splice(at,0,mClone(c.to));
      });
      const sortC=changes.filter(c=>c.sort)[0];
      if(sortC && sortC.checked){
        out=out.map((p,i)=>({p,i})).sort((a,b)=>((parseInt(b.p.year,10)||0)-(parseInt(a.p.year,10)||0)) || (a.i-b.i)).map(x=>x.p);
      }
      st[sec]=out;
    };
    return g;
  }

  if(op.op==='set_hidden'){
    g.section='sectionsHidden';
    const now=Array.isArray(snap.sectionsHidden) ? snap.sectionsHidden : [];
    (op.add||[]).forEach(id=>{ if(now.indexOf(id)<0) changes.push({kind:'change',label:'Hide the "'+id+'" section (its content is kept; show it again under Page Layout)',from:undefined,to:id,checked:true}); });
    g.apply=(st)=>{ const out=now.slice(); changes.forEach(c=>{ if(c.checked && out.indexOf(c.to)<0) out.push(c.to); }); st.sectionsHidden=out; };
    return g;
  }

  if(op.op==='seed_if_missing'){
    if(cur===undefined || cur===null) create(op.data,'Section "'+sec+'" is not in the database yet: copy it from the new website content');
    else g.apply=()=>{};
    return g;
  }

  g.info.push('Unknown operation "'+op.op+'" (skipped).');
  g.apply=()=>{};
  return g;
}
function mBuildPlan(mig,snap){ return (mig.operations||[]).map(op=>mPlanOp(op,snap)); }
function mResult(plan,snap){
  const st=mClone(snap);
  plan.forEach(g=>{ if(g.apply) g.apply(st); });
  const changed=Object.keys(st).filter(k=>!mEq(st[k],snap[k]));
  return {state:st, changed};
}

// ---- rendering
const M_KIND={change:['update','#7ec8e3'],replace:['replace','#7ec8e3'],fill:['fill empty','#7ec8e3'],add:['add','#8fe3a0'],remove:['remove','#ff8a8a'],create:['create section','#8fe3a0'],conflict:['you edited this','#e0b867']};
function mChangeHtml(gi,ci,c){
  const k=M_KIND[c.kind]||[c.kind,'#a2a5b9'];
  const detail = (c.kind==='conflict')
    ? `<div><small>Shipped value: ${escA(mShow(c.expected).slice(0,600))}</small></div>` : '';
  const showFrom = !(c.kind==='add' || c.kind==='create' || c.fills || c.sort || c.from===undefined);
  const showTo = !(c.kind==='remove' || c.sort || c.to===undefined || (typeof c.to==='string' && c.label.indexOf('Hide the')===0));
  return `<div style="border-top:1px solid #23263a;padding:8px 0">`+
    `<label style="display:flex;gap:8px;align-items:flex-start;text-transform:none;font-size:13px;color:#e8e8ef;margin:0">`+
    `<input type="checkbox" style="width:auto;margin-top:3px" ${c.checked?'checked':''} onchange="mToggle(${gi},${ci},this.checked)">`+
    `<span><span style="color:${k[1]};font-size:11px;border:1px solid ${k[1]};border-radius:4px;padding:0 5px;margin-right:6px">${k[0]}</span>${escA(c.label)}</span></label>`+
    `<details style="margin:4px 0 0 24px"><summary style="cursor:pointer;color:#6b6f85;font-size:12px">details</summary>`+
    (showFrom?`<div><small>Now: ${escA(mShow(c.from).slice(0,1500))}</small></div>`:'')+
    (showTo?`<div><small>New: ${escA(mShow(c.to).slice(0,1500))}</small></div>`:'')+
    detail+`</details></div>`;
}
function mPlanHtml(){
  const p=maint.plan; if(!p) return '';
  let total=0, sel=0, conf=0;
  p.forEach(g=>g.changes.forEach(c=>{ total++; if(c.checked) sel++; if(c.kind==='conflict') conf++; }));
  let html=`<div class="item"><div class="item-head"><b>Preview</b></div>`+
    `<p class="lead" style="margin:4px 0">${total} possible change(s), ${sel} selected${conf?`, ${conf} conflict(s) left unselected because you edited those fields yourself`:''}. Untick anything you want to keep as it is.</p>`;
  if(!total) html+=`<p class="lead">Nothing to do: the database already matches this update.</p>`;
  p.forEach((g,gi)=>{
    if(!g.changes.length && !g.info.length) return;
    html+=`<div style="margin-top:12px"><b style="font-size:13px;color:#7ec8e3">${escA(g.section||'')}</b> <small style="display:inline;color:#6b6f85">${escA(g.op)}</small>`+
      g.changes.map((c,ci)=>mChangeHtml(gi,ci,c)).join('')+
      g.info.map(t=>`<small>${escA(t)}</small>`).join('')+`</div>`;
  });
  html+=`</div>`;
  if(total) html+=`<div class="bar"><button class="primary" onclick="mApply()" ${maint.busy?'disabled':''}>Download backup, then apply ${sel} selected change(s)</button><button onclick="mPreview()">Refresh preview</button></div>`;
  return html;
}
// ---- maintenance mode switch (server writes the .maintenance flag + maintenance.html)
function mmHtml(){
  const st=(DATA.settings && DATA.settings.maintenance && typeof DATA.settings.maintenance==='object') ? DATA.settings.maintenance : {};
  const on=!!MAINT.flag;
  const col=on?'#ff8a8a':'#8fe3a0';
  let html=`<div class="item" data-mm style="border-color:${on?'#7a2b36':'#2d324b'}"><div class="item-head"><b>Maintenance mode</b>`+
    `<span style="color:${col};border:1px solid ${col};border-radius:6px;padding:2px 10px;font-size:13px;font-weight:600" role="status">${on?'ON: visitors see the maintenance page':'OFF: the site is live'}</span></div>`+
    `<p class="lead" style="margin:4px 0">While it is on, every visitor and search engine gets the maintenance page with HTTP status 503 ("temporarily unavailable, retry later"), which keeps your search rankings. The admin, the API, fonts and images keep working, and <b>you</b> still see the real site in this browser (a bypass cookie is set when you log in).</p>`;
  if(!!st.on!==on) html+=`<p class="lead" style="color:#e0b867">The saved setting says ${st.on?'ON':'OFF'}, but the server flag file says ${on?'ON':'OFF'}. The flag file is what visitors get. Switch again to bring them in line.</p>`;
  if(!MAINT.writable) html+=`<p class="lead" style="color:#ff8a8a">The web root folder is not writable by PHP, so the switch cannot work. Fix the folder permissions in cPanel (public_html should be 755).</p>`;
  if(!MAINT.page) html+=`<p class="lead" style="color:#e0b867">maintenance.html is not on the server yet (it comes with the next website upload). Until then a plain page is created when you switch on.</p>`;
  html+=`<label for="mm_msg">Message (optional; plain text, max 500 characters)</label><textarea id="mm_msg" maxlength="500" placeholder="The site is undergoing maintenance — back soon.">${escA(st.message)}</textarea>`+
    `<label for="mm_until">Expected back (optional)</label><input id="mm_until" maxlength="80" value="${escA(st.until)}" placeholder="e.g. 22 Nov 2026, 6 pm CST">`+
    `<div style="display:flex;gap:10px;margin-top:12px;flex-wrap:wrap">`+
    (on ? `<button class="primary" onclick="mmSend(false)">Turn maintenance mode OFF</button><button onclick="mmSend(true,true)">Update message</button>`
        : `<button class="del" onclick="mmSend(true)">Turn maintenance mode ON</button>`)+
    `</div>`+
    `<small>Uploading a new website build replaces maintenance.html with the default text; if maintenance mode is on at that time, click <b>Update message</b> again.</small>`+
    (st.changed?`<small>Last changed: ${escA(st.changed)}</small>`:'')+
    `</div>`;
  return html;
}
window.mmSend=async(on,update)=>{
  const message=v('mm_msg'), until=v('mm_until');
  if(!update){
    const q = on ? 'Turn maintenance mode ON?\n\nVisitors and search engines will see the maintenance page (HTTP 503) until you turn it off. You will still see the real site in this browser.'
                 : 'Turn maintenance mode OFF and make the site live again?';
    if(!window.confirm(q)) return;
  }
  try{
    const r=await fetch('index.php?action=maintenance',{method:'POST',headers:{'Content-Type':'application/json'},credentials:'same-origin',body:JSON.stringify({csrf:CSRF,on:!!on,message,until})});
    const j=await r.json();
    if(typeof j.flag==='boolean') MAINT.flag=j.flag;
    if(!j.ok){ toast('Error: '+(j.error||'failed')); renderMaintenance(); return; }
    DATA.settings=Object.assign({}, (DATA.settings && typeof DATA.settings==='object' && !Array.isArray(DATA.settings))?DATA.settings:{}, {maintenance:j.state});
    MAINT.page=true;
    toast(update?'Message updated ✓':(j.flag?'Maintenance mode is ON':'Maintenance mode is OFF: the site is live'));
    renderMaintenance();
  }catch(e){ toast('Network error'); }
};
function mApplied(){ const a=DATA.settings && DATA.settings.appliedMigrations; return Array.isArray(a) ? a.filter(x=>x && x.id) : []; }
function mIsApplied(m){ const id=m && m.data && m.data.id; return !!id && mApplied().some(x=>x.id===id); }
// A newer update that is already applied, if any. "Newer" = later "created" date, or the same
// date and a later file in the list (files are listed in name order: 2026-11-redesign, 2026-11-round2).
function mNewerApplied(m){
  const migs=Array.isArray(MIGRATIONS)?MIGRATIONS:[];
  const key=o=>String((o&&o.data&&o.data.created)||'')+'|'+String(migs.indexOf(o)).padStart(4,'0');
  return migs.find(o=>o!==m && mIsApplied(o) && key(o)>key(m) && (o.data.id||'')!==((m.data&&m.data.id)||'')) || null;
}
let mDefaulted=false;
function renderMaintenance(){
  const migs=Array.isArray(MIGRATIONS)?MIGRATIONS:[];
  if(!mDefaulted){ mDefaulted=true; const i=migs.findIndex(m=>!mIsApplied(m)); if(i>=0) maint.mig=i; }
  const opts=migs.map((m,i)=>`<option value="${i}" ${i===maint.mig?'selected':''}>${escA((m.data&&m.data.title)||m.file)}${mIsApplied(m)?' (already applied)':''}</option>`).join('');
  const selM=migs[maint.mig];
  const appliedNote = selM && mIsApplied(selM)
    ? `<p class="lead" style="margin:4px 0;color:#e0b867">This update was already applied${(mApplied().find(x=>x.id===selM.data.id)||{}).at?' on '+escA(mApplied().find(x=>x.id===selM.data.id).at):''}. Applying it again is usually not needed.</p>` : '';
  const newerNote = selM && mNewerApplied(selM)
    ? `<p class="lead" style="margin:4px 0;color:#ff8a8a">A newer update ("${escA(mNewerApplied(selM).data.title||mNewerApplied(selM).file)}") is already applied. Applying this older one can undo parts of it; you will be asked to confirm.</p>` : '';
  const notes=(migs[maint.mig] && Array.isArray(migs[maint.mig].data.notes)) ? migs[maint.mig].data.notes.map(n=>`<li>${escA(n)}</li>`).join('') : '';
  app().innerHTML=`<h2>Maintenance Mode &amp; Backup</h2><p class="lead">Take the site offline for maintenance, download a copy of everything in the database, restore a copy, or apply a content update that came with a new version of the website.</p>`+
    mmHtml()+GEN_NOTE+
    `<div class="item"><div class="item-head"><b>1. Full content backup</b></div>`+
    `<p class="lead" style="margin:4px 0">Downloads every section (publications, hero, headings, layout, blog…) as one JSON file. Keep it somewhere safe.</p>`+
    `<button onclick="mBackupNow()">Download full content backup (JSON)</button></div>`+
    `<div class="item"><div class="item-head"><b>2. Content update</b></div>`+
    (migs.length
      ? `<label>Update</label><select id="mt_mig" onchange="maint.mig=parseInt(this.value,10)||0;maint.plan=null;renderMaintenance()" style="width:100%;padding:8px;background:#0c0d16;border:1px solid #2d324b;border-radius:6px;color:#fff">${opts}</select>`+
        (notes?`<ul style="color:#a2a5b9;font-size:13px;padding-left:18px">${notes}</ul>`:'')+appliedNote+newerNote+
        `<p class="lead" style="margin:4px 0">Nothing is written until you confirm. Before writing, a full backup downloads automatically (if your browser blocks that download, use button 1 above first).</p>`+
        `<button onclick="mPreview()" ${maint.busy?'disabled':''}>Preview changes</button>`
      : `<p class="lead">No content updates are available.</p>`)+
    `</div>`+
    `<div id="mt_plan">${mPlanHtml()}</div>`+
    `<div id="mt_log">${maint.log.length?`<div class="item"><div class="item-head"><b>Result</b></div>${maint.log.map(l=>`<small style="color:${l.ok?'#8fe3a0':'#ff8a8a'}">${escA(l.text)}</small>`).join('')}<div style="margin-top:10px"><button onclick="location.reload()">Reload the admin</button></div></div>`:''}</div>`+
    `<div class="item"><div class="item-head"><b>3. Restore from a backup file</b></div>`+
    `<p class="lead" style="margin:4px 0">Choose a backup downloaded from this page. You can pick which sections to restore. A backup of the current content downloads first.</p>`+
    `<input type="file" accept="application/json,.json" onchange="mPickRestore(this)" style="padding:6px">`+
    `<div id="mt_restore">${mRestoreHtml()}</div></div>`;
}
window.mBackupNow=async()=>{
  try{ const snap=await mFetchSnapshot(); mDownload(snap,'content-backup-'+mStamp()+'.json'); toast('Backup downloaded'); }
  catch(e){ toast('Backup failed: '+e.message); }
};
window.mToggle=(gi,ci,on)=>{ if(maint.plan && maint.plan[gi] && maint.plan[gi].changes[ci]) maint.plan[gi].changes[ci].checked=!!on; document.getElementById('mt_plan').innerHTML=mPlanHtml(); };
window.mPreview=async()=>{
  const mig=MIGRATIONS[maint.mig]; if(!mig) return;
  maint.log=[]; toast('Reading current content…');
  try{
    const snap=await mFetchSnapshot();
    maint.snapshot=snap; maint.plan=mBuildPlan(mig.data,snap.sections);
    renderMaintenance();
  }catch(e){ toast('Could not read the current content: '+e.message); }
};
async function mWriteSections(state,sections,label){
  const log=[];
  for(let i=0;i<sections.length;i++){
    const s=sections[i];
    const r=await mSave(s,state[s]);
    log.push({ok:r.ok,text:(r.ok?'Saved ':'FAILED ')+s+(r.ok?'':(': '+r.error))});
    if(r.ok) DATA[s]=mClone(state[s]);
  }
  const bad=log.filter(l=>!l.ok).length;
  log.unshift({ok:!bad,text:label+': '+(sections.length-bad)+' of '+sections.length+' section(s) saved'+(bad?'. Some sections failed; nothing else was changed. You can restore the downloaded backup below.':'. Changes are live within about a minute.')});
  return log;
}
window.mApply=async()=>{
  if(maint.busy || !maint.plan || !maint.snapshot) return;
  const mig=MIGRATIONS[maint.mig];
  // Re-read the database: if anything changed since the preview, ask for a fresh preview.
  let fresh;
  try{ fresh=await mFetchSnapshot(); }catch(e){ toast('Could not read the current content: '+e.message); return; }
  if(!mEq(fresh.sections,maint.snapshot.sections)){ toast('Content changed since the preview. Showing a fresh preview.'); maint.snapshot=fresh; maint.plan=mBuildPlan(mig.data,fresh.sections); renderMaintenance(); return; }
  const res=mResult(maint.plan,fresh.sections);
  if(!res.changed.length){ toast('Nothing selected to change'); return; }
  const newer=mNewerApplied(mig);
  if(newer && !window.confirm('The newer update "'+(newer.data.title||newer.file)+'" is already applied.\n\nApplying this OLDER update can undo parts of it. Continue anyway?')) return;
  if(!window.confirm('Apply the selected changes to: '+res.changed.join(', ')+'?\n\nA full backup will download first. The website shows the new content within about a minute.')) return;
  maint.busy=true;
  mDownload(fresh,'content-backup-'+mStamp()+'-before-'+(mig.data.id||'update')+'.json');
  // Record the update as applied (in settings, saved with the other sections).
  if(mig.data.id){
    const s0=mObj(res.state.settings) ? res.state.settings : {};
    const list=(Array.isArray(s0.appliedMigrations)?s0.appliedMigrations:[]).filter(x=>x && x.id!==mig.data.id);
    list.push({id:mig.data.id, created:mig.data.created||'', at:new Date().toISOString().slice(0,10)});
    res.state.settings=Object.assign({}, s0, {appliedMigrations:list});
    if(res.changed.indexOf('settings')<0) res.changed.push('settings');
  }
  maint.log=await mWriteSections(res.state,res.changed,'Update "'+(mig.data.title||mig.file)+'"');
  maint.busy=false; maint.plan=null; maint.snapshot=null;
  if(res.changed.indexOf('sectionsHidden')>=0 && Array.isArray(DATA.sectionsHidden)) hiddenSet=new Set(DATA.sectionsHidden.filter(knownSection));
  renderMaintenance();
};

// ---- restore
function mRestoreHtml(){
  const r=maint.restore; if(!r) return '';
  const keys=Object.keys(r.sections);
  return `<p class="lead" style="margin:8px 0">Backup from ${escA(r.when||'unknown date')}: ${keys.length} section(s).</p>`+
    keys.map(k=>`<label style="display:flex;gap:8px;align-items:center;text-transform:none;font-size:13px;color:#e8e8ef;margin:4px 0"><input type="checkbox" style="width:auto" data-rs="${escA(k)}" checked> ${escA(k)}</label>`).join('')+
    `<div class="bar"><button class="primary" onclick="mRestore()" ${maint.busy?'disabled':''}>Download current backup, then restore the ticked sections</button></div>`;
}
window.mPickRestore=(fi)=>{
  const f=fi.files[0]; if(!f) return;
  const rd=new FileReader();
  rd.onload=()=>{
    try{
      const j=JSON.parse(String(rd.result));
      const sections = (j && j.format==='palashroy-content-backup' && mObj(j.sections)) ? j.sections : (mObj(j) ? j : null);
      if(!sections) throw new Error('not a backup file');
      if('customSections' in sections) sections.customSections=validCustom(sections.customSections);
      maint.restore={sections, when:j.exported_at||''};
    }catch(e){ maint.restore=null; toast('Could not read that file: '+e.message); }
    document.getElementById('mt_restore').innerHTML=mRestoreHtml();
  };
  rd.readAsText(f);
};
window.mRestore=async()=>{
  const r=maint.restore; if(!r || maint.busy) return;
  const pick=[...document.querySelectorAll('#mt_restore [data-rs]')].filter(x=>x.checked).map(x=>x.dataset.rs);
  if(!pick.length){ toast('Tick at least one section'); return; }
  if(!window.confirm('Replace these sections with the backup copy?\n\n'+pick.join(', ')+'\n\nA backup of the current content downloads first.')) return;
  let fresh;
  try{ fresh=await mFetchSnapshot(); }catch(e){ toast('Could not read the current content: '+e.message); return; }
  maint.busy=true;
  mDownload(fresh,'content-backup-'+mStamp()+'-before-restore.json');
  maint.log=await mWriteSections(r.sections,pick,'Restore');
  maint.busy=false; maint.restore=null;
  renderMaintenance();
};

renderNav(); switchTo('hero');
</script></body></html>
