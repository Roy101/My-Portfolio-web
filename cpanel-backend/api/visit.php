<?php
/**
 * Privacy-first visitor-country counter (aggregate only).
 *
 * POST /api/visit.php   body {"tz": "America/Regina"}  -> 204, counts one visit for that country.
 *   The website sends this at most once per browser session (skipped for Do Not Track / Global
 *   Privacy Control, automated browsers and bots). The country is looked up from the browser's
 *   time zone name (api/tzcountry.php); unknown zones and UTC are ignored.
 *   Abuse limit: one counted visit per network (IPv4 /24, IPv6 /48) per UTC day, at most 20,000
 *   networks per day and 2,000 visits per country per day. For that, a 16-byte HMAC of the
 *   shortened address under a random daily key is kept for the current day only; key and HMACs
 *   are deleted the next day (see visitlib.php). No IP address, cookie, user agent or time zone
 *   is stored in the statistics.
 *   Requests without an Origin or Referer of this host are ignored (browsers always send Origin
 *   on a POST).
 * GET  /api/visit.php?summary -> {"countries": N, "since": "YYYY-MM-DD"|null}
 *   N = distinct countries, all time. Only filled in while the admin switch
 *   "Show 'visitors from N countries' on the website" is on and N reaches the minimum (otherwise 0).
 */
declare(strict_types=1);
require __DIR__ . '/db.php';
require __DIR__ . '/visitlib.php';

header('X-Robots-Tag: noindex, nofollow');
header('X-Content-Type-Options: nosniff');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET' || $method === 'HEAD') {
    header('Content-Type: application/json; charset=utf-8');
    if (!isset($_GET['summary'])) { http_response_code(400); header('Cache-Control: no-store'); echo '{"error":"bad request"}'; exit; }
    $out = ['countries' => 0, 'since' => null];
    try {
        $db = db();
        try { visit_purge_old($db); } catch (Throwable $e) { /* tables not created yet */ }
        $line = visit_line_settings($db);
        if ($line['show']) {
            $r = $db->query("SELECT COUNT(DISTINCT country) AS n, MIN(day) AS since FROM visit_stats")->fetch();
            if ($r && (int)$r['n'] >= $line['min']) $out = ['countries' => (int)$r['n'], 'since' => $r['since'] !== null ? (string)$r['since'] : null];
        }
    } catch (Throwable $e) { /* table not created yet, or database down: report nothing */ }
    header('Cache-Control: public, max-age=3600');
    echo json_encode($out);
    exit;
}

if ($method !== 'POST') {
    http_response_code(405);
    header('Allow: GET, POST');
    header('Cache-Control: no-store');
    exit;
}

header('Cache-Control: no-store');
// Always answer 204 from here on: the visitor's page ignores the reply, and nothing is leaked.
function visit_done(): void { http_response_code(204); exit; }

// Same-origin only: the Origin (or, failing that, Referer) host must match this host. Browsers
// always send Origin with sendBeacon / fetch POST, so a request with neither is not a page visit.
$host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
$checked = false;
foreach (['HTTP_ORIGIN', 'HTTP_REFERER'] as $h) {
    $v = (string)($_SERVER[$h] ?? '');
    if ($v === '' || $v === 'null') continue;
    $p = parse_url($v);
    $vh = strtolower((string)($p['host'] ?? '')) . (isset($p['port']) ? ':' . $p['port'] : '');
    if ($host === '' || $vh !== $host) visit_done();
    $checked = true;
    break; // the first header present decides
}
if (!$checked) visit_done();
// The visitor asked not to be tracked.
if (($_SERVER['HTTP_DNT'] ?? '') === '1' || ($_SERVER['HTTP_SEC_GPC'] ?? '') === '1') visit_done();
if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 512) visit_done();

$raw = (string)file_get_contents('php://input', false, null, 0, 513);
if ($raw === '' || strlen($raw) > 512) visit_done();
$body = json_decode($raw, true);
$tz = is_array($body) && is_string($body['tz'] ?? null) ? $body['tz'] : '';
if ($tz === '' || strlen($tz) > 64) visit_done();
$map = require __DIR__ . '/tzcountry.php';
$country = is_array($map) ? ($map[$tz] ?? '') : '';
if (!is_string($country) || !preg_match('/^[A-Z]{2}$/', $country)) visit_done();

try {
    $db = db();
    // REMOTE_ADDR only (X-Forwarded-For is client-controlled and ignored). Tables are created on first use.
    visit_with_tables($db, function () use ($db, $country) {
        if (visit_once_today($db, (string)($_SERVER['REMOTE_ADDR'] ?? ''))) visit_count($db, $country);
    });
} catch (Throwable $e) { /* never leak errors */ }
visit_done();
