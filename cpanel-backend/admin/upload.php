<?php
/**
 * Secure file upload for the content admin.
 * Kinds: image (JPG/PNG/WebP/GIF), pdf/resume (PDF: CV, statements, papers),
 *        slides (PDF or PPTX: presentation slides), video (MP4 or WebM: presentation videos).
 * Login + CSRF required. Each kind has an extension + MIME allowlist (the file's extension AND its
 * sniffed content must both match), a size limit, and a server-chosen filename (an optional
 * descriptive part is reduced to [a-z0-9-] and always followed by a random suffix; the extension
 * comes from the allowlist, never from the uploaded name). Files go to /uploads, where an
 * .htaccess written here disables script execution.
 */
declare(strict_types=1);
require __DIR__ . '/../api/db.php';

session_set_cookie_params([
    'lifetime' => 0, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Strict',
]);
session_start();
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

function out($d): void { echo json_encode($d); exit; }
function fail(int $code, string $msg): void { http_response_code($code); out(['error' => $msg]); }

if (empty($_SESSION['admin'])) fail(401, 'Not logged in');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') fail(405, 'POST only');
// A request larger than post_max_size arrives with empty $_POST and $_FILES (so the CSRF
// check below would fail with a misleading message): say what really happened.
if (empty($_POST) && empty($_FILES) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    fail(413, 'File is larger than this server allows (post_max_size = ' . ini_get('post_max_size') . '). Use a smaller file, or raise upload_max_filesize and post_max_size in cPanel > MultiPHP INI Editor.');
}
if (!hash_equals($_SESSION['csrf'] ?? '', is_string($_POST['csrf'] ?? null) ? $_POST['csrf'] : '')) fail(403, 'Bad CSRF token — reload the page.');
$upErr = isset($_FILES['file']['error']) && !is_array($_FILES['file']['error']) ? (int)$_FILES['file']['error'] : UPLOAD_ERR_NO_FILE;
if ($upErr === UPLOAD_ERR_INI_SIZE || $upErr === UPLOAD_ERR_FORM_SIZE) {
    fail(413, 'File too large for this server (upload_max_filesize = ' . ini_get('upload_max_filesize') . '). Use a smaller file, or raise upload_max_filesize and post_max_size in cPanel > MultiPHP INI Editor.');
}
if ($upErr === UPLOAD_ERR_PARTIAL) fail(400, 'The upload was interrupted. Please try again.');
if (empty($_FILES['file']) || $upErr !== UPLOAD_ERR_OK || !is_uploaded_file((string)$_FILES['file']['tmp_name'])) fail(400, 'No file received or upload error');

// ---- allowlists: extension => accepted sniffed MIME types, plus a size limit per kind ----
const PPTX_MIME = 'application/vnd.openxmlformats-officedocument.presentationml.presentation';
$KINDS = [
    'image'  => ['max' => 8,   'prefix' => 'img-',    'label' => 'an image (JPG, PNG, WebP or GIF)',
                 'types' => ['jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'], 'png' => ['image/png'], 'webp' => ['image/webp'], 'gif' => ['image/gif']]],
    'pdf'    => ['max' => 20,  'prefix' => 'doc-',    'label' => 'a PDF',
                 'types' => ['pdf' => ['application/pdf']]],
    'slides' => ['max' => 50,  'prefix' => 'slides-', 'label' => 'slides as PDF or PPTX',
                 // libmagic reports PPTX as the OOXML type, or as a plain ZIP on older systems (checked below).
                 'types' => ['pdf' => ['application/pdf'], 'pptx' => [PPTX_MIME, 'application/zip', 'application/octet-stream']]],
    'video'  => ['max' => 200, 'prefix' => 'video-',  'label' => 'a video as MP4 or WebM',
                 'types' => ['mp4' => ['video/mp4', 'application/mp4'], 'webm' => ['video/webm', 'audio/webm']]],
];
$kindRaw = is_string($_POST['kind'] ?? null) ? $_POST['kind'] : 'image';
$kind = $kindRaw === 'resume' ? 'pdf' : $kindRaw;           // the CV upload is a PDF
if (!isset($KINDS[$kind])) fail(400, 'Unknown upload type');
$K = $KINDS[$kind];
$f = $_FILES['file'];

// Extension of the uploaded name (only used to pick an allowlisted extension; never trusted on its own).
$origExt = strtolower((string)pathinfo((string)($f['name'] ?? ''), PATHINFO_EXTENSION));
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime  = (string)$finfo->file($f['tmp_name']);

