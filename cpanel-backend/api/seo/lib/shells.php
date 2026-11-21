<?php
/**
 * Blog pages from live data, built on the prerendered blog shell (dist/_seo/shells/blog/index.html).
 *
 * The build prerenders /blog/ and each published post with Puppeteer (scripts/prerender.mjs). A post
 * published in the admin after that build has no snapshot; this file writes its page by porting the
 * React markup (BlogIndex / PostList / PostMeta / BlogPostView / renderMarkdown / mdInline / renderRich
 * in palash-portfolio/src/App.tsx) and the head that setPageHead() sets, then applies the same
 * JSON-LD and head edits as generate-seo.mjs (postGraph, postHead, fixCreator). React re-renders the
 * same markup when the app loads (createRoot), so the page does not change visually.
 *
 * Text is escaped the way the browser serializes a DOM snapshot (page.content()): text & < > and
 * attribute values & " (plus U+00A0 as &nbsp;), so a page written here matches a prerendered one.
 */
declare(strict_types=1);

const SEO_SITE_URL = 'https://palashroy.me';
const SEO_OWNER = 'Palash Ranjan Roy';

function dom_text(string $s): string { return strtr($s, ['&' => '&amp;', '<' => '&lt;', '>' => '&gt;', "\u{00A0}" => '&nbsp;']); }
function dom_attr(string $s): string { return strtr($s, ['&' => '&amp;', '"' => '&quot;', "\u{00A0}" => '&nbsp;']); }

/** App.tsx noEmoji() */
function app_no_emoji($s): string
{
    $t = $s === null ? '' : js_string($s);
    $t = preg_replace_callback('/[' . JS_EXT_PICT . '\x{1F1E6}-\x{1F1FF}\x{1F3FB}-\x{1F3FF}\x{FE0F}\x{200D}\x{20E3}]/u', function ($m) {
        return preg_match('/^[\x{00A9}\x{00AE}\x{2122}\x{203C}\x{2049}]$/u', $m[0]) ? $m[0] : '';
    }, $t) ?? $t;
    return js_trim(jreplace('/\s{2,}/', ' ', $t));
}
/** App.tsx slugify() */
function app_slugify($s): string
{
    $t = js_truthy($s) ? js_string($s) : '';
    $t = js_trim(js_lower($t));
    $t = preg_replace('/[^a-z0-9]+/', '-', $t) ?? '';
    return preg_replace('/^-+|-+$/D', '', $t) ?? '';
}
function app_eslug($p): string
{
    $s = js_get($p, 'slug');
    return (js_truthy($s) && js_trim(js_string($s)) !== '') ? js_trim(js_string($s)) : app_slugify(js_get($p, 'title'));
}
function app_is_draft($p): bool
{
    $d = js_lower(js_get($p, 'draft') === null ? '' : js_string(js_get($p, 'draft')));
    return js_get($p, 'draft') === true || $d === 'yes' || $d === 'true' || $d === '1';
}
function app_norm_url($u): ?string
{
    $t = js_trim(js_truthy($u) ? js_string($u) : '');
    if (jtest('/^(https?:\/\/|mailto:|tel:|\/(?![\/\\\\])|#)/i', $t)) return $t;
    if (jtest('/^[\w.-]+\.[a-z]{2,}(\/\S*)?$/i', $t)) return 'https://' . $t;
    return null;
}
function app_ext_attrs(string $u): string { return jtest('/^https?:\/\//i', $u) ? ' target="_blank" rel="noopener noreferrer"' : ''; }
function app_clip_desc(string $s, int $n = 158): string
{
    $t = js_trim(jreplace('/\s+/', ' ', $s));
    if (js_len($t) <= $n) return $t;
    return jreplace('/[,;:.]$/', '', js_slice($t, 0, js_last_index_of($t, ' ', $n - 1)), false) . '…';
}
/** App.tsx fmtDate(): "November 21, 2026" */
function app_fmt_date($d): string
{
    if (!js_truthy($d)) return '';
    $s = js_string($d);
    $M = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $s, $m)) {
        if (!checkdate((int)$m[2], (int)$m[3], (int)$m[1])) return $s;
        return $M[(int)$m[2] - 1] . ' ' . (int)$m[3] . ', ' . (int)$m[1];
    }
    $t = strtotime($s . (preg_match('/^\d{4}-\d{2}$/D', $s) ? '-01' : ''));
    if ($t === false) return $s;
    return $M[(int)gmdate('n', $t) - 1] . ' ' . gmdate('j', $t) . ', ' . gmdate('Y', $t);
}
function app_yt_id(string $u): ?string
{
    $m = jmatch('/(?:youtube\.com\/(?:watch\?v=|embed\/|shorts\/|live\/)|youtu\.be\/)([\w-]{11})/', $u);
    return $m ? $m[1] : null;
}

