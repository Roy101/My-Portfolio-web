<?php
/**
 * Live citation metrics for the website (OpenAlex, via ORCID 0000-0001-9470-4233).
 *
 * GET /api/metrics.php -> 200 {"citations","hIndex","works","source":"OpenAlex","profileUrl","openAlexId","updated"}
 *   Served from a server-side cache that is refreshed from OpenAlex at most once a day
 *   (see metricslib.php). If OpenAlex cannot be reached, the last good numbers are served.
 * -> 204 (empty) while the admin's Research Metrics are in manual mode, or before the first
 *   successful fetch; the website then keeps the numbers it was built with.
 */
declare(strict_types=1);
require __DIR__ . '/db.php';
require __DIR__ . '/metricslib.php';

header('X-Robots-Tag: noindex, nofollow');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'GET' && $method !== 'HEAD') {
    http_response_code(405);
    header('Allow: GET');
    header('Cache-Control: no-store');
    exit;
}

function metrics_none(): void
{
    http_response_code(204);
    header('Cache-Control: public, max-age=300');
    exit;
}

try {
    if (!metrics_auto_on(db())) metrics_none(); // manual mode: the numbers in the content row stand
} catch (Throwable $e) {
    metrics_none();
}
$c = metrics_get();
$d = $c['data'];
if (!is_array($d)) metrics_none();
$out = [];
foreach (['citations', 'hIndex', 'works', 'source', 'profileUrl', 'openAlexId', 'updated'] as $k) $out[$k] = $d[$k] ?? null;
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=3600');
echo json_encode($out, JSON_UNESCAPED_SLASHES);
