<?php
/**
 * URL -> page for serve.php, and the HTTP answer (headers as the static files get them today:
 * Content-Type, Cache-Control/Expires, CORS on .txt/.md/.xml, the rel=canonical Link on .md).
 * Paths are matched against fixed patterns only; a file name never comes from the URL.
 */
declare(strict_types=1);

const SEO_PAPER_SLUG = '[a-z0-9]+(?:-[a-z0-9]+)*';

/**
 * Returns one of:
 *   ['rel' => R]                          serve page R (404 when the version has no R)
 *   ['rel' => R, 'slash' => '/x/']        301 to '/x/' when R exists (the canonical trailing slash)
 *   ['redirect' => '/x/']                 301 always
 *   ['none' => true]                      404
 */
function seo_route(string $path): array
{
    $P = SEO_PAPER_SLUG;
    if ($path === '' || $path === '/' || $path === '/index.html') return ['rel' => 'index.html'];
    if (preg_match('#^/publications/(?:index\.html)?$#D', $path)) return ['rel' => 'publications/index.html'];
    if ($path === '/publications') return ['redirect' => '/publications/'];
    if (preg_match("#^/publications/($P)/(?:index\\.html)?$#D", $path, $m) && strlen($m[1]) <= 120) return ['rel' => "publications/{$m[1]}/index.html"];
    if (preg_match("#^/publications/($P)$#D", $path, $m) && strlen($m[1]) <= 120) return ['rel' => "publications/{$m[1]}/index.html", 'slash' => "/publications/{$m[1]}/"];
    if (preg_match("#^/publications/($P)/cite\\.bib$#D", $path, $m) && strlen($m[1]) <= 120) return ['rel' => "publications/{$m[1]}/cite.bib"];
    if (preg_match("#^/publications/($P)\\.md$#D", $path, $m) && strlen($m[1]) <= 120) return ['rel' => "publications/{$m[1]}.md"];
    if ($path === '/publications.md') return ['rel' => 'publications.md'];
    foreach (['faq', 'about', 'photos', 'blog'] as $s) {
        if ($path === "/$s/" || $path === "/$s/index.html") return ['rel' => "$s/index.html"];
        if ($path === "/$s") return ['rel' => "$s/index.html", 'slash' => "/$s/"];
    }
    if ($path === '/faq.md') return ['rel' => 'faq.md'];
    if (preg_match('#^/blog/([^/]+)(/(?:index\.html)?)?$#D', $path, $m)) {
        $raw = $m[1];
        $slug = rawurldecode($raw);
        if (strlen($slug) > 200 || !seo_blog_slug_ok($slug)) return ['none' => true];
        $rel = "blog/$slug/index.html";
        return isset($m[2]) && $m[2] !== '' ? ['rel' => $rel] : ['rel' => $rel, 'slash' => "/blog/$raw/"];
    }
    if ($path === '/sitemap.xml') return ['rel' => 'sitemap.xml'];
    if ($path === '/llms.txt') return ['rel' => 'llms.txt'];
    if ($path === '/llms-full.txt') return ['rel' => 'llms-full.txt'];
    return ['none' => true];
}

function seo_type_for(string $rel): array
{
    $ext = strtolower((string)pathinfo($rel, PATHINFO_EXTENSION));
    $canon = null;
    if (preg_match('#^publications/(' . SEO_PAPER_SLUG . ')\.md$#', $rel, $m)) $canon = "/publications/{$m[1]}/";
    elseif ($rel === 'publications.md') $canon = '/publications/';
    elseif ($rel === 'faq.md') $canon = '/faq/';
    switch ($ext) {
        case 'html': return ['text/html; charset=utf-8', 0, false, null];
        case 'bib': return ['text/plain; charset=utf-8', 3600, false, null];
        case 'txt': return ['text/plain; charset=utf-8', 3600, true, null];
        case 'md': return ['text/markdown; charset=utf-8', 3600, true, $canon];
        case 'xml': return ['application/xml; charset=utf-8', 3600, true, null];
    }
    return ['application/octet-stream', 0, false, null];
}

/** X-SEO-Cache: hit/miss/stale/static, only when config.php sets 'seo_debug_header' => true (it would
 *  tell anyone when the database is down). The preview and the tests turn it on. */
function seo_cache_header(string $mode): void
{
    if (!empty(seo_config()['seo_debug_header'])) header('X-SEO-Cache: ' . $mode);
}
function seo_plain(int $code, string $text): void
{
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo $text . "\n";
    exit;
}
function seo_redirect(string $to, string $query, string $mode): void
{
    http_response_code(301);
    header('Location: ' . $to . ($query !== '' ? '?' . $query : ''));
    header('Cache-Control: max-age=0');
    header('Expires: ' . gmdate('D, d M Y H:i:s', time()) . ' GMT');
    seo_cache_header($mode);
    exit;
}
/** Strip W/ and the "-gzip" suffix mod_deflate adds, so a compressed response's ETag still matches. */
function seo_etag_matches(string $inm, string $etag): bool
{
    foreach (explode(',', $inm) as $t) {
        $t = trim($t);
        if ($t === '*') return true;
        if (strncmp($t, 'W/', 2) === 0) $t = substr($t, 2);
        $t = trim($t, '"');
        $t = preg_replace('/-(gzip|br|deflate)$/', '', $t);
        if ($t === $etag) return true;
    }
    return false;
}
function seo_send(int $status, string $rel, string $body, string $etag, int $lastMod, string $mode, string $method): void
{
    [$type, $max, $cors, $canon] = seo_type_for($rel);
    if ($status === 200 && $etag !== '') {
        $inm = (string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '');
        $ims = (string)($_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? '');
        $not = $inm !== '' ? seo_etag_matches($inm, $etag) : ($ims !== '' && $lastMod > 0 && ($t = strtotime($ims)) !== false && $t >= $lastMod);
        if ($not) $status = 304;
    }
    http_response_code($status);
    header('Content-Type: ' . $type);
    header('Cache-Control: max-age=' . $max);
    header('Expires: ' . gmdate('D, d M Y H:i:s', time() + $max) . ' GMT');
    if ($etag !== '' && $status !== 404 && $status !== 503) header('ETag: "' . $etag . '"');
    if ($lastMod > 0 && ($status === 200 || $status === 304)) header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $lastMod) . ' GMT');
    if ($cors) header('Access-Control-Allow-Origin: *');
    if ($canon !== null) header('Link: <https://palashroy.me' . $canon . '>; rel="canonical"');
    seo_cache_header($mode);
    if ($status === 304 || $method === 'HEAD') exit;
    echo $body;
    exit;
}

