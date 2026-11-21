<?php
/**
 * Citation metrics from OpenAlex, cached on the server (used by api/metrics.php and the admin).
 *
 * The cache file (api/cache/metrics.json, denied to the web by api/.htaccess and
 * api/cache/.htaccess) holds the last good numbers and when they were fetched:
 *   {"data": {citations,hIndex,works,source,profileUrl,openAlexId,updated} | null,
 *    "fetched": unix time of the last successful fetch | null,
 *    "tried": unix time of the last attempt, "error": message of the last failed attempt | null}
 * OpenAlex is asked at most once per 24 hours (METRICS_TTL). A failed attempt keeps the last good
 * numbers and is retried after METRICS_RETRY. A lock file makes sure only one request fetches.
 * "updated" changes only when citations, h-index or works change (same rule as
 * palash-portfolio/scripts/fetch-metrics.mjs).
 */
declare(strict_types=1);

const METRICS_ORCID = '0000-0001-9470-4233';
const METRICS_TTL   = 86400; // refresh at most once a day
const METRICS_RETRY = 900;   // after a failed attempt, wait 15 minutes before trying again
const METRICS_UA    = 'palashroy.me-metrics/1.0 (academic homepage; daily citation count refresh)';

function metrics_source_url(): string
{
    // Optional override in config.php ('metrics_source_url'), used only by tests to simulate an outage.
    try { $c = config(); } catch (Throwable $e) { $c = []; }
    $u = is_array($c) ? (string)($c['metrics_source_url'] ?? '') : '';
    return $u !== '' ? $u : 'https://api.openalex.org/authors/https://orcid.org/' . METRICS_ORCID;
}

function metrics_cache_dir(): string
{
    $d = __DIR__ . '/cache';
    if (!is_dir($d)) @mkdir($d, 0755, true);
    if (is_dir($d) && is_writable($d)) return $d;
    // Last resort when api/cache cannot be written: a private folder in the system temp dir.
    $t = rtrim(sys_get_temp_dir(), '/') . '/pr-metrics-' . substr(hash('sha256', __DIR__), 0, 12);
    if (!is_dir($t)) @mkdir($t, 0700, true);
    return $t;
}

function metrics_read_cache(): array
{
    $f = metrics_cache_dir() . '/metrics.json';
    $raw = is_file($f) ? @file_get_contents($f) : false;
    $c = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($c)) $c = [];
    return [
        'data'    => isset($c['data']) && is_array($c['data']) ? $c['data'] : null,
        'fetched' => isset($c['fetched']) && is_int($c['fetched']) ? $c['fetched'] : null,
        'tried'   => isset($c['tried']) && is_int($c['tried']) ? $c['tried'] : null,
        'error'   => isset($c['error']) && is_string($c['error']) ? $c['error'] : null,
    ];
}

function metrics_write_cache(array $c): void
{
    $dir = metrics_cache_dir();
    $tmp = @tempnam($dir, 'm');
    if ($tmp === false) return;
    if (@file_put_contents($tmp, json_encode($c, JSON_UNESCAPED_SLASHES)) === false) { @unlink($tmp); return; }
    @chmod($tmp, 0644);
    if (!@rename($tmp, $dir . '/metrics.json')) @unlink($tmp);
}

/** Is the website's metrics row in automatic (OpenAlex) mode? */
function metrics_auto_on(PDO $db): bool
{
    $st = $db->prepare("SELECT data FROM content WHERE section = 'metrics' LIMIT 1");
    $st->execute();
    $r = $st->fetch();
    $d = $r ? json_decode((string)$r['data'], true) : null;
    return is_array($d) && ($d['auto'] ?? null) === true;
}

/** GET the OpenAlex author record. Returns [body|null, error|null]. */
function metrics_http_get(string $url): array
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_USERAGENT      => METRICS_UA,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS | CURLPROTO_HTTP,
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($body === false) return [null, 'request failed: ' . ($err ?: 'unknown error')];
        if ($code !== 200) return [null, 'HTTP ' . $code];
        return [(string)$body, null];
    }
    if (!filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) return [null, 'neither curl nor allow_url_fopen is available'];
    $ctx = stream_context_create(['http' => [
        'method' => 'GET', 'timeout' => 5, 'ignore_errors' => true,
        'header' => "User-Agent: " . METRICS_UA . "\r\nAccept: application/json\r\n",
    ]]);
    $body = @file_get_contents($url, false, $ctx, 0, 2000000);
    if ($body === false) return [null, 'request failed'];
    $status = isset($http_response_header[0]) && preg_match('#\s(\d{3})\s?#', $http_response_header[0], $m) ? (int)$m[1] : 0;
    if ($status !== 200) return [null, 'HTTP ' . $status];
    return [$body, null];
}

/** Turn an OpenAlex author record into the website's metrics shape, or null if it looks wrong. */
function metrics_parse(string $body): ?array
{
    $d = json_decode($body, true);
    if (!is_array($d)) return null;
    $id = (string)($d['id'] ?? '');
    $c = $d['cited_by_count'] ?? null;
    $h = $d['summary_stats']['h_index'] ?? null;
    $w = $d['works_count'] ?? null;
    if (!preg_match('#^https://openalex\.org/A\d+$#', $id)) return null;
    foreach ([$c, $h, $w] as $n) if (!is_int($n) || $n < 0 || $n > 10000000) return null;
    if ($w === 0) return null; // an empty record is a glitch, not real numbers
    return [
        'citations'  => $c,
        'hIndex'     => $h,
        'works'      => $w,
        'source'     => 'OpenAlex',
        'profileUrl' => $id,
        'openAlexId' => substr($id, strrpos($id, '/') + 1),
    ];
}

/**
 * Return the cache, refreshing it from OpenAlex first when it is due (or when $force is true).
 * Never throws; on any failure the last good numbers stay in place.
 */
function metrics_get(bool $force = false): array
{
    $c = metrics_read_cache();
    $now = time();
    $due = $c['fetched'] === null || $now - $c['fetched'] >= METRICS_TTL;
    $mayTry = $c['tried'] === null || $now - $c['tried'] >= METRICS_RETRY || $c['tried'] > $now;
    if (!$force && !($due && $mayTry)) return $c;

    $lock = @fopen(metrics_cache_dir() . '/metrics.lock', 'c');
    if ($lock === false) return $c;
    if (!flock($lock, LOCK_EX | LOCK_NB)) { fclose($lock); return $c; } // someone else is fetching
    try {
        $c = metrics_read_cache(); // another request may have refreshed it meanwhile
        $due = $c['fetched'] === null || $now - $c['fetched'] >= METRICS_TTL;
        if (!$force && !$due) return $c;
        [$body, $err] = metrics_http_get(metrics_source_url());
        $m = $body !== null ? metrics_parse($body) : null;
        if ($m === null) {
            $c['tried'] = $now;
            $c['error'] = $err ?? 'unexpected reply from OpenAlex';
        } else {
            $prev = $c['data'];
            $same = is_array($prev) && isset($prev['updated'])
                && (string)($prev['citations'] ?? '') === (string)$m['citations']
                && (string)($prev['hIndex'] ?? '') === (string)$m['hIndex']
                && (string)($prev['works'] ?? '') === (string)$m['works'];
            $m['updated'] = $same ? (string)$prev['updated'] : gmdate('Y-m-d', $now);
            $c = ['data' => $m, 'fetched' => $now, 'tried' => $now, 'error' => null];
        }
        metrics_write_cache($c);
        return $c;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}
