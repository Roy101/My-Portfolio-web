<?php
/**
 * ONE-TIME admin setup. Visit https://palashroy.me/create-admin.php once,
 * set your username + password in the form, submit. It then DELETES ITSELF.
 * It refuses to run if an admin already exists (so it can't be used to hijack the login).
 */
declare(strict_types=1);
require __DIR__ . '/api/db.php';

session_set_cookie_params(['secure' => true, 'httponly' => true, 'samesite' => 'Strict']);
session_start();
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));

$msg = '';
try {
    $count = (int) db()->query("SELECT COUNT(*) c FROM admin_users")->fetch()['c'];
} catch (Throwable $e) {
    exit('Database not reachable yet. Finish the phpMyAdmin import and config.php first, then reload.');
}
if ($count > 0) {
    @unlink(__FILE__);
    exit('An admin account already exists. This setup file is now disabled — delete create-admin.php if it is still on the server.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
        exit('Bad token — reload the page.');
    }
    $user = trim($_POST['username'] ?? '');
    $pass = (string) ($_POST['password'] ?? '');
    if (strlen($user) < 3 || strlen($pass) < 10) {
        $msg = 'Username must be 3+ chars and password 10+ chars.';
    } else {
        $hash = password_hash($pass, PASSWORD_DEFAULT);
        db()->prepare("INSERT INTO admin_users (username, password_hash) VALUES (?, ?)")->execute([$user, $hash]);
        @unlink(__FILE__); // remove this provisioner
        exit('Admin created. You can now log in at /admin. (This setup file has deleted itself.)');
    }
}
?><!doctype html><html lang="en"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">
<title>One-time admin setup</title>
<style>body{font-family:system-ui,sans-serif;background:#0c0d16;color:#e8e8ef;display:flex;min-height:100vh;align-items:center;justify-content:center;margin:0}
form{background:#181a22;border:1px solid #2d324b;padding:32px;border-radius:12px;width:320px}
h1{font-size:18px;margin:0 0 16px}input{width:100%;box-sizing:border-box;padding:10px;margin-bottom:12px;background:#0c0d16;border:1px solid #2d324b;border-radius:8px;color:#fff}
button{width:100%;padding:11px;background:linear-gradient(90deg,#35c7ff,#ff4081);border:0;border-radius:8px;color:#fff;font-weight:600;cursor:pointer}
.msg{color:#ff6b6b;font-size:13px;margin-bottom:12px}</style></head><body>
<form method="post">
  <h1>Create your admin login</h1>
  <?php if ($msg): ?><div class="msg"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
  <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
  <input name="username" placeholder="Username (3+ chars)" required autofocus>
  <input name="password" type="password" placeholder="Password (10+ chars)" required>
  <button type="submit">Create admin</button>
</form></body></html>
