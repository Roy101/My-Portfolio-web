<?php
// Public, read-only content API. The website fetches this to render the latest content.
require __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');
$c = config();
header('Access-Control-Allow-Origin: ' . $c['allowed_origin']);
header('Access-Control-Allow-Methods: GET');
// Revalidated on every load (ETag below), so an admin save shows on the next page view while
// repeat visits still cost only a 304. (Was max-age=60: edits used to appear within a minute.)
header('Cache-Control: no-cache');

try {
    $rows = db()->query("SELECT section, data FROM content")->fetchAll();
    $out = [];
    foreach ($rows as $r) {
        // Only public content sections; private rows (e.g. the maintenance bypass token) are never returned.
        if (!in_array($r['section'], ALLOWED_SECTIONS, true)) continue;
        $out[$r['section']] = json_decode($r['data']);
    }
    // Never sent, whatever is stored: papers on the do-not-publish list (config.php
    // seo_block_title_sha256) and documents marked "hidden" in the admin.
    if (isset($out['publications']) && is_array($out['publications'])) {
        $out['publications'] = array_values(array_filter($out['publications'], fn($p) => !(is_object($p) && title_on_block_list($p->title ?? '', $c))));
    }
    $hiddenOff = function ($list) {
        return array_values(array_filter($list, fn($d) => !(is_object($d) && isset($d->hidden) && ($d->hidden === true || (is_string($d->hidden) && preg_match('/^(true|yes|1)$/i', trim($d->hidden)))))));
    };
    if (isset($out['documents'])) {
        if (is_array($out['documents'])) $out['documents'] = $hiddenOff($out['documents']);
        elseif (is_object($out['documents']) && isset($out['documents']->items) && is_array($out['documents']->items)) $out['documents']->items = $hiddenOff($out['documents']->items);
    }
    // Tells the website that api/visit.php (visitor-country counter) is on this server, so an
    // older backend never gets a request it would answer with 404.
    $out['_visit'] = is_file(__DIR__ . '/visit.php');
    // Same for api/metrics.php (live OpenAlex citation numbers, used in automatic metrics mode).
    $out['_metrics'] = is_file(__DIR__ . '/metrics.php');
    $json = json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $etag = 'c-' . substr(sha1((string)$json), 0, 20);
    header('ETag: "' . $etag . '"');
    // mod_deflate appends "-gzip" to the ETag of a compressed response; compare without it.
    $inm = (string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '');
    foreach (explode(',', $inm) as $t) {
        $t = trim(preg_replace('/^W\//', '', trim($t)), '"');
        if ($t !== '' && preg_replace('/-(gzip|br|deflate)$/', '', $t) === $etag) { http_response_code(304); exit; }
    }
    echo $json;
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'server error']);
}
