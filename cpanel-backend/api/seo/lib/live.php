<?php
/**
 * Live layer: database content -> rendered pages, the version fingerprint, the page cache and the
 * admin hook. Used by serve.php (public, read-only), the admin (after every content write) and cli.php.
 *
 * Cache (api/cache/seo/, never served: api/cache/.htaccess + api/.htaccess):
 *   state.json            {fp, checkedAt, dbMax, dates:{section: Y-m-d}, dbError}
 *   v-<key>/index.json    {files: {rel: etag}, facts, warnings, builtAt, lastMod}
 *   v-<key>/<sha1(rel)>.bin   page bodies; v-<key>/.complete when the version is fully written
 *   render.lock, errors.log
 * key = sha1(fp + build hash + SEO_LIB_VERSION + UTC year): a save, a new build, a new PHP release
 * or a new year each make a new version. At most two versions are kept (current + previous).
 */
declare(strict_types=1);
require_once __DIR__ . '/build.php';
require_once __DIR__ . '/shells.php';

const SEO_STATE_TTL = 30;           // seconds between database checks on public requests
const SEO_PRIVATE_ROW = '_private_seo';
const SEO_HOME_ROW = '_private_home';  // the homepage body rendered by React in the admin's browser
const SEO_HOME_MAX = 1572864;          // bytes

// ---------------------------------------------------------------- environment
function seo_docroot(): string
{
    $r = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
    $mine = dirname(__DIR__, 3); // api/seo/lib -> web root
    if ($r === '' || !is_dir($r . '/api/seo')) $r = $mine;
    return $r;
}
function seo_config(): array
{
    static $c = null;
    if ($c !== null) return $c;
    foreach ([__DIR__ . '/../../../../portfolio-config.php', __DIR__ . '/../../config.php'] as $p) {
        if (is_file($p)) { $v = require $p; return $c = is_array($v) ? $v : []; }
    }
    return $c = [];
}
function seo_public_sections(): array
{
    if (!defined('ALLOWED_SECTIONS')) require_once __DIR__ . '/../../db.php';
    return ALLOWED_SECTIONS;
}
/** PDO without db.php's exit-on-missing-config; short connect timeout so a stuck database falls back fast. */
function seo_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    $c = seo_config();
    if (!empty($c['seo_test_db_down'])) throw new RuntimeException('database disabled for a test (config seo_test_db_down)');
    if (!isset($c['db_host'], $c['db_name'])) throw new RuntimeException('config missing');
    // A database that accepts the connection but never answers must not hold the page: short reads too.
    @ini_set('mysqlnd.net_read_timeout', '3');
    @ini_set('default_socket_timeout', '3');
    $pdo = new PDO("mysql:host={$c['db_host']};dbname={$c['db_name']};charset=utf8mb4", (string)($c['db_user'] ?? ''), (string)($c['db_pass'] ?? ''), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_TIMEOUT => 3,
    ]);
    return $pdo;
}
function seo_cache_dir(): string
{
    static $d = null;
    if ($d !== null) return $d;
    $base = dirname(__DIR__, 2) . '/cache';
    $x = $base . '/seo';
    if (!is_dir($x)) @mkdir($x, 0755, true);
    if (is_dir($x) && is_writable($x)) return $d = $x;
    // Last resort: a folder in the system temp dir, only when this account owns it and nobody else
    // can read or write it (private_tmp_dir in api/db.php). Otherwise '' = no cache (static pages).
    if (!function_exists('private_tmp_dir')) require_once dirname(__DIR__, 2) . '/db.php';
    return $d = (private_tmp_dir('pr-seo-', __DIR__) ?? '');
}
function seo_log(string $msg): void
{
    if (seo_cache_dir() === '') { error_log('[seo] ' . $msg); return; }
    $f = seo_cache_dir() . '/errors.log';
    if (@filesize($f) > 65536) @rename($f, $f . '.1');
    @file_put_contents($f, gmdate('c') . ' ' . preg_replace('/\s+/', ' ', $msg) . "\n", FILE_APPEND | LOCK_EX);
}
function seo_atomic_write(string $file, string $bytes): bool
{
    $dir = dirname($file);
    $tmp = @tempnam($dir, '.t');
    if ($tmp === false) return false;
    if (@file_put_contents($tmp, $bytes) === false) { @unlink($tmp); return false; }
    @chmod($tmp, 0644);
    if (!@rename($tmp, $file)) { @unlink($tmp); return false; }
    return true;
}

