<?php
/**
 * JavaScript semantics for the PHP port of palash-portfolio/scripts/generate-seo.mjs.
 *
 * The renderer must give the same bytes as the Node generator (scripts/seo-parity.mjs checks
 * this). PHP and JS differ in small ways that change output; each helper here follows the JS rule:
 *   - String(v) / .trim() / toLowerCase()      -> js_str, js_trim, js_lower
 *   - \s and . in regexes (Unicode in JS)       -> jre() rewrites patterns (JS_WS class, no $ before a final \n)
 *   - .length / slice / lastIndexOf (UTF-16)    -> js_len, js_slice, js_last_index_of
 *   - truthiness ("0" is truthy in JS)          -> js_truthy, js_or
 *   - JSON.stringify, encodeURI(Component), decodeURI, localeCompare
 */
declare(strict_types=1);

// PHP 8.0 has no array_is_list() (added in 8.1); every render needs it.
if (!function_exists('array_is_list')) {
    function array_is_list(array $a): bool { $i = 0; foreach ($a as $k => $_) { if ($k !== $i++) return false; } return true; }
}

// JS /\p{Extended_Pictographic}/u as a character-class body (generated with Node 22, Unicode 17).
// Spelled out because the PCRE2 in PHP 8.0/8.1 builds (and some cPanel builds of later PHP) does not
// know that property; the same ranges everywhere also keep the output identical to Node's.
const JS_EXT_PICT = '\x{A9}\x{AE}\x{203C}\x{2049}\x{2122}\x{2139}\x{2194}-\x{2199}\x{21A9}-\x{21AA}\x{231A}-\x{231B}\x{2328}\x{23CF}\x{23E9}-\x{23F3}\x{23F8}-\x{23FA}\x{24C2}\x{25AA}-\x{25AB}\x{25B6}\x{25C0}\x{25FB}-\x{25FE}\x{2600}-\x{2604}\x{260E}\x{2611}\x{2614}-\x{2615}\x{2618}\x{261D}\x{2620}\x{2622}-\x{2623}\x{2626}\x{262A}\x{262E}-\x{262F}\x{2638}-\x{263A}\x{2640}\x{2642}\x{2648}-\x{2653}\x{265F}-\x{2660}\x{2663}\x{2665}-\x{2666}\x{2668}\x{267B}\x{267E}-\x{267F}\x{2692}-\x{2697}\x{2699}\x{269B}-\x{269C}\x{26A0}-\x{26A1}\x{26A7}\x{26AA}-\x{26AB}\x{26B0}-\x{26B1}\x{26BD}-\x{26BE}\x{26C4}-\x{26C5}\x{26C8}\x{26CE}-\x{26CF}\x{26D1}\x{26D3}-\x{26D4}\x{26E9}-\x{26EA}\x{26F0}-\x{26F5}\x{26F7}-\x{26FA}\x{26FD}\x{2702}\x{2705}\x{2708}-\x{270D}\x{270F}\x{2712}\x{2714}\x{2716}\x{271D}\x{2721}\x{2728}\x{2733}-\x{2734}\x{2744}\x{2747}\x{274C}\x{274E}\x{2753}-\x{2755}\x{2757}\x{2763}-\x{2764}\x{2795}-\x{2797}\x{27A1}\x{27B0}\x{27BF}\x{2934}-\x{2935}\x{2B05}-\x{2B07}\x{2B1B}-\x{2B1C}\x{2B50}\x{2B55}\x{3030}\x{303D}\x{3297}\x{3299}\x{1F004}\x{1F02C}-\x{1F02F}\x{1F094}-\x{1F09F}\x{1F0AF}-\x{1F0B0}\x{1F0C0}\x{1F0CF}-\x{1F0D0}\x{1F0F6}-\x{1F0FF}\x{1F170}-\x{1F171}\x{1F17E}-\x{1F17F}\x{1F18E}\x{1F191}-\x{1F19A}\x{1F1AE}-\x{1F1E5}\x{1F201}-\x{1F20F}\x{1F21A}\x{1F22F}\x{1F232}-\x{1F23A}\x{1F23C}-\x{1F23F}\x{1F249}-\x{1F25F}\x{1F266}-\x{1F321}\x{1F324}-\x{1F393}\x{1F396}-\x{1F397}\x{1F399}-\x{1F39B}\x{1F39E}-\x{1F3F0}\x{1F3F3}-\x{1F3F5}\x{1F3F7}-\x{1F3FA}\x{1F400}-\x{1F4FD}\x{1F4FF}-\x{1F53D}\x{1F549}-\x{1F54E}\x{1F550}-\x{1F567}\x{1F56F}-\x{1F570}\x{1F573}-\x{1F57A}\x{1F587}\x{1F58A}-\x{1F58D}\x{1F590}\x{1F595}-\x{1F596}\x{1F5A4}-\x{1F5A5}\x{1F5A8}\x{1F5B1}-\x{1F5B2}\x{1F5BC}\x{1F5C2}-\x{1F5C4}\x{1F5D1}-\x{1F5D3}\x{1F5DC}-\x{1F5DE}\x{1F5E1}\x{1F5E3}\x{1F5E8}\x{1F5EF}\x{1F5F3}\x{1F5FA}-\x{1F64F}\x{1F680}-\x{1F6C5}\x{1F6CB}-\x{1F6D2}\x{1F6D5}-\x{1F6E5}\x{1F6E9}\x{1F6EB}-\x{1F6F0}\x{1F6F3}-\x{1F6FF}\x{1F7DA}-\x{1F7FF}\x{1F80C}-\x{1F80F}\x{1F848}-\x{1F84F}\x{1F85A}-\x{1F85F}\x{1F888}-\x{1F88F}\x{1F8AE}-\x{1F8AF}\x{1F8BC}-\x{1F8BF}\x{1F8C2}-\x{1F8CF}\x{1F8D9}-\x{1F8FF}\x{1F90C}-\x{1F93A}\x{1F93C}-\x{1F945}\x{1F947}-\x{1F9FF}\x{1FA58}-\x{1FA5F}\x{1FA6E}-\x{1FAFF}\x{1FC00}-\x{1FFFD}';

