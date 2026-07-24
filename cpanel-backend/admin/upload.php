<?php
/**
 * Secure file upload for the content admin (images + resume PDF).
 * Login + CSRF required. MIME-sniffed, size-limited, random filenames,
 * written to /uploads with script execution disabled in that folder.
 */
declare(strict_types=1);
require __DIR__ . '/../api/db.php';

session_set_cookie_params([
    'lifetime' => 0, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Strict',
]);
session_start();
header('Content-Type: application/json');

function out($d): void { echo json_encode($d); exit; }

if (empty($_SESSION['admin'])) { http_response_code(401); out(['error' => 'Not logged in']); }
if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) { http_response_code(403); out(['error' => 'Bad CSRF token — reload the page.']); }
if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) { http_response_code(400); out(['error' => 'No file received or upload error']); }

$kind = ($_POST['kind'] ?? 'image') === 'resume' ? 'resume' : 'image';
$f    = $_FILES['file'];

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime  = $finfo->file($f['tmp_name']);
$imgTypes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];

if ($kind === 'resume') {
    if ($mime !== 'application/pdf') { http_response_code(400); out(['error' => 'Resume must be a PDF file']); }
    if ($f['size'] > 15 * 1024 * 1024) { http_response_code(400); out(['error' => 'PDF too large (max 15 MB)']); }
    $ext = 'pdf';
} else {
    if (!isset($imgTypes[$mime])) { http_response_code(400); out(['error' => 'Unsupported image type (use JPG, PNG, WebP or GIF)']); }
    if ($f['size'] > 8 * 1024 * 1024) { http_response_code(400); out(['error' => 'Image too large (max 8 MB)']); }
    $ext = $imgTypes[$mime];
}

$dir = __DIR__ . '/../uploads';
if (!is_dir($dir)) { @mkdir($dir, 0755, true); }

// Defense in depth: never execute anything served from /uploads.
$ht = $dir . '/.htaccess';
if (!file_exists($ht)) {
    @file_put_contents($ht, "RemoveHandler .php .phtml .php3 .php4 .php5 .php6 .php7 .phps\nRemoveType .php .phtml\n");
}

$name = ($kind === 'resume' ? 'resume-' : 'img-') . bin2hex(random_bytes(8)) . '.' . $ext;
$dest = $dir . '/' . $name;
if (!move_uploaded_file($f['tmp_name'], $dest)) { http_response_code(500); out(['error' => 'Could not save the uploaded file']); }
@chmod($dest, 0644);

out(['ok' => true, 'url' => '/uploads/' . $name]);
