<?php
/**
 * Front controller for the live public pages (palash-portfolio/public/.htaccess rule 3b sends the
 * generated URLs here): /, /publications/…, /faq/, /about/, /photos/, /blog/…, sitemap.xml, llms.txt,
 * llms-full.txt and the Markdown copies, rendered from the database by lib/gen.php (a port of
 * scripts/generate-seo.mjs) and cached in api/cache/seo/.
 *
 * Read-only: it never writes to the database. Order of answers: cached version -> fresh render ->
 * previous version (stale) -> the build's static copy -> 503 (a page added after the build while
 * the database is down) / 404 (an unknown URL). Errors are logged to api/cache/seo/errors.log.
 */
declare(strict_types=1);
ini_set('display_errors', '0');
if (function_exists('ini_set')) @ini_set('zlib.output_compression', '0'); // mod_deflate compresses (as for static files)
@set_time_limit(10);
require_once __DIR__ . '/lib/live.php';
require_once __DIR__ . '/lib/router.php';

$uri = (string)($_SERVER['REQUEST_URI'] ?? '/');
$qpos = strpos($uri, '?');
$path = $qpos === false ? $uri : substr($uri, 0, $qpos);
$query = $qpos === false ? '' : substr($uri, $qpos + 1);
$method = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');

// Never callable as itself (/api/seo/serve.php): only through the .htaccess rewrite of a public URL.
if (strncmp($path, '/api/', 5) === 0 || strncmp($path, '/admin/', 7) === 0) { seo_plain(404, 'Not Found'); }
if ($method !== 'GET' && $method !== 'HEAD') { header('Allow: GET, HEAD'); seo_plain(405, 'Method Not Allowed'); }

$docroot = seo_docroot();
$route = ['none' => true];

set_error_handler(function ($no, $str, $file, $line) { if (!(error_reporting() & $no)) return false; throw new ErrorException($str, 0, $no, $file, $line); });
try {
    $route = seo_route($path);
    $build = seo_build($docroot, $why);
    if (!$build) throw new SeoStatic($why);
    if (seo_cache_dir() === '') throw new SeoStatic('no private writable cache folder');
    if (!empty(seo_config()['seo_force_fail'])) throw new RuntimeException('seo_force_fail (test switch in config.php)');
    $state = seo_state();
    if (!$state) throw new RuntimeException('no content state (database unavailable and nothing cached yet)');
    $key = seo_version_key($state, $build);
    $ix = seo_version_index($key);
    $mode = 'hit';
    if (!$ix) {
        $mode = 'miss';
        try {
            // A version that failed to render is not retried for SEO_FAIL_TTL seconds (every request
            // would otherwise render the whole site again), and a request never waits for another
            // request's render: both serve the previous version meanwhile.
            $ix = seo_render_failed_recently($key) ? null : seo_build_version($build, $state, false);
        } catch (Throwable $e) {
            seo_log('render: ' . get_class($e) . ': ' . $e->getMessage());
            seo_render_failed($key);
            $ix = null;
        }
        if (!$ix) {
            $prev = seo_previous_version($key);
            if (!$prev) throw new RuntimeException('render failed and no earlier version is cached');
            [$key, $ix, $mode] = [$prev['key'], $prev['index'], 'stale'];
        }
    }
    restore_error_handler();
    seo_answer_cached($route, $key, $ix, $mode, $query, $method);
} catch (Throwable $e) {
    restore_error_handler();
    if (!empty($route['none']) && $path !== '' && !($e instanceof SeoStatic)) { try { $route = seo_route($path); } catch (Throwable $e2) {} }
    if (!($e instanceof SeoStatic)) seo_log('serve ' . $path . ': ' . get_class($e) . ': ' . $e->getMessage());
    seo_answer_static($route, $docroot, $query, $method);
}
final class SeoStatic extends RuntimeException {}