// JS \s (ECMAScript WhiteSpace + LineTerminator), as the inside of a character class.
const JS_WS_IN = '\t\n\x0B\f\r \x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}';

/**
 * Rewrite a JS regex literal (as a PCRE pattern string with delimiters) so PCRE matches like JS:
 * \s / \S use the JS whitespace set, "." excludes all four JS line terminators, "$" never matches
 * before a final newline (D), and the subject is UTF-8 (u). \w \d \b stay ASCII, as in JS.
 */
function jre(string $re): string
{
    static $cache = [];
    if (isset($cache[$re])) return $cache[$re];
    $d = $re[0];
    $end = strrpos($re, $d);
    $body = substr($re, 1, $end - 1);
    $flags = substr($re, $end + 1);
    $out = '';
    $inClass = false;
    $n = strlen($body);
    for ($i = 0; $i < $n; $i++) {
        $c = $body[$i];
        if ($c === '\\' && $i + 1 < $n) {
            $nx = $body[$i + 1];
            if ($nx === 's') { $out .= $inClass ? JS_WS_IN : '[' . JS_WS_IN . ']'; $i++; continue; }
            if ($nx === 'S') { $out .= $inClass ? '\S' : '[^' . JS_WS_IN . ']'; $i++; continue; }
            $out .= $c . $nx; $i++; continue;
        }
        if ($inClass) { if ($c === ']') $inClass = false; $out .= $c; continue; }
        if ($c === '[') {
            $inClass = true; $out .= $c;
            // A "]" right after "[" or "[^" is a literal in PCRE but ends an empty class in JS; not used here.
            continue;
        }
        if ($c === '.') { $out .= '[^\n\r\x{2028}\x{2029}]'; continue; }
        $out .= $c;
    }
    foreach (['u', 'D'] as $f) if (strpos($flags, $f) === false) $flags .= $f;
    return $cache[$re] = $d . $out . $d . $flags;
}
function jtest(string $re, string $s): bool { return preg_match(jre($re), $s) === 1; }
/** First match (JS String.prototype.match without g): array of groups, unmatched groups as null. */
function jmatch(string $re, string $s): ?array
{
    if (preg_match(jre($re), $s, $m, PREG_UNMATCHED_AS_NULL) !== 1) return null;
    return $m;
}
/** JS str.replace(re, string|callable); $all mirrors the g flag. Replacement strings use PHP $1 syntax. */
function jreplace(string $re, $rep, string $s, bool $all = true): string
{
    $lim = $all ? -1 : 1;
    if (is_callable($rep) && !is_string($rep)) {
        $r = preg_replace_callback(jre($re), function ($m) use ($rep) { return (string)$rep($m); }, $s, $lim);
    } else {
        $r = preg_replace(jre($re), (string)$rep, $s, $lim);
    }
    if ($r === null) throw new RuntimeException('regex failed: ' . $re);
    return $r;
}
/** JS str.split(re) with a regex (no captures in the patterns used here). */
function jsplit(string $re, string $s): array
{
    $r = preg_split(jre($re), $s);
    if ($r === false) throw new RuntimeException('split failed: ' . $re);
    return $r;
}
/** JS [...str.matchAll(re)]: list of match arrays. */
function jmatch_all(string $re, string $s): array
{
    preg_match_all(jre($re), $s, $m, PREG_SET_ORDER);
    return $m;
}