/** App.tsx renderRich(noEmoji(t)) */
function app_rich($text): string
{
    $s = app_no_emoji($text);
    if ($s === '') return '';
    $out = '';
    $last = 0;
    preg_match_all(jre('/\[([^\]]+)\]\(([^)\s]+)\)|\[([^\]]+)\]\[([^\]\s]+)\]|\*\*([^*]+)\*\*|\*([^*]+)\*/'), $s, $all, PREG_SET_ORDER | PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL);
    foreach ($all as $m) {
        $at = $m[0][1];
        if ($at > $last) $out .= dom_text(substr($s, $last, $at - $last));
        $label = $m[1][0] ?? ($m[3][0] ?? null);
        $raw = isset($m[1][0]) ? $m[2][0] : (isset($m[3][0]) ? $m[4][0] : null);
        if ($label !== null && $raw !== null) {
            $url = app_norm_url($raw);
            $out .= $url !== null ? '<a href="' . dom_attr($url) . '"' . app_ext_attrs($url) . '>' . dom_text($label) . '</a>' : dom_text($label);
        } elseif (isset($m[5][0])) $out .= '<strong>' . dom_text($m[5][0]) . '</strong>';
        elseif (isset($m[6][0])) $out .= '<em>' . dom_text($m[6][0]) . '</em>';
        $last = $at + strlen($m[0][0]);
    }
    if ($last < strlen($s)) $out .= dom_text(substr($s, $last));
    return $out;
}
/** App.tsx mdInline() */
function app_md_inline(string $s): string
{
    $out = '';
    $last = 0;
    preg_match_all(jre('/!\[([^\]]*)\]\(([^)\s]+)\)|\[([^\]]+)\]\(([^)\s]+)\)|\*\*([^*]+)\*\*|\*([^*]+)\*|`([^`]+)`/'), $s, $all, PREG_SET_ORDER | PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL);
    foreach ($all as $m) {
        $at = $m[0][1];
        if ($at > $last) $out .= dom_text(substr($s, $last, $at - $last));
        if (isset($m[1][0])) { $u = app_norm_url($m[2][0]); if ($u !== null) $out .= '<img src="' . dom_attr($u) . '" alt="' . dom_attr($m[1][0]) . '" class="inline-block max-w-full rounded" loading="lazy">'; }
        elseif (isset($m[3][0])) { $u = app_norm_url($m[4][0]); $out .= $u !== null ? '<a href="' . dom_attr($u) . '"' . app_ext_attrs($u) . '>' . dom_text($m[3][0]) . '</a>' : dom_text($m[3][0]); }
        elseif (isset($m[5][0])) $out .= '<strong>' . dom_text($m[5][0]) . '</strong>';
        elseif (isset($m[6][0])) $out .= '<em>' . dom_text($m[6][0]) . '</em>';
        elseif (isset($m[7][0])) $out .= '<code class="px-1 py-0.5 rounded bg-[var(--c-surface2)] text-[0.9em]">' . dom_text($m[7][0]) . '</code>';
        $last = $at + strlen($m[0][0]);
    }
    if ($last < strlen($s)) $out .= dom_text(substr($s, $last));
    return $out;
}
/** App.tsx renderMarkdown() */
function app_render_markdown($md): string
{
    $lines = explode("\n", str_replace("\r\n", "\n", js_truthy($md) ? js_string($md) : ''));
    $n = count($lines);
    $b = '';
    $i = 0;
    $list = function (array $items, bool $ordered) { $t = $ordered ? 'ol' : 'ul'; return "<$t class=\"" . ($ordered ? 'list-decimal' : 'list-disc') . ' pl-6 my-4 space-y-1">' . implode('', array_map(fn($it) => '<li>' . app_md_inline($it) . '</li>', $items)) . "</$t>"; };
    while ($i < $n) {
        $line = $lines[$i];
        if (js_trim($line) === '') { $i++; continue; }
        if (preg_match('/^```/', $line)) {
            $buf = []; $i++;
            while ($i < $n && !preg_match('/^```/', $lines[$i])) { $buf[] = $lines[$i]; $i++; }
            $i++;
            $b .= '<pre class="my-4 p-4 rounded overflow-x-auto bg-[var(--c-surface2)] border border-[var(--c-border)] text-sm"><code>' . dom_text(implode("\n", $buf)) . '</code></pre>';
            continue;
        }
        if ($hm = jmatch('/^(#{1,3})\s+(.*)$/', $line)) {
            $lvl = strlen($hm[1]);
            $cls = $lvl === 1 ? 'text-[26px] mt-10 mb-3' : ($lvl === 2 ? 'text-[22px] mt-8 mb-3' : 'text-[19px] mt-6 mb-2');
            $b .= '<h' . ($lvl + 1) . ' class="' . $cls . '">' . app_md_inline($hm[2]) . '</h' . ($lvl + 1) . '>';
            $i++; continue;
        }
        if (jtest('/^(-{3,}|\*{3,}|_{3,})$/', js_trim($line))) { $b .= '<hr class="my-8 border-[var(--c-border)]">'; $i++; continue; }
        if (jtest('/^>\s?/', $line)) {
            $buf = [];
            while ($i < $n && jtest('/^>\s?/', $lines[$i])) { $buf[] = jreplace('/^>\s?/', '', $lines[$i], false); $i++; }
            $b .= '<blockquote class="my-4 pl-4 border-l-2 border-[var(--c-accent)] muted">' . app_md_inline(implode(' ', $buf)) . '</blockquote>';
            continue;
        }
        if (jtest('/^[-*]\s+/', $line)) {
            $items = [];
            while ($i < $n && jtest('/^[-*]\s+/', $lines[$i])) { $items[] = jreplace('/^[-*]\s+/', '', $lines[$i], false); $i++; }
            $b .= $list($items, false); continue;
        }
        if (jtest('/^\d+\.\s+/', $line)) {
            $items = [];
            while ($i < $n && jtest('/^\d+\.\s+/', $lines[$i])) { $items[] = jreplace('/^\d+\.\s+/', '', $lines[$i], false); $i++; }
            $b .= $list($items, true); continue;
        }
        $solo = js_trim($line);
        if ($im = jmatch('/^!\[([^\]]*)\]\(([^)\s]+)\)$/', $solo)) {
            $u = app_norm_url($im[2]);
            if ($u !== null) {
                $b .= '<figure class="my-6"><img src="' . dom_attr($u) . '" alt="' . dom_attr($im[1]) . '" class="w-full rounded border border-[var(--c-border)]" loading="lazy">' . ($im[1] !== '' ? '<figcaption class="text-center tiny muted mt-2">' . dom_text($im[1]) . '</figcaption>' : '') . '</figure>';
                $i++; continue;
            }
        }
        if (jtest('/^https?:\/\/\S+$/i', $solo)) {
            $yid = app_yt_id($solo);
            if ($yid !== null) {
                $b .= '<div class="relative w-full my-6 overflow-hidden rounded border border-[var(--c-border)] bg-black" style="aspect-ratio: 16 / 9;"><iframe class="absolute inset-0 w-full h-full" src="https://www.youtube-nocookie.com/embed/' . dom_attr($yid) . '" title="YouTube video" loading="lazy" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" allowfullscreen=""></iframe></div>';
            } elseif (jtest('/\.pdf(\?|#|$)/i', $solo)) {
                $b .= '<div class="my-6"><div class="overflow-hidden rounded border border-[var(--c-border)] bg-[var(--c-surface)]" style="height: 80vh; max-height: 900px;"><iframe src="' . dom_attr($solo) . '" title="PDF document" class="w-full h-full" loading="lazy"></iframe></div><a href="' . dom_attr($solo) . '" target="_blank" rel="noopener noreferrer" class="inline-block mt-2 small">Open or download the PDF</a></div>';
            } elseif (jtest('/\.(png|jpe?g|gif|webp|svg|avif)(\?|#|$)/i', $solo)) {
                $b .= '<figure class="my-6"><img src="' . dom_attr($solo) . '" alt="" class="w-full rounded border border-[var(--c-border)]" loading="lazy"></figure>';
            } else {
                $b .= '<p class="my-4 break-words"><a href="' . dom_attr($solo) . '" target="_blank" rel="noopener noreferrer">' . dom_text($solo) . '</a></p>';
            }
            $i++; continue;
        }
        $buf = [];
        while ($i < $n && js_trim($lines[$i]) !== '' && !jtest('/^(#{1,3}\s|>|[-*]\s|\d+\.\s|```)/', $lines[$i])) { $buf[] = $lines[$i]; $i++; }
        $b .= '<p class="my-4">' . app_md_inline(implode(' ', $buf)) . '</p>';
    }
    return '<div class="blog-body">' . $b . '</div>';
}
function app_post_meta($post): string
{
    $tags = js_filter(array_map('js_trim', explode(',', js_truthy(js_get($post, 'tags')) ? js_string(js_get($post, 'tags')) : '')), fn($t) => $t !== '');
    $d = js_get($post, 'date');
    return '<div class="post-meta">' . (js_truthy($d) ? '<time datetime="' . dom_attr(js_string($d)) . '">' . dom_text(app_fmt_date($d)) . '</time>' : '')
        . ($tags ? '<ul class="post-tags" aria-label="Tags">' . implode('', array_map(fn($t) => '<li class="tag" style="margin-left: 0px;">' . dom_text(app_no_emoji($t)) . '</li>', $tags)) . '</ul>' : '') . '</div>';
}
function app_post_list(array $posts): string
{
    $o = '<ul class="row-list">';
    foreach ($posts as $p) {
        $cover = js_get($p, 'cover');
        $o .= '<li class="post-item">' . (js_truthy($cover) ? '<img src="' . dom_attr(js_string($cover)) . '" alt="" loading="lazy" decoding="async" width="160" height="100" class="post-thumb">' : '')
            . '<div class="min-w-0">' . app_post_meta($p) . '<h2 class="post-title"><a href="' . dom_attr('/blog/' . app_eslug($p) . '/') . '">' . dom_text(app_no_emoji(js_get($p, 'title'))) . '</a></h2>'
            . (js_truthy(js_get($p, 'summary')) ? '<p class="muted small m-0" style="max-width: 68ch;">' . dom_text(app_no_emoji(js_get($p, 'summary'))) . '</p>' : '') . '</div></li>';
    }
    return $o . '</ul>';
}
function app_blog_index_body(array $posts, $head): string
{
    $title = app_no_emoji(js_get($head, 'title'));
    if ($title === '') $title = 'Blog';
    $sub = is_array($head) && array_key_exists('subtitle', $head) && $head['subtitle'] !== null ? $head['subtitle'] : 'Research notes and paper walkthroughs.';
    $o = '<header class="mb-8"><h1 class="page-title">' . dom_text($title) . '</h1>' . (js_truthy($sub) ? '<p class="muted mt-3 mb-0">' . app_rich($sub) . '</p>' : '') . '</header>';
    if (!$posts) {
        $et = app_no_emoji(js_get($head, 'emptyTitle'));
        $ex = app_no_emoji(js_get($head, 'emptyText'));
        return $o . '<div class="empty-state" role="status"><h2 class="text-[19px] font-semibold m-0">' . dom_text($et !== '' ? $et : 'Posts coming soon') . '</h2><p class="muted mt-2 mb-0">'
            . ($ex !== '' ? app_rich($ex) : 'There are no posts yet. Meanwhile, the <a href="/publications/">publications</a> and the <a href="/faq/">research FAQ</a> cover the work so far.') . '</p></div>';
    }
    return $o . app_post_list($posts);
}
function app_post_view($post): string
{
    $cover = js_get($post, 'cover');
    $sum = js_get($post, 'summary');
    return '<article><nav aria-label="Breadcrumb" class="small"><a href="/blog/" class="inline-flex items-center min-h-[44px]">Blog</a></nav><header class="mt-2 mb-8">'
        . (app_is_draft($post) ? '<p class="tag" style="margin-left: 0px;">Draft (not listed)</p>' : '')
        . '<h1 class="page-title">' . dom_text(app_no_emoji(js_get($post, 'title'))) . '</h1>'
        . (js_truthy($sum) ? '<p class="muted mt-3 mb-0 text-[19px]">' . dom_text(app_no_emoji($sum)) . '</p>' : '')
        . '<div class="mt-4">' . app_post_meta($post) . '</div></header>'
        . (js_truthy($cover) ? '<img src="' . dom_attr(js_string($cover)) . '" alt="" decoding="async" class="w-full rounded border border-[var(--c-border)] mb-8">' : '')
        . app_render_markdown(js_get($post, 'body') ?? '')
        . '<p class="mt-12 pt-6 border-t border-[var(--c-border)]"><a href="/blog/">All posts</a></p></article>';
}

