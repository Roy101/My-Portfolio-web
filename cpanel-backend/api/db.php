<?php
// Shared database connection (PDO, prepared statements only).
function config(): array
{
    $path = __DIR__ . '/config.php';
    if (!file_exists($path)) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'config.php missing — copy config.sample.php to config.php']);
        exit;
    }
    return require $path;
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
];
