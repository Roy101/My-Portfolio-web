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
        $out[$r['section']] = json_decode($r['data']);
    }
    echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'server error']);
}