/** Replace the inner HTML of <div class="prose-w"> inside <main id="main" …>. */
function shell_set_main(string $html, string $inner): ?string
{
    if (!preg_match('/<main id="main"[^>]*><div class="prose-w">/', $html, $m, PREG_OFFSET_CAPTURE)) return null;
    $start = $m[0][1] + strlen($m[0][0]);
    $end = strpos($html, '</div></main>', $start);
    if ($end === false) return null;
    return substr($html, 0, $start) . $inner . substr($html, $end);
}
/** Replace the inner HTML of <div id="root"> (the homepage shell: the root closes at the last </div> before </body>). */
function shell_set_root(string $html, string $inner): ?string
{
    $a = strpos($html, '<div id="root">');
    $body = strrpos($html, '</body>');
    if ($a === false || $body === false) return null;
    $start = $a + strlen('<div id="root">');
    $end = strrpos(substr($html, 0, $body), '</div>');
    if ($end === false || $end < $start) return null;
    return substr($html, 0, $start) . $inner . substr($html, $end);
}
/** setPageHead() in App.tsx: title, canonical, og:url/title, twitter:title, descriptions, robots. */
function shell_set_head(string $html, string $title, string $url, string $desc, string $robots): string
{
    $html = preg_replace_callback('/<title>[^<]*<\/title>/', fn() => '<title>' . dom_text($title) . '</title>', $html, 1) ?? $html;
    $set = function (string $re, string $val) use (&$html) {
        $html = preg_replace_callback($re, fn($m) => $m[1] . dom_attr($val) . $m[2], $html, 1) ?? $html;
    };
    $set('/(<link rel="canonical" href=")[^"]*(")/', $url);
    $set('/(<meta property="og:url" content=")[^"]*(")/', $url);
    $set('/(<meta property="og:title" content=")[^"]*(")/', $title);
    $set('/(<meta name="twitter:title" content=")[^"]*(")/', $title);
    if ($desc !== '') {
        $set('/(<meta name="description" content=")[^"]*(")/', $desc);
        $set('/(<meta property="og:description" content=")[^"]*(")/', $desc);
        $set('/(<meta name="twitter:description" content=")[^"]*(")/', $desc);
    }
    $set('/(<meta name="robots" content=")[^"]*(")/', $robots);
    return $html;
}

/** Published posts as the React blog list shows them (newest first, plain string order). */
function app_published_posts(array $content): array
{
    $all = js_filter(SeoGen::items($content['blog'] ?? ['items' => []]), fn($p) => js_truthy($p) && (js_truthy(js_get($p, 'slug')) || js_truthy(js_get($p, 'title'))));
    return js_sort(js_filter($all, fn($p) => !app_is_draft($p)), fn($a, $b) => strcmp(js_string(js_get($b, 'date') ?? ''), js_string(js_get($a, 'date') ?? '')));
}
function app_all_posts(array $content): array
{
    return js_filter(SeoGen::items($content['blog'] ?? ['items' => []]), fn($p) => js_truthy($p) && (js_truthy(js_get($p, 'slug')) || js_truthy(js_get($p, 'title'))));
}