// ---------------------------------------------------------------- content + fingerprint
/** One cheap query: section, change time, length and CRC32 of every public row. */
function seo_fingerprint(PDO $db): array
{
    $secs = seo_public_sections();
    $in = implode(',', array_fill(0, count($secs), '?'));
    $st = $db->prepare("SELECT section, UNIX_TIMESTAMP(updated_at) AS t, LENGTH(data) AS l, CRC32(data) AS c FROM content WHERE section IN ($in) ORDER BY section");
    $st->execute($secs);
    $parts = []; $max = 0; $dates = [];
    foreach ($st->fetchAll() as $r) {
        $t = (int)$r['t'];
        $parts[] = $r['section'] . '|' . $t . '|' . $r['l'] . '|' . $r['c'];
        $max = max($max, $t);
        if ($t > 0) $dates[(string)$r['section']] = gmdate('Y-m-d', $t);
    }
    // The content alone (what the homepage snapshot is rendered from; no dates or metrics).
    $cfp = sha1(implode("\n", $parts));
    // Item dates (admin-kept) and the homepage snapshot: part of the version, since they change pages.
    $st = $db->prepare("SELECT section, LENGTH(data) AS l, CRC32(data) AS c FROM content WHERE section IN (?, ?) ORDER BY section");
    $st->execute([SEO_PRIVATE_ROW, SEO_HOME_ROW]);
    foreach ($st->fetchAll() as $r) $parts[] = ($r['section'] === SEO_PRIVATE_ROW ? 'items' : 'home') . '|' . $r['l'] . '|' . $r['c'];
    $m = seo_metrics_auto_data();
    if ($m !== null) $parts[] = 'metrics|' . js_json($m);
    return ['fp' => sha1(implode("\n", $parts)), 'cfp' => $cfp, 'dbMax' => $max, 'dates' => $dates];
}
/** In automatic metrics mode the live numbers (api/cache/metrics.json) replace the stored ones. Never fetches. */
function seo_metrics_auto_data(): ?array
{
    static $memo = false;
    if ($memo !== false) return $memo;
    $f = dirname(__DIR__, 2) . '/cache/metrics.json';
    if (!is_file($f)) {
        // The same private folder metricslib.php falls back to (never another account's or site's file).
        if (!function_exists('private_tmp_dir')) require_once dirname(__DIR__, 2) . '/db.php';
        $t = private_tmp_dir('pr-metrics-', dirname(__DIR__, 2));
        $f = $t !== null && is_file($t . '/metrics.json') ? $t . '/metrics.json' : '';
    }
    $c = $f !== '' ? json_decode((string)@file_get_contents($f), true) : null;
    return $memo = (is_array($c) && is_array($c['data'] ?? null)) ? $c['data'] : null;
}
/**
 * Database content in the shape the generator reads (same rules as scripts/sync-content.mjs, except
 * that the database is the truth: an empty list is empty). Sections the database lacks come from the
 * build's bundled content. Settings never carry secrets.
 */
function seo_content_from_db(PDO $db, ?array $build = null): array
{
    $secs = seo_public_sections();
    $in = implode(',', array_fill(0, count($secs), '?'));
    $st = $db->prepare("SELECT section, data FROM content WHERE section IN ($in)");
    $st->execute($secs);
    $c = $build ? seo_content_from_dir($build['dir'] . '/content') : [];
    foreach ($st->fetchAll() as $r) {
        $v = json_decode((string)$r['data'], true);
        if ($v === null && trim((string)$r['data']) !== 'null') continue;
        $c[(string)$r['section']] = $v;
    }
    if (isset($c['settings']) && is_array($c['settings'])) {
        $scrub = function (array $o): array { foreach (array_keys($o) as $k) if (preg_match('/token|secret|bypass|password/i', (string)$k)) unset($o[$k]); return $o; };
        $c['settings'] = $scrub($c['settings']);
        if (isset($c['settings']['maintenance']) && is_array($c['settings']['maintenance'])) $c['settings']['maintenance'] = $scrub($c['settings']['maintenance']);
    }
    if (is_array($c['metrics'] ?? null) && ($c['metrics']['auto'] ?? null) === true) {
        $m = seo_metrics_auto_data();
        if ($m !== null) $c['metrics'] = array_merge($c['metrics'], $m);
    }
    return $c;
}
/**
 * Optional guard (config.php 'seo_block_title_sha256'): sha256 hashes of normalized titles (lower case,
 * only a-z0-9) of papers that must never be published, e.g. work under review. Only hashes are stored.
 */
function seo_title_blocked($title): bool
{
    if (!function_exists('title_on_block_list')) require_once dirname(__DIR__, 2) . '/db.php';
    return title_on_block_list($title, seo_config());
}
function seo_drop_blocked(array $content): array
{
    if (!isset($content['publications'])) return $content;
    $p = $content['publications'];
    $list = SeoGen::items($p);
    $keep = array_values(array_filter($list, fn($x) => !(is_array($x) && seo_title_blocked($x['title'] ?? ''))));
    if (count($keep) === count($list)) return $content;
    seo_log('blocked ' . (count($list) - count($keep)) . ' publication(s) listed in seo_block_title_sha256');
    $content['publications'] = js_is_array($p) ? $keep : array_merge($p, ['items' => $keep]);
    return $content;
}
function seo_item_dates(PDO $db): array
{
    $st = $db->prepare("SELECT data FROM content WHERE section = ?");
    $st->execute([SEO_PRIVATE_ROW]);
    $r = $st->fetch();
    $d = $r ? json_decode((string)$r['data'], true) : null;
    $out = [];
    foreach ((is_array($d['items'] ?? null) ? $d['items'] : []) as $k => $v) if (is_array($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($v['d'] ?? ''))) $out[(string)$k] = $v['d'];
    return $out;
}

