<?php
/**
 * Secure file upload for the content admin.
 * Kinds: image (JPG/PNG/WebP/GIF), pdf/resume (PDF: CV, statements, papers),
 *        slides (PDF or PPTX: presentation slides), video (MP4 or WebM: presentation videos),
 *        headshot (JPG/PNG/WebP profile photo: cropped to a square and resized with GD into WebP
 *        copies of 200/240/480/640 px and a 1200 px JPEG for sharing previews, all named after a hash
 *        of their content, so the year-long image cache never serves an old photo; without GD the
 *        original is stored as it is and the response says so).
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
    'headshot' => ['max' => 15, 'prefix' => 'headshot-', 'label' => 'a photo (JPG, PNG or WebP)',
                 'types' => ['jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'], 'png' => ['image/png'], 'webp' => ['image/webp']]],
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

if ($kind === 'image' || $kind === 'headshot') {
    // Images: decide by content (cameras and phones name files inconsistently).
    $ext = null;
    foreach ($K['types'] as $e => $mimes) { if (in_array($mime, $mimes, true)) { $ext = $e === 'jpeg' ? 'jpg' : $e; break; } }
    if ($ext === null || @getimagesize($f['tmp_name']) === false) fail(400, $kind === 'headshot' ? 'Unsupported photo type (use JPG, PNG or WebP)' : 'Unsupported image type (use JPG, PNG, WebP or GIF)');
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
// The first part is the owner's name from Identity (identity.fileSlug, else identity.name).
$prefix = $kindRaw === 'resume' ? 'resume-' : $K['prefix'];
if ($kind !== 'image' && $kind !== 'headshot' && isset($_POST['name']) && is_string($_POST['name'])) {
    $slug = strtolower((string)preg_replace('/[^A-Za-z0-9]+/', '-', $_POST['name']));
    $slug = trim(substr($slug, 0, 40), '-');
    if ($slug !== '') $prefix = owner_file_slug() . '-' . $slug . '-';
}
function owner_file_slug(): string
{
    try {
        $st = db()->prepare("SELECT data FROM content WHERE section = 'identity' LIMIT 1");
        $st->execute();
        $r = $st->fetch();
        $id = $r ? json_decode((string)$r['data'], true) : null;
        foreach (['fileSlug', 'name'] as $k) {
            $v = is_array($id) && is_string($id[$k] ?? null) ? $id[$k] : '';
            $s = trim(substr(strtolower((string)preg_replace('/[^A-Za-z0-9]+/', '-', $v)), 0, 40), '-');
            if ($s !== '') return $s;
        }
    } catch (Throwable $e) {}
    return 'palash-ranjan-roy';
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

if ($kind === 'headshot') out(headshot_store($f['tmp_name'], $ext, $dir, ($_POST['crop'] ?? 'square') !== 'none'));

$name = $prefix . bin2hex(random_bytes(8)) . '.' . $ext;
$dest = $dir . '/' . $name;
if ($kind === 'image' && ($clean = strip_image_metadata((string)file_get_contents($f['tmp_name']), $ext)) !== null) {
    // Photos are public: location (GPS), camera serial and time stamps are removed first.
    if (@file_put_contents($dest, $clean) === false) fail(500, 'Could not save the uploaded file');
} elseif (!move_uploaded_file($f['tmp_name'], $dest)) fail(500, 'Could not save the uploaded file');
@chmod($dest, 0644);

out(['ok' => true, 'url' => '/uploads/' . $name]);

/**
 * Profile photo: square crop (centred; optional), then WebP copies at 200/240/480/640 px (JPEG
 * copies when this PHP has no WebP support) and a JPEG of up to 1200 px for og:image/twitter:image.
 * Every file is named after the SHA-256 of its bytes. Returns the response for the admin, which
 * stores hero.photo (the JPEG) and hero.photoSet {jpg, w, h, type, srcset: [{w, url}]}.
 */
