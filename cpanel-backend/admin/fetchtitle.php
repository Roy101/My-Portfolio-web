<?php
/**
 * Fetch the page title (og:title / <title>) of an external article URL,
 * so the admin can auto-fill news/media titles instead of typing them.
 * Login + CSRF required. SSRF-guarded: only public http(s) hosts, private/
 * reserved IP ranges rejected, redirects re-validated, response size capped.
 */
declare(strict_types=1);

session_set_cookie_params([
    'lifetime' => 0, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Strict',
]);
session_start();
header('Content-Type: application/json');

function out($d): void { echo json_encode($d); exit; }

if (empty($_SESSION['admin'])) { http_response_code(401); out(['error' => 'Not logged in']); }
$body = json_decode(file_get_contents('php://input'), true) ?: [];
if (!hash_equals($_SESSION['csrf'] ?? '', $body['csrf'] ?? '')) { http_response_code(403); out(['error' => 'Bad CSRF token — reload the page.']); }

$url = trim((string)($body['url'] ?? ''));
if ($url === '') { http_response_code(400); out(['error' => 'No URL given']); }

// Reject anything that isn't a public http(s) host.
function is_safe_url(string $url): bool
{
    $p = parse_url($url);
    if (!$p || !isset($p['scheme'], $p['host'])) return false;
    if (!in_array(strtolower($p['scheme']), ['http', 'https'], true)) return false;
    $host = $p['host'];
    $ip = filter_var($host, FILTER_VALIDATE_IP) ? $host : gethostbyname($host);
    if (!filter_var($ip, FILTER_VALIDATE_IP)) return false;
    // Block private + reserved ranges (loopback, link-local, RFC1918, etc.)
    return (bool)filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
}

// Manual redirect handling so each hop is re-validated (redirects can't sneak to an internal host).
function fetch_once(string $url): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => false,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; PalashRoyPortfolioBot/1.0)',
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_BUFFERSIZE     => 16384,
        CURLOPT_NOPROGRESS     => false,
        // Abort once we've read ~300 KB — titles live near the top of the document.
        CURLOPT_PROGRESSFUNCTION => function ($ch, $dltotal, $dlnow) { return $dlnow > 300000 ? 1 : 0; },
    ]);
    $bodyText = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $loc  = (string)curl_getinfo($ch, CURLINFO_REDIRECT_URL);
    curl_close($ch);
    return [$code, (string)$bodyText, $loc];
}

if (!function_exists('curl_init')) { http_response_code(500); out(['error' => 'Server is missing cURL support']); }

$html = '';
for ($hop = 0; $hop < 4; $hop++) {
    if (!is_safe_url($url)) { http_response_code(400); out(['error' => 'URL is not allowed (must be a public http/https link)']); }
    [$code, $html, $loc] = fetch_once($url);
    if ($code >= 300 && $code < 400 && $loc !== '' && preg_match('#^https?://#i', $loc)) { $url = $loc; continue; }
    break;
}
if ($html === '') { http_response_code(502); out(['error' => 'Could not read that page']); }

$title = '';
$site  = '';
if (preg_match('/<meta[^>]+property=["\']og:title["\'][^>]*content=["\']([^"\']*)["\']/i', $html, $m)) $title = $m[1];
elseif (preg_match('/<meta[^>]+content=["\']([^"\']*)["\'][^>]*property=["\']og:title["\']/i', $html, $m)) $title = $m[1];
if ($title === '' && preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $m)) $title = trim($m[1]);
if (preg_match('/<meta[^>]+property=["\']og:site_name["\'][^>]*content=["\']([^"\']*)["\']/i', $html, $m)) $site = $m[1];

$title = trim(preg_replace('/\s+/', ' ', html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
$site  = trim(html_entity_decode($site, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

if ($title === '') { out(['ok' => false, 'error' => 'No title found on that page']); }
out(['ok' => true, 'title' => $title, 'siteName' => $site]);