// ---------------------------------------------------------------- state / version
function seo_state_read(): ?array
{
    $s = json_decode((string)@file_get_contents(seo_cache_dir() . '/state.json'), true);
    return is_array($s) && isset($s['fp']) ? $s : null;
}
/** Current content state: from state.json when checked within SEO_STATE_TTL, else from the database. */
function seo_state(bool $force = false, bool $write = true): ?array
{
    $s = seo_state_read();
    if (!$force && $s && (time() - (int)($s['checkedAt'] ?? 0)) < SEO_STATE_TTL) return $s;
    try {
        $f = seo_fingerprint(seo_db());
        $n = ['fp' => $f['fp'], 'cfp' => $f['cfp'], 'checkedAt' => time(), 'dbMax' => $f['dbMax'], 'dates' => $f['dates'], 'dbError' => false];
    } catch (Throwable $e) {
        if (!$s) return null;
        seo_log('state: database unavailable: ' . $e->getMessage());
        $n = ['checkedAt' => time(), 'dbError' => true] + $s; // keep serving the last known version
    }
    if ($write) seo_state_write($n);
    return $n;
}
function seo_state_write(array $n): void
{
    seo_atomic_write(seo_cache_dir() . '/state.json', js_json($n));
}
function seo_version_key(array $state, array $build): string
{
    return substr(sha1($state['fp'] . '|' . ($build['manifest']['hash'] ?? '') . '|' . SEO_LIB_VERSION . '|' . gmdate('Y')), 0, 20);
}
function seo_vdir(string $key): string { return seo_cache_dir() . '/v-' . $key; }
function seo_version_index(string $key): ?array
{
    $d = seo_vdir($key);
    if (!is_file($d . '/.complete')) return null;
    $ix = json_decode((string)@file_get_contents($d . '/index.json'), true);
    return is_array($ix) ? $ix : null;
}
/** Newest complete version other than $except (stale-if-error). */
function seo_previous_version(string $except = ''): ?array
{
    $dirs = glob(seo_cache_dir() . '/v-*', GLOB_ONLYDIR) ?: [];
    usort($dirs, fn($a, $b) => filemtime($b) <=> filemtime($a));
    foreach ($dirs as $d) {
        $k = substr(basename($d), 2);
        if ($k === $except) continue;
        if ($ix = seo_version_index($k)) return ['key' => $k, 'index' => $ix];
    }
    return null;
}

