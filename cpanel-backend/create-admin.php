<?php
/**
 * ONE-TIME: creates your admin login, then DELETE this file from the server.
 * 1. Edit the two lines below (username + a STRONG password).
 * 2. Visit https://palashroy.me/create-admin.php once.
 * 3. DELETE this file from cPanel immediately after.
 */
require __DIR__ . '/api/db.php';

$USERNAME = 'palash';
$PASSWORD = 'CHANGE_ME_TO_A_STRONG_PASSWORD';

if ($PASSWORD === 'CHANGE_ME_TO_A_STRONG_PASSWORD') {
    exit('Edit create-admin.php first: set a strong password, then reload.');
}
$hash = password_hash($PASSWORD, PASSWORD_DEFAULT);
db()->prepare(
    "INSERT INTO admin_users (username, password_hash) VALUES (?, ?)
     ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash)"
)->execute([$USERNAME, $hash]);

echo "Admin user '" . htmlspecialchars($USERNAME) . "' is ready. ";
echo "NOW DELETE create-admin.php from the server (File Manager → delete).";