function headshot_store(string $tmp, string $ext, string $dir, bool $square): array
{
    $save = function (string $bytes, string $suffix) use ($dir): string {
        $n = 'headshot-' . substr(hash('sha256', $bytes), 0, 16) . $suffix;
        $p = $dir . '/' . $n;
        if (!is_file($p) && @file_put_contents($p, $bytes) === false) fail(500, 'Could not save the photo');
        @chmod($p, 0644);
        return '/uploads/' . $n;
    };
    $info = @getimagesize($tmp);
    $w0 = (int)($info[0] ?? 0); $h0 = (int)($info[1] ?? 0);
    $loaders = ['jpg' => 'imagecreatefromjpeg', 'png' => 'imagecreatefrompng', 'webp' => 'imagecreatefromwebp'];
    $gd = extension_loaded('gd') && function_exists('imagecreatetruecolor') && function_exists('imagejpeg') && function_exists($loaders[$ext] ?? '-');
    if ($gd) @ini_set('memory_limit', '512M');
    // GD holds the whole photo in memory (about 5 bytes per pixel); a host may refuse the 512M above.
    $maxPx = min(50000000, upload_pixel_budget());
    if (!$gd || $w0 < 1 || $h0 < 1 || $w0 * $h0 > $maxPx) {
        // Fallback: the original file (one size), without its location / camera metadata.
        $raw = (string)file_get_contents($tmp);
        $url = $save(strip_image_metadata($raw, $ext) ?? $raw, '.' . $ext);
        $why = !$gd ? 'This server cannot resize images (the PHP GD extension' . (extension_loaded('gd') ? ' has no ' . strtoupper($ext) . ' support' : ' is not installed') . '), so the photo was stored as it is, in one size. It works, but a large file loads slowly; upload a photo of about 640 × 640 px, or ask your host to enable GD (cPanel > Select PHP Version > Extensions > gd).'
            : 'The photo is too large to resize on this server (' . $w0 . ' × ' . $h0 . ' px, PHP memory_limit ' . ini_get('memory_limit') . '), so it was stored as it is. Upload a smaller photo (about 1200 × 1200 px) for faster pages.';
        return ['ok' => true, 'url' => $url, 'photoSet' => null, 'resized' => false, 'warning' => $why, 'width' => $w0, 'height' => $h0];
    }
    $src = @$loaders[$ext]($tmp);
    if (!$src) fail(400, 'The photo could not be read');
    // Phone photos: the EXIF orientation says how to turn the picture upright. It is applied to each
    // small copy after scaling (turning the full-size photo would need a second full copy in memory).
    $o = $ext === 'jpg' ? jpeg_orientation((string)file_get_contents($tmp, false, null, 0, 262144)) : 1;
    $rot = [3 => 180, 6 => -90, 8 => 90][$o] ?? 0;
    $swap = $rot === 90 || $rot === -90;
    $sw0 = imagesx($src); $sh0 = imagesy($src);
    // Crop in the stored (unturned) picture: a centred square is the same region either way.
    $cx = 0; $cy = 0; $cw0 = $sw0; $ch0 = $sh0;
    if ($square) { $cw0 = $ch0 = min($sw0, $sh0); $cx = intdiv($sw0 - $cw0, 2); $cy = intdiv($sh0 - $ch0, 2); }
    $cw = $swap ? $ch0 : $cw0; $ch = $swap ? $cw0 : $ch0; // the crop as shown (upright)
    $resize = function (int $tw) use ($src, $cx, $cy, $cw0, $ch0, $cw, $ch, $rot, $swap) {
        $th = max(1, (int)round($ch * $tw / $cw));
        [$pw, $ph] = $swap ? [$th, $tw] : [$tw, $th];
        $im = imagecreatetruecolor($pw, $ph);
        imagealphablending($im, false); imagesavealpha($im, true);
        $white = imagecolorallocate($im, 255, 255, 255);
        imagefilledrectangle($im, 0, 0, $pw, $ph, $white);
        imagealphablending($im, true);
        imagecopyresampled($im, $src, 0, 0, $cx, $cy, $pw, $ph, $cw0, $ch0);
        if ($rot) { $r = imagerotate($im, $rot, 0); if ($r) { imagedestroy($im); $im = $r; } }
        return [$im, $th];
    };
    $enc = function ($im, string $type): string { ob_start(); $type === 'webp' ? imagewebp($im, null, 82) : imagejpeg($im, null, 85); return (string)ob_get_clean(); };
    $jw = min(1200, $cw);
    [$im, $jh] = $resize($jw);
    if (function_exists('imageinterlace')) imageinterlace($im, true);
    $jpg = $save($enc($im, 'jpg'), '.jpg');
    imagedestroy($im);
    $webp = function_exists('imagewebp');
    $set = [];
    foreach ([200, 240, 480, 640] as $w) {
        if ($w > $cw && $set) break;
        $tw = min($w, $cw);
        [$im] = $resize($tw);
        $set[] = ['w' => $tw, 'url' => $save($enc($im, $webp ? 'webp' : 'jpg'), '-' . $tw . 'w.' . ($webp ? 'webp' : 'jpg'))];
        imagedestroy($im);
        if ($tw < $w) break;
    }
    imagedestroy($src);
    $warn = $webp ? '' : 'This server has GD without WebP support, so the smaller copies are JPEG files (slightly larger downloads).';
    if ($cw < 400) $warn = trim($warn . ' The photo is small (' . $cw . ' px wide); a photo of at least 640 × 640 px looks sharper on high-resolution screens.');
    return ['ok' => true, 'url' => $jpg, 'resized' => true, 'warning' => $warn,
        'photoSet' => ['jpg' => $jpg, 'w' => $jw, 'h' => $jh, 'type' => $webp ? 'image/webp' : 'image/jpeg', 'srcset' => $set]];
}