// ---------------------------------------------------------------- render
const SEO_FAIL_TTL = 300; // seconds a version that failed to render is not retried by public requests
/** Remember that rendering $key failed (render-failed.json), so public requests do not retry it at once. */
function seo_render_failed(string $key): void
{
    if (seo_cache_dir() === '') return;
    seo_atomic_write(seo_cache_dir() . '/render-failed.json', js_json(['key' => $key, 'until' => time() + SEO_FAIL_TTL]));
}
function seo_render_failed_recently(string $key): bool
{
    $f = json_decode((string)@file_get_contents(seo_cache_dir() . '/render-failed.json'), true);
    return is_array($f) && ($f['key'] ?? '') === $key && (int)($f['until'] ?? 0) > time();
}
/** All live files: the generator's output plus live blog pages. */
function seo_live_files(SeoGen $g, array $content, array $build, array $env): array
{
    $files = $g->files;
    // Homepage body: React's own rendering of the current content (seo_home_store), when there is one
    // for exactly this content and build; else the build's prerendered body.
    if (isset($files['index.html']) && is_string($env['homeBody'] ?? null)) {
        $h = shell_set_root($files['index.html'], $env['homeBody']);
        if ($h !== null) $files['index.html'] = $h;
    }
    $shells = $build['shells'];
    $base = $build['dir'] . '/content';
    $builtContent = seo_content_from_dir($base);
    $blogShell = $shells['blog/index.html'] ?? null;
    if ($blogShell === null) return $files;
    $SITE = SEO_SITE_URL;
    $published = app_published_posts($content);
    $all = app_all_posts($content);
    $head = js_get($content['headings'] ?? [], 'blog');
    $owner = $g->ownerName();
    $seoBlog = $g->seoPage('blog', 'title') . "\n" . $g->seoPage('blog', 'description');
    // The built name / blog texts: what the prerendered pages were made with.
    $bi = js_get($builtContent, 'identity');
    $builtOwner = is_array($bi) && is_string($bi['name'] ?? null) && app_no_emoji($bi['name']) !== '' ? app_no_emoji($bi['name']) : SEO_OWNER;
    $bs = js_get(js_get($builtContent['settings'] ?? [], 'seo'), 'pages');
    $bsb = is_array($bs) ? js_get($bs, 'blog') : null;
    $builtSeoBlog = (is_array($bsb) && is_string($bsb['title'] ?? null) ? app_no_emoji($bsb['title']) : '') . "\n" . (is_array($bsb) && is_string($bsb['description'] ?? null) ? app_no_emoji($bsb['description']) : '');
    $headChanged = $owner !== $builtOwner || $seoBlog !== $builtSeoBlog;
    $sig = fn($c) => js_json([app_published_posts($c), js_get($c['headings'] ?? [], 'blog')]);
    // /blog/: re-render the list and head only when the posts or the blog heading changed since the build.
    if ($headChanged || $sig($content) !== $sig($builtContent)) {
        $title = app_no_emoji(js_get($head, 'title')); if ($title === '') $title = 'Blog';
        $sub = is_array($head) && array_key_exists('subtitle', $head) && $head['subtitle'] !== null ? $head['subtitle'] : 'Research notes and paper walkthroughs.';
        $subPlain = js_trim(jreplace('/\[([^\]]+)\]\([^)]*\)/', '$1', app_no_emoji(js_truthy($sub) ? js_string($sub) : '')));
        $sd = $g->seoPage('blog', 'description');
        $desc = app_clip_desc($sd !== '' ? $sd : implode(' ', js_filter([$subPlain, 'Posts by ' . $owner . ', PhD researcher in Computer Science at the University of Saskatchewan.'], fn($x) => $x !== '')));
        $html = shell_set_main($blogShell, app_blog_index_body($published, $head));
        if ($html !== null) {
            $html = $g->shareImage($html);
            $st = $g->seoPage('blog', 'title');
            $html = shell_set_head($html, $st !== '' ? $st : $title . ' | ' . $owner, $SITE . '/blog/', $desc, $published ? 'index, follow, max-image-preview:large' : 'noindex, follow');
            $files['blog/index.html'] = $g->fixCreator(seo_inject_ld($html, $files['blog/index.html'] ?? ''));
        }
    }
    // Posts: published (BlogPosting JSON-LD) and drafts (noindex, reachable by URL for previews, as in React).
    $builtPosts = [];
    foreach (app_all_posts($builtContent) as $p) $builtPosts[app_eslug($p)] = js_json($p);
    $want = [];
    foreach ($all as $p) {
        $slug = app_eslug($p);
        if (!seo_blog_slug_ok($slug)) continue;
        if (isset($want[$slug])) continue; // the first post with a slug wins (React's find())
        $want[$slug] = $p;
    }
    $gPosts = [];
    foreach ($g->blogPostsAll() as $b) $gPosts[$g->slugOf($b)] = $b;
    foreach ($want as $slug => $p) {
        $rel = 'blog/' . $slug . '/index.html';
        $draft = app_is_draft($p);
        $own = $shells[$rel] ?? null;
        if (!$headChanged && !$draft && $own !== null && isset($files[$rel]) && ($builtPosts[$slug] ?? null) === js_json($p)) continue; // prerendered and unchanged
        $html = shell_set_main($own ?? $blogShell, app_post_view($p));
        if ($html === null) continue;
        $html = $g->shareImage($html);
        $title = js_string(js_get($p, 'title') ?? '') . ' | ' . $owner;
        $sum = js_get($p, 'summary');
        $desc = app_clip_desc(js_truthy($sum) ? app_no_emoji(js_string($sum)) : app_no_emoji(js_get($p, 'title')) . ': a post by ' . $owner . '.');
        $html = shell_set_head($html, $title, $SITE . '/blog/' . encode_uri_component($slug) . '/', $desc, $draft ? 'noindex' : 'index, follow, max-image-preview:large');
        $gp = $gPosts[$slug] ?? null;
        if (!$draft && $gp !== null) {
            $html = $g->postHead(seo_inject_ld($html, '', $g->postGraph($gp, $slug)), $gp);
        } else {
            $html = seo_inject_ld($html, $files['blog/index.html'] ?? '');
        }
        $files[$rel] = $g->fixCreator($html);
    }
    // Prerendered posts that are no longer published or drafts: not served (real 404).
    foreach (array_keys($files) as $rel) {
        if (preg_match('#^blog/([^/]+)/index\.html$#', $rel, $m) && !isset($want[$m[1]])) unset($files[$rel]);
    }
    // Pages written here (not the build's snapshots) carry the blog data React needs for its first
    // render (App.tsx reads #seo-live), so the app does not first draw the build's older posts.
    $live = str_replace('<', '\\u003c', js_json(['blog' => $content['blog'] ?? [], 'headings' => $content['headings'] ?? new stdClass(), 'identity' => $content['identity'] ?? new stdClass(), 'footer' => $content['footer'] ?? new stdClass()]));
    foreach ($files as $rel => $html) {
        if (strncmp($rel, 'blog/', 5) !== 0 || isset($g->files[$rel]) && $g->files[$rel] === $html) continue;
        $files[$rel] = SeoGen::replaceFirst($html, '</head>', '<script type="application/json" id="seo-live">' . $live . "</script>\n</head>");
    }
    return $files;
}
/** Put the JSON-LD into a pristine shell: from a graph, or copied from an already rendered page. */
function seo_inject_ld(string $html, string $from, ?array $graph = null): string
{
    $tag = $graph !== null ? SeoGen::ldScript($graph) : (preg_match('#<script type="application/ld\+json">.*?</script>#s', $from, $m) ? $m[0] : '');
    if ($tag === '') return $html;
    if (strpos($html, '<!--SEO-GRAPH-->') !== false) return SeoGen::replaceFirst($html, '<!--SEO-GRAPH-->', $tag);
    $r = preg_replace_callback('#<script type="application/ld\+json">.*?</script>#s', fn() => $tag, $html, 1);
    return is_string($r) ? $r : $html;
}