// ---------------------------------------------------------------- values
/** JS Number -> String for the values content can hold. */
function js_num_str($v): string
{
    if (is_int($v)) return (string)$v;
    if (is_float($v)) {
        if (is_nan($v)) return 'NaN';
        if (is_infinite($v)) return $v > 0 ? 'Infinity' : '-Infinity';
        if ($v == floor($v) && abs($v) < 1e21) return number_format($v, 0, '.', '');
        $s = json_encode($v); // shortest round-trip form (serialize_precision -1), same as JS for normal numbers
        return is_string($s) ? $s : (string)$v;
    }
    return (string)$v;
}
/** String(v) (null/undefined -> "", as in the generator's str() before trim). */
function js_string($v): string
{
    if ($v === null) return '';
    if (is_bool($v)) return $v ? 'true' : 'false';
    if (is_int($v) || is_float($v)) return js_num_str($v);
    if (is_string($v)) return $v;
    if (is_array($v)) {
        if (array_is_list($v)) return implode(',', array_map(fn($x) => ($x === null ? '' : js_string($x)), $v));
        return '[object Object]';
    }
    return '';
}
/** generate-seo.mjs str(): String(v ?? "").trim() */
function str($v): string { return js_trim(js_string($v)); }
/** String(x ?? "") without trim (used where the generator writes String(t ?? "")). */
function js_sv($v): string { return js_string($v); }
function js_trim(string $s): string
{
    $r = preg_replace('/^[' . JS_WS_IN . ']+|[' . JS_WS_IN . ']+$/uD', '', $s);
    return $r ?? $s;
}
function js_trim_end(string $s): string { return preg_replace('/[' . JS_WS_IN . ']+$/uD', '', $s) ?? $s; }
function js_lower(string $s): string { return mb_strtolower($s, 'UTF-8'); }
/** JS truthiness: false for "", null, false, 0, NaN only (arrays and objects are truthy). */
function js_truthy($v): bool
{
    if ($v === null || $v === false || $v === '') return false;
    if (is_int($v)) return $v !== 0;
    if (is_float($v)) return !($v == 0.0 || is_nan($v));
    return true;
}
function js_or(...$vs) { foreach ($vs as $v) if (js_truthy($v)) return $v; return $vs ? $vs[count($vs) - 1] : null; }
function js_is_array($v): bool { return is_array($v) && array_is_list($v); }
function js_is_object($v): bool { return is_array($v) && (!array_is_list($v) || $v === []); }
function js_get($a, string $k) { return is_array($a) && array_key_exists($k, $a) ? $a[$k] : null; }