/** Pixels GD may decode within PHP's memory_limit (about 5 bytes per pixel plus the small copies). */
function upload_pixel_budget(): int
{
    $v = trim((string)ini_get('memory_limit'));
    if ($v === '' || $v === '-1') return PHP_INT_MAX;
    $n = (float)$v;
    $u = strtolower(substr($v, -1));
    $bytes = (int)($n * ($u === 'g' ? 1073741824 : ($u === 'm' ? 1048576 : ($u === 'k' ? 1024 : 1))));
    $free = $bytes - memory_get_usage(true) - 24 * 1048576;
    return $free > 0 ? (int)($free / 5.5) : 0;
}

/** EXIF orientation (1-8) of a JPEG, read from its APP1 segment (no exif extension needed). */
function jpeg_orientation(string $b): int
{
    $n = strlen($b);
    if ($n < 4 || substr($b, 0, 2) !== "\xFF\xD8") return 1;
    for ($i = 2; $i + 4 <= $n;) {
        if ($b[$i] !== "\xFF") return 1;
        $m = ord($b[$i + 1]);
        if ($m === 0xDA || $m === 0xD9) return 1;
        $len = unpack('n', $b, $i + 2)[1];
        if ($m === 0xE1 && substr($b, $i + 4, 6) === "Exif\0\0") {
            $t = $i + 10;
            if ($t + 8 > $n) return 1;
            $le = substr($b, $t, 2) === 'II';
            $r16 = fn($o) => $o + 2 <= $n ? unpack($le ? 'v' : 'n', $b, $o)[1] : 0;
            $r32 = fn($o) => $o + 4 <= $n ? unpack($le ? 'V' : 'N', $b, $o)[1] : 0;
            $ifd = $t + $r32($t + 4);
            for ($k = 0, $cnt = $r16($ifd); $k < $cnt && $k < 500; $k++) {
                $e = $ifd + 2 + $k * 12;
                if ($r16($e) === 0x0112) { $o = $r16($e + 8); return $o >= 1 && $o <= 8 ? $o : 1; }
            }
            return 1;
        }
        $i += 2 + $len;
    }
    return 1;
}