/** Content + render for the current database state. Throws on database errors or invalid content. */
function seo_render_live(array $build, array $state, ?PDO $db = null): array
{
    $db = $db ?? seo_db();
    $content = seo_drop_blocked(seo_content_from_db($db, $build));
    $env = ['docroot' => seo_docroot(), 'year' => gmdate('Y'), 'itemDates' => seo_item_dates($db), 'sectionDates' => $state['dates'] ?? []];
    $g = seo_render($content, $build, $env);
    if ($g->errors) throw new SeoInvalid(implode('; ', $g->errors));
    $env['homeBody'] = seo_home_body($db, $state, $build);
    $files = seo_live_files($g, $content, $build, $env);
    return ['gen' => $g, 'files' => $files, 'moved' => (object)seo_moved($db)];
}
final class SeoInvalid extends RuntimeException {}

/** Render and store a version. Returns its index, or null when another request is rendering it. */
function seo_build_version(array $build, array $state, bool $wait = true): ?array
{
    $key = seo_version_key($state, $build);
    if ($ix = seo_version_index($key)) return $ix;
    $lock = @fopen(seo_cache_dir() . '/render.lock', 'c');
    if ($lock === false) throw new RuntimeException('cannot open render.lock');
    $got = flock($lock, LOCK_EX | LOCK_NB);
    if (!$got) {
        if (!$wait) { fclose($lock); return null; }
        for ($t = 0; $t < 30 && !$got; $t++) { usleep(100000); $got = flock($lock, LOCK_EX | LOCK_NB); }
        if (!$got) { fclose($lock); return seo_version_index($key); }
    }
    try {
        if ($ix = seo_version_index($key)) return $ix; // rendered while we waited
        $t0 = microtime(true);
        $r = seo_render_live($build, $state);
        $dir = seo_vdir($key);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) throw new RuntimeException('cannot create ' . $dir);
        $index = [];
        foreach ($r['files'] as $rel => $bytes) {
            if (!seo_atomic_write($dir . '/' . sha1($rel) . '.bin', $bytes)) throw new RuntimeException('cannot write cache file');
            $index[$rel] = 's-' . substr(sha1($bytes), 0, 20);
        }
        // Last-Modified is when this version was made: anything that makes a new version (a save, a
        // deleted row, new metrics, a new build or year) moves it, so If-Modified-Since is safe.
        $lastMod = time();
        $ix = ['files' => $index, 'moved' => $r['moved'], 'facts' => $r['gen']->facts, 'warnings' => $r['gen']->warnings, 'builtAt' => time(), 'lastMod' => $lastMod, 'ms' => (int)round((microtime(true) - $t0) * 1000)];
        seo_atomic_write($dir . '/index.json', js_json($ix));
        seo_atomic_write($dir . '/.complete', '');
        @touch($dir);
        seo_gc($key);
        return $ix;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}
/** Keep the current version and the newest other complete one. */
function seo_gc(string $current): void
{
    $dirs = glob(seo_cache_dir() . '/v-*', GLOB_ONLYDIR) ?: [];
    usort($dirs, fn($a, $b) => filemtime($b) <=> filemtime($a));
    $kept = 0;
    foreach ($dirs as $d) {
        $k = substr(basename($d), 2);
        if ($k === $current) continue;
        if ($kept < 1 && is_file($d . '/.complete')) { $kept++; continue; }
        foreach (array_merge(glob($d . '/*') ?: [], glob($d . '/.*') ?: []) as $f) if (is_file($f)) @unlink($f);
        @rmdir($d);
    }
}
function seo_body(string $key, string $rel): ?string
{
    $f = seo_vdir($key) . '/' . sha1($rel) . '.bin';
    $b = @file_get_contents($f);
    return is_string($b) ? $b : null;
}

// ---------------------------------------------------------------- admin hook
/**
 * After every content write in the admin: record item change dates (papers, blog posts), refresh the
 * version, render it, and rewrite the two files Apache serves without PHP (404.html, maintenance.html,
 * keeping the owner's maintenance message). Never throws; returns a status for the admin.
 * $old / $new: the section's JSON before and after the write (for the item dates).
 */
