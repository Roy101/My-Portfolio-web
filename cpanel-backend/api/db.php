<?php
// Shared database connection (PDO, prepared statements only).
function config(): array
{
    // Preferred (most secure): config kept ABOVE the web root, e.g. one level above public_html.
    // Fallback: config.php inside api/ (protected by api/.htaccess).
    $candidates = [
        __DIR__ . '/../../portfolio-config.php', // above public_html — not web-accessible
        __DIR__ . '/config.php',
    ];
    foreach ($candidates as $path) {
        if (file_exists($path)) return require $path;
    }
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'config missing — create config.php (see SETUP.md)']);
    exit;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    $c = config();
    $dsn = "mysql:host={$c['db_host']};dbname={$c['db_name']};charset=utf8mb4";
    $pdo = new PDO($dsn, $c['db_user'], $c['db_pass'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    return $pdo;
}

/**
 * Last-resort cache folder in the system temp dir (used only when api/cache cannot be written).
 * Its name is predictable, so on a shared host without a private /tmp another account could create
 * it first: it is used only when this PHP user owns it and nobody else can read or write it.
 * Returns null otherwise (the caller then works without a cache).
 */
function private_tmp_dir(string $prefix, string $seed): ?string
{
    $t = rtrim(sys_get_temp_dir(), '/') . '/' . $prefix . substr(hash('sha256', $seed), 0, 12);
    if (!is_dir($t)) { $old = umask(077); @mkdir($t, 0700, true); umask($old); }
    clearstatcache(true, $t);
    if (!is_dir($t) || is_link($t)) return null;
    $uid = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
    if (@fileowner($t) !== $uid) return null;
    if ((@fileperms($t) & 0077) !== 0) return null;
    return $t;
}

/**
 * Do-not-publish guard (config.php 'seo_block_title_sha256'): sha256 hashes of normalized titles
 * (lower case, only a-z0-9) of papers that must never be published, e.g. work under review. Only
 * the hashes are stored. Used by the admin (refuses the save), api/content.php (never sends such a
 * paper) and the live pages (api/seo/lib/live.php).
 */
function title_on_block_list($title, array $cfg): bool
{
    $list = $cfg['seo_block_title_sha256'] ?? [];
    if (!is_array($list) || !$list) return false;
    $t = is_string($title) ? $title : '';
    $n = preg_replace('/[^a-z0-9]/', '', function_exists('mb_strtolower') ? mb_strtolower($t, 'UTF-8') : strtolower($t)) ?? '';
    return $n !== '' && in_array(hash('sha256', $n), array_map(fn($h) => strtolower((string)$h), $list), true);
}

// The only content sections that may be read/written.
const ALLOWED_SECTIONS = [
    'publications', 'highlights', 'news', 'media',
    'gallery', 'leadership', 'service', 'references', 'metrics',
    'sectionOrder', 'sectionsHidden', 'customSections', 'hero', 'about', 'settings', 'headings', 'research', 'socials', 'footer', 'consultation', 'blog', 'faq',
    // 2026-11 round 2
    'documents', 'skills', 'software',
    // 2026-11 round C: owner identity (name, ORCID, email, organisations, scholarly IDs)
    'identity',
];