/**
 * The image without metadata that can identify a person or place: JPEG APP1 (EXIF incl. GPS, XMP),
 * APP13 (IPTC) and comments; PNG eXIf/tEXt/zTXt/iTXt/tIME; WebP EXIF/XMP. A JPEG keeps its colour
 * profile and, as a minimal EXIF block, only its orientation (so phone photos stay upright).
 * Pure PHP (works without GD). Returns null when the file cannot be parsed (then it is kept as is).
 */
function strip_image_metadata(string $b, string $ext): ?string
{
    $n = strlen($b);
    if ($ext === 'jpg') {
        if ($n < 4 || substr($b, 0, 2) !== "\xFF\xD8") return null;
        $o = jpeg_orientation($b);
        $out = "\xFF\xD8";
        if ($o !== 1) { // TIFF header + one IFD entry (0x0112 Orientation, SHORT, 1)
            $tiff = "MM\x00\x2A\x00\x00\x00\x08\x00\x01\x01\x12\x00\x03\x00\x00\x00\x01" . pack('n', $o) . "\x00\x00\x00\x00\x00\x00";
            $exif = "Exif\0\0" . $tiff;
            $app1 = "\xFF\xE1" . pack('n', strlen($exif) + 2) . $exif;
        } else $app1 = '';
        // The orientation block goes first (after a JFIF APP0 when there is one), where readers look for it.
        $placed = !($n > 3 && $b[2] === "\xFF" && ord($b[3]) === 0xE0);
        if ($placed) $out .= $app1;
        for ($i = 2; $i + 4 <= $n;) {
            if ($b[$i] !== "\xFF") return null;
            $m = ord($b[$i + 1]);
            if ($m === 0xFF) { $i++; continue; }
            if ($m === 0xDA) { if (!$placed) $out .= $app1; return $out . substr($b, $i); } // image data to the end
            if ($m === 0xD9) return null;
            $len = unpack('n', $b, $i + 2)[1];
            if ($len < 2 || $i + 2 + $len > $n) return null;
            $seg = substr($b, $i, 2 + $len);
            $drop = $m === 0xE1 || $m === 0xED || $m === 0xFE || ($m >= 0xE3 && $m <= 0xEC) || $m === 0xEF; // EXIF/XMP, IPTC, comments, other APPn
            if (!$drop) {
                $out .= $seg;
                if ($m === 0xE0 && !$placed) { $out .= $app1; $placed = true; } // after JFIF
            }
            $i += 2 + $len;
        }
        return null;
    }
    if ($ext === 'png') {
        if ($n < 8 || substr($b, 0, 8) !== "\x89PNG\r\n\x1A\n") return null;
        $out = substr($b, 0, 8);
        for ($i = 8; $i + 12 <= $n;) {
            $len = unpack('N', $b, $i)[1];
            if ($i + 12 + $len > $n) return null;
            $type = substr($b, $i + 4, 4);
            if (!in_array($type, ['eXIf', 'tEXt', 'zTXt', 'iTXt', 'tIME'], true)) $out .= substr($b, $i, 12 + $len);
            $i += 12 + $len;
            if ($type === 'IEND') return $out;
        }
        return null;
    }
    if ($ext === 'webp') {
        if ($n < 12 || substr($b, 0, 4) !== 'RIFF' || substr($b, 8, 4) !== 'WEBP') return null;
        $body = 'WEBP';
        for ($i = 12; $i + 8 <= $n;) {
            $type = substr($b, $i, 4);
            $len = unpack('V', $b, $i + 4)[1];
            $full = 8 + $len + ($len & 1);
            if ($i + 8 + $len > $n) return null;
            $chunk = substr($b, $i, min($full, $n - $i));
            if ($type === 'VP8X' && $len >= 10) $chunk[8] = chr(ord($chunk[8]) & ~0x0C); // EXIF and XMP flags off
            if ($type !== 'EXIF' && $type !== 'XMP ') $body .= $chunk;
            $i += $full;
        }
        return 'RIFF' . pack('V', strlen($body)) . $body;
    }
    return null;
}