function seo_after_write(?string $section, ?string $old = null, ?string $new = null): array
{
    $out = ['ok' => false];
    try {
        $docroot = seo_docroot();
        // Item dates and moved URLs first: they need only the database, so they are kept even when
        // the build is missing or the render below fails.
        if ($section === 'publications' || $section === 'blog') {
            try { seo_track_items(seo_db(), $section, $old, $new); } catch (Throwable $e) { seo_log('track_items(' . $section . '): ' . $e->getMessage()); }
        }
        $build = seo_build($docroot, $why);
        if (!$build) return ['ok' => false, 'error' => $why];
        if (seo_cache_dir() === '') return ['ok' => false, 'error' => 'no writable cache folder (api/cache/seo)'];
        // The new state is written only once its version is rendered, so public requests keep getting
        // the previous version (a cache hit) until the new one is complete.
        $state = seo_state(true, false);
        if (!$state || !empty($state['dbError'])) throw new RuntimeException('database unavailable');
        $key0 = seo_version_key($state, $build);
        try { $ix = seo_build_version($build, $state); }
        catch (Throwable $e) { seo_render_failed($key0); seo_state_write($state); throw $e; }
        seo_state_write($state);
        if (!$ix) throw new RuntimeException('render busy');
        $key = seo_version_key($state, $build);
        $out = ['ok' => true, 'version' => $key, 'ms' => $ix['ms'] ?? null, 'warnings' => $ix['warnings'] ?? []];
        if (is_writable($docroot)) {
            $nf = seo_body($key, '404.html');
            if ($nf !== null && @file_get_contents($docroot . '/404.html') !== $nf) seo_atomic_write($docroot . '/404.html', $nf);
            $mt = seo_body($key, 'maintenance.html');
            if ($mt !== null) {
                $cur = (string)@file_get_contents($docroot . '/maintenance.html');
                foreach (['MAINT-MESSAGE', 'MAINT-UNTIL'] as $r) {
                    if (preg_match('#<!--' . $r . '-->(.*?)<!--/' . $r . '-->#s', $cur, $m)) {
                        $mt = preg_replace_callback('#<!--' . $r . '-->.*?<!--/' . $r . '-->#s', fn() => $m[0], $mt, 1) ?? $mt;
                    }
                }
                if ($cur !== $mt) seo_atomic_write($docroot . '/maintenance.html', $mt);
            }
        } else $out['warnings'][] = 'The web root is not writable, so 404.html and maintenance.html keep the build version.';
    } catch (Throwable $e) {
        seo_log('after_write(' . ($section ?? '-') . '): ' . $e->getMessage());
        $out = ['ok' => false, 'error' => $e->getMessage()];
    }
    return $out;
}
/** Item change dates in the private row _private_seo: a new or changed paper/post gets today's date. */
function seo_track_items(PDO $db, string $section, ?string $old, ?string $new): void
{
    $pfx = $section === 'publications' ? 'pub:' : 'blog:';
    $keyOf = $section === 'publications'
        ? fn($p) => str(js_get($p, 'slug')) !== '' ? str(js_get($p, 'slug')) : SeoGen::slugify(js_get($p, 'title'))
        : fn($p) => app_eslug($p);
    // [key => hash] plus, per position, [key, identity] (DOI, arXiv id or PDF: what stays when a slug changes)
    $read = function (?string $json) use ($keyOf, $section) {
        $out = []; $rows = [];
        foreach (SeoGen::items($json !== null ? json_decode($json, true) : []) as $p) {
            if (!is_array($p)) { $rows[] = ['', '']; continue; }
            $k = $keyOf($p);
            $id = '';
            if ($section === 'publications') foreach (['doi', 'arxiv', 'pdf'] as $f) { $v = strtolower(str(js_get($p, $f))); if ($v !== '') { $id = $f . ':' . $v; break; } }
            $rows[] = [$k, $id];
            if ($k !== '' && !isset($out[$k])) $out[$k] = sha1(json_encode(seo_ksort_deep($p)));
        }
        return [$out, $rows];
    };
    [$before, $oldRows] = $read($old); [$after, $newRows] = $read($new);
    $st = $db->prepare("SELECT data FROM content WHERE section = ?");
    $st->execute([SEO_PRIVATE_ROW]);
    $row = $st->fetch();
    $d = $row ? json_decode((string)$row['data'], true) : null;
    $items = is_array($d['items'] ?? null) ? $d['items'] : [];
    $moved = is_array($d['moved'] ?? null) ? $d['moved'] : [];
    $today = gmdate('Y-m-d');
    foreach ($items as $k => $v) if (strpos((string)$k, $pfx) === 0 && !isset($after[substr((string)$k, strlen($pfx))])) unset($items[$k]);
    foreach ($after as $k => $h) {
        $prev = $items[$pfx . $k] ?? null;
        $was = $before[$k] ?? null;
        if ($was === null || $was !== $h) $items[$pfx . $k] = ['h' => $h, 'd' => $today];
        elseif (!$prev) $items[$pfx . $k] = ['h' => $h, 'd' => ''];
    }
    // Moved pages: an item whose slug changed (same DOI/arXiv/PDF, or the same place in an equally
    // long list) keeps its old address as a permanent redirect to the new one (serve.php answers 301).
    $gone = array_filter($oldRows, fn($r) => $r[0] !== '' && !isset($after[$r[0]]));
    $fresh = array_filter($newRows, fn($r) => $r[0] !== '' && !isset($before[$r[0]]));
    foreach ($gone as $i => [$ok, $oid]) {
        $to = null;
        if ($oid !== '') foreach ($fresh as $r) if ($r[1] === $oid) { $to = $r[0]; break; }
        if ($to === null && count($oldRows) === count($newRows) && isset($fresh[$i])) $to = $fresh[$i][0];
        if ($to !== null && $to !== $ok) $moved[$pfx . $ok] = $to;
    }
    foreach ($moved as $k => $to) {
        if (strpos((string)$k, $pfx) !== 0) continue;
        if (isset($after[substr((string)$k, strlen($pfx))])) { unset($moved[$k]); continue; } // the old address is used again
        for ($n = 0; $n < 10 && isset($moved[$pfx . $to]); $n++) $to = $moved[$pfx . $to];          // a -> b -> c becomes a -> c
        if (!isset($after[$to]) || $to === substr((string)$k, strlen($pfx))) unset($moved[$k]); else $moved[$k] = $to;
    }
    if (count($moved) > 300) $moved = array_slice($moved, -300, null, true);
    $out = ['items' => (object)$items];
    if ($moved) $out['moved'] = (object)$moved;
    $db->prepare("INSERT INTO content (section,data) VALUES (?,?) ON DUPLICATE KEY UPDATE data=VALUES(data)")
        ->execute([SEO_PRIVATE_ROW, json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]);
}
/** Old address -> new slug for papers ("pub:<old>") and posts ("blog:<old>") whose slug changed. */
function seo_moved(PDO $db): array
{
    $st = $db->prepare("SELECT data FROM content WHERE section = ?");
    $st->execute([SEO_PRIVATE_ROW]);
    $r = $st->fetch();
    $d = $r ? json_decode((string)$r['data'], true) : null;
    $out = [];
    foreach ((is_array($d['moved'] ?? null) ? $d['moved'] : []) as $k => $v) if (is_string($v) && $v !== '') $out[(string)$k] = $v;
    return $out;
}
/** Same values in another key order (the admin re-saves items in its schema order) hash the same. */
function seo_ksort_deep($v)
{
    if (!is_array($v)) return $v;
    if (!array_is_list($v)) ksort($v, SORT_STRING);
    foreach ($v as $k => $x) $v[$k] = seo_ksort_deep($x);
    return $v;
}
/** Validation of a section about to be saved: what would fail the build's --check. */
function seo_validate_save(PDO $db, string $section, $data): array
{
    $new = json_decode(json_encode($data), true);
    // The do-not-publish list first: it needs neither the build nor the renderer.
    $errors = $section === 'publications' ? seo_blocked_errors($new) : [];
    $warnings = $section === 'blog' ? seo_blog_warnings($new) : [];
    $build = seo_build(seo_docroot(), $why);
    $shared = $build['shared'] ?? null;
    if (!$shared) return ['errors' => $errors, 'warnings' => $warnings];
    $content = seo_content_from_db($db, $build);
    $content[$section] = $new;
    $g = (new SeoGen($content, $shared, ['docroot' => seo_docroot(), 'check' => true, 'shells' => []]))->run();
    return ['errors' => array_merge($errors, $g->errors), 'warnings' => array_values(array_unique(array_merge($warnings, $g->warnings)))];
}
/** "publication #3 is on the do-not-publish list" for every blocked title in a publications section. */
function seo_blocked_errors($pubs): array
{
    $errors = [];
    foreach (SeoGen::items($pubs) as $i => $p) {
        if (is_array($p) && seo_title_blocked($p['title'] ?? '')) $errors[] = 'publication #' . ($i + 1) . ' is on the do-not-publish list (config.php seo_block_title_sha256)';
    }
    return $errors;
}
/** Hints for blog posts: a summary is the post's search and link-preview description. */
function seo_blog_warnings($blog): array
{
    $w = [];
    foreach (SeoGen::items($blog) as $p) {
        if (!is_array($p) || app_is_draft($p)) continue;
        $t = trim((string)(is_string($p['title'] ?? null) ? $p['title'] : ''));
        $sum = trim((string)(is_string($p['summary'] ?? null) ? $p['summary'] : ''));
        $n = function_exists('mb_strlen') ? mb_strlen($sum, 'UTF-8') : strlen($sum);
        if ($sum === '') $w[] = 'Blog post "' . $t . '" has no summary: search results and link previews will show "' . $t . ': a post by …". A summary of 70-160 characters works best.';
        elseif ($n < 50) $w[] = 'Blog post "' . $t . '" has a short summary (' . $n . ' characters): it is also the description in search results and link previews, where 70-160 characters work best.';
    }
    return $w;
}
/** Status for the admin panel. */
function seo_status(): array
{
    $docroot = seo_docroot();
    $build = seo_build($docroot, $why);
    $s = seo_state_read() ?? ($build && seo_cache_dir() !== '' ? seo_state() : null);
    $key = ($build && $s) ? seo_version_key($s, $build) : '';
    $ix = $key !== '' ? seo_version_index($key) : null;
    $log = seo_cache_dir() !== '' ? (string)@file_get_contents(seo_cache_dir() . '/errors.log') : '';
    return [
        'php' => PHP_VERSION, 'phpOk' => PHP_VERSION_ID >= 80100, 'cacheOk' => seo_cache_dir() !== '',
        'home' => seo_home_status($build, $s),
        'serveOn' => is_file($docroot . '/api/seo/serve.php') && !is_file($docroot . '/.seo-static'),
        'staticSwitch' => is_file($docroot . '/.seo-static'),
        'build' => $build ? ['builtAt' => $build['manifest']['builtAt'] ?? '', 'ok' => true] : ['ok' => false, 'error' => $why],
        'version' => $key, 'fp' => $s ? substr((string)$s['fp'], 0, 10) : '', 'checkedAt' => $s['checkedAt'] ?? null, 'dbError' => !empty($s['dbError']),
        'cacheBuiltAt' => $ix['builtAt'] ?? null, 'renderMs' => $ix['ms'] ?? null, 'files' => $ix ? count($ix['files']) : 0,
        'warnings' => $ix['warnings'] ?? [], 'errors' => array_slice(array_filter(explode("\n", $log)), -8),
        'writable' => is_writable($docroot),
    ];
}

