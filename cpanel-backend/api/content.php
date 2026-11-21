<?php
// Public, read-only content API. The website fetches this to render the latest content.
require __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');
$c = config();
header('Access-Control-Allow-Origin: ' . $c['allowed_origin']);
header('Access-Control-Allow-Methods: GET');
header('Cache-Control: public, max-age=60'); // 60s cache; edits appear within a minute

try {
    $rows = db()->query("SELECT section, data FROM content")->fetchAll();
    $out = [];
    foreach ($rows as $r) {
        // Only public content sections; private rows (e.g. the maintenance bypass token) are never returned.
        if (!in_array($r['section'], ALLOWED_SECTIONS, true)) continue;
        $out[$r['section']] = json_decode($r['data']);
    }
    // Tells the website that api/visit.php (visitor-country counter) is on this server, so an
    // older backend never gets a request it would answer with 404.
    $out['_visit'] = is_file(__DIR__ . '/visit.php');
    echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'server error']);
}