function seo_answer_cached(array $route, string $key, array $ix, string $mode, string $query, string $method): void
{
    $files = $ix['files'] ?? [];
    if (isset($route['redirect'])) seo_redirect($route['redirect'], $query, $mode);
    $rel = $route['rel'] ?? null;
    if ($rel !== null && isset($files[$rel])) {
        if (isset($route['slash'])) seo_redirect($route['slash'], $query, $mode);
        $body = seo_body($key, $rel);
        if ($body === null) throw new RuntimeException('cache file missing: ' . $rel);
        seo_send(200, $rel, $body, (string)$files[$rel], (int)($ix['lastMod'] ?? 0), $mode, $method);
    }
    // A paper or post whose slug changed in the admin: its old address moves permanently.
    if ($rel !== null && is_array($ix['moved'] ?? null)) {
        $to = null;
        if (preg_match('#^publications/(' . SEO_PAPER_SLUG . ')(/index\.html|/cite\.bib|\.md)$#', $rel, $m) && isset($ix['moved']['pub:' . $m[1]])) {
            $n = (string)$ix['moved']['pub:' . $m[1]];
            if (preg_match('#^' . SEO_PAPER_SLUG . '$#D', $n)) $to = ['/publications/' . $n . ($m[2] === '/index.html' ? '/' : $m[2]), 'publications/' . $n . $m[2]];
        } elseif (preg_match('#^blog/(.+)/index\.html$#', $rel, $m) && isset($ix['moved']['blog:' . $m[1]])) {
            $n = (string)$ix['moved']['blog:' . $m[1]];
            if (seo_blog_slug_ok($n)) $to = ['/blog/' . encode_uri_component($n) . '/', 'blog/' . $n . '/index.html'];
        }
        if ($to !== null && isset($files[$to[1]])) seo_redirect($to[0], $query, $mode);
    }
    // Unknown URL: the same cached 404 page for every one (nothing is rendered or written).
    $nf = seo_body($key, '404.html');
    seo_send(404, '404.html', $nf ?? 'Not Found', '', 0, $mode, $method);
}

/** The build's own copies (website upload). Used when the live layer cannot answer. */
function seo_answer_static(array $route, string $docroot, string $query, string $method): void
{
    $man = json_decode((string)@file_get_contents($docroot . '/_seo/manifest.json'), true);
    $allowed = ['index.html' => true];
    foreach ((is_array($man['staticFiles'] ?? null) ? $man['staticFiles'] : []) as $f) $allowed[(string)$f] = true;
    if (is_array($man)) {
        if (!empty($man['shells']['blogIndex'])) $allowed['blog/index.html'] = true;
        foreach (($man['shells']['blogPosts'] ?? []) as $f) $allowed[(string)$f] = true;
    } else {
        // No manifest (an older build): the generated files at their usual places.
        foreach (['publications/index.html', 'publications.md', 'faq/index.html', 'faq.md', 'about/index.html', 'photos/index.html', 'blog/index.html', 'sitemap.xml', 'llms.txt', 'llms-full.txt'] as $f) $allowed[$f] = true;
        if (isset($route['rel']) && preg_match('#^(publications/' . SEO_PAPER_SLUG . '(/index\.html|/cite\.bib|\.md)|blog/[^/]+/index\.html)$#', $route['rel'])) $allowed[$route['rel']] = true;
    }
    if (isset($route['redirect'])) seo_redirect($route['redirect'], $query, 'static');
    $rel = $route['rel'] ?? null;
    if ($rel !== null && isset($allowed[$rel])) {
        $f = $docroot . '/' . $rel;
        if (is_file($f)) {
            if (isset($route['slash'])) seo_redirect($route['slash'], $query, 'static');
            $body = (string)file_get_contents($f);
            seo_send(200, $rel, $body, 'b-' . substr(sha1($body), 0, 20), (int)filemtime($f), 'static', $method);
        }
    }
    if ($rel !== null && !isset($route['none']) && preg_match('#^(publications/[^/]+/(index\.html|cite\.bib)|publications/[^/]+\.md|blog/[^/]+/index\.html|faq/index\.html|faq\.md|photos/index\.html)$#', $rel)) {
        // Possibly a page added after the build while the database is down: temporary, so search
        // engines keep the URL (a 404 would drop it).
        header('Retry-After: 300');
        $mt = (string)@file_get_contents($docroot . '/maintenance.html');
        seo_send(503, 'maintenance.html', $mt !== '' ? $mt : 'Temporarily unavailable', '', 0, 'static', $method);
    }
    $nf = (string)@file_get_contents($docroot . '/404.html');
    seo_send(404, '404.html', $nf !== '' ? $nf : 'Not Found', '', 0, 'static', $method);
}
