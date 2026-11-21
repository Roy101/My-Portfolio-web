<?php
/**
 * PHP port of palash-portfolio/scripts/generate-seo.mjs (the live renderer).
 *
 * SeoGen::run() takes the same content the Node generator reads (section => decoded JSON) and
 * returns every file it writes, as bytes, plus the validation errors and warnings. Methods keep
 * the Node names and order, so a change on one side is easy to find on the other; the parity
 * harness (palash-portfolio/scripts/seo-parity.mjs) requires identical output.
 *
 * Inputs from the build (dist/_seo/): shared.json (CSS, scripts, icons, identity constants),
 * the pristine homepage/blog shells and manifest.json. File probes (image sizes, PDFs, WebP
 * variants) read the document root.
 *
 * Live-only additions (never used by the Node build, so parity mode leaves them empty):
 * $env['itemDates'] ("pub:<slug>" / "blog:<slug>" => YYYY-MM-DD, kept by the admin) and
 * $env['sectionDates'] (section => DATE(updated_at)) add content dates to the lastmod values.
 */
declare(strict_types=1);
require_once __DIR__ . '/js.php';
require_once __DIR__ . '/images.php';

final class SeoGen
{
    /** @var array<string,string> rel path => bytes, in the order Node writes them */
    public array $files = [];
    public array $errors = [];
    public array $warnings = [];
    /** facts the router needs: paper slugs, blog posts, faq/photos present */
    public array $facts = [];

    private array $C;      // content: section => value
    private array $S;      // shared.json
    private array $env;
    private string $SITE, $NAME, $ORCID, $EMAIL, $IMAGE, $PERSON_ID, $WEBSITE_ID;

    // content
    private array $pubsRaw = [], $faqItems = [], $about = [], $socials = [], $highlights = [], $blogPosts = [];
    private array $footer = [], $research = [], $metricsJson = [], $settings = [], $headings = [], $hero = [];
    private array $leadership = [], $galleryItems = [], $softwareRaw = [], $serviceItems = [], $newsItems = [];
    private array $TAG_VOCAB = [], $ML_VOCAB = [], $AREA_TAGS = [], $ML_AREA_TAGS = [], $EDU = [];
    private ?array $PHD_ROW = null;
    private string $EXPECTED = '', $EXPECTED_LONG = '', $NAME_LINE = '';
    private array $documents = [];
    private ?array $cvDoc = null;
    private bool $BLOG_SHOWN = true;
    private array $THEMES = [];
    private array $pubs = [];
    private array $slugs = [];
    private string $PUBS_LASTMOD = '', $HOME_LASTMOD = '';
    private array $softwareItems = [];
    private array $HEADSHOTS = [], $PERSON_IMAGES = [];
    private array $awards = [], $KNOWS = [], $IN_THE_NEWS = [], $NEWS_ARTICLES = [];
    private string $PERSON_DESC = '';
    private array $websiteNode = [];
    private bool $SHOW_PROMPT = true;
    private array $NAV_ITEMS = [], $ON_HOME = [], $FOOTER_ON_HOME = [], $profileLinks = [], $FOOTER_PAGES = [];
    private string $TOOLS_LABEL = '', $COPYRIGHT = '', $VISIT_URL = '';
    private array $themesUsed = [], $areasUsed = [], $mlUsed = [];
    private array $teachRows = [], $researchRows = [], $talks = [], $nominations = [], $serviceRows = [], $newsRows = [];
    private ?array $reviewer = null;
    private string $reviewerLine = '';
    private array $photos = [], $software = [];
    private array $sizeCache = [];
    // identity / SEO texts (resolveIdentity, mirrors the "identity and SEO texts" block of generate-seo.mjs)
    private array $identity = [], $SEO = [], $IDS = [], $ALT_NAMES = [], $AFFILIATION = [], $MEMBER_OF = [], $ALUMNI_OF = [], $PRON = [], $KNOWS_SEED = [], $PHOTO_SET = [];
    private string $GIVEN = '', $ADDITIONAL = '', $FAMILY = '', $PUBLISHED_AS = '', $LLMS_AS = '', $JOB_TITLE = '', $SUMMARY_TEXT = '', $LABS_LINE = '', $DISAMBIGUATION = '';
    private string $YEARV = '', $PHOTO_ALT = '', $OG_IMAGE = '', $OG_ALT = '', $CV_NAV = '', $CV_FOOT = '';
    private bool $CV_IS_PDF = false;
    /** normalized extra spellings of the owner (isOwner) */
    private static array $OWNER_SET = [];

    public function __construct(array $content, array $shared, array $env)
    {
        $this->C = $content;
        $this->S = $shared;
        $this->env = $env + ['docroot' => '', 'year' => gmdate('Y'), 'check' => false, 'visitUrl' => '', 'itemDates' => [], 'sectionDates' => [], 'shells' => []];
        $this->SITE = $shared['SITE'];
        $this->NAME = $shared['NAME'];
        $this->ORCID = $shared['ORCID'];
        $this->EMAIL = $shared['EMAIL'];
        $this->IMAGE = $shared['IMAGE'];
        $this->PERSON_ID = $this->SITE . '/#person';
        $this->WEBSITE_ID = $this->SITE . '/#website';
    }

    private function warn(string $m): void { $this->warnings[] = $m; }