if ($kind === 'image') {
    // Images: decide by content (cameras and phones name files inconsistently).
    $ext = null;
    foreach ($K['types'] as $e => $mimes) { if (in_array($mime, $mimes, true)) { $ext = $e === 'jpeg' ? 'jpg' : $e; break; } }
    if ($ext === null || @getimagesize($f['tmp_name']) === false) fail(400, 'Unsupported image type (use JPG, PNG, WebP or GIF)');
} else {
    if (!isset($K['types'][$origExt])) fail(400, 'File must be ' . $K['label'] . ' (allowed: .' . implode(', .', array_keys($K['types'])) . ')');
    if (!in_array($mime, $K['types'][$origExt], true)) fail(400, 'The file content does not match its .' . $origExt . ' extension (detected ' . $mime . ')');
    $ext = $origExt;
    $head = (string)file_get_contents($f['tmp_name'], false, null, 0, 64);
    if ($ext === 'pdf' && strncmp($head, '%PDF-', 5) !== 0) fail(400, 'Not a valid PDF file');
    if ($ext === 'pptx' && !is_pptx($f['tmp_name'], $head)) fail(400, 'Not a valid PowerPoint (.pptx) file');
    if ($ext === 'mp4' && substr($head, 4, 4) !== 'ftyp') fail(400, 'Not a valid MP4 file');
    if ($ext === 'webm' && strncmp($head, "\x1A\x45\xDF\xA3", 4) !== 0) fail(400, 'Not a valid WebM file');
}
if ((int)$f['size'] > $K['max'] * 1024 * 1024) fail(400, ucfirst($K['label']) . ' too large (max ' . $K['max'] . ' MB)');

// A PPTX is a ZIP whose parts include [Content_Types].xml and ppt/presentation.xml.
function is_pptx(string $path, string $head): bool
{
    if (strncmp($head, "PK\x03\x04", 4) !== 0) return false;
    if (class_exists('ZipArchive')) {
        $z = new ZipArchive();
        if ($z->open($path) !== true) return false;
        $ok = $z->locateName('[Content_Types].xml') !== false && $z->locateName('ppt/presentation.xml') !== false;
        // No macro-enabled content (that would be .pptm) and no embedded executables.
        for ($i = 0; $ok && $i < $z->numFiles; $i++) {
            $n = strtolower((string)$z->getNameIndex($i));
            if (preg_match('/vbaproject\.bin$|\.(exe|dll|bat|cmd|js|vbs|ps1|sh|php\d?|phtml|phar)$/', $n)) $ok = false;
        }
        $z->close();
        return $ok;
    }
    // Without ZipArchive: look for the part names in the ZIP central directory at the end of the file.
    $size = (int)filesize($path);
    $tail = (string)file_get_contents($path, false, null, max(0, $size - 262144));
    return strpos($tail, '[Content_Types].xml') !== false && strpos($tail, 'ppt/presentation.xml') !== false
        && stripos($tail, 'vbaProject.bin') === false;
}

// Optional readable name, e.g. "CV" -> palash-ranjan-roy-cv-<random>.pdf (recruiters see the file name).
$prefix = $kindRaw === 'resume' ? 'resume-' : $K['prefix'];
if ($kind !== 'image' && isset($_POST['name']) && is_string($_POST['name'])) {
    $slug = strtolower((string)preg_replace('/[^A-Za-z0-9]+/', '-', $_POST['name']));
    $slug = trim(substr($slug, 0, 40), '-');
    if ($slug !== '') $prefix = 'palash-ranjan-roy-' . $slug . '-';
}

$dir = __DIR__ . '/../uploads';
if (!is_dir($dir)) { @mkdir($dir, 0755, true); }

// Defense in depth: never execute anything served from /uploads. Rewritten when an older version is found.
const UPLOADS_HTACCESS_VERSION = '# pr-uploads-htaccess v3';
$ht = $dir . '/.htaccess';
if (!is_file($ht) || strpos((string)@file_get_contents($ht), UPLOADS_HTACCESS_VERSION) === false) {
    $rules = UPLOADS_HTACCESS_VERSION . "\n"
        . "# Written by admin/upload.php. Uploaded files are served as plain downloads; nothing here may run as code.\n"
        . "<IfModule mod_php.c>\n  php_flag engine off\n</IfModule>\n"
        . "<IfModule mod_php7.c>\n  php_flag engine off\n</IfModule>\n"
        . "<IfModule mod_php5.c>\n  php_flag engine off\n</IfModule>\n"
        . "RemoveHandler .php .phtml .php3 .php4 .php5 .php7 .php8 .phps .phar .pht .cgi .pl .py .sh\n"
        . "RemoveType .php .phtml .php3 .php4 .php5 .php7 .php8 .phps .phar .pht\n"
        . "Options -Indexes -ExecCGI\n"
        . "<FilesMatch \"(?i)\\.(php[0-9]?|phtml|phar|pht|phps|cgi|pl|py|sh|shtml|html?|svg|js|htaccess)$\">\n"
        . "  <IfModule mod_authz_core.c>\n    Require all denied\n  </IfModule>\n"
        . "  <IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n  </IfModule>\n"
        . "</FilesMatch>\n"
        . "AddType application/pdf .pdf\n"
        . "AddType application/vnd.openxmlformats-officedocument.presentationml.presentation .pptx\n"
        . "AddType video/mp4 .mp4\n"
        . "AddType video/webm .webm\n"
        . "<IfModule mod_headers.c>\n  Header set X-Content-Type-Options \"nosniff\"\n"
        . "  <FilesMatch \"(?i)\\.pptx$\">\n    Header set Content-Disposition \"attachment\"\n  </FilesMatch>\n</IfModule>\n";
    @file_put_contents($ht, $rules);
}

$name = $prefix . bin2hex(random_bytes(8)) . '.' . $ext;
$dest = $dir . '/' . $name;
if (!move_uploaded_file($f['tmp_name'], $dest)) fail(500, 'Could not save the uploaded file');
@chmod($dest, 0644);

out(['ok' => true, 'url' => '/uploads/' . $name]);