// ---------------------------------------------------------------- UTF-16 lengths
function js_u16(string $s): string { return mb_convert_encoding($s, 'UTF-16LE', 'UTF-8'); }
function js_from_u16(string $b): string { return mb_convert_encoding($b, 'UTF-8', 'UTF-16LE'); }
function js_len(string $s): int
{
    if (preg_match('/[^\x00-\x7F]/', $s) !== 1) return strlen($s);
    return intdiv(strlen(js_u16($s)), 2);
}
function js_slice(string $s, int $a, ?int $b = null): string
{
    if (preg_match('/[^\x00-\x7F]/', $s) !== 1) {
        $n = strlen($s);
        $b = $b ?? $n;
        if ($a < 0) $a = max(0, $n + $a);
        if ($b < 0) $b = max(0, $n + $b);
        $b = min($b, $n);
        return $b > $a ? substr($s, $a, $b - $a) : '';
    }
    $u = js_u16($s);
    $n = intdiv(strlen($u), 2);
    $b = $b ?? $n;
    if ($a < 0) $a = max(0, $n + $a);
    if ($b < 0) $b = max(0, $n + $b);
    $b = min($b, $n);
    return $b > $a ? js_from_u16(substr($u, 2 * $a, 2 * ($b - $a))) : '';
}
/** s.lastIndexOf(needle, from) in UTF-16 units. */
function js_last_index_of(string $s, string $needle, ?int $from = null): int
{
    $ascii = preg_match('/[^\x00-\x7F]/', $s . $needle) !== 1;
    $u = $ascii ? $s : js_u16($s);
    $nd = $ascii ? $needle : js_u16($needle);
    $w = $ascii ? 1 : 2;
    $n = intdiv(strlen($u), $w);
    $from = $from === null ? $n : max(0, min($from, $n));
    for ($i = $from; $i >= 0; $i--) {
        if (substr($u, $i * $w, strlen($nd)) === $nd) return $i;
    }
    return -1;
}
function js_starts_with(string $s, string $p): bool { return strncmp($s, $p, strlen($p)) === 0; }
function js_includes(string $s, string $p): bool { return $p === '' || strpos($s, $p) !== false; }

// ---------------------------------------------------------------- comparison
/** a.localeCompare(b) for the strings the generator sorts (dates, years, short text). */
function js_locale_compare(string $a, string $b): int
{
    if ($a === $b) return 0;
    $la = js_lower($a); $lb = js_lower($b);
    if ($la !== $lb) return strcmp($la, $lb) < 0 ? -1 : 1;
    // Same letters: ICU puts lower case first.
    return strcmp($b, $a) < 0 ? -1 : 1;
}

// ---------------------------------------------------------------- JSON / URLs
/** JSON.stringify (no indent). */
function js_json($v): string
{
    return json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS | JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION * 0);
}
function js_encode_component(string $s, string $keep): string
{
    $out = '';
    $n = strlen($s);
    for ($i = 0; $i < $n; $i++) {
        $c = $s[$i];
        $o = ord($c);
        if (($o >= 0x30 && $o <= 0x39) || ($o >= 0x41 && $o <= 0x5A) || ($o >= 0x61 && $o <= 0x7A) || strpos($keep, $c) !== false) $out .= $c;
        else $out .= '%' . strtoupper(str_pad(dechex($o), 2, '0', STR_PAD_LEFT));
    }
    return $out;
}
function encode_uri_component(string $s): string { return js_encode_component($s, "-_.!~*'()"); }
function encode_uri(string $s): string { return js_encode_component($s, "-_.!~*'();/?:@&=+\$,#"); }
/** decodeURI: reserved characters (;/?:@&=+$,#) stay escaped; throws on malformed input. */
function decode_uri(string $s): string
{
    $out = '';
    $n = strlen($s);
    for ($i = 0; $i < $n; $i++) {
        if ($s[$i] !== '%') { $out .= $s[$i]; continue; }
        if ($i + 2 >= $n || !ctype_xdigit(substr($s, $i + 1, 2))) throw new InvalidArgumentException('URIError');
        $b = hexdec(substr($s, $i + 1, 2));
        if ($b < 0x80) {
            $ch = chr($b);
            $out .= strpos(";/?:@&=+\$,#", $ch) !== false ? substr($s, $i, 3) : $ch;
            $i += 2;
            continue;
        }
        $len = $b >= 0xF0 && $b < 0xF8 ? 4 : ($b >= 0xE0 ? 3 : ($b >= 0xC2 && $b < 0xE0 ? 2 : 0));
        if ($len === 0) throw new InvalidArgumentException('URIError');
        $bytes = chr($b);
        for ($k = 1; $k < $len; $k++) {
            $j = $i + 3 * $k;
            if ($j + 2 > $n - 1) throw new InvalidArgumentException('URIError');
            if ($s[$j] !== '%' || !ctype_xdigit(substr($s, $j + 1, 2))) throw new InvalidArgumentException('URIError');
            $bytes .= chr(hexdec(substr($s, $j + 1, 2)));
        }
        if (!mb_check_encoding($bytes, 'UTF-8')) throw new InvalidArgumentException('URIError');
        $out .= $bytes;
        $i += 3 * $len - 1;
    }
    return $out;
}
function safe_decode(string $u): string { try { return decode_uri($u); } catch (Throwable $e) { return $u; } }

