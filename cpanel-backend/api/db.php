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

// The only content sections that may be read/written.
const ALLOWED_SECTIONS = [
    'publications', 'highlights', 'news', 'media',
    'gallery', 'leadership', 'service', 'references', 'metrics',
    'sectionOrder', 'hero', 'about',
];
