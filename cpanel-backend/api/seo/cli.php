<?php
/**
 * Command-line entry for the live SEO renderer (never served: api/seo/.htaccess denies it, and it
 * exits unless run by the PHP CLI).
 *
 *   php cli.php export --content DIR --docroot DIR --out DIR [--year YYYY] [--live]
 *       Render every file from a content folder (same shape as src/content) into DIR.
 *       Without --live the output is what scripts/generate-seo.mjs writes (parity harness);
 *       --live adds the live-only pages (blog posts added after the build, updated blog list).
 *   php cli.php helpers --in FILE         JS-semantics helpers on a JSON list of strings (parity step A)
 *   php cli.php validate --content DIR --docroot DIR
 *   php cli.php dump-content --out DIR    Database content -> DIR/<section>.json
 *   php cli.php warm                      Rebuild the page cache from the database now
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/lib/build.php';
require_once __DIR__ . '/lib/live.php';

$argv = $_SERVER['argv'];
$cmd = $argv[1] ?? '';
$opt = [];
for ($i = 2; $i < count($argv); $i++) {
    if (substr($argv[$i], 0, 2) !== '--') continue;
    $k = substr($argv[$i], 2);
    $opt[$k] = (isset($argv[$i + 1]) && substr($argv[$i + 1], 0, 2) !== '--') ? $argv[++$i] : true;
}
$fail = function (string $m) { fwrite(STDERR, $m . "\n"); exit(1); };

if ($cmd === 'export' || $cmd === 'validate') {
    $docroot = (string)($opt['docroot'] ?? $fail('--docroot is required'));
    $build = seo_build($docroot, $why) ?? $fail('build: ' . $why);
    $content = seo_content_from_dir((string)($opt['content'] ?? ($docroot . '/_seo/content')));
    $env = ['docroot' => $docroot, 'year' => (string)($opt['year'] ?? gmdate('Y')), 'check' => $cmd === 'validate'];
    $g = seo_render($content, $build, $env);
    foreach ($g->warnings as $w) fwrite(STDERR, "  [seo] warn: $w\n");
    if ($g->errors) { fwrite(STDERR, "SEO content validation failed:\n - " . implode("\n - ", $g->errors) . "\n"); exit(1); }
    if ($cmd === 'validate') { echo 'content OK: ' . count($g->facts['papers']) . " publications\n"; exit(0); }
    $out = rtrim((string)($opt['out'] ?? $fail('--out is required')), '/');
    $files = $g->files;
    if (!empty($opt['live'])) $files = seo_live_files($g, $content, $build, $env);
    foreach ($files as $rel => $bytes) {
        $f = $out . '/' . $rel;
        if (!is_dir(dirname($f))) mkdir(dirname($f), 0777, true);
        file_put_contents($f, $bytes);
    }
    echo count($files) . " files\n";
    exit(0);
}
if ($cmd === 'helpers') {
    $in = json_decode((string)file_get_contents((string)($opt['in'] ?? 'php://stdin')), true);
    $out = [];
    foreach ($in as $s) {
        $s = (string)$s;
        $dec = null; try { $dec = decode_uri($s); } catch (Throwable $e) { $dec = 'URIError'; }
        $out[] = [
            'esc' => SeoGen::esc($s), 'slugify' => SeoGen::slugify($s), 'stripEmoji' => SeoGen::stripEmoji($s), 'clip158' => SeoGen::clip($s, 158), 'clip20' => SeoGen::clip($s, 20),
            'firstSentence' => SeoGen::firstSentence($s), 'encodeURI' => encode_uri($s), 'encodeURIComponent' => encode_uri_component($s), 'decodeURI' => $dec,
            'ldScript' => SeoGen::ldScript(['x' => $s]), 'isoDay' => SeoGen::isoDay($s), 'len' => js_len($s), 'trim' => js_trim($s), 'lower' => js_lower($s), 'isOwner' => SeoGen::isOwner($s),
        ];
    }
    echo js_json($out);
    exit(0);
}
if ($cmd === 'dump-content') {
    $out = rtrim((string)($opt['out'] ?? $fail('--out is required')), '/');
    if (!is_dir($out)) mkdir($out, 0777, true);
    $c = seo_content_from_db(seo_db());
    foreach ($c as $s => $v) file_put_contents("$out/$s.json", json_encode($v, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    echo count($c) . " sections\n";
    exit(0);
}
if ($cmd === 'warm') {
    $r = seo_after_write(null);
    echo js_json($r) . "\n";
    exit(empty($r['ok']) ? 1 : 0);
}
$fail('usage: php cli.php export|validate|helpers|dump-content|warm [options]');