/** new URL(u).origin for http(s) URLs. */
function js_origin(string $u): string
{
    $p = parse_url($u);
    if (!is_array($p) || empty($p['host'])) throw new InvalidArgumentException('Invalid URL');
    $scheme = strtolower((string)($p['scheme'] ?? 'https'));
    $host = strtolower((string)$p['host']);
    if (function_exists('idn_to_ascii') && preg_match('/[^\x00-\x7F]/', $host)) { $h = idn_to_ascii($host); if (is_string($h)) $host = $h; }
    $port = isset($p['port']) ? (int)$p['port'] : null;
    if ($port !== null && !(($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443))) $host .= ':' . $port;
    return $scheme . '://' . $host;
}

/** [...new Set(list)] */
function js_uniq(array $list): array
{
    $out = [];
    foreach ($list as $x) if (!in_array($x, $out, true)) $out[] = $x;
    return $out;
}
/** list.filter(f) re-indexed */
function js_filter(array $list, callable $f): array { return array_values(array_filter($list, $f)); }
function js_find(array $list, callable $f) { foreach ($list as $x) if ($f($x)) return $x; return null; }
function js_some(array $list, callable $f): bool { foreach ($list as $x) if ($f($x)) return true; return false; }
function js_find_index(array $list, callable $f): int { foreach (array_values($list) as $i => $x) if ($f($x)) return $i; return -1; }
function js_index_of(array $list, $v): int { $i = array_search($v, array_values($list), true); return $i === false ? -1 : (int)$i; }
/** Stable sort, as Array.prototype.sort (decorated with the index, so it is stable on any PHP version). */
function js_sort(array $list, callable $cmp): array
{
    $d = [];
    foreach (array_values($list) as $i => $x) $d[] = [$i, $x];
    usort($d, function ($a, $b) use ($cmp) { $r = $cmp($a[1], $b[1]); return $r < 0 ? -1 : ($r > 0 ? 1 : $a[0] <=> $b[0]); });
    return array_map(fn($e) => $e[1], $d);
}

/**
 * A usable blog post slug: valid UTF-8, no / \ ? #, no control characters (C0 or U+007F), not "." or "..".
 * The same rule in gen.php, live.php, scripts/generate-seo.mjs and scripts/prerender.mjs, so a
 * post is either listed everywhere (sitemap, llms.txt, /blog/) and served, or nowhere.
 * preg_match with /u fails on invalid UTF-8, so no mbstring is needed.
 */
function seo_blog_slug_ok(string $slug): bool
{
    return $slug !== '.' && $slug !== '..' && preg_match('/^[^\/\\\\?#\x00-\x1f\x7f]+$/uD', $slug) === 1;
}
