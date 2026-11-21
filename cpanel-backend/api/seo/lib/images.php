<?php
/**
 * File probes for the live renderer: image sizes, PDF existence and WebP variant files.
 * Ports of imageSize() / localImageSize() in generate-seo.mjs and variantPath() in
 * scripts/image-variants-lib.mjs. Pure PHP (no GD or exif extension needed).
 *
 * Every path comes from content (an editor-entered URL), so it is resolved with realpath() and
 * must stay inside the document root, outside api/, admin/ and _seo/, and be a regular file.
 */
declare(strict_types=1);

const SEO_PROBE_MAX = 20 * 1024 * 1024;
/** An out-of-bounds read while parsing an image header (the image size is then unknown). */
final class SeoRangeError extends RuntimeException {}

/**
 * Site path ("/images/a b.jpg", already percent-decoded) -> absolute file path, or null.
 * $rel may carry ?query or #hash (stripped) and must start with a single "/".
 */
function seo_doc_file(string $docroot, string $rel, bool $decoded): ?string
{
    if ($docroot === '') return null;
    if (!$decoded) $rel = safe_decode($rel);
    $rel = preg_split('/[?#]/', $rel)[0];
    if ($rel === '' || $rel[0] !== '/' || strpos($rel, "\0") !== false) return null;
    static $roots = [];
    $root = $roots[$docroot] ?? ($roots[$docroot] = (string)realpath($docroot));
    if ($root === '') return null;
    $f = realpath($root . $rel);
    if ($f === false || strncmp($f, $root . '/', strlen($root) + 1) !== 0) return null;
    $inside = substr($f, strlen($root));
    if (preg_match('#^/(api|admin|_seo)(/|$)#', $inside)) return null;
    if (!is_file($f)) return null;
    $sz = @filesize($f);
    if ($sz === false || $sz > SEO_PROBE_MAX) return null;
    return $f;
}

/** Pixel size of an image file (cached per path + mtime + size for this request). */
function seo_image_size_file(string $f): ?array
{
    static $cache = [];
    $st = @stat($f);
    if ($st === false) return null;
    $k = $f . '|' . $st['mtime'] . '|' . $st['size'];
    if (array_key_exists($k, $cache)) return $cache[$k];
    $h = @fopen($f, 'rb');
    if ($h === false) return $cache[$k] = null;
    $head = (string)fread($h, 524288);
    $r = seo_image_size($head);
    if ($r === null && strlen($head) === 524288) { // header past the first 512 KB: read it all, as Node does
        $rest = stream_get_contents($h);
        $r = seo_image_size($head . (is_string($rest) ? $rest : ''));
    }
    fclose($h);
    return $cache[$k] = $r;
}

/** mirrors generate-seo.mjs imageSize(buf) */
function seo_image_size(string $b): ?array
{
    $n = strlen($b);
    if ($n < 30) return null;
    $u8 = fn(int $o) => ord($b[$o]);
    $u16be = function (int $o) use ($b, $n) { if ($o + 2 > $n || $o < 0) throw new SeoRangeError(); return unpack('n', $b, $o)[1]; };
    $u16le = function (int $o) use ($b, $n) { if ($o + 2 > $n || $o < 0) throw new SeoRangeError(); return unpack('v', $b, $o)[1]; };
    $u32be = function (int $o) use ($b, $n) { if ($o + 4 > $n || $o < 0) throw new SeoRangeError(); return unpack('N', $b, $o)[1]; };
    $u32le = function (int $o) use ($b, $n) { if ($o + 4 > $n || $o < 0) throw new SeoRangeError(); return unpack('V', $b, $o)[1]; };
    try {
        if ($u32be(0) === 0x89504e47) return ['w' => $u32be(16), 'h' => $u32be(20)];
        if (substr($b, 0, 3) === 'GIF') return ['w' => $u16le(6), 'h' => $u16le(8)];
        if (substr($b, 0, 4) === 'RIFF' && substr($b, 8, 4) === 'WEBP') {
            $t = substr($b, 12, 4);
            if ($t === 'VP8 ') return ['w' => $u16le(26) & 0x3fff, 'h' => $u16le(28) & 0x3fff];
            if ($t === 'VP8L') { $x = $u32le(21); return ['w' => ($x & 0x3fff) + 1, 'h' => (($x >> 14) & 0x3fff) + 1]; }
            if ($t === 'VP8X') {
                $w = $u8(24) | ($u8(25) << 8) | ($u8(26) << 16);
                $h = $u8(27) | ($u8(28) << 8) | ($u8(29) << 16);
                return ['w' => 1 + $w, 'h' => 1 + $h];
            }
            return null;
        }
    } catch (SeoRangeError $e) { return null; }
    if ($u8(0) !== 0xff || $u8(1) !== 0xd8) return null;
    $i = 2; $rotated = false;
    try {
        while ($i + 9 < $n) {
            if ($u8($i) !== 0xff) { $i++; continue; }
            $m = $u8($i + 1);
            if ($m === 0xff) { $i++; continue; }
            if ($m === 0xd8 || $m === 0x01 || ($m >= 0xd0 && $m <= 0xd7)) { $i += 2; continue; }
            $len = $u16be($i + 2);
            if ($m === 0xe1 && substr($b, $i + 4, 6) === "Exif\0\0") {
                try { // EXIF orientation 5-8: the browser shows the image rotated by 90 degrees
                    $t = $i + 10; $le = substr($b, $t, 2) === 'II';
                    $r16 = fn($o) => $le ? $u16le($o) : $u16be($o);
                    $r32 = fn($o) => $le ? $u32le($o) : $u32be($o);
                    $ifd = $t + $r32($t + 4);
                    for ($k = 0, $cnt = $r16($ifd); $k < $cnt; $k++) {
                        $e = $ifd + 2 + $k * 12;
                        if ($r16($e) === 0x0112) { $o = $r16($e + 8); $rotated = $o >= 5 && $o <= 8; break; }
                    }
                } catch (Throwable $e) { /* malformed EXIF: ignore */ }
            }
            if ($m >= 0xc0 && $m <= 0xcf && $m !== 0xc4 && $m !== 0xc8 && $m !== 0xcc) {
                $h = $u16be($i + 5); $w = $u16be($i + 7);
                return $rotated ? ['w' => $h, 'h' => $w] : ['w' => $w, 'h' => $h];
            }
            $i += 2 + $len;
        }
    } catch (SeoRangeError $e) { return null; }
    return null;
}

/** mirrors image-variants-lib.mjs variantPath(rel, w) */
function seo_variant_path(string $rel, int $w): string
{
    // path.posix.dirname / basename (PHP's basename() is locale-dependent for multibyte names)
    $t = rtrim($rel, '/');
    $cut = strrpos($t, '/');
    $dir = $cut === false ? '.' : ($cut === 0 ? '/' : substr($t, 0, $cut));
    $base = $cut === false ? $t : substr($t, $cut + 1);
    $base = preg_replace('/\.[^.]+$/u', '', $base) ?? $base;
    $base = preg_replace('/[^A-Za-z0-9_-]+/u', '-', $base) ?? $base;
    return $dir . '/_v/' . $base . '-' . $w . 'w.webp';
}