    // ---------------------------------------------------------------- helpers
    // mirrors generate-seo.mjs isoDay() / newest()
    public static function isoDay($d): string
    {
        $s = js_truthy($d) ? js_string($d) : '';
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $s, $m) || (int)$m[1] < 1900 || (int)$m[1] > 2099) return '';
        return checkdate((int)$m[2], (int)$m[3], (int)$m[1]) ? $m[0] : '';
    }
    public static function newest(...$ds): string
    {
        $best = '';
        foreach ($ds as $d) {
            foreach (js_is_array($d) ? $d : [$d] as $x) { $v = self::isoDay($x); if ($v !== '' && strcmp($v, $best) > 0) $best = $v; }
        }
        return $best;
    }
    private function readJson(string $name, $fallback)
    {
        return array_key_exists($name, $this->C) ? $this->C[$name] : $fallback;
    }
    public static function items($d): array
    {
        if (js_is_array($d)) return $d;
        if (is_array($d) && isset($d['items']) && js_is_array($d['items'])) return $d['items'];
        return [];
    }
    public static function esc($s): string { return strtr(str($s), ['&' => '&amp;', '<' => '&lt;', '>' => '&gt;', '"' => '&quot;']); }
    public static function slugify($s): string
    {
        $t = js_lower(str($s));
        $t = preg_replace('/[^a-z0-9]+/', '-', $t) ?? '';
        return preg_replace('/^-+|-+$/D', '', $t) ?? '';
    }
    public static function stripEmoji($s): string
    {
        $t = str($s);
        $t = preg_replace('/[\x{1F1E6}-\x{1F1FF}]/u', '', $t) ?? $t;
        $t = preg_replace_callback('/[' . JS_EXT_PICT . '](\x{FE0F}|\x{200D}[' . JS_EXT_PICT . '])*/u', function ($m) {
            return preg_match('/^[\x{00A9}\x{00AE}\x{2122}]/u', $m[0]) ? mb_substr($m[0], 0, 1, 'UTF-8') : '';
        }, $t) ?? $t;
        $t = preg_replace('/\x{FE0F}/u', '', $t) ?? $t;
        $t = jreplace('/\s{2,}/', ' ', $t);
        return js_trim($t);
    }
    public static function ldScript($obj): string
    {
        return '<script type="application/ld+json">' . str_replace('<', '\\u003c', js_json($obj)) . '</script>';
    }
    private function breadcrumbLd(array $trail): array
    {
        $items = [];
        foreach ($trail as $i => [$name, $item]) $items[] = ['@type' => 'ListItem', 'position' => $i + 1, 'name' => $name, 'item' => $item];
        return ['@type' => 'BreadcrumbList', '@id' => $trail[count($trail) - 1][1] . '#breadcrumb', 'itemListElement' => $items];
    }
    private function metaDesc($s): string { return self::clip($s, 158); }
    private function abs(string $u): string
    {
        return preg_match('/^https?:\/\//', $u) ? $u : $this->SITE . (js_starts_with($u, '/') ? $u : '/' . $u);
    }
    private static function normDoi($d): string
    {
        $t = jreplace('/^https?:\/\/(dx\.)?doi\.org\//i', '', str($d), false);
        $t = jreplace('/^doi:\s*/i', '', $t, false);
        return js_trim($t);
    }
    public static function clip($s, int $n): string
    {
        $s = jreplace('/\s+/', ' ', str($s));
        if (js_len($s) <= $n) return $s;
        $cut = js_slice($s, 0, js_last_index_of($s, ' ', $n - 1));
        return jreplace('/[,;:.]$/', '', $cut, false) . '…';
    }
    public static function firstSentence($s): string
    {
        $m = jmatch('/^.*?[.!?](\s|$)/', str($s));
        return js_trim($m ? $m[0] : str($s));
    }
    private function write(string $rel, string $content): void { $this->files[$rel] = $content; }
    private function inlineMd($s, bool $absolute = false): string
    {
        $t = self::esc($s);
        $t = jreplace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $t);
        return jreplace('/\[([^\]]+)\]\(([^)\s]+)\)/', function ($m) use ($absolute) {
            // only web, mail, same-site ("/x", never "//x" or "/\\x") and "#" targets: no javascript: etc.
            if (!$absolute && !jtest('/^(https?:|mailto:|\/(?![\/\\\\])|#)/i', $m[2])) return $m[1];
            return '<a href="' . ($absolute ? self::esc($this->abs(str_replace('&amp;', '&', $m[2]))) : $m[2]) . '">' . $m[1] . '</a>';
        }, $t);
    }
    private function mdParas($s, bool $absolute = false): string
    {
        return implode("\n", array_map(fn($p) => '<p>' . $this->inlineMd(js_trim($p), $absolute) . '</p>', jsplit('/\n{2,}/', str($s))));
    }
    private function mdAbsLinks($s): string
    {
        return jreplace('/\]\((\/[^)\s]*)\)/', fn($m) => '](' . $this->SITE . $m[1] . ')', str($s));
    }
    private static function plainFromMd($s): string
    {
        $t = jreplace('/\[([^\]]+)\]\([^)]+\)/', '$1', str($s));
        return jreplace('/\*\*(.+?)\*\*/', '$1', $t);
    }
    private static function ownerKey($n): string
    {
        $t = js_lower(str($n));
        $t = str_replace('.', ' ', $t);
        $t = jreplace('/\s+/', ' ', $t);
        $t = str_replace(' ,', ',', $t);
        return js_trim($t);
    }
    public static function isOwner($n): bool
    {
        $t = self::ownerKey($n);
        return preg_match('/^(palash( ranjan| r)? roy|p r roy|p roy|roy,? p(alash)?( r(anjan)?)?)$/D', $t) === 1 || isset(self::$OWNER_SET[$t]);
    }
    private static function idStr($v): string { return is_string($v) ? self::stripEmoji($v) : ''; }
    private static function extUrl($v): string { $t = self::idStr($v); return jtest('/^https?:\/\/[^\s"<>]+$/i', $t) ? $t : ''; }
    private static function validEmail(string $e): string { return jtest('/^[^\s@<>"\'&]+@[^\s@<>"\'&]+\.[^\s@<>"\'&]+$/', $e) ? $e : ''; }
    private function idText(string $k, string $d): string { $v = self::idStr(js_get($this->identity, $k)); return $v !== '' ? $v : $d; }
    private function idList(string $k, array $d): array
    {
        $v = js_get($this->identity, $k);
        $v = js_is_array($v) ? js_filter(array_map(fn($x) => self::idStr($x), $v), fn($x) => $x !== '') : [];
        return $v ?: $d;
    }
    private function orgList(string $k): array
    {
        $v = js_get($this->identity, $k);
        $out = [];
        foreach (js_is_array($v) ? $v : [] as $o) {
            if (!js_is_object($o)) continue;
            $e = ['id' => self::slugify(self::idStr(js_get($o, 'id'))), 'type' => self::idStr(js_get($o, 'type')), 'name' => self::idStr(js_get($o, 'name')), 'url' => self::extUrl(js_get($o, 'url')), 'sameAs' => self::extUrl(js_get($o, 'sameAs'))];
            if ($e['name'] !== '') $out[] = $e;
        }
        return $out;
    }
    private function orgNode(array $o, string $type): array
    {
        $n = ['@type' => $o['type'] !== '' ? $o['type'] : $type, '@id' => $this->SITE . '/#' . ($o['id'] !== '' ? $o['id'] : self::slugify($o['name'])), 'name' => $o['name']];
        if ($o['url'] !== '') $n['url'] = $o['url'];
        if ($o['sameAs'] !== '') $n['sameAs'] = $o['sameAs'];
        return $n;
    }
    private function orgFor(string $inst): ?array
    {
        if (!$this->orgList('affiliation') && !$this->orgList('alumniOf')) return jtest('/saskatchewan/i', $inst) ? $this->S['ORGS']['usask'] : null;
        foreach (array_merge($this->AFFILIATION, js_filter($this->ALUMNI_OF, fn($o) => isset($o['name']))) as $o) {
            if (isset($o['name']) && $o['name'] !== '' && js_includes(js_lower($inst), js_lower($o['name']))) return $o;
        }
        return null;
    }
    public function seoPage(string $pg, string $f): string
    {
        $pages = js_get($this->SEO, 'pages');
        $x = js_is_object($pages) ? js_get($pages, $pg) : null;
        return js_is_object($x) ? self::idStr(js_get($x, $f)) : '';
    }
    private function fillTokens($t): string
    {
        $m = ['name' => $this->NAME, 'orcid' => $this->ORCID, 'publishedAs' => $this->PUBLISHED_AS, 'email' => $this->EMAIL, 'year' => $this->YEARV];
        return preg_replace_callback('/\{(name|orcid|publishedAs|year|email)\}/', fn($x) => $m[$x[1]], str($t)) ?? str($t);
    }
    /** mirrors the "identity and SEO texts" block of generate-seo.mjs */
    private function resolveIdentity(): void
    {
        $S = $this->S;
        $id = $this->readJson('identity', []);
        $this->identity = js_is_object($id) ? $id : [];
        $consultation = self::obj($this->readJson('consultation', []));
        $this->NAME = $this->idText('name', $S['NAME']);
        $o = self::idStr(js_get($this->identity, 'orcid'));
        $this->ORCID = jtest('/^\d{4}-\d{4}-\d{4}-\d{3}[\dX]$/', $o) ? $o : $S['ORCID'];
        $e = self::validEmail(self::idStr(js_get($this->identity, 'email')));
        if ($e === '') $e = self::validEmail(self::idStr(js_get($this->footer, 'email')));
        if ($e === '') $e = self::validEmail(self::idStr(js_get($consultation, 'email')));
        $this->EMAIL = $e !== '' ? $e : $S['EMAIL'];
        $this->ALT_NAMES = $this->idList('alternateNames', $S['ALT_NAMES']);
        $this->GIVEN = $this->idText('givenName', 'Palash'); $this->ADDITIONAL = $this->idText('additionalName', 'Ranjan'); $this->FAMILY = $this->idText('familyName', 'Roy');
        $this->PUBLISHED_AS = js_get($this->identity, 'showPublishedAs') === false ? '' : $this->idText('publishedAs', 'Palash Roy / Palash R. Roy');
        $list = [];
        foreach ($this->ALT_NAMES as $i => $n) {
            if (!preg_match("/^[\p{Latin} .,'-]+\$/u", $n)) continue;
            $dup = false;
            foreach (array_slice($this->ALT_NAMES, 0, $i) as $m) if (str_replace('.', '', $m) === str_replace('.', '', $n)) { $dup = true; break; }
            if (!$dup) $list[] = $n;
        }
        $this->LLMS_AS = count($list) > 1 ? implode(', ', array_slice($list, 0, -1)) . ' and ' . $list[count($list) - 1] : implode('', $list);
        $this->JOB_TITLE = $this->idText('jobTitle', 'PhD Researcher in Computer Science');
        $this->SUMMARY_TEXT = $this->idText('summary', $S['SUMMARY']);
        $this->LABS_LINE = $this->idText('labsLine', 'He works in the Software Research Lab (SRLab) and the Interactive Software Engineering and Analytics (iSE) Lab at the University of Saskatchewan, Canada, supervised by Dr. Kevin Schneider.');
        $this->DISAMBIGUATION = $this->idText('disambiguation', "Disambiguation: he is identified by ORCID {$this->ORCID}, dblp 355/4465, OpenAlex A5058812722 and Semantic Scholar 2051272535. Other researchers named Palash Roy are different people.");
        $this->PRON = ['he' => ['He', 'his', 'is'], 'she' => ['She', 'her', 'is'], 'they' => ['They', 'their', 'are']][js_lower(self::idStr(js_get($this->identity, 'pronoun')))] ?? ['He', 'his', 'is'];
        $ids = [];
        $raw = js_get($this->identity, 'identifiers');
        foreach (js_is_array($raw) ? $raw : [] as $i) {
            if (!js_is_object($i)) continue;
            $x = ['propertyID' => self::idStr(js_get($i, 'propertyID')), 'value' => self::idStr(js_get($i, 'value')), 'url' => self::extUrl(js_get($i, 'url'))];
            if ($x['propertyID'] === '' || $x['value'] === '') continue;
            if ($x['url'] === '') unset($x['url']);
            $ids[] = $x;
        }
        if (!$ids) {
            $ids = $S['IDS'];
            $ids[0] = ['propertyID' => 'ORCID', 'value' => $this->ORCID, 'url' => 'https://orcid.org/' . $this->ORCID];
        }
        $this->IDS = $ids;
        $aff = $this->orgList('affiliation');
        $this->AFFILIATION = $aff ? array_map(fn($o) => $this->orgNode($o, 'CollegeOrUniversity'), $aff) : [$S['ORGS']['usask']];
        $mem = $this->orgList('memberOf');
        $this->MEMBER_OF = $mem ? array_map(function ($o) {
            $n = ['@type' => $o['type'] !== '' ? $o['type'] : 'ResearchOrganization', 'name' => $o['name']];
            if ($o['url'] !== '') $n['url'] = $o['url'];
            if ($this->AFFILIATION) $n['parentOrganization'] = ['@id' => $this->AFFILIATION[0]['@id']];
            return $n;
        }, $mem) : [$S['ORGS']['srlab'], $S['ORGS']['ise']];
        $alu = $this->orgList('alumniOf');
        $this->ALUMNI_OF = $alu ? array_map(function ($o) {
            foreach ($this->AFFILIATION as $a) if (js_lower($a['name']) === js_lower($o['name'])) return ['@id' => $a['@id']];
            return $this->orgNode($o, 'CollegeOrUniversity');
        }, $alu) : [$S['ORGS']['brac'], ['@id' => $this->SITE . '/#usask']];
        self::$OWNER_SET = [];
        foreach (array_merge([$this->NAME], $this->ALT_NAMES) as $n) self::$OWNER_SET[self::ownerKey($n)] = true;
        $this->YEARV = preg_match('/^\d{4}$/D', (string)$this->env['year']) ? (string)$this->env['year'] : gmdate('Y');
        $seo = js_get($this->settings, 'seo');
        $this->SEO = js_is_object($seo) ? $seo : [];
        $ka = js_get($this->SEO, 'knowsAbout');
        $ka = js_is_array($ka) ? $ka : (is_string($ka) ? jsplit('/[;\n]/', $ka) : []);
        $ka = js_filter(array_map(fn($x) => self::idStr($x), $ka), fn($x) => $x !== '');
        $this->KNOWS_SEED = $ka ?: $S['KNOWS_ABOUT'];
        // profile photo set (hero.photoSet)
        $ps = js_get($this->hero, 'photoSet');
        $ps = js_is_object($ps) ? $ps : [];
        $jpg = $this->safeUrl(js_get($ps, 'jpg'));
        $this->PHOTO_SET = [];
        if ($jpg !== '' && $this->safeUrl(js_get($this->hero, 'photo')) === $jpg) {
            $set = [];
            $ss = js_get($ps, 'srcset');
            foreach (js_is_array($ss) ? $ss : [] as $x) {
                if (!js_is_object($x) || $this->safeUrl(js_get($x, 'url')) === '') continue;
                $w = js_get($x, 'w');
                if (!(is_int($w) || (is_float($w) && floor($w) == $w)) || $w <= 0) continue;
                $set[] = encode_uri(safe_decode($this->safeUrl(js_get($x, 'url')))) . ' ' . (int)$w . 'w';
            }
            $this->PHOTO_SET = ['jpg' => $jpg, 'srcset' => implode(', ', $set), 'type' => js_get($ps, 'type') === 'image/jpeg' ? 'image/jpeg' : 'image/webp'];
        }
        $pa = self::idStr(js_get($this->hero, 'photoAlt'));
        $this->PHOTO_ALT = $pa !== '' ? $pa : $this->NAME;
        $og = $this->safeUrl(js_get($this->SEO, 'image'));
        if ($og === '') $og = $this->safeUrl(js_get($this->hero, 'photo'));
        if ($og === '') $og = $this->safeUrl(js_get($this->about, 'photo'));
        $this->OG_IMAGE = $og !== '' ? $this->abs($this->encPath($og)) : $this->IMAGE;
        $oa = self::idStr(js_get($this->SEO, 'imageAlt'));
        $this->OG_ALT = $oa !== '' ? $oa : 'Photograph of ' . $this->NAME;
    }
    private function safeUrl($u): string { return jtest('/^(https?:\/\/|\/(?![\/\\\\]))/i', str($u)) ? str($u) : ''; }

    // ---------------------------------------------------------------- vocab
    private function vocabFor($tag): ?array
    {
        $l = js_lower(str($tag));
        foreach ($this->TAG_VOCAB as $t) if (js_lower($t['tag']) === $l) return $t;
        return null;
    }
    private function tagLabel($tag): string { $v = $this->vocabFor($tag); return $v && $v['label'] !== '' && $v['label'] !== $v['tag'] ? $v['label'] : ''; }
    private function tagLong($tag): string
    {
        if ($this->tagLabel($tag) !== '') return $this->tagLabel($tag) . ' (' . $this->vocabFor($tag)['tag'] . ')';
        $v = $this->vocabFor($tag);
        return $v && $v['tag'] !== '' ? $v['tag'] : str($tag);
    }
    private function tagId($tag): string { return 'tag-' . self::slugify($tag); }
    private function mlVocabFor($t): ?array
    {
        $l = js_lower(str($t));
        foreach ($this->ML_VOCAB as $v) if (js_lower($v['tag']) === $l) return $v;
        return null;
    }
    private function mlLabel($t): string { $v = $this->mlVocabFor($t); return $v && $v['label'] !== '' && $v['label'] !== $v['tag'] ? $v['label'] : ''; }
    private function mlLong($t): string
    {
        if ($this->mlLabel($t) !== '') return $this->mlLabel($t);
        $v = $this->mlVocabFor($t);
        return $v && $v['tag'] !== '' ? $v['tag'] : str($t);
    }
    private function mlId($t): string { return 'ml-' . self::slugify($t); }
    private static function vocabList($v): array
    {
        $out = [];
        foreach (js_is_array($v) ? $v : [] as $t) {
            $e = is_string($t) ? ['tag' => self::stripEmoji($t), 'label' => ''] : ['tag' => self::stripEmoji(js_get($t, 'tag')), 'label' => self::stripEmoji(js_get($t, 'label'))];
            if ($e['tag'] !== '') $out[] = $e;
        }
        return $out;
    }
    private static function strList($v): array
    {
        $src = js_is_array($v) ? $v : jsplit('/[,;\n]/', str($v));
        return js_filter(array_map(fn($x) => self::stripEmoji(js_sv($x)), $src), fn($x) => $x !== '');
    }
    /** (Array.isArray(v) ? v : str(v).split(re)).map(t => stripEmoji(String(t ?? ""))).filter(Boolean) */
    private static function listField($v, string $re = '/[,;\n]/'): array
    {
        $src = js_is_array($v) ? $v : jsplit($re, str($v));
        return js_filter(array_map(fn($x) => self::stripEmoji(js_sv($x)), $src), fn($x) => $x !== '');
    }
    private function hnav(string $k, string $d): string { $v = self::stripEmoji(js_get(js_get($this->headings, $k), 'nav')); return $v !== '' ? $v : $d; }
    private function htitle(string $k, string $d): string { $v = self::stripEmoji(js_get(js_get($this->headings, $k), 'title')); return $v !== '' ? $v : $d; }
    private function hsub(string $k): string { return self::stripEmoji(js_get(js_get($this->headings, $k), 'subtitle')); }
    private static function asSlugs($v): array
    {
        $src = js_is_array($v) ? $v : jsplit('/[,;\n]/', str($v));
        return js_filter(array_map(fn($x) => self::slugify($x), $src), fn($x) => $x !== '');
    }

    // mirrors parseAuthors()
    private function parseAuthors(array $p): array
    {
        $af = js_get($p, 'authors_full');
        if (js_is_array($af) && count($af)) return js_filter(array_map('str', $af), fn($x) => $x !== '');
        $parts = jsplit('/\s*,\s*|\s+and\s+/', str(js_get($p, 'authors')));
        return js_filter(array_map('js_trim', $parts), fn($s) => $s !== '' && !jtest('/^et\.? al\.?$/i', $s));
    }

    private function pdfExists(string $pdf): bool
    {
        if ($this->env['check']) return true;
        return seo_doc_file($this->env['docroot'], $pdf, false) !== null;
    }

    // mirrors normalize()
    private function normalize($p, int $i): array
    {
        $p = is_array($p) ? $p : [];
        $g = fn($k) => js_get($p, $k);
        $doi = self::normDoi($g('doi'));
        $preprint = str($g('preprint'));
        $arxiv = jreplace('/^arxiv:/i', '', str($g('arxiv')), false);
        $am = jmatch('/arxiv\.org\/(?:abs|pdf)\/(\d{4}\.\d{4,5})/i', $preprint);
        if ($arxiv === '' && $am) $arxiv = $am[1];
        $pdf = str($g('pdf'));
        if ($pdf === '') $pdf = (js_starts_with($preprint, '/') && jtest('/\.pdf$/i', $preprint)) ? $preprint : '';
        $pdfExists = $pdf !== '' && $this->pdfExists($pdf);
        if ($pdf !== '' && !$pdfExists) { $this->warn("$pdf not found in dist; PDF link omitted"); $pdf = ''; }
        $pages = jreplace('/^pp?\.\s*/i', '', str($g('pages')), false);
        $pp = jsplit('/\s*[-–]+\s*/', $pages);
        $fp = $pp[0] ?? null; $lp = $pp[1] ?? null;
        $authors = $this->parseAuthors($p);
        $vt = str($g('venue')) . ' ' . str($g('track'));
        $venueRaw = $g('venue') === null ? 'undefined' : js_string($g('venue'));
        $type = jreplace('/[\s-]+/', '_', js_lower(str($g('type'))));
        if ($type === '') {
            $type = jtest('/thesis|dissertation/i', $vt) ? 'thesis' : (jtest('/doctoral symposium/i', $vt) ? 'doctoral_symposium' : (jtest('/journal|proceedings of the acm on|transactions/i', $venueRaw) ? 'journal' : (jtest('/poster/i', $venueRaw) ? 'poster' : (jtest('/workshop/i', $venueRaw) ? 'workshop' : 'conference'))));
        }
        $title = str($g('title'));
        $hRaw = jreplace('/^hdl:\s*/i', '', str($g('handle')), false);
        $handle = jtest('/^https?:\/\//i', $hRaw) ? jreplace('/^http:/i', 'https:', $hRaw, false) : ($hRaw !== '' ? 'https://hdl.handle.net/' . $hRaw : '');
        $degreeText = implode(' ', array_map('str', [$g('dissertation_name'), $g('in_support_of'), $g('degree'), $g('venue')]));
        $level = jtest('/m\.?\s?sc|master/i', $degreeText) ? 'msc' : (jtest('/ph\.?\s?d|doctor/i', $degreeText) ? 'phd' : '');
        $eduDegree = '';
        if ($level !== '') {
            $re = $level === 'msc' ? '/m\.?\s?sc|master/i' : '/ph\.?\s?d|doctor/i';
            foreach ($this->EDU as $e) { $d = self::stripEmoji(js_get($e, 'degree')); if (jtest($re, $d)) { $eduDegree = $d; break; } }
        }
        $institution = str($g('institution'));
        if ($institution === '') $institution = str($g('publisher'));
        if ($institution === '') { $m = jmatch('/University of [A-Z][A-Za-z]+(?: [A-Z][A-Za-z]+)*|[A-Z][A-Za-z]+(?: [A-Z][A-Za-z]+)* University/', str($g('venue'))); $institution = $m ? $m[0] : ''; }
        if ($institution === '') $institution = str($g('venue'));
        $media = fn($u) => jtest('/^\s*(-+|#|none|null|n\/?a|tbd|todo)?\s*$/i', str($u)) ? '' : $this->safeUrl($u);
        $tags = js_uniq(array_map(fn($t) => ($v = $this->vocabFor($t)) && $v['tag'] !== '' ? $v['tag'] : $t, self::listField($g('tags'))));
        $ml = js_uniq(array_map(fn($t) => ($v = $this->mlVocabFor($t)) && $v['tag'] !== '' ? $v['tag'] : $t, self::listField($g('ml_topics'), '/[;\n]/')));
        $eqSrc = js_is_array($g('equal_contribution')) ? $g('equal_contribution') : jsplit('/[,;\n]/', str($g('equal_contribution')));
        $equal = js_filter(array_map('str', $eqSrc), fn($x) => $x !== '');
        $slug = str($g('slug')) !== '' ? str($g('slug')) : self::slugify($title);
        $kwRaw = js_is_array($g('keywords')) ? $g('keywords') : [];
        $haystack = implode(' ', array_merge([$title], array_map(fn($x) => $x === null ? '' : js_string($x), $kwRaw)));
        $themes = [];
        foreach ($this->THEMES as $t) {
            if (isset($t['papers']) ? in_array($slug, $t['papers'], true) : preg_match($t['re'], $title)) $themes[] = $t['id'];
        }
        if (!$themes) foreach ($this->THEMES as $t) if (isset($t['re']) && preg_match($t['re'], $haystack)) $themes[] = $t['id'];
        $venueShort = str($g('venue_short'));
        if ($venueShort === '') $venueShort = self::venueShortOf(str($g('venue')), str($g('year')));
        $diss = self::stripEmoji($g('dissertation_name'));
        if ($diss === '') $diss = $level === 'msc' ? 'M.Sc. thesis' : ($level === 'phd' ? 'Ph.D. thesis' : 'Thesis');
        $inSup = self::stripEmoji($g('in_support_of'));
        if ($inSup === '') $inSup = self::stripEmoji($g('degree'));
        if ($inSup === '') $inSup = $eduDegree;
        $rel = js_is_array($g('relevant_to')) ? $g('relevant_to') : [];
        return [
            'raw' => $p, 'index' => $i, 'title' => $title, 'slug' => $slug, 'type' => $type, 'doi' => $doi, 'arxiv' => $arxiv, 'pdf' => $pdf,
            'year' => str($g('year')), 'date' => str($g('published_date')) !== '' ? str($g('published_date')) : str($g('year')),
            'venue' => str($g('venue')), 'venueFull' => str($g('venue_full')) !== '' ? str($g('venue_full')) : str($g('venue')), 'venueShort' => $venueShort,
            'publisher' => str($g('publisher')), 'volume' => str($g('volume')), 'issue' => str($g('issue')), 'issn' => str($g('issn')),
            'firstPage' => str($g('first_page')) !== '' ? str($g('first_page')) : (($fp !== null && $fp !== '' && preg_match('/^\d+$/D', $fp)) ? $fp : ''),
            'lastPage' => str($g('last_page')) !== '' ? str($g('last_page')) : (($lp !== null && $lp !== '' && preg_match('/^\d+$/D', $lp)) ? $lp : ''),
            'pages' => $pages, 'pagesText' => jreplace('/\s*[-–]+\s*/', '–', $pages, false), 'authors' => $authors, 'award' => self::stripEmoji($g('award')),
            'abstract' => str($g('abstract')), 'description' => self::stripEmoji($g('description')),
            'problem' => str($g('problem')), 'relevant' => js_filter(array_map('str', $rel), fn($x) => $x !== ''), 'keywords' => js_filter(array_map('str', $kwRaw), fn($x) => $x !== ''),
            'code' => $this->safeUrl($g('code_url')), 'tool' => $this->safeUrl($g('tool_url')), 'toolName' => self::stripEmoji($g('tool_name')), 'artifact' => $this->safeUrl($g('artifact_url')), 'dataset' => $this->safeUrl($g('dataset_url')),
            'video' => $media($g('video')), 'slides' => $media($g('slides_url')), 'handle' => $handle, 'institution' => $institution,
            'dissertationName' => $diss, 'inSupportOf' => $inSup,
            'tags' => $tags, 'ml' => $ml, 'equal' => $equal, 'bibtex' => str($g('bibtex')), 'track' => str($g('track')), 'themes' => $themes,
            'myRole' => self::stripEmoji($g('my_role')), 'venueRank' => self::stripEmoji($g('venue_rank')),
            'displayKeywords' => self::listField($g('display_keywords')),
            'url' => $this->SITE . '/publications/' . (str($g('slug')) !== '' ? str($g('slug')) : self::slugify($title)) . '/',
        ];
    }

    // ---------------------------------------------------------------- validation
    // mirrors venueShortOf(): "ICSME 2026" from "… (ICSME)", else the venue name with its year
    private static function venueShortOf(string $venue, string $year): string
    {
        $vm = jmatch('/\(([A-Z][A-Za-z-]+)\)/', $venue);
        if ($vm) return implode(' ', js_filter([$vm[1], $year], fn($x) => js_truthy($x)));
        return implode(' ', js_filter([$venue, $year !== '' && !js_includes($venue, $year) ? $year : ''], fn($x) => js_truthy($x)));
    }
    private static function nameParts($n): array
    {
        $s = js_trim(jreplace('/\s+/', ' ', str_replace('.', ' ', js_lower(str($n)))));
        if (js_includes($s, ',')) { $x = array_map('js_trim', explode(',', $s)); $s = ($x[1] ?? 'undefined') . ' ' . $x[0]; }
        $w = js_filter(explode(' ', $s), fn($x) => $x !== '');
        $first = $w[0] ?? '';
        return ['last' => $w ? $w[count($w) - 1] : '', 'first' => $first === '' ? '' : mb_substr($first, 0, 1, 'UTF-8'), 'full' => implode(' ', $w)];
    }
    private static function sameAuthor($a, $b): bool
    {
        if (self::isOwner($a) && self::isOwner($b)) return true;
        $x = self::nameParts($a); $y = self::nameParts($b);
        return $x['full'] === $y['full'] || ($x['last'] === $y['last'] && $x['first'] === $y['first']);
    }
    private static function isEqual(array $p, $a): bool
    {
        return count($p['equal']) > 1 && js_some($p['equal'], fn($n) => self::sameAuthor($a, $n));
    }

    // ---------------------------------------------------------------- run
    public function run(): self
    {
        $S = $this->S;
        $this->pubsRaw = self::items($this->readJson('publications', ['items' => []]));
        $this->faqItems = js_filter(self::items($this->readJson('faq', ['items' => []])), fn($f) => str(js_get($f, 'q')) !== '' && str(js_get($f, 'a')) !== '');
        $this->about = self::obj($this->readJson('about', []));
        $socialsJson = self::obj($this->readJson('socials', []));
        $this->socials = js_is_array(js_get($socialsJson, 'links')) ? $socialsJson['links'] : [];
        $hl = [];
        foreach (self::items($this->readJson('highlights', ['items' => []])) as $h) {
            if (is_array($h) && jtest("/^People's Choice Best Paper Award and Student Encouragement Award/i", str(js_get($h, 'title')))) {
                $hl[] = array_merge($h, ['title' => "People's Choice Best Paper Award (IWSC 2023)", 'description' => 'Awarded by attendee vote to the IWSC 2023 paper on generating semantic and cross-language clones with large language models.', 'paper_slug' => 'llm-clone-generation-iwsc']);
                $hl[] = array_merge($h, ['title' => 'Student Encouragement Award (IWSC 2023)', 'description' => 'Awarded to Palash Ranjan Roy at IWSC 2023.']);
            } else $hl[] = $h;
        }
        $this->highlights = $hl;
        $bp = js_filter(self::items($this->readJson('blog', ['items' => []])), function ($p) {
            if (!js_truthy($p)) return false;
            $d = js_lower(str(js_get($p, 'draft')));
            return (js_truthy(js_get($p, 'slug')) || js_truthy(js_get($p, 'title'))) && !(js_get($p, 'draft') === true || $d === 'yes' || $d === 'true' || $d === '1');
        });
        $bp = js_filter($bp, fn($p) => seo_blog_slug_ok($this->postSlug($p)));
        $this->blogPosts = js_sort($bp, fn($a, $b) => js_locale_compare(str(js_get($b, 'date')), str(js_get($a, 'date'))));
        $this->footer = self::obj($this->readJson('footer', []));
        $this->research = self::obj($this->readJson('research', []));
        $this->metricsJson = self::obj($this->readJson('metrics', []));
        $this->settings = self::obj($this->readJson('settings', []));
        $this->headings = self::obj($this->readJson('headings', []));
        $this->hero = self::obj($this->readJson('hero', []));
        $this->leadership = js_filter(self::items($this->readJson('leadership', ['items' => []])), fn($l) => js_truthy($l) && str(js_get($l, 'role')) !== '');
        $this->galleryItems = js_filter(self::items($this->readJson('gallery', ['items' => []])), fn($g) => js_truthy($g) && str(js_get($g, 'image')) !== '');
        $this->softwareRaw = js_filter(self::items($this->readJson('software', ['items' => []])), fn($s) => js_truthy($s) && str(js_get($s, 'name')) !== '' && str(js_get($s, 'paper_slug')) !== ''
            && ($this->safeUrl(js_get($s, 'url')) !== '' || $this->safeUrl(js_get($s, 'code_url')) !== '' || $this->safeUrl(js_get($s, 'dataset_url')) !== '' || $this->safeUrl(js_get($s, 'artifact_url')) !== ''));
        $this->serviceItems = js_filter(self::items($this->readJson('service', ['items' => []])), fn($s) => js_truthy($s) && str(js_get($s, 'role')) !== '');
        $this->newsItems = js_filter(self::items($this->readJson('news', ['items' => []])), fn($n) => js_truthy($n) && str(js_get($n, 'title')) !== '');
        $this->resolveIdentity();

        // tag vocabularies
        $list = self::vocabList(js_get($this->settings, 'tagVocabulary'));
        $out = $list ?: $S['DEFAULT_TAGS'];
        foreach ($S['DEFAULT_TAGS'] as $d) {
            if (!in_array($d['tag'], $S['UMBRELLA_TAGS'], true)) continue;
            foreach ($out as $k => $t) { if (js_lower($t['tag']) === js_lower($d['tag'])) { if ($t['label'] === '') $out[$k]['label'] = $d['label']; break; } }
        }
        $this->TAG_VOCAB = $out;
        $this->ML_VOCAB = self::vocabList(js_get($this->settings, 'mlTopicVocabulary'));
        $v = self::strList(js_get($this->settings, 'areaTags'));
        $this->AREA_TAGS = $v ?: ['Software Maintenance & Evolution', 'Program Comprehension', 'Code Clones', 'Refactoring', 'Empirical SE', 'AI4SE', 'SE4AI', 'Green & Sustainable SE'];
        $v = self::strList(js_get($this->settings, 'mlAreaTags'));
        $this->ML_AREA_TAGS = $v ?: ['Model Merging', 'Model Compression', 'Out-of-Distribution Generalization', 'Code Language Models', 'LLM-Generated Code', 'Efficient & Green LLMs'];

        // expected PhD completion
        $edu = js_get($this->about, 'education');
        $this->EDU = js_filter(js_is_array($edu) ? $edu : [], fn($e) => js_truthy($e) && js_truthy(js_get($e, 'degree')));
        $this->PHD_ROW = js_find($this->EDU, fn($e) => jtest('/ph\.?\s*d/i', str(js_get($e, 'degree'))));
        $raw = self::stripEmoji(js_or(js_get($this->PHD_ROW ?? [], 'expected'), js_get($this->about, 'expected'), js_get($this->hero, 'expected')));
        $iso = jmatch('/^(\d{4})-(\d{2})/', $raw);
        $M3 = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        $this->EXPECTED = $iso ? (($M3[(int)$iso[2] - 1] ?? 'undefined') . ' ' . $iso[1]) : $raw;
        $this->EXPECTED_LONG = jreplace('/^([A-Za-z]{3})[a-z]*\.?(?=\s)/', function ($m) use ($S) {
            foreach ($S['MONTHS_LONG'] as $x) if (js_starts_with($x, $m[1])) return $x;
            return $m[0];
        }, $this->EXPECTED, false);

        // documents
        $docs = [];
        foreach (self::items($this->readJson('documents', ['items' => []])) as $d) {
            if (!js_truthy($d) || $this->safeUrl(js_get($d, 'url')) === '' || js_get($d, 'hidden') === true || jtest('/^(true|yes|1)$/i', str(js_get($d, 'hidden')))) continue;
            $kind = str(js_get($d, 'kind')) !== '' ? str(js_get($d, 'kind')) : 'other';
            $label = self::stripEmoji(js_get($d, 'label'));
            if ($label === '') $label = $S['DOC_LABELS'][str(js_get($d, 'kind'))] ?? 'Document';
            $docs[] = ['kind' => $kind, 'url' => $this->safeUrl(js_get($d, 'url')), 'label' => $label];
        }
        if ($this->safeUrl(js_get($this->settings, 'resumeUrl')) !== '' && !js_some($docs, fn($d) => $d['kind'] === 'cv')) array_unshift($docs, ['kind' => 'cv', 'url' => $this->safeUrl(js_get($this->settings, 'resumeUrl')), 'label' => 'CV']);
        $this->documents = $docs;
        $this->cvDoc = js_find($docs, fn($d) => $d['kind'] === 'cv') ?? js_find($docs, fn($d) => $d['kind'] === 'resume');

        $bh = js_get($this->headings, 'blog');
        $this->BLOG_SHOWN = !(js_get($bh, 'hidden') === true || js_get($this->settings, 'blogInNav') === false);
        $l = self::idStr(js_get($this->hero, 'title'));
        if ($l === '') $l = self::idStr(js_get($this->hero, 'titleLine'));
        if ($l === '') $l = self::idStr(js_get($this->hero, 'line2'));
        if ($l === '') $l = 'PhD Researcher in Computer Science · University of Saskatchewan';
        $this->NAME_LINE = ($this->EXPECTED !== '' && !jtest('/expected/i', $l)) ? $l . ' · expected ' . $this->EXPECTED : $l;

        // themes
        $rt = js_get($this->research, 'themes');
        if (js_is_array($rt) && js_some($rt, fn($t) => js_truthy($t) && (js_truthy(js_get($t, 'title')) || js_truthy(js_get($t, 'label'))))) {
            $th = [];
            foreach ($rt as $t) {
                $t = is_array($t) ? $t : [];
                $ps = js_get($t, 'paper_slugs');
                $kw = js_get($t, 'keywords');
                $kwl = js_filter(array_map(fn($x) => self::stripEmoji($x), js_is_array($kw) ? $kw : jsplit('/[,;\n]/', str($kw))), fn($x) => $x !== '');
                $e = ['id' => self::slugify(js_or(js_get($t, 'id'), js_get($t, 'title'), js_get($t, 'label'))), 'name' => self::stripEmoji(js_or(js_get($t, 'title'), js_get($t, 'label'))),
                    'papers' => self::asSlugs(js_truthy($ps) && count(self::asSlugs($ps)) ? $ps : js_get($t, 'papers')),
                    'summary' => self::stripEmoji(js_or(js_get($t, 'summary'), js_get($t, 'description'))), 'keyResult' => self::stripEmoji(js_get($t, 'key_result')), 'keywords' => $kwl];
                if ($e['id'] !== '' && $e['name'] !== '') $th[] = $e;
            }
            $also = js_get($this->research, 'also');
            if (js_is_array($also) && count($also)) $th[] = ['id' => 'other-work', 'name' => 'Other work', 'papers' => self::asSlugs($also)];
            $this->THEMES = $th;
        } else {
            $this->THEMES = array_map(fn($t) => ['id' => $t['id'], 'name' => $t['name'], 're' => jre('/' . $t['source'] . '/' . $t['flags'])], $S['FALLBACK_THEMES']);
        }

        // publications + validation
        $pubs = [];
        foreach ($this->pubsRaw as $i => $p) $pubs[] = $this->normalize($p, $i);
        $seen = [];
        foreach ($pubs as $p) {
            $label = 'publication #' . ($p['index'] + 1) . ' "' . js_or(self::clip($p['title'], 60), '(untitled)') . '"';
            if ($p['title'] === '') $this->errors[] = "$label: missing title";
            if (!preg_match('/^\d{4}$/D', $p['year'])) $this->errors[] = "$label: missing or invalid year";
            if ($p['venue'] === '') $this->errors[] = "$label: missing venue";
            if ($p['doi'] === '' && $p['arxiv'] === '' && !($p['type'] === 'thesis' && $p['handle'] !== '')) $this->warn("$label has no DOI or arXiv id" . ($p['type'] === 'thesis' ? ' (or, for a thesis, a handle)' : '') . ' yet: its page has no DOI link, citation_doi or identifier until one is added');
            if ($p['handle'] !== '' && !jtest('/^https:\/\/hdl\.handle\.net\/\d+(\.\d+)*\/\S+$/', $p['handle'])) $this->errors[] = "$label: handle \"" . str(js_get($p['raw'], 'handle')) . '" is not a handle (expected https://hdl.handle.net/<prefix>/<id>)';
            if (!in_array($p['type'], $S['KNOWN_TYPES'], true)) $this->warn("$label has unknown type \"{$p['type']}\" (known: " . implode(', ', $S['KNOWN_TYPES']) . ')');
            foreach ($p['tags'] as $t) if (!$this->vocabFor($t)) $this->warn("$label tag \"$t\" is not in settings.tagVocabulary");
            foreach ($p['ml'] as $t) if ($this->ML_VOCAB && !$this->mlVocabFor($t)) $this->warn("$label ML topic \"$t\" is not in settings.mlTopicVocabulary");
            foreach ($p['equal'] as $n) if (!js_some($p['authors'], fn($a) => self::sameAuthor($a, $n))) $this->warn("$label equal_contribution name \"$n\" is not one of its authors");
            if ($p['doi'] !== '' && !jtest('/^10\.\d{4,9}\/\S+$/', $p['doi'])) $this->errors[] = "$label: DOI \"{$p['doi']}\" is not a bare DOI (expected 10.xxxx/...)";
            if ($p['arxiv'] !== '' && !jtest('/^\d{4}\.\d{4,5}(v\d+)?$/', $p['arxiv'])) $this->errors[] = "$label: arXiv id \"{$p['arxiv']}\" is malformed";
            if (strlen($p['slug']) > 120) $this->errors[] = "$label: slug \"" . substr($p['slug'], 0, 40) . '…" is longer than 120 characters';
            if (!preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/D', $p['slug'])) $this->errors[] = "$label: slug \"{$p['slug']}\" must be lowercase kebab-case";
            if (array_key_exists($p['slug'], $seen)) $this->errors[] = "$label: duplicate slug \"{$p['slug']}\" (also used by #" . ($seen[$p['slug']] + 1) . ')';
            $seen[$p['slug']] = $p['index'];
            if (!$p['authors']) $this->errors[] = "$label: no authors";
            $af = js_get($p['raw'], 'authors_full');
            if (!js_is_array($af) || !count($af)) $this->warn("$label has no authors_full; using the abbreviated author string");
            if ($p['abstract'] === '') $this->warn("$label has no abstract (Google Scholar needs a visible abstract)");
        }
        $this->slugs = array_values(array_unique(array_map(fn($p) => $p['slug'], $pubs)));
        foreach ($this->faqItems as $f) {
            foreach (jmatch_all('/\/publications\/([a-z0-9-]+)\//', str(js_get($f, 'a'))) as $m) {
                if (!in_array($m[1], $this->slugs, true)) $this->warn('FAQ "' . self::clip(js_get($f, 'q'), 50) . "\" links to unknown paper /publications/{$m[1]}/");
            }
        }
        $this->facts = ['papers' => $this->slugs, 'faq' => count($this->faqItems) > 0];
        if ($this->errors || $this->env['check']) return $this;

        $this->VISIT_URL = (string)$this->env['visitUrl'];
        $pubs = js_sort($pubs, fn($a, $b) => js_locale_compare($b['date'], $a['date']) ?: ($a['index'] - $b['index']));
        $this->pubs = $pubs;
        $this->PUBS_LASTMOD = self::newest(array_map(fn($p) => $this->paperLastmod($p), $pubs), $this->sectionDate('publications'), $this->sectionDate('research'));
        $dated = array_map(fn($x) => js_truthy($x) ? js_get($x, 'date') : $x, array_merge($this->newsItems, $this->highlights, $this->serviceItems, $this->leadership));
        $this->HOME_LASTMOD = self::newest($this->PUBS_LASTMOD, js_get($this->metricsJson, 'updated'), array_map(fn($b) => $this->blogLastmod($b), $this->blogPosts), $dated,
            array_map(fn($s) => $this->sectionDate($s), ['publications', 'highlights', 'news', 'service', 'leadership', 'blog', 'hero', 'research', 'about', 'metrics']));
        $this->softwareItems = js_filter($this->softwareRaw, function ($s) {
            $ok = in_array(self::slugify(js_get($s, 'paper_slug')), $this->slugs, true);
            if (!$ok) $this->warn('Tools & Benchmarks item "' . js_string(js_get($s, 'name')) . '" names paper_slug "' . js_string(js_get($s, 'paper_slug')) . '", which is not a publication; left out');
            return $ok;
        });

        // images
        // mirrors headshotList(): once the owner has set a photo, only that one
        $list = function (array $us) {
            $hs = [];
            foreach (js_uniq(js_filter($us, fn($x) => $x !== '')) as $u) {
                $size = $this->localImageSize($u);
                if ($size || preg_match('/^https?:\/\//', $u)) $hs[] = ['u' => $u, 'size' => $size];
            }
            return $hs;
        };
        $hs = $list([$this->safeUrl($this->PHOTO_SET['jpg'] ?? ''), $this->safeUrl(js_get($this->hero, 'photo')), $this->safeUrl(js_get($this->about, 'photo'))]);
        if (!$hs) $hs = $list(['/images/palash_roy_headshot.jpg', '/images/palash_roy.jpg', '/images/palash_ranjan_roy.jpg']);
        $this->HEADSHOTS = $hs;
        $this->PERSON_IMAGES = array_map(function ($h) {
            $extra = ['caption' => $this->NAME];
            $gc = $this->galleryCredit($h['u']);
            if ($gc !== '') $extra['creditText'] = $gc;
            return $this->imageObject($h['u'], $h['size'], $extra);
        }, $hs);

        // identity graph
        $this->buildAwards();
        $this->buildKnows();
        $this->buildNews();
        $this->PERSON_DESC = $this->SUMMARY_TEXT . ($this->EXPECTED_LONG !== '' ? ' PhD expected ' . $this->EXPECTED_LONG . '.' : '');
        $this->websiteNode = [
            '@type' => 'WebSite', '@id' => $this->WEBSITE_ID, 'url' => $this->SITE . '/', 'name' => $this->NAME, 'inLanguage' => 'en',
            'description' => self::idStr(js_get($this->SEO, 'websiteDescription')) !== '' ? self::idStr(js_get($this->SEO, 'websiteDescription')) : 'Research website of ' . $this->NAME . ': publications, software and contact.', 'publisher' => ['@id' => $this->PERSON_ID], 'about' => ['@id' => $this->PERSON_ID],
        ];
        $profile = [
            '@type' => 'ProfilePage', '@id' => $this->SITE . '/#profilepage', 'url' => $this->SITE . '/',
            'name' => self::idStr(js_get($this->SEO, 'ogTitle')) !== '' ? self::idStr(js_get($this->SEO, 'ogTitle')) : $this->NAME . ' | ' . $this->JOB_TITLE . ' | ' . ($this->AFFILIATION ? $this->AFFILIATION[0]['name'] : 'University of Saskatchewan'),
            'isPartOf' => ['@id' => $this->WEBSITE_ID], 'mainEntity' => ['@id' => $this->PERSON_ID], 'about' => ['@id' => $this->PERSON_ID],
        ];
        if ($this->HOME_LASTMOD !== '') $profile['dateModified'] = $this->HOME_LASTMOD;
        $profile['inLanguage'] = 'en';
        $profile['hasPart'] = array_map(fn($p) => ['@type' => $p['type'] === 'thesis' ? 'Thesis' : 'ScholarlyArticle', '@id' => $p['url'] . '#article', 'url' => $p['url'], 'name' => $p['title']], $pubs);
        $homeGraph = ['@context' => 'https://schema.org', '@graph' => [$this->websiteNode, $profile, $this->personNode(true)]];

        // shells: homepage + prerendered blog pages
        $shells = $this->env['shells'];
        if (isset($shells['index.html'])) {
            $html = $this->injectGraph($shells['index.html'], $homeGraph);
            $html = $this->fixCreator($html);
            $html = $this->homeHead($html);
            $this->files['index.html'] = $html;
        }
        $blogGraphPerson = $this->personNode(true); unset($blogGraphPerson['mainEntityOfPage']);
        $blogGraph = ['@context' => 'https://schema.org', '@graph' => [$this->websiteNode, $blogGraphPerson, $this->breadcrumbLd([['Home', $this->SITE . '/'], ['Blog', $this->SITE . '/blog/']])]];
        $postBySlugLast = [];
        foreach ($this->blogPosts as $b) $postBySlugLast[$this->postSlug($b)] = $b; // new Map(): the last entry wins
        foreach ($shells as $rel => $html) {
            if (!preg_match('#^blog/(?:(.+)/)?index\.html$#', $rel, $m)) continue;
            $r = $m[1] ?? '';
            $post = ($r !== '' && strpos($r, '/') === false) ? ($postBySlugLast[$r] ?? null) : null;
            $html = $this->injectGraph($html, $post ? $this->postGraph($post, $r) : $blogGraph);
            $html = $this->shareImage($html);
            if ($post) $html = $this->postHead($html, $post);
            $html = $this->fixCreator($html);
            $this->files[$rel] = $html;
        }

        $this->SHOW_PROMPT = js_get($this->settings, 'themePrompt') !== false && !jtest('/^(true|yes|1)$/i', str(js_get($this->settings, 'themePromptOff')));
        $this->buildNav();
        $this->YEAR();
        $this->papersOut();
        $this->pubsIndex();
        $this->faqOut();
        $this->aboutOut();
        $this->photosOut();
        $this->blogFallback();
        $this->maintenanceOut();
        $this->notFoundOut();
        $this->softwareOut();
        $this->llmsOut();
        $this->sitemapOut();
        $this->facts['photos'] = count($this->photos) > 0;
        $this->facts['blog'] = array_map(fn($b) => $this->postSlug($b), $this->blogPosts);
        return $this;
    }

    private static function obj($v): array { return is_array($v) ? $v : []; }
    private function postSlug($b): string { return str(js_get($b, 'slug')) !== '' ? str(js_get($b, 'slug')) : self::slugify(js_get($b, 'title')); }
    private function sectionDate(string $s): string { return (string)($this->env['sectionDates'][$s] ?? ''); }
    private function paperLastmod(array $p): string { return self::newest(js_get($p['raw'], 'updated'), $p['date'], (string)($this->env['itemDates']['pub:' . $p['slug']] ?? '')); }
    private function blogLastmod($b): string { return self::newest(js_get($b, 'updated'), js_get($b, 'date'), (string)($this->env['itemDates']['blog:' . $this->postSlug($b)] ?? '')); }

    // ---------------------------------------------------------------- images
    // mirrors localImageSize() (document root only; the build's dist holds the public/ copies)
    public function localImageSize($u): ?array
    {
        $u = str($u);
        if (!js_starts_with($u, '/') || js_starts_with($u, '//')) return null;
        if (array_key_exists($u, $this->sizeCache)) return $this->sizeCache[$u];
        $rel = safe_decode(preg_split('/[?#]/', $u)[0]);
        $f = seo_doc_file($this->env['docroot'], $rel, true);
        $size = $f !== null ? seo_image_size_file($f) : null;
        return $this->sizeCache[$u] = $size;
    }
    private function encPath(string $u): string { return preg_match('/^https?:\/\//i', $u) ? $u : encode_uri(safe_decode($u)); }
    private function imageObject(string $u, ?array $size, array $extra = []): array
    {
        $o = ['@type' => 'ImageObject', 'contentUrl' => $this->abs($this->encPath($u)), 'url' => $this->abs($this->encPath($u))];
        if ($size) { $o['width'] = $size['w']; $o['height'] = $size['h']; }
        foreach ($extra as $k => $v) $o[$k] = $v;
        return $o;
    }
    private function galleryCredit(string $u): string
    {
        $g = js_find($this->galleryItems, fn($x) => safe_decode(str(js_get($x, 'image'))) === safe_decode($u));
        return $g ? self::stripEmoji(js_or(js_get($g, 'creditText'), js_get($g, 'credit'))) : '';
    }

    // ---------------------------------------------------------------- identity graph
    private function isHiddenSocial($s): bool { return js_get($s, 'hidden') === true || jtest('/^(true|yes|1)$/i', str(js_get($s, 'hidden'))); }
    public function xHandle(): string
    {
        $x = js_find($this->socials, fn($s) => js_truthy($s) && !$this->isHiddenSocial($s) && (jtest('/^(x|twitter)$/i', str(js_get($s, 'platform'))) || jtest('/\/\/(www\.)?(twitter|x)\.com\//i', str(js_get($s, 'url')))));
        $m = $x ? jmatch('/\/\/(?:www\.)?(?:twitter|x)\.com\/([A-Za-z0-9_]{1,15})(?:[\/?#]|$)/i', str(js_get($x, 'url'))) : null;
        return $m ? '@' . $m[1] : '';
    }
    private function sameAsList(): array
    {
        $out = [];
        $key = fn($u) => jreplace('/\/+$/', '', jreplace('/^https?:\/\/(www\.)?/', '', js_lower($u), false), false);
        $add = function ($u) use (&$out, $key) {
            $url = jreplace('/[?&]hl=[a-z-]+$/i', '', str($u), false);
            if ($url === '' || jtest('/usask\.ca|doi\.org|arxiv\.org|palashroy\.me/i', $url)) return;
            foreach ($out as $x) if ($key($x) === $key($url)) return;
            $out[] = $url;
        };
        foreach ($this->IDS as $i) $add($i['url'] ?? '');
        $shown = js_filter($this->socials, fn($s) => js_truthy($s) && !$this->isHiddenSocial($s) && preg_match('/^https?:\/\//', str(js_get($s, 'url'))));
        if (!js_some($this->socials, fn($s) => js_truthy($s) && preg_match('/^https?:\/\//', str(js_get($s, 'url'))))) foreach ($this->S['EXTRA_SAMEAS'] as $u) $add($u);
        foreach ($shown as $s) $add(js_get($s, 'url'));
        return $out;
    }
    private static function isNom($h): bool { return js_lower(str(js_get($h, 'kind'))) === 'nomination' || jtest('/nominat|nominee/i', self::stripEmoji(js_get($h, 'title'))); }
    private function buildAwards(): void
    {
        $this->awards = [];
        foreach ($this->highlights as $h) {
            if (!js_truthy($h) || self::stripEmoji(js_get($h, 'title')) === '' || self::isNom($h)) continue;
            $t = self::stripEmoji(js_get($h, 'title')); $org = self::stripEmoji(js_get($h, 'organization'));
            $m = jmatch('/^(.*?)\s*(\((?:19|20)\d{2}[^)]*\))$/', $t);
            $mv = !$m ? jmatch('/^(.*?)\s*\(([^()]*?)\s*((?:19|20)\d{2})\)$/', $t) : null;
            if ($m) { $name = $m[1]; $year = $m[2]; }
            elseif ($mv && $mv[2] !== '' && js_includes(js_lower($org), js_lower($mv[2]))) { $name = $mv[1]; $year = '(' . $mv[3] . ')'; }
            else { $name = $t; $year = ''; }
            $orgShort = ($org !== '' && !js_includes(js_lower($name), js_lower($org))) ? $org : '';
            $y = $year !== '' ? $year : (preg_match('/^(19|20)\d{2}$/D', str(js_get($h, 'year'))) ? '(' . str(js_get($h, 'year')) . ')' : '');
            $this->awards[] = implode(', ', js_filter([$name, $orgShort], fn($x) => $x !== '')) . ($y !== '' ? ' ' . $y : '');
        }
    }
    private function buildKnows(): void
    {
        $out = $this->KNOWS_SEED;
        $usedTags = []; $usedMl = [];
        foreach ($this->pubs as $p) { foreach ($p['tags'] as $t) $usedTags[js_lower($t)] = 1; foreach ($p['ml'] as $t) $usedMl[js_lower($t)] = 1; }
        $push = function ($k) use (&$out) { if ($k === '' || $k === null) return; foreach ($out as $x) if (js_lower($x) === js_lower($k)) return; $out[] = $k; };
        foreach ($this->TAG_VOCAB as $v) {
            if ($v['label'] === '' || $v['label'] === $v['tag']) continue;
            if (!in_array($v['tag'], $this->S['UMBRELLA_TAGS'], true) && !isset($usedTags[js_lower($v['tag'])])) continue;
            $push($v['label']);
        }
        foreach ($this->AREA_TAGS as $t) if (isset($usedTags[js_lower($t)])) $push($this->tagLabel($t) !== '' ? $this->tagLabel($t) : $t);
        foreach ($this->ML_AREA_TAGS as $t) if (isset($usedMl[js_lower($t)])) $push($this->mlLong($t));
        $this->KNOWS = $out;
    }
    private function buildNews(): void
    {
        $itn = js_get($this->about, 'inTheNews');
        $list = [];
        foreach (js_is_array($itn) ? $itn : [] as $n) {
            $e = ['title' => self::stripEmoji(js_get($n, 'title')), 'url' => jtest('/^https?:\/\//i', str(js_get($n, 'url'))) ? str(js_get($n, 'url')) : '', 'publisher' => self::stripEmoji(js_get($n, 'publisher')),
                'date' => self::isoDay(js_get($n, 'date')), 'kind' => js_lower(str(js_get($n, 'kind'))) === 'profile' ? 'profile' : 'article'];
            if ($e['title'] !== '' && $e['url'] !== '') $list[] = $e;
        }
        $this->IN_THE_NEWS = $list;
        $arts = js_sort(js_filter($list, fn($n) => $n['kind'] === 'article' && $n['date'] !== ''), fn($a, $b) => js_locale_compare($b['date'], $a['date']));
        $this->NEWS_ARTICLES = array_map(function ($n) {
            $o = ['@type' => 'NewsArticle', 'headline' => self::clip($n['title'], 110), 'url' => $n['url'], 'datePublished' => $n['date']];
            if ($n['publisher'] !== '') $o['publisher'] = ['@type' => 'Organization', 'name' => $n['publisher'], 'url' => js_origin($n['url']) . '/'];
            return $o;
        }, $arts);
    }
    private function personNode(bool $full = true): array
    {
        $base = ['@type' => 'Person', '@id' => $this->PERSON_ID, 'name' => $this->NAME, 'url' => $this->SITE . '/'];
        if (!$full) return $base + ['identifier' => ['@type' => 'PropertyValue', 'propertyID' => 'ORCID', 'value' => $this->ORCID], 'sameAs' => ['https://orcid.org/' . $this->ORCID]];
        $n = $base + [
            'givenName' => $this->GIVEN, 'additionalName' => $this->ADDITIONAL, 'familyName' => $this->FAMILY,
            'alternateName' => $this->ALT_NAMES,
            'description' => $this->PERSON_DESC,
            'jobTitle' => $this->JOB_TITLE,
            'email' => 'mailto:' . $this->EMAIL,
            'image' => $this->PERSON_IMAGES ?: [['@type' => 'ImageObject', 'url' => $this->IMAGE, 'contentUrl' => $this->IMAGE, 'caption' => $this->NAME]],
            'affiliation' => $this->AFFILIATION,
            'memberOf' => $this->MEMBER_OF,
            'alumniOf' => $this->ALUMNI_OF,
        ];
        if ($this->NEWS_ARTICLES) $n['subjectOf'] = $this->NEWS_ARTICLES;
        $n['award'] = $this->awards;
        $n['knowsAbout'] = $this->KNOWS;
        $n['identifier'] = array_map(fn($i) => ['@type' => 'PropertyValue'] + $i, $this->IDS);
        $n['sameAs'] = $this->sameAsList();
        $n['mainEntityOfPage'] = ['@id' => $this->SITE . '/#profilepage'];
        return $n;
    }

    // mirrors injectGraph()
    private function injectGraph(string $html, array $graph): string
    {
        $tag = self::ldScript($graph);
        if (strpos($html, '<!--SEO-GRAPH-->') !== false) return self::replaceFirst($html, '<!--SEO-GRAPH-->', $tag);
        $this->warn('no <!--SEO-GRAPH--> placeholder in a shell; inserting before </head>');
        return self::replaceFirst($html, '</head>', $tag . "\n</head>");
    }
    public static function replaceFirst(string $s, string $a, string $b): string
    {
        $i = strpos($s, $a);
        return $i === false ? $s : substr($s, 0, $i) . $b . substr($s, $i + strlen($a));
    }
    // mirrors fixCreator()
    public function fixCreator(string $html): string
    {
        $h = $this->xHandle();
        return jreplace('/[ \t]*<meta name="twitter:creator" content="[^"]*"\s*\/?>\n?/', fn() => $h !== '' ? '  <meta name="twitter:creator" content="' . self::esc($h) . "\" />\n" : '', $html, false);
    }
    // mirrors shareImage(): og:image / twitter:image of the homepage and blog shells
    public function shareImage(string $html): string
    {
        $setMeta = function ($attr, $key, $val) use (&$html) {
            $re = '/(<meta ' . $attr . '="' . preg_quote($key, '/') . '" content=")[^"]*(")/';
            $html = jreplace($re, fn($m) => $m[1] . self::esc($val) . $m[2], $html, false);
        };
        $img = $this->safeUrl(js_get($this->SEO, 'image'));
        if ($img === '') $img = $this->safeUrl(js_get($this->hero, 'photo'));
        if ($img === '') $img = $this->safeUrl(js_get($this->about, 'photo'));
        if ($img !== '') {
            $u = $this->abs($this->encPath($img));
            $setMeta('property', 'og:image', $u);
            $setMeta('name', 'twitter:image', $u);
            $size = $this->localImageSize($img);
            if ($size) { $setMeta('property', 'og:image:width', (string)$size['w']); $setMeta('property', 'og:image:height', (string)$size['h']); }
            else $html = jreplace('/[ \t]*<meta property="og:image:(?:width|height)" content="[^"]*"\s*\/?>\n?/', '', $html);
        }
        $setMeta('property', 'og:image:alt', $this->OG_ALT); $setMeta('name', 'twitter:image:alt', $this->OG_ALT);
        return $html;
    }
    // mirrors homeHead()
    public function homeHead(string $html): string
    {
        $seo = $this->SEO;
        $v = fn($k) => self::stripEmoji(js_get($seo, $k));
        $setMeta = function ($attr, $key, $val) use (&$html) {
            $re = '/(<meta ' . $attr . '="' . preg_quote($key, '/') . '" content=")[^"]*(")/';
            $html = jreplace($re, fn($m) => $m[1] . self::esc($val) . $m[2], $html, false);
        };
        $N = self::esc($this->NAME);
        $bm = jmatch('/<meta name="author" content="([^"]*)"/', $html);
        $builtName = $bm ? $bm[1] : '';
        if ($builtName !== '' && $builtName !== $N) {
            $cut = strpos($html, '<script type="application/ld+json">');
            $end = $cut === false ? strpos($html, '</head>') : $cut;
            if ($end !== false && $end > 0) $html = str_replace($builtName, $N, substr($html, 0, $end)) . substr($html, $end);
            $html = jreplace('/(<a [^>]*class="wordmark[^"]*"[^>]*>)([^<]*)(<\/a>)/', fn($m) => $m[2] === $builtName ? $m[1] . $N . $m[3] : $m[0], $html, false);
            $html = jreplace('/(<h1 class="hero-name" id="hero-name">)([^<]*)(<\/h1>)/', fn($m) => $m[2] === $builtName ? $m[1] . $N . $m[3] : $m[0], $html, false);
        }
        $html = jreplace('/<noscript>[\s\S]*?<\/noscript>/', function ($m) use ($builtName, $N) {
            $em = jmatch('/mailto:([^"]+)"/', $m[0]);
            $out = $builtName !== '' && $builtName !== $N ? str_replace($builtName, $N, $m[0]) : $m[0];
            if ($em && $em[1] !== $this->EMAIL) $out = str_replace($em[1], $this->EMAIL, $out);
            return $out;
        }, $html, false);
        $setMeta('property', 'profile:first_name', $this->GIVEN);
        $setMeta('property', 'profile:last_name', $this->FAMILY);
        if ($v('title') !== '') $html = jreplace('/<title>[^<]*<\/title>/', fn() => '<title>' . self::esc($v('title')) . '</title>', $html, false);
        if ($v('description') !== '') $setMeta('name', 'description', $v('description'));
        if ($v('ogTitle') !== '') $setMeta('property', 'og:title', $v('ogTitle'));
        if ($v('ogDescription') !== '') $setMeta('property', 'og:description', $v('ogDescription'));
        if ($v('twitterTitle') !== '') $setMeta('name', 'twitter:title', $v('twitterTitle'));
        if ($v('twitterDescription') !== '') $setMeta('name', 'twitter:description', $v('twitterDescription'));
        $html = $this->shareImage($html);
        $photo = js_or(js_get($this->hero, 'photo'), js_get($this->about, 'photo'));
        $SIZES = '(min-width: 768px) 200px, 96px';
        $set = $this->PHOTO_SET['srcset'] ?? '';
        if (js_truthy($photo) && is_string($photo)) {
            $ph = self::esc($photo);
            $type = $this->PHOTO_SET['type'] ?? 'image/webp';
            $pre = $set !== '' ? '<link rel="preload" as="image" type="' . $type . '" fetchpriority="high" imagesrcset="' . self::esc($set) . '" imagesizes="' . $SIZES . '">' : '<link rel="preload" as="image" href="' . $ph . '" fetchpriority="high">';
            $im = '<img src="' . $ph . '" alt="' . self::esc($this->PHOTO_ALT) . '" class="hero-photo" width="200" height="200" decoding="async" fetchpriority="high">';
            $html = jreplace('/<link rel="preload" as="image"[^>]*imagesrcset="[^"]*headshot[^"]*"[^>]*>/', fn() => $pre, $html, false);
            $html = jreplace('/(<div class="hero-photo-wrap">)(?:<picture>[\s\S]*?<\/picture>|<img [^>]*>)(<\/div>)/', fn($m) => $m[1] . ($set !== '' ? '<picture><source srcset="' . self::esc($set) . '" sizes="' . $SIZES . '" type="' . $type . '">' . $im . '</picture>' : $im) . $m[2], $html, false);
        } else {
            $html = jreplace('/(<div class="hero-photo-wrap">(?:<picture>[\s\S]*?)?<img [^>]*?alt=")([^"]*)(")/', fn($m) => $m[1] . self::esc($this->PHOTO_ALT) . $m[3], $html, false);
        }
        return $html;
    }
    private function absUrl($u): string
    {
        $s = str($u);
        return preg_match('/^https?:\/\//i', $s) ? $s : (preg_match('/^\//', $s) ? $this->SITE . $s : '');
    }
    // mirrors postGraph()
    public function postGraph($b, string $slug): array
    {
        $url = $this->SITE . '/blog/' . $slug . '/';
        $cover = $this->absUrl(js_get($b, 'cover'));
        $tags = js_filter(array_map(fn($t) => js_trim(self::stripEmoji(js_sv($t))), js_is_array(js_get($b, 'tags')) ? js_get($b, 'tags') : explode(',', str(js_get($b, 'tags')))), fn($x) => $x !== '');
        $person = $this->personNode(true); unset($person['mainEntityOfPage']);
        $post = ['@type' => 'BlogPosting', '@id' => $url . '#post', 'headline' => self::stripEmoji(js_get($b, 'title')), 'url' => $url, 'mainEntityOfPage' => $url];
        if (str(js_get($b, 'summary')) !== '') $post['description'] = self::stripEmoji(js_get($b, 'summary'));
        if (self::isoDay(js_get($b, 'date')) !== '') { $post['datePublished'] = self::isoDay(js_get($b, 'date')); $post['dateModified'] = js_or(self::isoDay(js_get($b, 'updated')), self::isoDay(js_get($b, 'date'))); }
        $post['author'] = ['@id' => $this->PERSON_ID];
        $post['publisher'] = ['@id' => $this->PERSON_ID];
        if ($cover !== '') $post['image'] = $cover;
        if ($tags) $post['keywords'] = $tags;
        $post['isPartOf'] = ['@id' => $this->WEBSITE_ID];
        $post['inLanguage'] = 'en';
        return ['@context' => 'https://schema.org', '@graph' => [$this->websiteNode, $person, $post, $this->breadcrumbLd([['Home', $this->SITE . '/'], ['Blog', $this->SITE . '/blog/'], [self::stripEmoji(js_get($b, 'title')), $url]])]];
    }
    // mirrors postHead()
    public function postHead(string $html, $b): string
    {
        $cover = $this->absUrl(js_get($b, 'cover'));
        $d = self::isoDay(js_get($b, 'date'));
        $html = jreplace('/<meta property="og:type" content="[^"]*"\s*\/?>/', fn() => '<meta property="og:type" content="article" />'
            . ($d !== '' ? "\n  <meta property=\"article:published_time\" content=\"$d\" />" : '')
            . "\n  <meta property=\"article:author\" content=\"{$this->SITE}/\" />", $html, false);
        $html = jreplace('/[ \t]*<meta property="profile:[a-z_]+" content="[^"]*"\s*\/?>\n?/', '', $html);
        $html = jreplace('/[ \t]*<link rel="alternate" type="text\/markdown" href="\/publications\.md"[^>]*>\n?/', '', $html);
        if ($cover !== '') {
            $c = self::esc($cover);
            $html = jreplace('/(<meta (?:property|name)="(?:og:image|twitter:image)" content=")[^"]*(")/', fn($m) => $m[1] . $c . $m[2], $html);
            $html = jreplace('/[ \t]*<meta property="og:image:(?:width|height)" content="[^"]*"\s*\/?>\n?/', '', $html);
            $alt = self::esc('Cover image: ' . self::stripEmoji(js_get($b, 'title')));
            $html = jreplace('/(<meta (?:property|name)="(?:og:image:alt|twitter:image:alt)" content=")[^"]*(")/', fn($m) => $m[1] . $alt . $m[2], $html);
        }
        return $html;
    }

    // ---------------------------------------------------------------- page shell
    private function hn(array $keys, string $d, ?string $ignore = null): string
    {
        foreach ($keys as $k) { $v = $this->hnav($k, ''); if ($v !== '' && !($ignore && jtest($ignore, $v))) return $v; }
        return $d;
    }
    private function platformOf($s): string
    {
        $given = js_lower(str(js_get($s, 'platform')));
        if ($given !== '') return $given;
        $p = preg_replace('/[^a-z]/', '', js_lower(str(js_get($s, 'label')))) ?? '';
        $u = js_lower(str(js_get($s, 'url')));
        $has = fn($re) => preg_match($re, $p) || preg_match($re, $u);
        foreach (['orcid', 'dblp', 'github', 'linkedin', 'researchgate', 'facebook', 'instagram', 'youtube', 'openalex'] as $k) if ($has('/' . $k . '/')) return $k;
        if ($has('/semanticscholar/')) return 'semanticscholar';
        if ($has('/scholar/')) return 'scholar';
        if ($p === 'x' || preg_match('/twitter/', $p) || preg_match('/\/\/(www\.)?(twitter|x)\.com\//', $u)) return 'x';
        return 'website';
    }
    private function buildNav(): void
    {
        $this->NAV_ITEMS = [
            ['research', $this->hn(['research', 'about'], 'Research', '/^(about|home)$/i'), '/#research'],
            ['publications', $this->hn(['publications', 'portfolio'], 'Publications', '/^portfolio$/i'), '/publications/'],
            ['experience', $this->hn(['experience'], 'Experience'), '/#experience'],
        ];
        if ($this->BLOG_SHOWN) $this->NAV_ITEMS[] = ['blog', $this->hn(['blog'], 'Blog'), '/blog/'];
        $this->NAV_ITEMS[] = ['contact', $this->hn(['contact'], 'Contact', '/^collaborate$/i'), '/#contact'];
        $this->TOOLS_LABEL = $this->hn(['software'], 'Tools & Benchmarks', '/^software\b/i');
        $on = [];
        if ($this->softwareItems) $on[] = ['Software', $this->TOOLS_LABEL, '/#software'];
        if (js_some($this->highlights, fn($h) => js_truthy($h) && self::stripEmoji(js_get($h, 'title')) !== '' && !self::isNom($h))) $on[] = ['Awards', $this->hn(['awards', 'highlights'], 'Awards'), '/#awards'];
        if (self::items($this->readJson('news', ['items' => []]))) $on[] = ['News', $this->hn(['news'], 'News'), '/#news'];
        $this->ON_HOME = $on;
        $f = [['research', $this->NAV_ITEMS[0][1], '/#research'], ['publications', $this->NAV_ITEMS[1][1], '/#publications']];
        foreach ($on as $x) if ($x[0] === 'Software') $f[] = $x;
        $f[] = ['experience', $this->hn(['experience'], 'Experience'), '/#experience'];
        foreach ($on as $x) if ($x[0] !== 'Software') $f[] = $x;
        $f[] = ['contact', $this->hn(['contact'], 'Contact', '/^collaborate$/i'), '/#contact'];
        $this->FOOTER_ON_HOME = $f;

        $rank = function ($k) { $i = js_index_of($this->S['PLATFORM_ORDER'], $k); return $i < 0 ? 99 : $i; };
        $pl = [];
        foreach ($this->socials as $s) {
            if (!js_truthy($s) || js_get($s, 'hidden') === true || jtest('/^(true|yes|1)$/i', str(js_get($s, 'hidden'))) || !jtest('/^https?:\/\//i', str(js_get($s, 'url')))) continue;
            $k = $this->platformOf($s);
            $label = self::stripEmoji(js_get($s, 'label'));
            if ($label === '') $label = $this->S['PLATFORM_LABEL'][$k] ?? 'Link';
            $e = ['k' => $k, 'label' => $label, 'url' => jreplace('/[?&]hl=[a-z-]+$/i', '', str(js_get($s, 'url')), false)];
            if ($e['k'] !== 'email') $pl[] = $e;
        }
        $pl = js_sort($pl, fn($a, $b) => $rank($a['k']) - $rank($b['k']));
        if (!$pl) {
            foreach ([['scholar', 'https://scholar.google.com/citations?user=Vy_sw5UAAAAJ'], ['orcid', 'https://orcid.org/' . $this->ORCID], ['dblp', 'https://dblp.org/pid/355/4465.html'], ['github', 'https://github.com/Roy101'], ['linkedin', 'https://www.linkedin.com/in/palashranjanroy/']] as [$k, $url])
                $pl[] = ['k' => $k, 'label' => $this->S['PLATFORM_LABEL'][$k], 'url' => $url];
        }
        $this->profileLinks = $pl;
        $fp = [[$this->NAV_ITEMS[1][1], '/publications/']];
        if ($this->galleryItems) $fp[] = [$this->hnav('photos', 'Photos'), '/photos/'];
        if ($this->faqItems) $fp[] = [$this->hnav('faq', 'Research FAQ'), '/faq/'];
        $fp[] = [$this->hnav('aboutPage', 'About'), '/about/'];
        if ($this->BLOG_SHOWN || $this->blogPosts) $fp[] = [$this->hn(['blog'], 'Blog'), '/blog/'];
        $this->FOOTER_PAGES = $fp;
    }
    private function YEAR(): void
    {
        $y = (string)$this->env['year'];
        $c = $this->fillTokens(self::stripEmoji(js_get($this->footer, 'copyright')));
        $this->COPYRIGHT = self::esc($c !== '' ? $c : '© ' . $y . ' ' . $this->NAME);
        $cv = $this->cvDoc;
        $this->CV_IS_PDF = $cv !== null && jtest('/\.pdf([?#]|$)/i', $cv['url']);
        $n = self::idStr(js_get($this->footer, 'cvNavLabel'));
        $this->CV_NAV = $n !== '' ? $n : 'CV';
        $f = self::idStr(js_get($this->footer, 'cvLabel'));
        $this->CV_FOOT = $f !== '' ? $f : ($this->CV_IS_PDF || $cv === null ? 'CV (PDF)' : 'CV');
    }
    private function navLinks(string $active): string
    {
        $o = '';
        foreach ($this->NAV_ITEMS as [$id, $l, $u]) $o .= '<a href="' . $u . '"' . ($id === $active ? ' aria-current="page"' : '') . '>' . self::esc($l) . '</a>';
        return $o;
    }
    private function icon(string $platform): string
    {
        $p = $platform === 'twitter' ? 'x' : $platform;
        $open = fn($attrs) => '<svg class="ic" width="16" height="16" viewBox="0 0 16 16" aria-hidden="true" focusable="false" ' . $attrs . '>';
        if (isset($this->S['ICON_PATHS'][$p])) return $open('fill="currentColor"') . '<path d="' . $this->S['ICON_PATHS'][$p] . '"/></svg>';
        [$a, $inner] = $this->S['ICON_SPECIAL'][$p] ?? $this->S['ICON_SPECIAL']['globe'];
        return $open($a) . $inner . '</svg>';
    }
    private function profileLink(array $p): string
    {
        return '<a href="' . self::esc($p['url']) . '" aria-label="' . self::esc($p['k'] === 'email' ? 'Email ' . $this->EMAIL : $p['label'] . ' profile') . '">' . $this->icon($p['k']) . '<span>' . self::esc($p['label']) . '</span></a>';
    }
    private function profileByKind(string $k): ?array { return js_find($this->profileLinks, fn($p) => $p['k'] === $k); }
    private function footerHtml(): string
    {
        $cv = $this->cvDoc;
        $o = "<footer class=\"site\"><div class=\"wrap\">\n";
        $fname = self::stripEmoji(js_get($this->footer, 'name'));
        $o .= '<div class="row"><span class="f-name">' . self::esc($fname !== '' ? $fname : $this->NAME) . '</span><a href="mailto:' . self::esc($this->EMAIL) . '">' . self::esc($this->EMAIL) . '</a>'
            . ($cv ? '<a href="' . self::esc($cv['url']) . '"' . ($this->CV_IS_PDF ? ' type="application/pdf"' : '') . '>' . self::esc($this->CV_FOOT) . '</a>' : '') . "</div>\n";
        $o .= (js_get($this->footer, 'showSocials') !== false && $this->profileLinks ? '<ul class="prof" aria-label="Profiles">' . implode('', array_map(fn($p) => '<li>' . $this->profileLink($p) . '</li>', $this->profileLinks)) . '</ul>' : '') . "\n";
        $o .= '<nav class="row pages" aria-label="Site pages">' . implode('', array_map(fn($x) => '<a href="' . $x[1] . '">' . self::esc($x[0]) . '</a>', $this->FOOTER_PAGES)) . "</nav>\n";
        $o .= '<nav class="row onhome" aria-labelledby="f-onhome"><span id="f-onhome">On the homepage:</span>' . implode('', array_map(fn($x) => '<a href="' . $x[2] . '">' . self::esc($x[1]) . '</a>', $this->FOOTER_ON_HOME)) . "</nav>\n";
        $o .= '<div class="copy">' . $this->COPYRIGHT . "</div>\n</div></footer>";
        return $o;
    }
    // mirrors page()
    private function page(array $a): string
    {
        $a += ['ogTitle' => '', 'head' => '', 'jsonld' => null, 'ogType' => 'website', 'crumbs' => '', 'active' => '', 'wide' => false, 'robots' => '', 'beacon' => true, 'fonts' => [500, 600]];
        $S = $this->S;
        $nav = $this->navLinks($a['active']);
        $canonical = $a['canonical'];
        $ogt = $a['ogTitle'] !== '' ? $a['ogTitle'] : $a['title'];
        $cv = $this->cvDoc;
        $h = "<!doctype html>\n<html lang=\"en\">\n<head>\n<meta charset=\"utf-8\">\n<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n";
        $h .= '<title>' . self::esc($a['title']) . "</title>\n";
        $h .= '<meta name="description" content="' . self::esc($a['description']) . "\">\n";
        $h .= ($canonical !== '' ? '<link rel="canonical" href="' . self::esc($canonical) . "\">\n<meta name=\"robots\" content=\"" . ($a['robots'] !== '' ? $a['robots'] : 'index, follow, max-image-preview:large') . '">' : '<meta name="robots" content="noindex">') . "\n";
        $h .= '<meta name="author" content="' . self::esc($this->NAME) . "\">\n";
        $h .= '<meta property="og:type" content="' . $a['ogType'] . "\">\n";
        $h .= '<meta property="og:site_name" content="' . self::esc($this->NAME) . "\">\n";
        $h .= '<meta property="og:title" content="' . self::esc($ogt) . "\">\n";
        $h .= '<meta property="og:description" content="' . self::esc($a['description']) . "\">\n";
        $h .= ($canonical !== '' ? '<meta property="og:url" content="' . self::esc($canonical) . "\">\n" : '') . '<meta property="og:image" content="' . self::esc($this->OG_IMAGE) . "\">\n";
        $h .= '<meta property="og:image:alt" content="' . self::esc($this->OG_ALT) . "\">\n";
        $h .= "<meta name=\"twitter:card\" content=\"summary\">\n";
        $h .= '<meta name="twitter:title" content="' . self::esc($ogt) . "\">\n";
        $h .= '<meta name="twitter:description" content="' . self::esc($a['description']) . "\">\n";
        $h .= '<meta name="twitter:image" content="' . self::esc($this->OG_IMAGE) . "\">\n";
        $h .= "<link rel=\"icon\" href=\"/favicon.svg\" type=\"image/svg+xml\">\n<link rel=\"icon\" href=\"/favicon.ico\" sizes=\"32x32\">\n<link rel=\"apple-touch-icon\" href=\"/apple-touch-icon.png\">\n";
        $h .= "<link rel=\"preload\" href=\"/fonts/source-serif-4-latin-600-normal.woff2\" as=\"font\" type=\"font/woff2\" crossorigin>\n<link rel=\"preload\" href=\"/fonts/inter-latin-400-normal.woff2\" as=\"font\" type=\"font/woff2\" crossorigin>\n";
        foreach ($a['fonts'] as $w) $h .= '<link rel="preload" href="/fonts/inter-latin-' . $w . "-normal.woff2\" as=\"font\" type=\"font/woff2\" crossorigin>\n";
        $h .= $a['head'] . ($a['beacon'] && $this->VISIT_URL !== '' ? '<script src="' . $this->VISIT_URL . "\" defer></script>\n" : '') . '<script>' . $S['THEME_JS'] . "</script>\n";
        $h .= '<style>' . $S['CSS'] . ($a['wide'] ? "\nmain>.wide{max-width:none}" : '') . "</style>\n";
        $h .= ($a['jsonld'] ? self::ldScript($a['jsonld']) . "\n" : '') . "</head>\n<body>\n<a class=\"skip\" href=\"#main\">Skip to content</a>\n<header class=\"site\"><div class=\"wrap\">\n";
        $h .= '<a class="wordmark" href="/">' . self::esc($this->NAME) . "</a>\n";
        $h .= '<nav class="site" aria-label="Primary">' . $nav . "</nav>\n<div class=\"hdr-r\">\n";
        $h .= ($cv ? '<a class="btn" href="' . self::esc($cv['url']) . '">' . self::esc($this->CV_NAV) . "</a>\n" : '') . '<button type="button" class="tt" id="tt" aria-label="Switch to dark theme" title="Switch to dark theme">' . $S['MOON'] . $S['SUN'] . "</button>\n";
        $h .= '<details class="menu"><summary>' . $S['MENU_ICON'] . '<span>Menu</span></summary><nav aria-label="Site menu">' . $nav . ($cv ? '<a class="m-cv" href="' . self::esc($cv['url']) . '">' . self::esc($this->CV_NAV) . '</a>' : '')
            . ($this->ON_HOME ? '<span class="grp">On the homepage</span>' : '') . implode('', array_map(fn($x) => '<a href="' . $x[2] . '">' . self::esc($x[1]) . '</a>', $this->ON_HOME)) . "</nav></details>\n";
        $h .= "</div>\n</div></header>\n<main id=\"main\" class=\"wrap\">\n" . $a['crumbs'] . $a['body'] . "\n</main>\n" . $this->footerHtml() . "\n";
        $h .= '<script>' . $S['TOGGLE_JS'] . ($this->SHOW_PROMPT ? $S['PROMPT_JS'] : '') . "</script>\n</body>\n</html>\n";
        return $h;
    }

    // ---------------------------------------------------------------- per-paper output
    private function authorsHtml(array $p): string
    {
        return implode(', ', array_map(function ($a) use ($p) {
            $n = self::esc($a) . (self::isEqual($p, $a) ? '<sup aria-hidden="true">*</sup><span class="sr"> (equal contribution)</span>' : '');
            return self::isOwner($a) ? "<strong>$n</strong>" : $n;
        }, $p['authors']));
    }
    private function eqNoteHtml(array $p): string { return js_some($p['authors'], fn($a) => self::isEqual($p, $a)) ? '<p class="small eq">' . self::esc($this->S['EQ_NOTE']) . '</p>' : ''; }
    private function authorsMd(array $p): string
    {
        return implode(', ', array_map(function ($a) use ($p) { $n = $a . (self::isEqual($p, $a) ? '\\*' : ''); return self::isOwner($a) ? "**$n**" : $n; }, $p['authors']));
    }
    private function eqMd(array $p): string { return js_some($p['authors'], fn($a) => self::isEqual($p, $a)) ? self::replaceFirst($this->S['EQ_NOTE'], '*', '\\*') . '.' : ''; }
    private function eqSentence(array $p): string
    {
        $eq = js_filter($p['authors'], fn($a) => self::isEqual($p, $a));
        if (count($eq) < 2) return '';
        if (!js_some($eq, fn($a) => self::isOwner($a))) return implode(' and ', $eq) . ' contributed equally (co-first authors).';
        $others = js_filter($eq, fn($a) => !self::isOwner($a));
        return $this->NAME . ' is co-first author (equal contribution with ' . implode(' and ', $others) . ')' . (self::isOwner($p['authors'][0] ?? '') ? ' and the first-listed author' : '') . '.';
    }
    private function kwLabel($t): string { $l = $this->tagLabel($t); return $l !== '' ? $l : $this->mlLabel($t); }
    private function kwKey($t): string { $l = $this->kwLabel($t); return js_lower($l !== '' ? $l : $t); }
    private function keywordsOf(array $p, int $max = 0): array
    {
        if ($p['displayKeywords']) return $max ? array_slice($p['displayKeywords'], 0, $max) : $p['displayKeywords'];
        $S = $this->S;
        $in = fn($set, $t) => in_array(js_lower($t), $S[$set], true);
        $narrow = js_filter($p['tags'], fn($t) => !($in('KW_BROAD', $t) || $in('KW_UMBRELLA', $t) || $in('KW_KIND', $t) || $in('KW_GENERIC', $t)));
        $specific = js_filter($p['ml'], fn($t) => !$in('KW_GENERIC', $t));
        $alt = [];
        for ($i = 0; $i < max(count($narrow), count($specific)); $i++) { if (isset($narrow[$i]) && $narrow[$i] !== '') $alt[] = $narrow[$i]; if (isset($specific[$i]) && $specific[$i] !== '') $alt[] = $specific[$i]; }
        $all = array_merge($alt, js_filter($p['tags'], fn($t) => $in('KW_KIND', $t)), js_filter($p['tags'], fn($t) => $in('KW_BROAD', $t)), js_filter($p['tags'], fn($t) => $in('KW_UMBRELLA', $t)),
            js_filter(array_merge($p['tags'], $p['ml']), fn($t) => $in('KW_GENERIC', $t)));
        $present = array_map('js_lower', $all);
        $seen = []; $out = [];
        foreach ($all as $t) {
            $k = $this->kwKey($t);
            $spec = $S['KW_SPECIFIC'][js_lower($t)] ?? [];
            if (in_array($k, $seen, true) || js_some(is_array($spec) ? $spec : [], fn($x) => in_array($x, $present, true))) continue;
            if ($in('KW_GENERIC', $t) && count($out) >= 4) continue;
            $seen[] = $k; $out[] = $t;
        }
        return $max ? array_slice($out, 0, $max) : $out;
    }
    private function tagLi(string $t, string $label): string { return '<li>' . ($label !== '' ? '<abbr title="' . self::esc($label) . '">' . self::esc($t) . '</abbr>' : self::esc($t)) . '</li>'; }
    private function chipsHtml(array $list): string { return $list ? '<ul class="tags" aria-label="Keywords">' . implode('', array_map(fn($t) => $this->tagLi($t, $this->kwLabel($t)), $list)) . "</ul>\n" : ''; }
    private function tagsHtml(array $p, int $max = 4): string { return $this->chipsHtml($this->keywordsOf($p, $max)); }
    private function areasText(array $p): string { return implode(', ', array_map(fn($t) => $this->tagLong($t), $p['tags'])); }
    private function mlText(array $p): string { return implode(', ', array_map(fn($t) => $this->mlLong($t), $p['ml'])); }
    private function venueLine(array $p): string
    {
        if ($p['type'] === 'thesis') $parts = [$p['dissertationName'], $p['institution'], $p['year']];
        else {
            $tr = $p['track'] !== '' ? $p['track'] : 'Doctoral Symposium';
            $parts = [$p['venueFull'], ($p['track'] !== '' || $p['type'] === 'doctoral_symposium') && !js_includes(js_lower($p['venueFull']), js_lower($tr)) ? $tr : '',
                $p['volume'] !== '' ? 'vol. ' . $p['volume'] . ($p['issue'] !== '' ? ' (' . $p['issue'] . ')' : '') : '', $p['pages'] !== '' ? 'pp. ' . $p['pagesText'] : '', $p['year']];
        }
        return implode(', ', js_filter($parts, fn($x) => $x !== '' && $x !== false));
    }
    private function hwDate(array $p): string { return preg_match('/^\d{4}-\d{2}-\d{2}$/D', $p['date']) ? str_replace('-', '/', $p['date']) : $p['year']; }
    private function shortTitle(array $p): string { $c = js_trim(jsplit('/[:?]/', $p['title'])[0]); return js_len($c) <= 60 ? $c : self::clip($p['title'], 60); }
    private function linkList(array $p): array
    {
        $l = [];
        $add = function ($label, $u, $aria = null) use (&$l) { if ($u === '' || $u === null || $u === false) return; foreach ($l as $x) if ($x[1] === $u) return; $l[] = [$label, $u, $aria]; };
        $rm = jmatch('/^https?:\/\/github\.com\/[^\/]+\/([^\/?#]+)/i', $p['tool']);
        $repo = $rm ? $rm[1] : null;
        $toolLabel = $p['toolName'] !== '' ? 'Tool (' . $p['toolName'] . ')' : ($repo !== null && $repo !== '' ? 'Tool (' . $repo . ')' : 'Tool');
        $add('PDF', $p['pdf']);
        $add('DOI', $p['doi'] !== '' ? 'https://doi.org/' . $p['doi'] : '');
        $add('arXiv', $p['arxiv'] !== '' ? 'https://arxiv.org/abs/' . $p['arxiv'] : '');
        if ($p['handle'] !== '') {
            $hr = jtest('/hdl\.handle\.net\/10388\//i', $p['handle']) ? 'USask HARVEST' : '';
            $add($hr !== '' ? "Full text ($hr)" : 'Full text', $p['handle'], 'Full text of ' . ($p['type'] === 'thesis' ? 'the ' . $p['dissertationName'] : $this->shortTitle($p)) . ($hr !== '' ? " on $hr" : ''));
        }
        if (!(($repo !== null && $repo !== '') && $p['tool'] === $p['code'])) $add('Code', $p['code']);
        $add('Replication package', $p['artifact']);
        $add($toolLabel, $p['tool']);
        $add('Dataset', $p['dataset']);
        $add('Slides', $p['slides'], 'Slides for ' . $this->shortTitle($p));
        $add('Video', $p['video'], 'Video for ' . $this->shortTitle($p));
        return $l;
    }
    private function absParas($t): string
    {
        $ps = js_filter(array_map('js_trim', jsplit('/\n\s*\n/', str($t))), fn($x) => $x !== '');
        return implode("\n", array_map(fn($x) => '<p>' . self::esc($x) . '</p>', $ps));
    }
    private function trackOf(array $p): string { return $p['track'] !== '' ? $p['track'] : ($p['type'] === 'doctoral_symposium' ? $this->S['TYPE_LABEL']['doctoral_symposium'] : ''); }
    private function metaLine(array $p): string
    {
        if ($p['type'] === 'thesis') return '<span class="v"><em>' . self::esc($p['dissertationName']) . '</em></span>, ' . self::esc($p['institution']) . ', ' . self::esc($p['year']);
        $tr = $this->trackOf($p);
        return '<span class="v"><em>' . self::esc($p['venueFull']) . '</em></span>' . ($tr !== '' && !js_includes(js_lower($p['venueFull']), js_lower($tr)) ? ', ' . self::esc($tr) : '')
            . ($p['volume'] !== '' ? ', vol. ' . self::esc($p['volume']) . ($p['issue'] !== '' ? ' (' . self::esc($p['issue']) . ')' : '') : '')
            . ($p['pages'] !== '' ? ', <span class="nw">pp. ' . self::esc($p['pagesText']) . '</span>' : '') . ', ' . self::esc($p['year'])
            . ($p['publisher'] !== '' ? ' · ' . self::esc($p['publisher']) : '') . ($p['venueRank'] !== '' ? ' · <span class="rank">' . self::esc($p['venueRank']) . '</span>' : '');
    }
    private function bibtexFor(array $p): string
    {
        if ($p['bibtex'] !== '') return $p['bibtex'];
        $last = function ($n) { $x = js_includes($n, ',') ? explode(',', $n)[0] : (function ($a) { return $a[count($a) - 1]; })(jsplit('/\s+/', $n)); return preg_replace('/[^A-Za-z]/', '', $x) ?? ''; };
        $tm = jmatch('/[A-Za-z]{4,}/', $p['title']);
        $key = js_lower($last($p['authors'][0] ?? 'anon')) . $p['year'] . js_lower($tm ? $tm[0] : 'paper');
        $bibName = function ($n) { if (js_includes($n, ',')) return $n; $w = jsplit('/\s+/', $n); return $w[count($w) - 1] . ', ' . implode(' ', array_slice($w, 0, -1)); };
        $journal = $p['type'] === 'journal';
        $fmt = fn($f) => implode(",\n", array_map(fn($kv) => '  ' . $kv[0] . ' = {' . $kv[1] . '}', $f));
        if ($p['type'] === 'thesis') {
            $phd = jtest('/ph\.?\s?d|doctor/i', $p['dissertationName']);
            $tf = [['title', '{' . $p['title'] . '}'], ['author', implode(' and ', array_map($bibName, $p['authors']))], ['school', $p['institution']], ['year', $p['year']]];
            if ($p['handle'] !== '') $tf[] = ['url', $p['handle']];
            if ($p['doi'] !== '') $tf[] = ['doi', $p['doi']];
            return '@' . ($phd ? 'phdthesis' : 'mastersthesis') . '{' . $key . ",\n" . $fmt($tf) . "\n}";
        }
        $f = [['title', '{' . $p['title'] . '}'], ['author', implode(' and ', array_map($bibName, $p['authors']))], [$journal ? 'journal' : 'booktitle', $p['venueFull']], ['year', $p['year']]];
        if ($p['volume'] !== '') $f[] = ['volume', $p['volume']];
        if ($p['issue'] !== '') $f[] = ['number', $p['issue']];
        if ($p['pages'] !== '') $f[] = ['pages', jreplace('/\s*[-–]+\s*/', '--', $p['pages'], false)];
        if ($p['publisher'] !== '') $f[] = ['publisher', $p['publisher']];
        if ($p['doi'] !== '') $f[] = ['doi', $p['doi']];
        if ($p['arxiv'] !== '') { $f[] = ['eprint', $p['arxiv']]; $f[] = ['archivePrefix', 'arXiv']; }
        return '@' . ($journal ? 'article' : 'inproceedings') . '{' . $key . ",\n" . $fmt($f) . "\n}";
    }
    private function highwire(array $p): string
    {
        $m = fn($n, $c) => ($c !== '' && $c !== null) ? '<meta name="' . $n . '" content="' . self::esc($c) . "\">\n" : '';
        $h = $m('citation_title', $p['title']);
        foreach ($p['authors'] as $a) $h .= $m('citation_author', $a);
        $h .= $m('citation_publication_date', $this->hwDate($p));
        if ($p['type'] === 'thesis') $h .= $m('citation_dissertation_institution', $p['institution']) . $m('citation_dissertation_name', $p['dissertationName']);
        elseif ($p['type'] === 'journal') $h .= $m('citation_journal_title', $p['venueFull']) . $m('citation_issn', $p['issn']) . $m('citation_volume', $p['volume']) . $m('citation_issue', $p['issue']);
        else $h .= $m('citation_conference_title', $p['venueFull']);
        $h .= $m('citation_firstpage', $p['firstPage']) . $m('citation_lastpage', $p['lastPage']) . ($p['type'] === 'thesis' ? '' : $m('citation_publisher', $p['publisher']));
        $h .= $m('citation_doi', $p['doi']);
        if ($p['type'] === 'thesis') $h .= $m('citation_public_url', $p['handle']);
        if ($p['pdf'] !== '') $h .= $m('citation_pdf_url', $this->abs($p['pdf']));
        $h .= $m('citation_abstract_html_url', $p['url']);
        $h .= $m('citation_language', 'en');
        return $h;
    }
    private function paperTitle(array $p): string
    {
        $pre = js_includes($p['title'], ':') ? js_trim(explode(':', $p['title'])[0]) : '';
        $c = [$p['title'] . ' · ' . $p['venueShort'] . ' | ' . $this->NAME, $p['title'] . ' · ' . $p['venueShort'], $p['title'] . ' | ' . $this->NAME, $p['title']];
        if ($pre !== '' && js_len($pre) >= 6) { $c[] = $pre . ' · ' . $p['venueShort'] . ' | ' . $this->NAME; $c[] = $pre . ' · ' . $p['venueShort']; }
        foreach ($c as $t) if (js_len($t) <= 60) return $t;
        return $p['title'];
    }
    private function articleNode(array $p): array
    {
        $ORGS = $this->S['ORGS'];
        $container = $p['type'] === 'journal' ? ['@type' => 'Periodical', 'name' => $p['venueFull']] + ($p['issn'] !== '' ? ['issn' => $p['issn']] : []) : ['@type' => 'Book', 'name' => $p['venueFull']];
        $sameAs = js_filter([$p['doi'] !== '' ? 'https://doi.org/' . $p['doi'] : '', $p['arxiv'] !== '' ? 'https://arxiv.org/abs/' . $p['arxiv'] : '', $p['handle']], fn($x) => $x !== '');
        $thesis = $p['type'] === 'thesis';
        $node = [
            '@type' => $thesis ? 'Thesis' : 'ScholarlyArticle',
            '@id' => $p['url'] . '#article', 'url' => $p['url'], 'mainEntityOfPage' => $p['url'],
            'headline' => self::clip($p['title'], 110), 'name' => $p['title'], 'datePublished' => $p['date'], 'inLanguage' => 'en',
            'author' => array_map(fn($a) => self::isOwner($a) ? ['@id' => $this->PERSON_ID] : ['@type' => 'Person', 'name' => $a], $p['authors']),
        ];
        if (!$thesis) $node['isPartOf'] = $container;
        if ($thesis) {
            if ($p['inSupportOf'] !== '') $node['inSupportOf'] = $p['inSupportOf'];
            if ($p['institution'] !== '') $node['sourceOrganization'] = $this->orgFor($p['institution']) ?? ['@type' => 'CollegeOrUniversity', 'name' => $p['institution']];
            if ($p['institution'] !== '') $node['publisher'] = jtest('/saskatchewan/i', $p['institution']) ? ['@id' => $this->SITE . '/#usask'] : ['@type' => 'CollegeOrUniversity', 'name' => $p['institution']];
            if ($p['handle'] !== '') $node['identifier'][] = ['@type' => 'PropertyValue', 'propertyID' => 'Handle', 'value' => preg_replace('/^https:\/\/hdl\.handle\.net\//', '', $p['handle']), 'url' => $p['handle']];
        } elseif ($p['type'] !== 'journal') $node['publication'] = ['@type' => 'PublicationEvent', 'name' => $p['venueShort']];
        if ($p['type'] === 'doctoral_symposium' || $p['track'] !== '') $node['genre'] = $p['track'] !== '' ? $p['track'] : $this->S['TYPE_LABEL'][$p['type']];
        if (!$thesis && ($p['volume'] !== '' || $p['issue'] !== '')) {
            $part = $container;
            if ($p['volume'] !== '') $part = ['@type' => 'PublicationVolume', 'volumeNumber' => $p['volume'], 'isPartOf' => $part];
            if ($p['issue'] !== '') $part = ['@type' => 'PublicationIssue', 'issueNumber' => $p['issue'], 'isPartOf' => $part];
            $node['isPartOf'] = $part;
        }
        if ($p['firstPage'] !== '') { $node['pageStart'] = $p['firstPage']; if ($p['lastPage'] !== '') $node['pageEnd'] = $p['lastPage']; }
        if ($p['publisher'] !== '' && !$thesis) $node['publisher'] = ['@type' => 'Organization', 'name' => $p['publisher']];
        if ($p['doi'] !== '') { $node['identifier'] = $node['identifier'] ?? []; array_unshift($node['identifier'], ['@type' => 'PropertyValue', 'propertyID' => 'DOI', 'value' => $p['doi'], 'url' => 'https://doi.org/' . $p['doi']]); }
        if ($p['arxiv'] !== '') $node['identifier'][] = ['@type' => 'PropertyValue', 'propertyID' => 'arXiv', 'value' => $p['arxiv'], 'url' => 'https://arxiv.org/abs/' . $p['arxiv']];
        if ($sameAs) $node['sameAs'] = $sameAs;
        if ($p['abstract'] !== '') $node['abstract'] = $p['abstract'];
        elseif ($p['description'] !== '') $node['description'] = $p['description'];
        $kw = array_merge(js_filter($p['keywords'], fn($k) => !self::isOwner($k)), array_map(fn($t) => $this->tagLabel($t) !== '' ? $this->tagLabel($t) : $t, $p['tags']), array_map(fn($t) => $this->mlLong($t), $p['ml']));
        $kwUniq = [];
        foreach ($kw as $i => $k) { if (js_find_index($kw, fn($x) => js_lower($x) === js_lower($k)) === $i) $kwUniq[] = $k; }
        if ($kwUniq) $node['keywords'] = $kwUniq;
        $about = [];
        foreach ($p['tags'] as $t) { $v = $this->vocabFor($t); $about[] = ['@type' => 'DefinedTerm', 'name' => $this->tagLabel($t) !== '' ? $this->tagLabel($t) : $t, 'termCode' => $v && $v['tag'] !== '' ? $v['tag'] : $t, 'inDefinedTermSet' => $this->SITE . '/publications/#areas']; }
        foreach ($p['ml'] as $t) {
            if (js_some($p['tags'], fn($x) => js_lower($this->tagLabel($x) !== '' ? $this->tagLabel($x) : $x) === js_lower($this->mlLong($t)))) continue;
            $about[] = ['@type' => 'DefinedTerm', 'name' => $this->mlLong($t), 'termCode' => $t, 'inDefinedTermSet' => $this->SITE . '/publications/#ml-topics'];
        }
        foreach ($p['relevant'] as $r) $about[] = ['@type' => 'Thing', 'name' => $r];
        if ($about) $node['about'] = $about;
        $eq = js_filter($p['authors'], fn($a) => self::isEqual($p, $a));
        if (count($eq) > 1) $node['creditText'] = implode(' and ', $eq) . ' contributed equally (co-first authors).';
        if ($p['slides'] !== '') $node['subjectOf'] = ['@type' => 'PresentationDigitalDocument', 'name' => 'Slides: ' . $p['title'], 'url' => $this->abs($p['slides'])];
        if ($p['award'] !== '') $node['award'] = $p['award'];
        if ($p['pdf'] !== '') $node['encoding'] = ['@type' => 'MediaObject', 'contentUrl' => $this->abs($p['pdf']), 'encodingFormat' => 'application/pdf'];
        return $node;
    }
    private function papersOut(): void
    {
        foreach ($this->pubs as $p) {
            $links = $this->linkList($p);
            $bib = $this->bibtexFor($p);
            $desc = $this->metaDesc(js_or($p['problem'], $p['description'], self::firstSentence($p['abstract']), $p['title']));
            $body = "<article>\n<h1>" . self::esc($p['title']) . "</h1>\n<p class=\"authors\">" . $this->authorsHtml($p) . "</p>\n"
                . $this->eqNoteHtml($p) . '<p class="meta">' . $this->metaLine($p) . "</p>\n"
                . ($p['award'] !== '' ? '<p class="award">' . self::esc($p['award']) . "</p>\n" : '') . ($p['myRole'] !== '' ? '<p class="small role">Role: ' . self::esc($p['myRole']) . "</p>\n" : '')
                . $this->tagsHtml($p, 0) . '<p class="small">' . self::esc($this->NAME) . ($this->PUBLISHED_AS !== '' ? ' (published as ' . self::esc($this->PUBLISHED_AS) . ')' : '') . ' · <a href="https://orcid.org/' . $this->ORCID . '">ORCID ' . $this->ORCID . "</a></p>\n"
                . ($links ? '<ul class="links">' . implode('', array_map(fn($x) => '<li><a href="' . self::esc($x[1]) . '"' . ($x[2] !== null && $x[2] !== '' ? ' aria-label="' . self::esc($x[2]) . '"' : '') . '>' . self::esc($x[0]) . '</a></li>', $links)) . '</ul>' : '') . "\n"
                . ($p['abstract'] !== '' ? "<h2>Abstract</h2>\n" . $this->absParas($p['abstract']) : ($p['description'] !== '' ? "<h2>Summary</h2>\n<p>" . self::esc($p['description']) . '</p>' : '')) . "\n"
                . ($p['problem'] !== '' ? "<h2>Problem addressed</h2>\n<p>" . self::esc($p['problem']) . '</p>' : '') . "\n"
                . ($p['relevant'] ? "<h2>Relevant to</h2>\n<ul class=\"plain\">" . implode('', array_map(fn($r) => '<li>' . self::esc($r) . '</li>', $p['relevant'])) . '</ul>' : '') . "\n"
                . "<h2>Cite</h2>\n<pre tabindex=\"0\" aria-label=\"BibTeX\">" . self::esc($bib) . "</pre>\n"
                . '<p class="small"><a href="/publications/' . $p['slug'] . '/cite.bib" download>Download BibTeX</a> · <a href="/publications/' . $p['slug'] . ".md\">Markdown version</a></p>\n</article>";
            $crumbs = '<p class="crumbs"><a href="/">Home</a> / <a href="/publications/">Publications</a> / ' . self::esc($p['venueShort']) . "</p>\n";
            $graph = ['@context' => 'https://schema.org', '@graph' => [$this->articleNode($p), $this->personNode(false), $this->breadcrumbLd([['Home', $this->SITE . '/'], ['Publications', $this->SITE . '/publications/'], [$p['title'], $p['url']]])]];
            $this->write('publications/' . $p['slug'] . '/index.html', $this->page([
                'title' => $this->paperTitle($p), 'ogTitle' => $p['title'] . ' · ' . $p['venueShort'] . ' | ' . $this->NAME, 'description' => $desc, 'canonical' => $p['url'], 'ogType' => 'article', 'crumbs' => $crumbs, 'body' => $body, 'jsonld' => $graph, 'active' => 'publications',
                'head' => $this->highwire($p) . '<link rel="alternate" type="text/markdown" href="/publications/' . $p['slug'] . ".md\">\n",
            ]));
            $this->write('publications/' . $p['slug'] . '/cite.bib', $bib . "\n");
            $this->write('publications/' . $p['slug'] . '.md', $this->paperMd($p, $bib, 1));
        }
    }
    private function paperMd(array $p, string $bib, int $level): string
    {
        $H = str_repeat('#', $level);
        $links = implode("\n", array_map(fn($x) => '- ' . $x[0] . ': ' . $this->abs($x[1]), $this->linkList($p)));
        $eqMd = $this->eqMd($p); $eqS = $this->eqSentence($p);
        return "$H {$p['title']}\n\n" . $this->authorsMd($p) . '. *' . $this->venueLine($p) . '*.' . ($p['venueRank'] !== '' ? ' Venue ranking: ' . $p['venueRank'] . '.' : '')
            . ($eqMd !== '' ? "\n\n" . $eqMd . ($eqS !== '' ? ' ' . $eqS : '') : '') . ($p['award'] !== '' ? "\n\nAward: {$p['award']}." : '') . ($p['myRole'] !== '' ? "\n\nRole of {$this->NAME}: {$p['myRole']}" : '')
            . ($p['tags'] ? "\n\nAreas: " . $this->areasText($p) . '.' : '') . ($p['ml'] ? "\n\nML topics: " . $this->mlText($p) . '.' : '')
            . "\n\nPage: {$p['url']}\n" . ($links !== '' ? "\n" . $links . "\n" : '') . "\n"
            . ($p['abstract'] !== '' ? "$H# Abstract\n\n{$p['abstract']}\n" : ($p['description'] !== '' ? "$H# Summary\n\n{$p['description']}\n" : ''))
            . ($p['problem'] !== '' ? "\n$H# Problem addressed\n\n{$p['problem']}\n" : '')
            . ($p['relevant'] ? "\n$H# Relevant to research on\n\n" . implode("\n", array_map(fn($r) => "- $r", $p['relevant'])) . "\n" : '')
            . "\n$H# BibTeX\n\n```bibtex\n$bib\n```\n";
    }

    // ---------------------------------------------------------------- publications index
    private function companionOf(array $p): string { return self::slugify(js_or(js_get($p['raw'], 'companion_of'), js_get($p['raw'], 'poster_of'), '')); }
    private function byYear(): array
    {
        return js_sort(js_uniq(array_map(fn($p) => $p['year'], $this->pubs)), fn($a, $b) => js_locale_compare($b, $a));
    }
    private function yearPubs(string $y): array
    {
        $list = js_filter($this->pubs, fn($p) => $p['year'] === $y);
        $minor = fn($p) => $p['type'] === 'poster' || $this->companionOf($p) !== '';
        $out = js_filter($list, fn($p) => !$minor($p));
        foreach (js_filter($list, $minor) as $c) {
            $at = js_find_index($out, fn($p) => $p['slug'] === $this->companionOf($c));
            if ($at >= 0) { $j = $at + 1; while ($j < count($out) && $this->companionOf($out[$j]) === $this->companionOf($c)) $j++; array_splice($out, $j, 0, [$c]); }
            else $out[] = $c;
        }
        return $out;
    }
    private function themePapers(array $t): array
    {
        $papers = $t['papers'] ?? [];
        $order = function ($p) use ($papers) { $i = js_index_of($papers, $p['slug']); return $i < 0 ? 999 : $i; };
        $all = js_filter($this->pubs, fn($p) => in_array($t['id'], $p['themes'], true));
        $main = js_sort(js_filter($all, fn($p) => !($this->companionOf($p) !== '' && js_some($all, fn($q) => $q['slug'] === $this->companionOf($p)))), fn($a, $b) => $order($a) - $order($b));
        $out = [];
        foreach ($main as $p) { $out[] = $p; foreach ($all as $c) if ($this->companionOf($c) === $p['slug']) $out[] = $c; }
        return $out;
    }
    private function shortVenue(array $p): string
    {
        $v = str(js_or($p['venueShort'], $p['year']));
        $v = jreplace('/\s*\([^()]*\)/', '', $v);
        $v = jreplace('/\bDoctoral Symposium\b/i', 'DS', $v, false);
        $v = jreplace('/\bTool Track\b/i', 'Tool', $v, false);
        $v = jreplace('/\bResearch Track\b/i', '', $v, false);
        $v = jreplace('/\b(?:19|20)(\d{2})\b/', "'\$1", $v);
        $v = js_trim(jreplace('/\s{2,}/', ' ', $v));
        if (jtest('/distinguished paper/i', $p['award'])) $v .= ' · Distinguished Paper';
        $sw = js_find($this->softwareRaw, fn($x) => self::slugify(js_get($x, 'paper_slug')) === $p['slug']);
        $n = $sw ? self::stripEmoji(js_get($sw, 'name')) : '';
        return $n !== '' && count(jsplit('/\s+/', $n)) <= 2 ? "$n ($v)" : $v;
    }
    private function themeCardsHtml(): string
    {
        $cards = js_filter($this->themesUsed, fn($t) => $t['id'] !== 'other-work');
        if (!$cards) return '';
        $other = js_find($this->themesUsed, fn($t) => $t['id'] === 'other-work');
        $ref = function ($p) {
            $lead = $p['type'] === 'poster' ? 'Poster' : $this->shortVenue($p);
            $tool = ($p['tool'] !== '' && $p['toolName'] !== '' && !js_some($this->softwareRaw, fn($x) => self::slugify(js_get($x, 'paper_slug')) === $p['slug'])) ? ' · <a href="' . self::esc($p['tool']) . '">' . self::esc($p['toolName']) . '</a>' : '';
            return '<a href="/publications/' . $p['slug'] . '/" aria-label="' . self::esc($lead . ': ' . $p['title']) . '">' . self::esc($lead) . '</a>' . $tool;
        };
        $o = '<ul class="cards">';
        foreach ($cards as $t) {
            $chips = array_slice(($t['keywords'] ?? []) ? $t['keywords'] : $this->keywordsOf($t['papers'][0], 3), 0, 4);
            $o .= '<li class="card"><h3>' . self::esc($t['name']) . '</h3>' . (($t['summary'] ?? '') !== '' ? '<p>' . self::esc($t['summary']) . '</p>' : '') . (($t['keyResult'] ?? '') !== '' ? '<p class="res">' . self::esc($t['keyResult']) . '</p>' : '')
                . $this->chipsHtml($chips) . '<p class="pp">' . implode(' · ', array_map($ref, $t['papers'])) . '</p></li>';
        }
        $o .= '</ul>' . ($other ? "\n" . '<p class="small">Also: ' . implode(' · ', array_map($ref, $other['papers'])) . '</p>' : '');
        return $o;
    }
    private static function venueInline($v): string { return jreplace('/\s*\(([^()]+)\)/', ', $1', str($v)); }
    private function entryHtml(array $p): string
    {
        $links = array_merge([['Paper page', '/publications/' . $p['slug'] . '/', null]], $this->linkList($p), [['BibTeX', '/publications/' . $p['slug'] . '/cite.bib', null]]);
        return '<div class="entry"' . ($p['tags'] ? ' data-tags="' . self::esc(implode('|', $p['tags'])) . '"' : '') . ($p['ml'] ? ' data-ml="' . self::esc(implode('|', $p['ml'])) . '"' : '') . '><div class="t"><a href="/publications/' . $p['slug'] . '/">' . self::esc($p['title']) . "</a></div>\n"
            . '<div>' . $this->authorsHtml($p) . "</div>\n"
            . $this->eqNoteHtml($p) . '<div class="meta"><span class="v">' . self::esc($p['venueShort']) . '</span>' . ($p['type'] === 'doctoral_symposium' && !jtest('/doctoral/i', $p['venueShort']) ? ' · ' . $this->S['TYPE_LABEL']['doctoral_symposium'] : '')
            . ($p['venueRank'] !== '' ? ' · <span class="rank">' . self::esc($p['venueRank']) . '</span>' : '') . ($p['award'] !== '' ? ' · <span class="award">' . self::esc($p['award']) . '</span>' : '') . "</div>\n"
            . ($p['myRole'] !== '' ? '<div class="small role">Role: ' . self::esc($p['myRole']) . "</div>\n" : '') . $this->tagsHtml($p) . '<div class="small">'
            . implode(' · ', array_map(fn($x) => '<a href="' . self::esc($x[1]) . '" aria-label="' . self::esc(($x[2] !== null && $x[2] !== '') ? $x[2] : $x[0] . ': ' . $p['title']) . '">' . self::esc($x[0]) . '</a>', $links)) . "</div>\n"
            . ($p['abstract'] !== '' ? '<details class="abs"><summary>Abstract</summary>' . $this->absParas($p['abstract']) . '</details>' : '') . '</div>';
    }
    private function usedFacet(string $field, array $vocab): array
    {
        $used = [];
        foreach ($this->pubs as $p) foreach ($p[$field] as $t) if (!in_array($t, $used, true)) $used[] = $t;
        $order = function ($t) use ($vocab) { $i = js_find_index($vocab, fn($v) => js_lower($v['tag']) === js_lower($t)); return $i < 0 ? 999 : $i; };
        $used = js_sort($used, fn($x, $y) => $order($x) - $order($y));
        return array_map(fn($tag) => ['tag' => $tag, 'papers' => js_filter($this->pubs, fn($p) => in_array($tag, $p[$field], true))], $used);
    }
    private function mlName($t): string { return $this->mlLabel($t) !== '' ? '<abbr title="' . self::esc($this->mlLabel($t)) . '">' . self::esc($t) . '</abbr>' : self::esc($t); }
    private function tagName($t): string { return $this->tagLabel($t) !== '' ? '<abbr title="' . self::esc($this->tagLabel($t)) . '">' . self::esc($t) . '</abbr>' : self::esc($t); }
    private function plainList(array $papers): string
    {
        return '<ul class="plain">' . implode('', array_map(fn($p) => '<li><a href="/publications/' . $p['slug'] . '/">' . self::esc($p['title']) . '</a> <span class="small">(' . self::esc(self::venueInline($p['venueShort'])) . ')</span></li>', $papers)) . '</ul>';
    }
    private function pubsIndex(): void
    {
        $this->themesUsed = js_filter(array_map(fn($t) => array_merge($t, ['papers' => $this->themePapers($t)]), $this->THEMES), fn($t) => count($t['papers']) > 0);
        $this->areasUsed = $this->usedFacet('tags', $this->TAG_VOCAB);
        $this->mlUsed = $this->usedFacet('ml', $this->ML_VOCAB);
        $areas = $this->areasUsed; $mls = $this->mlUsed;
        $btn = function ($f, $a, $name, $label) {
            $n = count($a['papers']);
            return '<button type="button" data-f="' . $f . '" data-tag="' . self::esc($a['tag']) . '" data-name="' . self::esc($name) . '" aria-pressed="false">' . $label . '<span class="n"><span class="sr">, </span>' . $n . '<span class="sr"> publication' . ($n === 1 ? '' : 's') . '</span></span></button>';
        };
        $filterHtml = ($areas || $mls) ? "<div id=\"tagf\" class=\"tagf-w\" role=\"group\" aria-label=\"Filter publications by topic\" hidden>\n<div class=\"tagf\"><button type=\"button\" data-f=\"\" data-tag=\"\" data-name=\"All\" aria-pressed=\"true\">All</button></div>\n"
            . ($areas ? '<details class="tagf-d"><summary>Software Engineering areas <span class="n">(' . count($areas) . ')</span></summary><div class="tagf" role="group" aria-label="Software Engineering areas">' . implode('', array_map(fn($a) => $btn('tag', $a, $this->tagLong($a['tag']), $this->tagName($a['tag'])), $areas)) . "</div></details>\n" : '')
            . ($mls ? '<details class="tagf-d"><summary>AI/ML topics <span class="n">(' . count($mls) . ')</span></summary><div class="tagf" role="group" aria-label="AI/ML topics">' . implode('', array_map(fn($a) => $btn('ml', $a, $this->mlLong($a['tag']), $this->mlName($a['tag'])), $mls)) . "</div></details>\n" : '')
            . "</div>\n<p id=\"tagf-s\" class=\"small\" role=\"status\"></p>\n" : '';
        $byYear = $this->byYear();
        $PUBS_T = $this->htitle('portfolio', 'Publications');
        if (jtest('/^portfolio$/i', $PUBS_T)) $PUBS_T = 'Publications';
        $intro = self::stripEmoji(js_get(js_get($this->headings, 'portfolio'), 'pageIntro'));
        $body = '<h1>' . self::esc($PUBS_T) . "</h1>\n<p>" . ($intro !== '' ? $this->inlineMd($this->fillTokens($intro))
            : 'All peer-reviewed papers and posters by ' . self::esc($this->NAME) . ' (' . ($this->PUBLISHED_AS !== '' ? 'published as ' . self::esc($this->PUBLISHED_AS) . ', ' : '') . "<a href=\"https://orcid.org/{$this->ORCID}\">ORCID {$this->ORCID}</a>)" . (js_some($this->pubs, fn($p) => $p['type'] === 'thesis') ? ', and ' . $this->PRON[1] . ' thesis' : '') . '. Each paper page has the abstract, links to the DOI, arXiv, code and artifacts, and BibTeX.') . "</p>\n"
            . ($this->themesUsed ? '<p class="small">Jump to: <a href="#by-year">By year</a> · <a href="#by-theme">By theme</a></p>' : '') . "\n"
            . "<div id=\"by-year\">\n<h2 id=\"by-year-h\">All publications by year</h2>\n"
            . $filterHtml . implode("\n", array_map(fn($y) => '<section class="yr" aria-labelledby="y' . $y . '"><h3 id="y' . $y . '">' . $y . "</h3>\n" . implode("\n", array_map(fn($p) => $this->entryHtml($p), $this->yearPubs($y))) . '</section>', $byYear))
            . "\n</div>\n<h2 id=\"by-theme\">By theme</h2>\n<p class=\"small\">Each paper is listed under its themes here and in full, with links and abstract, <a href=\"#by-year\">by year</a> above.</p>\n"
            . implode("\n", array_map(fn($t) => '<h3 id="' . $t['id'] . '">' . self::esc($t['name']) . "</h3>\n" . $this->plainList($t['papers']), $this->themesUsed)) . "\n"
            . (($areas || $mls) ? "<details class=\"idx\" id=\"topic-index\"><summary>Index by research area and AI/ML topic</summary>\n<p class=\"small\">Every publication under each of its topics. The filter above shows the same lists.</p>\n"
                . ($areas ? "<h3 id=\"areas\">Research areas</h3>\n" . implode("\n", array_map(fn($a) => '<h4 id="' . $this->tagId($a['tag']) . '">' . ($this->tagLabel($a['tag']) !== '' ? self::esc($this->tagLabel($a['tag'])) . ' (' . self::esc($a['tag']) . ')' : self::esc($a['tag'])) . "</h4>\n" . $this->plainList($a['papers']), $areas)) : '') . "\n"
                . ($mls ? "<h3 id=\"ml-topics\">AI/ML topics</h3>\n" . implode("\n", array_map(fn($a) => '<h4 id="' . $this->mlId($a['tag']) . '">' . ($this->mlLabel($a['tag']) !== '' ? self::esc($this->mlLabel($a['tag'])) . ' (' . self::esc($a['tag']) . ')' : self::esc($a['tag'])) . "</h4>\n" . $this->plainList($a['papers']), $mls)) : '') . "\n</details>" : '') . "\n"
            . "<p class=\"small\"><a href=\"/publications.md\">Markdown version</a></p>\n"
            . (($areas || $mls) ? '<script>' . $this->S['FILTER_JS'] . '</script>' : '');
        $this->write('publications/index.html', $this->page([
            'title' => js_or($this->seoPage('publications', 'title'), $PUBS_T . ' | ' . $this->NAME), 'canonical' => $this->SITE . '/publications/', 'ogTitle' => $this->seoPage('publications', 'ogTitle'),
            'description' => $this->metaDesc(js_or($this->seoPage('publications', 'description'), 'Publications by ' . $this->NAME . ' on code clones, clone detection, LLMs for software engineering, model compression and model merging, with BibTeX.')),
            'crumbs' => '<p class="crumbs"><a href="/">Home</a> / ' . self::esc($PUBS_T) . "</p>\n", 'body' => $body, 'active' => 'publications',
            'head' => "<link rel=\"alternate\" type=\"text/markdown\" href=\"/publications.md\">\n",
            'jsonld' => ['@context' => 'https://schema.org', '@graph' => [
                ['@type' => 'CollectionPage', '@id' => $this->SITE . '/publications/#page', 'url' => $this->SITE . '/publications/', 'name' => 'Publications of ' . $this->NAME, 'isPartOf' => ['@id' => $this->WEBSITE_ID], 'about' => ['@id' => $this->PERSON_ID],
                    'mainEntity' => ['@type' => 'ItemList', 'itemListElement' => array_map(fn($p, $i) => ['@type' => 'ListItem', 'position' => $i + 1, 'url' => $p['url'], 'name' => $p['title']], $this->pubs, array_keys($this->pubs))]],
                $this->websiteNode, $this->personNode(false), $this->breadcrumbLd([['Home', $this->SITE . '/'], ['Publications', $this->SITE . '/publications/']]),
            ]],
        ]));
        $md = '# Publications of ' . $this->NAME . "\n\n" . $this->NAME . ' (' . ($this->PUBLISHED_AS !== '' ? 'published as ' . $this->PUBLISHED_AS . '; ' : '') . 'ORCID ' . $this->ORCID . "). Newest first.\n\n";
        $md .= implode("\n\n", array_map(function ($y) {
            return "## $y\n\n" . implode("\n", array_map(function ($p) {
                $e = $this->eqMd($p);
                return '- [' . $p['title'] . '](' . $this->SITE . '/publications/' . $p['slug'] . '.md). ' . $this->authorsMd($p) . '. *' . $p['venueShort'] . '*.' . ($e !== '' ? " $e" : '') . ($p['award'] !== '' ? ' ' . $p['award'] . '.' : '')
                    . ($p['tags'] ? ' Areas: ' . implode(', ', $p['tags']) . '.' : '') . ($p['ml'] ? ' ML topics: ' . implode(', ', $p['ml']) . '.' : '') . ($p['doi'] !== '' ? ' DOI: ' . $p['doi'] . '.' : '') . ($p['arxiv'] !== '' ? ' arXiv: ' . $p['arxiv'] . '.' : '')
                    . ($p['handle'] !== '' ? ' Handle: ' . $p['handle'] . '.' : '') . ($p['slides'] !== '' ? ' Slides: ' . $this->abs($p['slides']) . '.' : '') . ($p['video'] !== '' ? ' Video: ' . $this->abs($p['video']) . '.' : '');
            }, $this->yearPubs($y)));
        }, $byYear)) . "\n";
        $this->write('publications.md', $md);
    }

    // ---------------------------------------------------------------- FAQ
    private function faqOut(): void
    {
        if (!$this->faqItems) return;
        $FAQ_T = $this->htitle('faq', 'Research FAQ');
        $body = '<h1>' . self::esc($FAQ_T) . "</h1>\n<p>" . ($this->hsub('faq') !== '' ? $this->inlineMd($this->hsub('faq')) : 'Short, factual answers to questions this research addresses, with pointers to related work by other researchers. Written by ' . $this->NAME . '.') . "</p>\n<dl class=\"faq\">\n"
            . implode("\n", array_map(fn($f, $i) => '<dt id="q' . ($i + 1) . '">' . self::esc(js_get($f, 'q')) . "</dt>\n<dd>" . $this->mdParas(js_get($f, 'a')) . '</dd>', $this->faqItems, array_keys($this->faqItems)))
            . "\n</dl>\n<p class=\"small\"><a href=\"/faq.md\">Markdown version</a></p>";
        $this->write('faq/index.html', $this->page([
            'title' => js_or($this->seoPage('faq', 'title'), ($FAQ_T === 'Research FAQ' ? 'Research FAQ: Code Clones and LLMs' : $FAQ_T) . ' | ' . $this->NAME), 'canonical' => $this->SITE . '/faq/',
            'ogTitle' => js_or($this->seoPage('faq', 'ogTitle'), ($FAQ_T === 'Research FAQ' ? 'Research FAQ: code clones and LLMs for software engineering' : $FAQ_T) . ' | ' . $this->NAME),
            'description' => $this->metaDesc(js_or($this->seoPage('faq', 'description'), 'Answers on clone benchmarks, detecting clones in LLM-generated code, compressing code LLMs and merging fine-tuned models, with links to the papers.')),
            'crumbs' => '<p class="crumbs"><a href="/">Home</a> / ' . self::esc($FAQ_T) . "</p>\n", 'body' => $body,
            'head' => "<link rel=\"alternate\" type=\"text/markdown\" href=\"/faq.md\">\n",
            'jsonld' => ['@context' => 'https://schema.org', '@graph' => [
                ['@type' => 'FAQPage', '@id' => $this->SITE . '/faq/#page', 'url' => $this->SITE . '/faq/', 'isPartOf' => ['@id' => $this->WEBSITE_ID], 'author' => ['@id' => $this->PERSON_ID],
                    'mainEntity' => array_map(fn($f) => ['@type' => 'Question', 'name' => js_get($f, 'q'), 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $this->mdParas(js_get($f, 'a'), true)]], $this->faqItems)],
                $this->websiteNode, $this->personNode(false), $this->breadcrumbLd([['Home', $this->SITE . '/'], [$FAQ_T, $this->SITE . '/faq/']]),
            ]],
        ]));
        $this->write('faq.md', '# Research FAQ: ' . $this->NAME . "\n\n" . implode("\n\n", array_map(fn($f) => '## ' . js_string(js_get($f, 'q')) . "\n\n" . $this->mdAbsLinks(js_get($f, 'a')), $this->faqItems)) . "\n");
    }

    // ---------------------------------------------------------------- about
    private static function placeText($s): string { return jreplace('/\s*\|\s*/', ', ', self::stripEmoji($s)); }
    private static function fmtPeriod($v): string
    {
        $t = js_trim(jreplace('/\s+/', ' ', self::stripEmoji($v)));
        if ($t === '' || jtest('/^\d{4}-\d{2}(-\d{2})?$/', $t)) return $t;
        $parts = js_filter(array_map('js_trim', jsplit('/\s*[-–—]\s*|\s+to\s+/i', $t)), fn($x) => $x !== '');
        if (count($parts) !== 2) return $t;
        [$a, $b] = $parts;
        $ym = '/^([A-Za-z]+\.?)\s+((?:19|20)\d{2})$/';
        $ma = jmatch($ym, $a); $mb = jmatch($ym, $b);
        if ($ma && $mb && $ma[2] === $mb[2]) return $ma[1] . '–' . $mb[1] . ' ' . $mb[2];
        if ($mb && jtest('/^[A-Za-z]+\.?$/', $a)) return "$a–$b";
        return "$a – $b";
    }
    private static function placeFmt($s): string
    {
        $p = js_filter(array_map('js_trim', explode('|', self::stripEmoji($s))), fn($x) => $x !== '');
        return count($p) > 1 ? implode(', ', array_slice($p, 0, -1)) . ', ' . self::fmtPeriod($p[count($p) - 1]) : implode('', $p);
    }
    private static function mergeRoles(array $list): array
    {
        $m = [];
        foreach ($list as $l) {
            $k = implode('|', array_map(fn($x) => js_trim(preg_replace('/[^a-z0-9]+/', ' ', js_lower(self::stripEmoji($x))) ?? ''), [js_get($l, 'role'), js_or(js_get($l, 'organization'), js_get($l, 'place'))]));
            $period = self::fmtPeriod(js_get($l, 'period'));
            if (array_key_exists($k, $m)) { if ($period !== '' && !in_array($period, $m[$k]['periods'], true)) $m[$k]['periods'][] = $period; }
            else { $m[$k] = is_array($l) ? $l : []; $m[$k]['periods'] = $period !== '' ? [$period] : []; }
        }
        return array_values($m);
    }
    private static function sortKey(array $x): string
    {
        $d = self::isoDay($x['date'] ?? null);
        if ($d === '') $d = str($x['date'] ?? null);
        if ($d !== '') return $d;
        $src = str(js_or($x['period'] ?? null, $x['place'] ?? null));
        preg_match_all('/(?:19|20)\d{2}/', $src, $ys);
        if (jtest('/present|current|ongoing/i', $src)) return '9999';
        return $ys[0] ? $ys[0][count($ys[0]) - 1] : '';
    }
    private static function orderNum($v): float
    {
        $s = str($v);
        if ($s === '') return INF;
        $t = js_trim($s);
        if ($t === '') return 0.0; // Number("  ") === 0 (str() already trimmed, so unreachable)
        if (preg_match('/^[+-]?(\d+\.?\d*|\.\d+)([eE][+-]?\d+)?$/D', $t)) return (float)$t;
        if (preg_match('/^0[xX][0-9a-fA-F]+$/D', $t)) return (float)hexdec(substr($t, 2));
        if (preg_match('/^0[bB][01]+$/D', $t)) return (float)bindec(substr($t, 2));
        if (preg_match('/^0[oO][0-7]+$/D', $t)) return (float)octdec(substr($t, 2));
        return INF; // NaN / Infinity are not finite
    }
    private static function roleOrg($x): string { return self::stripEmoji(js_or(js_get($x, 'venue'), js_get($x, 'organization'), js_get($x, 'org'), '')); }
    private function thesisFor($e): ?array
    {
        $deg = self::stripEmoji(js_get($e, 'degree'));
        $lvl = jtest('/m\.?\s?sc|master/i', $deg) ? '/m\.?\s?sc|master/i' : (jtest('/ph\.?\s?d|doctor/i', $deg) ? '/ph\.?\s?d|doctor/i' : null);
        $pub = null;
        if (str(js_get($e, 'thesis_slug')) !== '') $pub = js_find($this->pubs, fn($p) => $p['slug'] === self::slugify(js_get($e, 'thesis_slug')));
        if (!$pub && $lvl) $pub = js_find($this->pubs, fn($p) => $p['type'] === 'thesis' && jtest($lvl, $p['dissertationName'] . ' ' . $p['inSupportOf']));
        if ($pub) return ['title' => $pub['title'], 'url' => '/publications/' . $pub['slug'] . '/'];
        $t = self::stripEmoji(js_or(js_get($e, 'thesis'), js_get($e, 'thesis_title')));
        return $t !== '' ? ['title' => $t, 'url' => $this->safeUrl(js_get($e, 'thesis_url'))] : null;
    }
    private function eduPlace($e): string
    {
        $exp = $e === $this->PHD_ROW ? $this->EXPECTED : self::stripEmoji(js_get($e, 'expected'));
        $p = self::placeFmt(js_get($e, 'place'));
        if ($exp === '') return $p;
        if (jtest('/present|current/i', $p)) {
            $r = jreplace('/[-–]\s*(present|current)\b/i', fn() => "– $exp (expected)", $p, false);
            return jreplace('/(\d)–\s/', '$1 – ', $r, false);
        }
        return "$p (expected $exp)";
    }
    private function buildAboutRows(): void
    {
        $TEACH = '/^(teaching|mentoring|mentorship|supervision)$/i';
        $exp = js_get($this->about, 'experience');
        $exp = js_is_array($exp) ? $exp : [];
        $rows = [];
        foreach ($exp as $e) {
            if (!js_truthy($e) || !js_truthy(js_get($e, 'role')) || !jtest($TEACH, str(js_get($e, 'category')))) continue;
            $parts = js_filter(array_map('js_trim', explode('|', self::stripEmoji(js_get($e, 'place')))), fn($t) => $t !== '');
            $rows[] = ['role' => self::stripEmoji(js_get($e, 'role')), 'org' => count($parts) > 1 ? implode(', ', array_slice($parts, 0, -1)) : self::stripEmoji(js_or(js_get($e, 'organization'), $parts[0] ?? null, '')),
                'period' => self::fmtPeriod(count($parts) > 1 ? $parts[count($parts) - 1] : js_get($e, 'period')), 'description' => self::stripEmoji(js_get($e, 'description')), 'details' => self::stripEmoji(js_get($e, 'details')), 'date' => js_get($e, 'date'), 'order' => self::orderNum(js_get($e, 'order'))];
        }
        foreach ($this->serviceItems as $x) {
            if (!jtest('/^(supervision|mentoring|mentorship|teaching)$/i', str(js_get($x, 'category')))) continue;
            $rows[] = ['role' => self::stripEmoji(js_get($x, 'role')), 'org' => self::roleOrg($x), 'period' => self::fmtPeriod(js_get($x, 'period')), 'description' => self::stripEmoji(js_get($x, 'description')), 'details' => self::stripEmoji(js_get($x, 'details')), 'date' => js_get($x, 'date'), 'order' => self::orderNum(js_get($x, 'order'))];
        }
        $this->teachRows = js_sort($rows, function ($a, $b) {
            $d = $a['order'] - $b['order'];
            if (is_nan($d)) $d = 0;
            if ($d != 0) return $d < 0 ? -1 : 1;
            return js_locale_compare(self::sortKey($b), self::sortKey($a));
        });
        $this->researchRows = js_filter($exp, fn($e) => js_truthy($e) && js_truthy(js_get($e, 'role')) && !jtest($TEACH, str(js_get($e, 'category'))));
        $talks = [];
        foreach ($this->serviceItems as $x) {
            if (js_lower(str(js_get($x, 'category'))) !== 'talk') continue;
            $t = ['title' => self::stripEmoji(js_get($x, 'description')), 'role' => self::stripEmoji(js_get($x, 'role')), 'venue' => self::roleOrg($x), 'period' => self::fmtPeriod(js_get($x, 'period')), 'date' => js_get($x, 'date'), 'url' => $this->safeUrl(js_or(js_get($x, 'link'), js_get($x, 'url')))];
            if ($t['title'] !== '') $talks[] = $t;
        }
        $this->talks = js_sort($talks, fn($a, $b) => js_locale_compare(self::sortKey($b), self::sortKey($a)));
        $this->nominations = js_filter($this->highlights, fn($h) => js_truthy($h) && self::isNom($h));
        $this->serviceRows = [];
        foreach ($this->serviceItems as $x) {
            if (jtest('/^(talk|review|reviewing|supervision|mentoring|mentorship|teaching)$/i', str(js_get($x, 'category')))) continue;
            $this->serviceRows[] = ['role' => self::stripEmoji(js_get($x, 'role')), 'org' => self::roleOrg($x), 'period' => self::fmtPeriod(js_get($x, 'period')), 'description' => implode(' ', js_filter([self::stripEmoji(js_get($x, 'description')), self::stripEmoji(js_get($x, 'details'))], fn($v) => $v !== ''))];
        }
        $this->reviewer = js_find($this->serviceItems, fn($x) => jtest('/^(review|reviewing)$/i', str(js_get($x, 'category'))) && str(js_get($x, 'venues')) !== '');
        $r = $this->reviewer;
        $this->reviewerLine = $r ? js_or(self::stripEmoji(js_get($r, 'role')), 'Reviewer') . ': ' . self::stripEmoji(js_get($r, 'venues')) . (str(js_get($r, 'period')) !== '' ? ' (' . self::fmtPeriod(js_get($r, 'period')) . ')' : '') : '';
        $arts = js_sort(js_filter($this->IN_THE_NEWS, fn($n) => $n['kind'] === 'article'), fn($a, $b) => js_locale_compare($b['date'], $a['date']));
        $this->newsRows = array_merge($arts, js_filter($this->IN_THE_NEWS, fn($n) => $n['kind'] === 'profile'));
    }
    private static function talkRole(array $t): string
    {
        $m = jmatch('/^invited talk\s*\((.+)\)$/i', $t['role']);
        return $m ? $m[1] : (jtest('/^invited talk$/i', $t['role']) ? '' : $t['role']);
    }
    private static function newsDate($d): string
    {
        $m = jmatch('/^(\d{4})-(\d{2})/', str($d));
        $M3 = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        return $m ? ($M3[(int)$m[2] - 1] ?? 'undefined') . ' ' . $m[1] : '';
    }
    private function aboutOut(): void
    {
        $this->buildAboutRows();
        $paras = js_filter(array_map(fn($x) => self::stripEmoji($x), js_is_array(js_get($this->about, 'paragraphs')) ? $this->about['paragraphs'] : []), fn($x) => $x !== '');
        $edu = $this->EDU;
        $lead = self::mergeRoles($this->leadership);
        $refs = js_get($this->headings, 'references'); $refs = is_array($refs) ? $refs : [];
        $refNote = js_filter([self::stripEmoji(js_get($refs, 'subtitle')), self::stripEmoji(js_get($refs, 'note'))], fn($x) => $x !== '');
        $roleRow = fn($period, $what) => '<li><span class="d">' . self::esc($period) . '</span><span>' . $what . '</span></li>';
        $e = fn($s) => self::esc($s);
        $body = '<h1>' . $e($this->NAME) . "</h1>\n<p class=\"meta\">" . $e($this->NAME_LINE) . "</p>\n<p class=\"small\">" . ($this->PUBLISHED_AS !== '' ? 'Published as ' . $e($this->PUBLISHED_AS) . ' · ' : '') . "<a href=\"https://orcid.org/{$this->ORCID}\">ORCID {$this->ORCID}</a></p>\n"
            . implode("\n", array_map(fn($p) => '<p>' . $this->inlineMd($p) . '</p>', $paras)) . "\n"
            . ($this->galleryItems ? '<p><a href="/photos/">Photos</a></p>' : '') . "\n<h2>Research</h2>\n" . $this->themeCardsHtml() . "\n"
            . '<p><a href="/publications/">All publications</a>' . ($this->faqItems ? ' · <a href="/faq/">Research FAQ</a>' : '') . "</p>\n"
            . ($edu ? "<h2>Education</h2>\n<ul class=\"plain\">" . implode('', array_map(function ($x) use ($e) { $th = $this->thesisFor($x); return '<li>' . $e(self::stripEmoji(js_get($x, 'degree'))) . ', ' . $e($this->eduPlace($x)) . ($th ? '<br><span class="small">Thesis: ' . ($th['url'] !== '' ? '<a href="' . $e($th['url']) . '">' . $e($th['title']) . '</a>' : $e($th['title'])) . '</span>' : '') . '</li>'; }, $edu)) . '</ul>' : '') . "\n"
            . ($this->teachRows ? "<h2>Teaching and mentoring</h2>\n<ul class=\"roles\">" . implode('', array_map(fn($t) => $roleRow($t['period'], $e($t['role']) . ($t['org'] !== '' ? ', ' . $e($t['org']) : '') . ($t['description'] !== '' || $t['details'] !== '' ? '<br><span class="small">' . $e(implode(' ', js_filter([$t['description'], $t['details']], fn($v) => $v !== ''))) . '</span>' : '')), $this->teachRows)) . '</ul>' : '') . "\n"
            . ($this->researchRows ? "<h2>Research positions</h2>\n<ul class=\"plain\">" . implode('', array_map(fn($x) => '<li>' . $e(self::stripEmoji(js_get($x, 'role'))) . ', ' . $e(self::placeFmt(js_get($x, 'place'))) . '</li>', $this->researchRows)) . '</ul>' : '') . "\n"
            . ($this->talks ? "<h2>Invited talks</h2>\n<ul class=\"roles\">" . implode('', array_map(fn($t) => $roleRow($t['period'], ($t['url'] !== '' ? '<a href="' . $e($t['url']) . '">' . $e($t['title']) . '</a>' : $e($t['title'])) . ($t['venue'] !== '' ? ', ' . $e($t['venue']) : '') . (self::talkRole($t) !== '' ? ' <span class="small">(' . $e(self::talkRole($t)) . ')</span>' : '')), $this->talks)) . '</ul>' : '') . "\n"
            . ($lead ? "<h2>Leadership</h2>\n<ul class=\"roles\">" . implode('', array_map(fn($l) => $roleRow(implode(', ', $l['periods']), $e(self::stripEmoji(js_get($l, 'role'))) . ', ' . ($this->safeUrl(js_get($l, 'link')) !== '' ? '<a href="' . $e(js_get($l, 'link')) . '">' . $e(self::stripEmoji(js_get($l, 'organization'))) . '</a>' : $e(self::stripEmoji(js_get($l, 'organization')))) . (str(js_get($l, 'place')) !== '' ? ', ' . $e(self::placeText(js_get($l, 'place'))) : '')), $lead)) . '</ul>' : '') . "\n"
            . ($this->awards ? "<h2>Awards</h2>\n<ul class=\"plain\">" . implode('', array_map(fn($a) => '<li>' . $e($a) . '</li>', $this->awards)) . '</ul>' : '') . "\n"
            . ($this->serviceRows || $this->reviewerLine !== '' ? "<h2>Service</h2>\n<ul class=\"roles\">" . implode('', array_map(fn($r) => $roleRow($r['period'], $e($r['role']) . ($r['org'] !== '' ? ', ' . $e($r['org']) : '') . ($r['description'] !== '' ? '<br><span class="small">' . $e($r['description']) . '</span>' : '')), $this->serviceRows))
                . ($this->reviewerLine !== '' ? $roleRow(self::fmtPeriod(js_get($this->reviewer, 'period')), $e(js_or(self::stripEmoji(js_get($this->reviewer, 'role')), 'Reviewer') . ': ' . self::stripEmoji(js_get($this->reviewer, 'venues')))) : '') . '</ul>' : '') . "\n"
            . ($this->nominations ? "<h2>Nominations</h2>\n<ul class=\"plain\">" . implode('', array_map(fn($n) => '<li>' . $e(js_or(self::stripEmoji(js_get($n, 'description')), self::stripEmoji(js_get($n, 'title')))) . (str(js_get($n, 'organization')) !== '' ? ' <span class="small">(' . $e(self::stripEmoji(js_get($n, 'organization'))) . ')</span>' : '') . '</li>', $this->nominations)) . '</ul>' : '') . "\n"
            . ($this->newsRows ? "<h2>In the news</h2>\n<ul class=\"roles\">" . implode('', array_map(fn($n) => $roleRow($n['kind'] === 'profile' ? 'Profile' : self::newsDate($n['date']), '<a href="' . $e($n['url']) . '">' . $e($n['title']) . '</a>' . ($n['publisher'] !== '' ? ', ' . $e($n['publisher']) : '')), $this->newsRows)) . '</ul>' : '') . "\n"
            . ($refNote ? '<h2>' . $e(js_or(self::stripEmoji(js_get($refs, 'title')), 'References')) . "</h2>\n" . implode("\n", array_map(fn($r) => '<p>' . $this->inlineMd($r) . '</p>', $refNote)) : '') . "\n"
            . "<h2>Profiles and contact</h2>\n<ul class=\"plain\"><li>Email: <a href=\"mailto:" . $e($this->EMAIL) . '">' . $e($this->EMAIL) . '</a></li>' . implode('', array_map(fn($i) => '<li>' . $e($i['propertyID']) . ': ' . (($i['url'] ?? '') !== '' ? '<a href="' . $e($i['url']) . '">' . $e($i['value']) . '</a>' : $e($i['value'])) . '</li>', $this->IDS)) . '</ul>';
        $person = $this->personNode(true); unset($person['mainEntityOfPage']);
        $this->write('about/index.html', $this->page([
            'title' => js_or($this->seoPage('about', 'title'), 'About ' . $this->NAME . ': Biography, Education, Awards'), 'canonical' => $this->SITE . '/about/', 'ogType' => 'profile',
            'ogTitle' => js_or($this->seoPage('about', 'ogTitle'), 'About ' . $this->NAME . ' | PhD Researcher, University of Saskatchewan'),
            'description' => $this->metaDesc(js_or($this->seoPage('about', 'description'), 'Biography of ' . $this->NAME . (($this->ALT_NAMES[0] ?? '') !== '' ? ' (' . $this->ALT_NAMES[0] . ')' : '') . ', PhD researcher at the University of Saskatchewan: education, teaching, leadership, awards and news coverage.')),
            'crumbs' => '<p class="crumbs"><a href="/">Home</a> / ' . $e($this->htitle('aboutPage', 'About')) . "</p>\n", 'body' => $body,
            'jsonld' => ['@context' => 'https://schema.org', '@graph' => [
                ['@type' => 'AboutPage', '@id' => $this->SITE . '/about/#page', 'url' => $this->SITE . '/about/', 'name' => 'About ' . $this->NAME, 'isPartOf' => ['@id' => $this->WEBSITE_ID], 'mainEntity' => ['@id' => $this->PERSON_ID], 'about' => ['@id' => $this->PERSON_ID]],
                $this->websiteNode, $person, $this->breadcrumbLd([['Home', $this->SITE . '/'], [$this->htitle('aboutPage', 'About'), $this->SITE . '/about/']]),
            ]],
        ]));
    }

    // ---------------------------------------------------------------- photos
    private static function showDate($d): string
    {
        $m = jmatch('/^(\d{4})-(\d{2})/', str($d));
        $M3 = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        return $m ? js_trim(($M3[(int)$m[2] - 1] ?? '') . ' ' . $m[1]) : str($d);
    }
    private function variantSet(string $src): string
    {
        if (!js_starts_with($src, '/images/')) return '';
        $rel = safe_decode(preg_split('/[?#]/', $src)[0]);
        $sz = $this->localImageSize($src);
        $ws = js_uniq(array_merge($this->S['VARIANT_WIDTHS'], $sz ? [$sz['w']] : []));
        sort($ws, SORT_NUMERIC);
        $out = [];
        foreach ($ws as $w) {
            $vp = seo_variant_path($rel, (int)$w);
            $f = seo_doc_file($this->env['docroot'], $vp, true);
            if ($f === null) continue;
            $out[] = $vp . '?v=' . substr(hash_file('sha256', $f), 0, 10) . ' ' . $w . 'w';
        }
        return implode(', ', $out);
    }
    private function photosOut(): void
    {
        $ph = [];
        foreach ($this->galleryItems as $g) {
            $src = $this->safeUrl(js_get($g, 'image'));
            if ($src === '') continue;
            $title = self::stripEmoji(js_get($g, 'title')); $desc = self::stripEmoji(js_get($g, 'description')); $altText = self::stripEmoji(js_get($g, 'altText'));
            $fo = js_get($g, 'featuresOwner');
            $owner = is_bool($fo) ? $fo : jtest('/palash/i', implode(' ', array_map(fn($x) => $x === null ? '' : js_string($x), [js_get($g, 'altText'), js_get($g, 'title'), js_get($g, 'description')])));
            $alt = $altText !== '' ? $altText : implode('. ', js_filter([$title, $desc], fn($x) => $x !== ''));
            if ($alt === '') $alt = $owner ? 'Photo of ' . $this->NAME : 'Photo';
            $caption = $owner && !jtest('/palash/i', $alt) ? $this->NAME . ': ' . $alt : $alt;
            $visible = js_or(self::stripEmoji(js_get($g, 'caption')), $altText, $desc);
            $ph[] = ['src' => $src, 'url' => $this->encPath($src), 'size' => $this->localImageSize($src), 'title' => $title, 'desc' => $desc, 'alt' => $alt, 'caption' => $caption, 'visible' => (string)$visible, 'owner' => $owner, 'date' => str(js_get($g, 'date')), 'credit' => self::stripEmoji(js_or(js_get($g, 'creditText'), js_get($g, 'credit')))];
        }
        $this->photos = $ph;
        foreach ($ph as $p) if (!$p['size']) $this->warn("photo {$p['src']} is not in the build (or unreadable); no width/height on /photos/");
        if (!$ph) return;
        $fig = function ($p, $i) {
            $img = '<img src="' . self::esc($p['url']) . '" alt="' . self::esc($p['alt']) . '"' . ($p['size'] ? ' width="' . $p['size']['w'] . '" height="' . $p['size']['h'] . '"' : ' class="noar"') . ' loading="' . ($i === 0 ? 'eager' : 'lazy') . '"' . ($i === 0 ? ' fetchpriority="high"' : '') . ' decoding="async">';
            $set = $this->variantSet($p['src']);
            $meta = js_filter([$p['owner'] && !jtest('/palash/i', $p['title'] . ' ' . $p['visible']) ? 'Pictured: ' . $this->NAME : '', self::esc(self::showDate($p['date'])), $p['credit'] !== '' ? 'Photo: ' . self::esc($p['credit']) : ''], fn($x) => $x !== '');
            return "<figure>\n<a class=\"ph\" href=\"" . self::esc($p['url']) . '">' . ($set !== '' ? '<picture><source type="image/webp" srcset="' . self::esc($set) . '" sizes="' . $this->S['GALLERY_SIZES'] . '">' . $img . '</picture>' : $img) . "</a>\n"
                . '<figcaption>' . ($p['title'] !== '' ? '<strong>' . self::esc($p['title']) . '</strong>' : '') . ($p['visible'] !== '' ? self::esc($p['visible']) : '') . ($meta ? '<span class="small">' . implode(' · ', $meta) . '</span>' : '') . "</figcaption>\n</figure>";
        };
        $PH_T = $this->htitle('photos', 'Photos');
        $body = '<h1>' . self::esc($PH_T) . "</h1>\n<p>" . ($this->hsub('photos') !== '' ? $this->inlineMd($this->hsub('photos')) : 'Photos of ' . self::esc($this->NAME) . ' at university events and on ' . $this->PRON[1] . ' travels.') . "</p>\n<div class=\"gallery wide\">\n"
            . implode("\n", array_map($fig, $ph, array_keys($ph))) . "\n</div>";
        $owned = implode(', ', js_filter(array_map(fn($p) => $p['title'], js_filter($ph, fn($p) => $p['owner'])), fn($x) => $x !== ''));
        $media = array_map(function ($p) {
            $extra = [];
            if ($p['title'] !== '') $extra['name'] = $p['title'];
            $extra['caption'] = $p['caption'];
            if ($p['desc'] !== '') $extra['description'] = $p['desc'];
            if ($p['owner']) $extra['about'] = ['@id' => $this->PERSON_ID];
            if ($p['credit'] !== '') $extra['creditText'] = $p['credit'];
            if (preg_match('/^\d{4}(-\d{2}){0,2}$/D', $p['date'])) $extra['dateCreated'] = $p['date'];
            return $this->imageObject($p['src'], $p['size'], $extra);
        }, $ph);
        $this->write('photos/index.html', $this->page([
            'title' => js_or($this->seoPage('photos', 'title'), $PH_T . ' | ' . $this->NAME), 'canonical' => $this->SITE . '/photos/', 'wide' => true, 'ogTitle' => $this->seoPage('photos', 'ogTitle'),
            'description' => $this->metaDesc(js_or($this->seoPage('photos', 'description'), 'Photos of ' . $this->NAME . ', PhD researcher at the University of Saskatchewan: ' . $owned . '.')),
            'crumbs' => '<p class="crumbs"><a href="/">Home</a> / ' . self::esc($PH_T) . "</p>\n", 'body' => $body,
            'jsonld' => ['@context' => 'https://schema.org', '@graph' => [
                ['@type' => 'ImageGallery', '@id' => $this->SITE . '/photos/#gallery', 'url' => $this->SITE . '/photos/', 'name' => 'Photos of ' . $this->NAME, 'inLanguage' => 'en',
                    'isPartOf' => ['@id' => $this->WEBSITE_ID], 'about' => ['@id' => $this->PERSON_ID], 'associatedMedia' => $media],
                $this->websiteNode, $this->personNode(false) + ['image' => $this->PERSON_IMAGES], $this->breadcrumbLd([['Home', $this->SITE . '/'], [$PH_T, $this->SITE . '/photos/']]),
            ]],
        ]));
    }

    // ---------------------------------------------------------------- blog (fallback)
    private function blogFallback(): void
    {
        if (isset($this->env['shells']['blog/index.html'])) return; // prerendered by the build
        $bh = js_get($this->headings, 'blog'); $bh = is_array($bh) ? $bh : [];
        $bt = self::stripEmoji(js_get($bh, 'title'));
        $bs = self::stripEmoji(js_get($bh, 'subtitle'));
        $body = '<h1>' . self::esc($bt !== '' ? $bt : 'Blog') . "</h1>\n" . ($bs !== '' ? '<p class="meta">' . $this->inlineMd($bs) . '</p>' : '') . "\n"
            . ($this->blogPosts ? implode("\n", array_map(fn($b) => '<article class="post"><p class="small">' . self::esc(self::showDate(js_get($b, 'date'))) . '</p><h2><a href="/blog/' . self::esc($this->postSlug($b)) . '/">' . self::esc(self::stripEmoji(js_get($b, 'title'))) . '</a></h2>' . (str(js_get($b, 'summary')) !== '' ? '<p>' . self::esc(self::stripEmoji(js_get($b, 'summary'))) . '</p>' : '') . '</article>', $this->blogPosts))
                : '<p class="empty">' . self::esc(js_or(self::stripEmoji(js_get($bh, 'emptyTitle')), 'Posts coming soon')) . '.</p>');
        $this->write('blog/index.html', $this->page([
            'title' => js_or($this->seoPage('blog', 'title'), ($bt !== '' ? $bt : 'Blog') . ' | ' . $this->NAME), 'canonical' => $this->SITE . '/blog/', 'active' => 'blog',
            'robots' => $this->blogPosts ? '' : 'noindex, follow',
            'description' => $this->metaDesc(js_or($this->seoPage('blog', 'description'), implode(' ', js_filter([self::plainFromMd($bs), 'Posts by ' . $this->NAME . ', PhD researcher in Computer Science at the University of Saskatchewan.'], fn($x) => $x !== '')))),
            'crumbs' => "<p class=\"crumbs\"><a href=\"/\">Home</a> / Blog</p>\n", 'body' => $body,
            'jsonld' => ['@context' => 'https://schema.org', '@graph' => [$this->websiteNode, $this->personNode(false), $this->breadcrumbLd([['Home', $this->SITE . '/'], ['Blog', $this->SITE . '/blog/']])]],
        ]));
        $this->warn('no prerendered /blog/ found; wrote the static fallback blog index');
    }

    // ---------------------------------------------------------------- maintenance page
    private function maintenanceOut(): void
    {
        $scholar = $this->profileByKind('scholar') ?? ['k' => 'scholar', 'label' => 'Google Scholar', 'url' => 'https://scholar.google.com/citations?user=Vy_sw5UAAAAJ'];
        $orcid = $this->profileByKind('orcid') ?? ['k' => 'orcid', 'label' => 'ORCID', 'url' => 'https://orcid.org/' . $this->ORCID];
        $N = self::esc($this->NAME);
        $mt = js_get($this->settings, 'maintenance');
        $MT = self::esc(js_or(self::stripEmoji(js_is_object($mt) ? js_get($mt, 'title') : ''), 'Back soon'));
        $EM = self::esc($this->EMAIL);
        $this->write('maintenance.html', "<!doctype html>\n<html lang=\"en\">\n<head>\n<meta charset=\"utf-8\">\n<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n<title>$MT | $N</title>\n<meta name=\"robots\" content=\"noindex\">\n<meta name=\"description\" content=\"The website of $N is undergoing maintenance.\">\n<link rel=\"icon\" href=\"/favicon.svg\" type=\"image/svg+xml\">\n<link rel=\"icon\" href=\"/favicon.ico\" sizes=\"32x32\">\n<script>" . $this->S['THEME_JS'] . "</script>\n<style>" . $this->S['CSS'] . "\n.maint{min-height:100vh;display:flex;flex-direction:column;justify-content:center;padding-top:48px;padding-bottom:48px}\n.maint .until:empty{display:none}\n.maint ul.prof a{color:var(--muted);display:inline-flex;align-items:center;gap:6px;min-height:40px}\n.maint ul.prof a:hover{color:var(--accent)}</style>\n</head>\n<body>\n<main id=\"main\" class=\"wrap maint\">\n<h1>$N</h1>\n<p class=\"meta\">" . self::esc($this->NAME_LINE) . "</p>\n<p><!--MAINT-MESSAGE-->The site is undergoing maintenance — back soon.<!--/MAINT-MESSAGE--></p>\n<p class=\"small until\"><!--MAINT-UNTIL--><!--/MAINT-UNTIL--></p>\n<ul class=\"prof\" aria-label=\"Contact and profiles\"><li><a href=\"mailto:$EM\">" . $this->icon('email') . "<span>$EM</span></a></li><li>" . $this->profileLink($scholar) . '</li><li>' . $this->profileLink($orcid) . "</li></ul>\n</main>\n</body>\n</html>\n");
    }

    // ---------------------------------------------------------------- 404
    private function notFoundOut(): void
    {
        $NF = js_get($this->headings, 'notFound');
        $NF = js_is_object($NF) ? $NF : [];
        $NF_T = js_or(self::stripEmoji(js_get($NF, 'title')), 'Page not found');
        $this->write('404.html', $this->page([
            'title' => $NF_T . ' | ' . $this->NAME, 'description' => js_or(self::stripEmoji(js_get($NF, 'description')), 'This page does not exist.'), 'canonical' => '', 'beacon' => false, 'fonts' => [500],
            'body' => '<h1>' . self::esc($NF_T) . "</h1>\n<p>" . (self::stripEmoji(js_get($NF, 'text')) !== '' ? $this->inlineMd($this->fillTokens(self::stripEmoji(js_get($NF, 'text')))) : 'There is no page at this address. It may have moved.') . "</p>\n<ul class=\"plain\"><li><a href=\"/\">Home</a></li>" . implode('', array_map(fn($x) => '<li><a href="' . $x[1] . '">' . self::esc($x[0]) . '</a></li>', $this->FOOTER_PAGES)) . '</ul>',
        ]));
    }

    // ---------------------------------------------------------------- tools & benchmarks
    private function shortName(array $p): string { $c = js_trim(explode(':', $p['title'])[0]); return js_len($c) <= 40 && $c !== $p['title'] ? $c : $p['title']; }
    private static function swDesc($d): string
    {
        $t = js_trim(jreplace('/\s+/', ' ', self::stripEmoji($d)));
        if (js_len($t) <= 320) return jreplace('/[.\s]+$/', '', $t, false);
        $cut = js_slice($t, 0, 320); $end = js_last_index_of($cut, '. ');
        return jreplace('/[.\s]+$/', '', $end > 80 ? js_slice($cut, 0, $end) : self::clip($t, 320), false);
    }
    private function softwareOut(): void
    {
        $KIND = ['tool' => 'tool', 'dataset' => 'dataset', 'benchmark' => 'benchmark'];
        $this->software = [];
        foreach ($this->softwareItems as $s) {
            $paper = js_find($this->pubs, fn($p) => $p['slug'] === self::slugify(js_get($s, 'paper_slug')));
            $kind = $KIND[js_lower(str(js_get($s, 'publication_kind')))] ?? ($KIND[js_lower(str(js_get($s, 'kind')))] ?? 'tool');
            $links = [];
            $add = function ($label, $u) use (&$links) { $u = $this->safeUrl($u); if ($u === '') return; foreach ($links as $l) if ($l[1] === $u) return; $links[] = [$label, $u]; };
            $add($kind === 'tool' ? 'Tool' : ($kind === 'benchmark' ? 'Benchmark' : 'Dataset'), js_get($s, 'url'));
            $add('Code', js_get($s, 'code_url'));
            $add(jtest('/zenodo/i', str(js_get($s, 'dataset_url'))) ? 'Zenodo' : 'Dataset', js_get($s, 'dataset_url'));
            $add(jtest('/zenodo|10\.5281\//i', str(js_get($s, 'artifact_url'))) ? 'Zenodo' : 'Replication package', js_get($s, 'artifact_url'));
            if ($links) $this->software[] = ['name' => self::stripEmoji(js_get($s, 'name')), 'kind' => $kind, 'desc' => self::swDesc(js_get($s, 'description')), 'paper' => $paper, 'links' => $links];
        }
    }

    // ---------------------------------------------------------------- llms.txt / llms-full.txt
    private function paperLine(array $p): string
    {
        $eq = $this->eqSentence($p);
        $s = '- [' . $p['title'] . '](' . $this->SITE . '/publications/' . $p['slug'] . '.md): ' . $p['venueShort'] . ($p['type'] === 'doctoral_symposium' && !jtest('/doctoral/i', $p['venueShort']) ? ' (Doctoral Symposium)' : '') . '.'
            . ($p['award'] !== '' ? ' ' . $p['award'] . '.' : '') . ($eq !== '' ? ' ' . $eq : '') . (($c = self::clip(js_or($p['problem'], $p['description'], self::firstSentence($p['abstract'])), 240)) !== '' ? ' ' . $c : '')
            . ($p['doi'] !== '' ? ' DOI ' . $p['doi'] . '.' : '') . ($p['arxiv'] !== '' ? ' arXiv ' . $p['arxiv'] . '.' : '') . ($p['handle'] !== '' ? ' Handle ' . $p['handle'] . '.' : '');
        return jreplace('/\s+$/', '', $s, false);
    }
    private function refLine(array $ps): string { return implode('; ', array_map(fn($p) => '[' . $this->shortName($p) . '](' . $this->SITE . '/publications/' . $p['slug'] . '.md) (' . $p['venueShort'] . ')', $ps)); }
    private function swKind(array $s): string { return $s['kind'] === 'tool' ? 'Tool' : ($s['kind'] === 'benchmark' ? 'Benchmark' : 'Dataset'); }
    private static function teachLine(array $t): string
    {
        $d = implode(' ', js_filter([$t['description'], $t['details']], fn($v) => $v !== ''));
        return '- ' . $t['role'] . ($t['org'] !== '' ? ', ' . $t['org'] : '') . ($t['period'] !== '' ? ' (' . $t['period'] . ')' : '') . ($d !== '' ? ': ' . jreplace('/[.\s]+$/', '', $d, false) . '.' : '.');
    }
    private static function talkLine(array $t): string
    {
        return '- ' . ($t['period'] !== '' ? $t['period'] . ': ' : '') . '"' . $t['title'] . '"' . ($t['venue'] !== '' ? ', ' . $t['venue'] : '') . (self::talkRole($t) !== '' ? ' (' . self::talkRole($t) . ')' : '') . '.' . ($t['url'] !== '' ? ' ' . $t['url'] : '');
    }
    private function blogLine($b): string
    {
        $d = self::isoDay(js_get($b, 'date'));
        return '- [' . self::stripEmoji(js_get($b, 'title')) . '](' . $this->SITE . '/blog/' . encode_uri_component($this->postSlug($b)) . '/)' . ($d !== '' ? " ($d)" : '') . (str(js_get($b, 'summary')) !== '' ? ': ' . self::clip(self::stripEmoji(js_get($b, 'summary')), 240) : '');
    }
    private function llmsOut(): void
    {
        $N = $this->NAME; $SITE = $this->SITE;
        $intro = "> $N (" . ($this->LLMS_AS !== '' ? "published as {$this->LLMS_AS}; " : '') . "ORCID {$this->ORCID}) is a {$this->PERSON_DESC}";
        $eqPapers = js_filter($this->pubs, fn($p) => js_some($p['authors'], fn($a) => self::isOwner($a) && self::isEqual($p, $a)));
        $prose = implode("\n\n", js_filter([
            $this->LABS_LINE,
            $eqPapers ? $this->PRON[0] . ' ' . $this->PRON[2] . ' co-first author (equal contribution) of ' . implode(' and of ', array_map(fn($p) => $this->shortName($p) . ' (' . $p['venueShort'] . '; with ' . implode(' and ', js_filter($p['authors'], fn($a) => self::isEqual($p, $a) && !self::isOwner($a))) . ')', $eqPapers)) . '.' : '',
            $this->DISAMBIGUATION,
            "Each paper below has an HTML page with the abstract and BibTeX and a Markdown version (linked here). Contact: {$this->EMAIL}.",
        ], fn($x) => $x !== ''));
        $UMB = $this->S['UMBRELLA_TAGS'];
        if (js_some($this->pubs, fn($p) => count($p['tags']) > 0)) {
            $groups = [];
            foreach ($UMB as $u) { $ps = js_filter($this->pubs, fn($p) => in_array($u, $p['tags'], true)); if ($ps) $groups[] = [$this->tagLong($u), $ps]; }
            foreach (js_filter($this->pubs, fn($p) => !js_some($p['tags'], fn($t) => in_array($t, $UMB, true))) as $p) {
                $h = $p['tags'] ? $this->tagLong($p['tags'][0]) : 'Other work';
                $gi = js_find_index($groups, fn($x) => $x[0] === $h);
                if ($gi >= 0) $groups[$gi][1][] = $p; else $groups[] = [$h, [$p]];
            }
            $sections = array_map(fn($g) => '## Papers: ' . $g[0] . "\n\n" . implode("\n", array_map(fn($p) => $this->paperLine($p), $g[1])), $groups);
        } else {
            $listed = [];
            $sections = [];
            foreach ($this->themesUsed as $t) {
                $ps = js_filter($t['papers'], fn($p) => !in_array($p['slug'], $listed, true));
                foreach ($ps as $p) $listed[] = $p['slug'];
                if ($ps) $sections[] = '## Papers: ' . $t['name'] . "\n\n" . implode("\n", array_map(fn($p) => $this->paperLine($p), $ps));
            }
            $rest = js_filter($this->pubs, fn($p) => !in_array($p['slug'], $listed, true));
            if ($rest) $sections[] = "## Papers: other\n\n" . implode("\n", array_map(fn($p) => $this->paperLine($p), $rest));
        }
        $seIndex = js_filter(array_map(fn($t) => [$this->tagLong($t), js_filter($this->pubs, fn($p) => js_some($p['tags'], fn($x) => js_lower($x) === js_lower($t)))], $this->AREA_TAGS), fn($x) => count($x[1]) > 0);
        $mlIndex = array_map(fn($a) => [$this->mlLong($a['tag']), $a['papers']], $this->mlUsed);
        if ($seIndex) $sections[] = "## Research areas (software engineering)\n\n" . implode("\n", array_map(fn($x) => '- ' . $x[0] . ': ' . $this->refLine($x[1]), $seIndex));
        if ($mlIndex) $sections[] = "## AI/ML research\n\nThe same papers grouped by machine-learning topic.\n\n" . implode("\n", array_map(fn($x) => '- ' . $x[0] . ': ' . $this->refLine($x[1]), $mlIndex));
        $swLine = fn($s) => '- [' . $s['name'] . '](' . $s['links'][0][1] . '): ' . ($s['desc'] !== '' ? $s['desc'] . '. ' : '') . $this->swKind($s) . ' paper: ' . $s['paper']['venueShort'] . ' (' . $s['paper']['url'] . ').' . implode('', array_map(fn($l) => ' ' . $l[0] . ': ' . $l[1] . '.', array_slice($s['links'], 1)));
        $awardLines = implode("\n", array_map(fn($a) => "- $a", $this->awards));
        $LLMS_PROFILES = js_filter($this->profileLinks, fn($p) => !in_array($p['k'], ['scholar', 'orcid', 'dblp'], true) && !js_some($this->IDS, fn($i) => ($i['url'] ?? null) === $p['url']));
        $TOOLS_HEADING = $this->TOOLS_LABEL;
        $faqQs = implode(' ', array_map(fn($f) => js_string(js_get($f, 'q')), $this->faqItems));
        $llms = "# $N\n\n$intro\n\n$prose\n\n" . implode("\n\n", $sections) . "\n"
            . ($this->software ? "\n## $TOOLS_HEADING\n\n" . implode("\n", array_map($swLine, $this->software)) . "\n" : '')
            . ($this->awards ? "\n## Awards\n\n$awardLines\n" : '')
            . ($this->teachRows ? "\n## Teaching and mentoring\n\n" . implode("\n", array_map(fn($t) => self::teachLine($t), $this->teachRows)) . "\n" : '')
            . ($this->talks ? "\n## Invited talks\n\n" . implode("\n", array_map(fn($t) => self::talkLine($t), $this->talks)) . "\n" : '')
            . ($this->blogPosts ? "\n## Blog\n\n" . implode("\n", array_map(fn($b) => $this->blogLine($b), $this->blogPosts)) . "\n" : '')
            . ($this->faqItems ? "\n## Research questions\n\n- [Questions this research addresses]($SITE/faq.md): $faqQs\n" : '')
            . "\n## Profiles\n\n" . implode("\n", array_map(fn($i) => isset($i['url']) ? '- [' . $i['propertyID'] . '](' . $i['url'] . '): ' . $i['value'] : '- ' . $i['propertyID'] . ': ' . $i['value'], $this->IDS)) . "\n"
            . implode("\n", array_map(fn($p) => '- [' . $p['label'] . '](' . $p['url'] . ')', $LLMS_PROFILES)) . "\n\n## Optional\n\n"
            . "- [Full text of paper summaries, abstracts and BibTeX]($SITE/llms-full.txt)\n- [Publication list]($SITE/publications.md)\n- [About page]($SITE/about/)\n"
            . ($this->photos ? "- [Photos]($SITE/photos/)\n" : '') . implode('', array_map(fn($d) => '- [' . $d['label'] . '](' . $this->abs($d['url']) . ")\n", $this->documents));
        $this->write('llms.txt', $llms);

        $bio = implode("\n\n", js_filter(array_map(fn($s) => self::plainFromMd(self::stripEmoji($s)), js_is_array(js_get($this->about, 'paragraphs')) ? $this->about['paragraphs'] : []), fn($x) => $x !== ''));
        $eduLines = implode("\n", array_map(function ($e) {
            $th = $this->thesisFor($e);
            return '- ' . self::stripEmoji(js_get($e, 'degree')) . ', ' . $this->eduPlace($e) . ($th ? '. Thesis: "' . $th['title'] . '"' . ($th['url'] !== '' ? ' (' . $this->abs($th['url']) . ')' : '') : '') . '.';
        }, $this->EDU));
        $leadLines = [];
        foreach (self::mergeRoles($this->leadership) as $l) {
            $hl = js_get($l, 'highlights');
            $leadLines[] = '- ' . self::stripEmoji(js_get($l, 'role')) . ', ' . self::stripEmoji(js_get($l, 'organization')) . (str(js_get($l, 'place')) !== '' ? ', ' . self::placeText(js_get($l, 'place')) : '') . ($l['periods'] ? ' (' . implode(', ', $l['periods']) . ')' : '')
                . (str(js_get($l, 'description')) !== '' ? ': ' . jreplace('/[.\s]+$/', '', self::stripEmoji(js_get($l, 'description')), false) . '.' : '.') . implode('', array_map(fn($h) => "\n  - " . self::stripEmoji($h), js_is_array($hl) ? $hl : []));
        }
        foreach ($this->serviceRows as $r) $leadLines[] = '- ' . $r['role'] . ($r['org'] !== '' ? ', ' . $r['org'] : '') . ($r['period'] !== '' ? ' (' . $r['period'] . ')' : '') . ($r['description'] !== '' ? ': ' . jreplace('/[.\s]+$/', '', $r['description'], false) . '.' : '.');
        if ($this->reviewerLine !== '') $leadLines[] = '- ' . $this->reviewerLine . '.';
        $full = "# $N\n\n$intro\n\n$prose\n" . ($bio !== '' ? "\n## Biography\n\n$bio\n" : '') . ($this->EDU ? "\n## Education\n\n$eduLines\n" : '')
            . "\n## Publications\n\n" . implode("\n", array_map(fn($p) => $this->paperMd($p, $this->bibtexFor($p), 3), $this->pubs)) . "\n"
            . ($this->software ? "\n## $TOOLS_HEADING\n\n" . implode("\n", array_map(fn($s) => '- ' . $s['name'] . ': ' . ($s['desc'] !== '' ? $s['desc'] . '. ' : '') . $this->swKind($s) . ' paper: ' . $s['paper']['title'] . ', ' . $s['paper']['venueShort'] . ' (' . $s['paper']['url'] . '). Links: ' . implode('; ', array_map(fn($l) => $l[0] . ' ' . $l[1], $s['links'])) . '.', $this->software)) . "\n" : '')
            . ($this->awards ? "\n## Awards\n\n$awardLines\n" : '')
            . ($this->teachRows ? "\n## Teaching and mentoring\n\n" . implode("\n", array_map(fn($t) => self::teachLine($t), $this->teachRows)) . "\n" : '')
            . ($this->talks ? "\n## Invited talks\n\n" . implode("\n", array_map(fn($t) => self::talkLine($t), $this->talks)) . "\n" : '')
            . ($this->researchRows ? "\n## Research and professional positions\n\n" . implode("\n", array_map(fn($e) => '- ' . self::stripEmoji(js_get($e, 'role')) . ', ' . self::placeFmt(js_get($e, 'place')) . (str(js_get($e, 'description')) !== '' ? ': ' . jreplace('/[.\s]+$/', '', self::stripEmoji(js_get($e, 'description')), false) . '.' : '.'), $this->researchRows)) . "\n" : '')
            . ($this->leadership || $this->serviceRows || $this->reviewerLine !== '' ? "\n## Leadership and service\n\n" . implode("\n", $leadLines) . "\n" : '')
            . ($this->nominations ? "\n## Nominations\n\n" . implode("\n", array_map(fn($n) => '- ' . self::stripEmoji(js_get($n, 'title')) . ': ' . self::stripEmoji(js_get($n, 'description')), $this->nominations)) . "\n" : '')
            . ($this->blogPosts ? "\n## Blog\n\n" . implode("\n", array_map(fn($b) => $this->blogLine($b), $this->blogPosts)) . "\n" : '')
            . ($this->newsRows ? "\n## In the news\n\n" . implode("\n", array_map(fn($n) => '- [' . $n['title'] . '](' . $n['url'] . ')' . ($n['publisher'] !== '' ? ', ' . $n['publisher'] : '') . ($n['kind'] === 'article' && $n['date'] !== '' ? ' (' . $n['date'] . ')' : ($n['kind'] === 'profile' ? ' (lab profile page)' : '')), $this->newsRows)) . "\n" : '')
            . ($this->faqItems ? "\n## Research questions\n\n" . implode("\n\n", array_map(fn($f) => '### ' . js_string(js_get($f, 'q')) . "\n\n" . $this->mdAbsLinks(js_get($f, 'a')), $this->faqItems)) . "\n" : '')
            . "\n## Profiles\n\n" . implode("\n", array_map(fn($i) => '- ' . $i['propertyID'] . ': ' . ($i['url'] ?? $i['value']), $this->IDS)) . "\n"
            . implode("\n", array_map(fn($p) => '- ' . $p['label'] . ': ' . $p['url'], $LLMS_PROFILES)) . "\n";
        $this->write('llms-full.txt', $full);
    }

    // ---------------------------------------------------------------- sitemap
    private function sitemapOut(): void
    {
        $SITE = $this->SITE;
        $imgLocs = fn($list) => array_map(fn($u) => $this->abs($this->encPath($u)), $list);
        $urls = [[$SITE . '/', '1.0', $this->HOME_LASTMOD, $imgLocs(array_map(fn($h) => $h['u'], array_slice($this->HEADSHOTS, 0, 1)))], [$SITE . '/publications/', '0.9', $this->PUBS_LASTMOD]];
        foreach ($this->pubs as $p) $urls[] = [$p['url'], '0.8', $this->paperLastmod($p)];
        if ($this->faqItems) $urls[] = [$SITE . '/faq/', '0.6', self::newest(js_get($this->readJson('faq', []), 'updated'), $this->PUBS_LASTMOD, $this->sectionDate('faq'))];
        $urls[] = [$SITE . '/about/', '0.6', self::newest(js_get($this->about, 'updated'), $this->HOME_LASTMOD, array_map(fn($s) => $this->sectionDate($s), ['about', 'service', 'leadership', 'highlights']))];
        if ($this->photos) $urls[] = [$SITE . '/photos/', '0.5', self::newest(js_get($this->readJson('gallery', []), 'updated'), array_map(fn($p) => $p['date'], $this->photos), $this->sectionDate('gallery')), $imgLocs(array_map(fn($p) => $p['src'], $this->photos))];
        if ($this->blogPosts) {
            $urls[] = [$SITE . '/blog/', '0.5', self::newest(array_map(fn($b) => $this->blogLastmod($b), $this->blogPosts), $this->sectionDate('blog'))];
            foreach ($this->blogPosts as $b) $urls[] = [$SITE . '/blog/' . encode_uri_component($this->postSlug($b)) . '/', '0.5', $this->blogLastmod($b), $imgLocs(js_filter([$this->safeUrl(js_get($b, 'cover'))], fn($x) => $x !== ''))];
        }
        foreach ($this->pubs as $p) if ($p['pdf'] !== '') $urls[] = [$this->abs($p['pdf']), '0.5', $this->paperLastmod($p)];
        $locEsc = fn($u) => self::esc(encode_uri(safe_decode($u)));
        $lines = array_map(function ($x) use ($locEsc) {
            [$u, $pr, $lm] = $x; $imgs = $x[3] ?? [];
            return '  <url><loc>' . $locEsc($u) . '</loc>' . ($lm !== '' ? "<lastmod>$lm</lastmod>" : '') . "<priority>$pr</priority>" . implode('', array_map(fn($i) => '<image:image><image:loc>' . $locEsc($i) . '</image:loc></image:image>', js_uniq($imgs))) . '</url>';
        }, $urls);
        $this->write('sitemap.xml', "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\" xmlns:image=\"http://www.google.com/schemas/sitemap-image/1.1\">\n" . implode("\n", $lines) . "\n</urlset>\n");
        $this->facts['sitemapUrls'] = count($urls);
    }

    // Accessors for the live layer (shells.php)
    public function blogPostsAll(): array { return $this->blogPosts; }
    public function ownerName(): string { return $this->NAME; }
    public function setting(string $k) { return js_get($this->settings, $k); }
    public function heading(string $k) { return js_get($this->headings, $k); }
    public function slugOf($b): string { return $this->postSlug($b); }
}
