<?php
/**
 * Build inputs for the live renderer: dist/_seo/ (manifest.json, shared.json, shells/, content/)
 * as uploaded with the website build, and content from a folder (CLI) or the database.
 */
declare(strict_types=1);
require_once __DIR__ . '/../VERSION.php';
require_once __DIR__ . '/gen.php';

/** Reads <docroot>/_seo/. Returns null when the build has no (compatible) _seo folder. */
function seo_build(string $docroot, ?string &$why = null): ?array
{
    static $memo = [];
    if (isset($memo[$docroot])) return $memo[$docroot];
    if (PHP_VERSION_ID < 70400) { $why = 'PHP ' . PHP_VERSION . ' is too old for the live pages (PHP 7.4 or newer; 8.1+ recommended)'; return null; }
    $missing = array_keys(array_filter(['mbstring' => 'mb_strtolower', 'ctype' => 'ctype_xdigit', 'json' => 'json_encode'], fn($f) => !function_exists($f)));
    if ($missing) { $why = 'PHP extension missing: ' . implode(', ', $missing) . ' (cPanel > Select PHP Version > Extensions)'; return null; }
    $dir = rtrim($docroot, '/') . '/_seo';
    $mf = $dir . '/manifest.json';
    if (!is_file($mf)) { $why = 'no _seo/manifest.json in the uploaded website build'; return null; }
    $man = json_decode((string)file_get_contents($mf), true);
    if (!is_array($man)) { $why = '_seo/manifest.json is not valid JSON'; return null; }
    if (($man['seoApi'] ?? null) !== SEO_API) { $why = 'website build and backend are from different releases (seoApi ' . json_encode($man['seoApi'] ?? null) . ', backend ' . SEO_API . ')'; return null; }
    $shared = json_decode((string)@file_get_contents($dir . '/shared.json'), true);
    if (!is_array($shared) || !isset($shared['CSS'], $shared['SITE'])) { $why = '_seo/shared.json missing or invalid'; return null; }
    $shells = [];
    $sh = $man['shells'] ?? [];
    $rels = [];
    if (!empty($sh['home'])) $rels[] = $sh['home'];
    if (!empty($sh['blogIndex'])) $rels[] = $sh['blogIndex'];
    foreach (($sh['blogPosts'] ?? []) as $r) $rels[] = $r;
    foreach ($rels as $r) {
        if (!is_string($r) || !preg_match('#^(index\.html|blog/(?:[^/]+/)?index\.html)$#', $r)) continue;
        $f = $dir . '/shells/' . $r;
        if (is_file($f)) $shells[$r] = (string)file_get_contents($f);
    }
    return $memo[$docroot] = ['dir' => $dir, 'manifest' => $man, 'shared' => $shared, 'shells' => $shells];
}

/** Content from a folder of <section>.json files (the build's src/content or _seo/content). */
function seo_content_from_dir(string $dir): array
{
    $out = [];
    foreach (glob(rtrim($dir, '/') . '/*.json') ?: [] as $f) {
        $v = json_decode((string)file_get_contents($f), true);
        if ($v === null && trim((string)file_get_contents($f)) !== 'null') continue; // unreadable: as Node's readJson fallback
        $out[basename($f, '.json')] = $v;
    }
    return $out;
}

/** Render everything. $env: docroot, year, itemDates, sectionDates. */
function seo_render(array $content, array $build, array $env): SeoGen
{
    $g = new SeoGen($content, $build['shared'], $env + [
        'visitUrl' => (string)($build['manifest']['visitUrl'] ?? ''),
        'shells' => $build['shells'],
    ]);
    return $g->run();
}