// ---------------------------------------------------------------- homepage snapshot
/**
 * The homepage <main> is React's rendering; PHP cannot reproduce it. So after a save the admin page
 * loads "/" in a hidden frame (React renders the new content there, as the build's prerender does)
 * and posts the rendered #root back (admin/index.php?action=seo_home_snapshot). It is stored with the
 * key of the content and build it was made from, and used for exactly that state: any later change
 * falls back to the build's body until the next snapshot (the admin makes one after every save).
 */
function seo_home_key(array $state, array $build): string
{
    return substr(sha1('home|' . ($state['cfp'] ?? '') . '|' . ($build['manifest']['hash'] ?? '') . '|' . SEO_LIB_VERSION . '|' . gmdate('Y')), 0, 20);
}
function seo_home_row(PDO $db): ?array
{
    $st = $db->prepare("SELECT data, UNIX_TIMESTAMP(updated_at) AS t FROM content WHERE section = ?");
    $st->execute([SEO_HOME_ROW]);
    $r = $st->fetch();
    $d = $r ? json_decode((string)$r['data'], true) : null;
    return is_array($d) && is_string($d['key'] ?? null) && is_string($d['html'] ?? null) ? $d + ['t' => (int)$r['t']] : null;
}
function seo_home_body(PDO $db, array $state, array $build): ?string
{
    if (empty($state['cfp'])) return null;
    $r = seo_home_row($db);
    return $r && hash_equals($r['key'], seo_home_key($state, $build)) && seo_home_check($r['html']) === null ? $r['html'] : null;
}
/** Why a posted #root body is refused, or null. It must be the homepage as React renders it: no scripts, frames or handlers. */
function seo_home_check(string $html): ?string
{
    if ($html === '' || strlen($html) > SEO_HOME_MAX) return 'empty or too large';
    if (preg_match('//u', $html) !== 1) return 'not UTF-8';
    if (strpos($html, '<main id="main"') === false || strpos($html, 'id="hero-name"') === false || strpos($html, 'class="hero-photo') === false) return 'not the homepage (maintenance page or an error?)';
    if (preg_match('#<\s*/?\s*(script|iframe|frame|object|embed|base|meta|link|style|template|noscript|applet)\b#i', $html)) return 'contains a script, frame or head element';
    // Inside tags only (text is escaped: a "<" in text is "&lt;"); attribute values are double-quoted.
    if (preg_match('#<[^>]*[\s/"\'](on[a-z]+|srcdoc|formaction|action)\s*=#i', $html)) return 'contains an event handler or form target';
    // The browser's serializer writes only &amp; &lt; &gt; &quot; &nbsp;: any other character reference could hide a URL.
    if (preg_match('/&(?!(amp|lt|gt|quot|nbsp);)[#a-z0-9]+;/i', $html)) return 'contains an unexpected character reference';
    if (preg_match('#=\s*"\s*(javascript|vbscript|data\s*:\s*text/html)#i', str_replace(["\t", "\n", "\r"], '', $html))) return 'contains a script URL';
    return null;
}
/** Store a snapshot posted by the admin for $key (the key the save answered with). Returns an error or null. */
function seo_home_store(PDO $db, string $key, string $html): ?string
{
    $build = seo_build(seo_docroot(), $why);
    if (!$build) return 'no website build: ' . $why;
    $state = seo_state(true);
    if (!$state || !empty($state['dbError'])) return 'database unavailable';
    if (!hash_equals(seo_home_key($state, $build), $key)) return 'the content changed meanwhile (a newer save makes a new snapshot)';
    $html = preg_replace('/ aria-current="location"/', '', $html) ?? $html; // a visitor lands on the hero (as in the prerender)
    if (($e = seo_home_check($html)) !== null) return $e;
    $cur = seo_home_row($db);
    if ($cur && $cur['key'] === $key && $cur['html'] === $html) return null; // unchanged: keep the version
    $db->prepare("INSERT INTO content (section,data) VALUES (?,?) ON DUPLICATE KEY UPDATE data=VALUES(data)")
        ->execute([SEO_HOME_ROW, json_encode(['key' => $key, 'html' => $html, 'at' => gmdate('c')], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]);
    return null;
}
/** For the admin panel: is the homepage body for readers without JavaScript the current one? */
function seo_home_status(?array $build, ?array $state): array
{
    try {
        if (!$build || !$state || empty($state['cfp'])) return ['state' => 'unknown', 'key' => ''];
        $key = seo_home_key($state, $build);
        $r = seo_home_row(seo_db());
        if (!$r) return ['state' => 'build', 'key' => $key];
        return ['state' => hash_equals($r['key'], $key) ? 'current' : 'stale', 'key' => $key, 'at' => $r['at'] ?? null];
    } catch (Throwable $e) { return ['state' => 'unknown', 'key' => '', 'error' => $e->getMessage()]; }
}

