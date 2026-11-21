// Build-time SEO / GEO generator. Runs after `vite build` and `prerender.mjs`.
//
// Reads src/content/*.json (the same files the CMS sync writes) and writes into dist/:
//   - the homepage JSON-LD @graph (WebSite, ProfilePage, Person) at <!--SEO-GRAPH-->
//   - /publications/<slug>/index.html  (Highwire citation_* tags, ScholarlyArticle JSON-LD,
//     visible abstract + BibTeX), /publications/<slug>.md, /publications/<slug>/cite.bib
//   - /publications/index.html + /publications.md
//   - /faq/index.html + /faq.md (from faq.json)
//   - /about/index.html, /photos/index.html (from gallery.json; ImageGallery JSON-LD)
//   - /blog/index.html only when prerender.mjs did not produce one (static fallback with
//     the empty state); prerendered blog pages get the WebSite + Person graph
//   - /maintenance.html (default page served with 503 by .htaccess while /.maintenance exists;
//     the admin rewrites only the text between the MAINT-MESSAGE / MAINT-UNTIL markers)
//   - /llms.txt, /llms-full.txt, /sitemap.xml (with image entries), /404.html
//
// Env overrides: DIST (default dist), CONTENT_DIR (default src/content).
// `node scripts/generate-seo.mjs --check` validates the content only (no dist needed) and
// exits non-zero on errors, so the build can fail fast before vite runs.
import { readFileSync, writeFileSync, mkdirSync, existsSync, rmSync, readdirSync, statSync } from "fs";
import path from "path";

const ROOT = path.resolve(path.dirname(new URL(import.meta.url).pathname), "..");
const DIST = path.resolve(process.env.DIST || path.join(ROOT, "dist"));
const CONTENT = path.resolve(process.env.CONTENT_DIR || path.join(ROOT, "src/content"));
const CHECK_ONLY = process.argv.includes("--check");

const SITE = "https://palashroy.me";
const PERSON_ID = `${SITE}/#person`;
const WEBSITE_ID = `${SITE}/#website`;
const NAME = "Palash Ranjan Roy";
const ORCID = "0000-0001-9470-4233";
const EMAIL = "palash.roy@usask.ca";
const IMAGE = `${SITE}/images/palash_roy_headshot.jpg`;
// Real content dates only (never the build date): see lastmodFor() and the sitemap.
const isoDay = (d) => (/^\d{4}-\d{2}-\d{2}/.test(String(d || "")) ? String(d).slice(0, 10) : "");
const newest = (...ds) => ds.flat().map(isoDay).filter(Boolean).sort().pop() || "";

// Stable identity facts (verified against ORCID / dblp / OpenAlex / Semantic Scholar / Wikidata).
const IDS = [
  { propertyID: "ORCID", value: ORCID, url: `https://orcid.org/${ORCID}` },
  { propertyID: "dblp", value: "355/4465", url: "https://dblp.org/pid/355/4465.html" },
  { propertyID: "OpenAlex", value: "A5058812722", url: "https://openalex.org/A5058812722" },
  { propertyID: "Semantic Scholar", value: "2051272535", url: "https://www.semanticscholar.org/author/2051272535" },
  { propertyID: "Wikidata", value: "Q130853775", url: "https://www.wikidata.org/wiki/Q130853775" },
  { propertyID: "Google Scholar", value: "Vy_sw5UAAAAJ", url: "https://scholar.google.com/citations?user=Vy_sw5UAAAAJ" },
];
// Used only when socials.json has no profile links at all; otherwise sameAs comes from the
// admin-editable, non-hidden socials.json entries plus the fixed scholarly IDs above.
const EXTRA_SAMEAS = [
  "https://github.com/Roy101",
  "https://www.linkedin.com/in/palashranjanroy/",
  "https://www.researchgate.net/profile/Palash_Roy",
];
const ALT_NAMES = ["Palash Roy", "Palash R. Roy", "Palash R Roy", "P. R. Roy", "পলাশ রঞ্জন রায়"];
const ORGS = {
  // Wikidata QIDs checked with wbsearchentities on 2026-11-20: Q1514848 "University of
  // Saskatchewan, public university in Saskatoon", Q4836325 "BRAC University, private university in Bangladesh".
  usask: { "@type": "CollegeOrUniversity", "@id": `${SITE}/#usask`, name: "University of Saskatchewan", url: "https://www.usask.ca/", sameAs: "https://www.wikidata.org/wiki/Q1514848" },
  brac: { "@type": "CollegeOrUniversity", "@id": `${SITE}/#brac`, name: "BRAC University", url: "https://www.bracu.ac.bd/", sameAs: "https://www.wikidata.org/wiki/Q4836325" },
  srlab: { "@type": "ResearchOrganization", name: "Software Research Lab (SRLab)", url: "https://srlab.usask.ca/", parentOrganization: { "@id": `${SITE}/#usask` } },
  ise: { "@type": "ResearchOrganization", name: "Interactive Software Engineering and Analytics (iSE) Lab", url: "https://ise.usask.ca/", parentOrganization: { "@id": `${SITE}/#usask` } },
};
const KNOWS_ABOUT = [
  "Code clones", "Code clone detection", "Cross-language clone detection", "Clone refactoring",
  "Large language models for software engineering", "Model compression", "Green AI",
  "Model merging", "Software maintenance", "Software engineering",
];
const SUMMARY =
  "PhD researcher in Computer Science at the University of Saskatchewan working on code clones " +
  "(detection, benchmarks and refactoring), large language models for software engineering, " +
  "compression of code language models, and model merging.";

// Research themes. The owner's list lives in research.json `themes` ({id, title, papers:[slugs]})
// and is shared with the homepage. When it is missing (an older database copy), a paper belongs
// to every built-in theme whose pattern matches its title (falling back to keywords).
const FALLBACK_THEMES = [
  { id: "clone-benchmarks", name: "Code clone benchmarks and LLM-generated clones", re: /benchmark|generat\w* semantic|clone generation/i },
  { id: "clone-detection", name: "Clone detection in the LLM era", re: /clone detect/i },
  { id: "model-merging", name: "Model merging for software engineering", re: /merg/i },
  { id: "green-llms", name: "Efficient and sustainable language models for code", re: /compress|carbon|green|sustainab/i },
  { id: "clone-refactoring", name: "Clone refactoring", re: /refactor/i },
  { id: "llms-programming", name: "LLMs and programming platforms", re: /programming platform|competitive programming/i },
  { id: "scholarly-metrics", name: "Scholarly metrics", re: /ranking|bibliometric/i },
];

// ---------------------------------------------------------------- helpers
const readJson = (name, fallback) => {
  try { return JSON.parse(readFileSync(path.join(CONTENT, name + ".json"), "utf8")); } catch { return fallback; }
};
const items = (d) => (Array.isArray(d) ? d : Array.isArray(d?.items) ? d.items : []);
const str = (v) => (v === null || v === undefined ? "" : String(v)).trim();
const esc = (s) => str(s).replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;");
const slugify = (s) => str(s).toLowerCase().replace(/[^a-z0-9]+/g, "-").replace(/^-+|-+$/g, "");
const stripEmoji = (s) => str(s)
  .replace(/[\u{1F1E6}-\u{1F1FF}]/gu, "")
  .replace(/\p{Extended_Pictographic}(\uFE0F|\u200D\p{Extended_Pictographic})*/gu, (c) => (/^[\u00A9\u00AE\u2122]/.test(c) ? c[0] : ""))
  .replace(/\uFE0F/g, "").replace(/\s{2,}/g, " ").trim();
const ldScript = (obj) => `<script type="application/ld+json">${JSON.stringify(obj).replace(/</g, "\\u003c")}</script>`;
// BreadcrumbList for a subpage: [[name, url], ...] from Home to the page itself.
const breadcrumbLd = (trail) => ({ "@type": "BreadcrumbList", "@id": `${trail[trail.length - 1][1]}#breadcrumb`, itemListElement: trail.map(([name, item], i) => ({ "@type": "ListItem", position: i + 1, name, item })) });
// Meta descriptions stay within 160 characters (search results cut longer ones).
const metaDesc = (s) => clip(s, 158);
const abs = (u) => (/^https?:\/\//.test(u) ? u : SITE + (u.startsWith("/") ? u : "/" + u));
const normDoi = (d) => str(d).replace(/^https?:\/\/(dx\.)?doi\.org\//i, "").replace(/^doi:\s*/i, "").trim();
const clip = (s, n) => { s = str(s).replace(/\s+/g, " "); return s.length <= n ? s : s.slice(0, s.lastIndexOf(" ", n - 1)).replace(/[,;:.]$/, "") + "…"; };
const firstSentence = (s) => (str(s).match(/^.*?[.!?](\s|$)/) || [str(s)])[0].trim();
const write = (rel, content) => {
  const file = path.join(DIST, rel);
  mkdirSync(path.dirname(file), { recursive: true });
  writeFileSync(file, content);
};
// Minimal markdown for CMS strings: [text](url), **bold**, paragraphs.
const inlineMd = (s, absolute = false) =>
  esc(s)
    .replace(/\*\*(.+?)\*\*/g, "<strong>$1</strong>")
    .replace(/\[([^\]]+)\]\(([^)\s]+)\)/g, (_, t, u) => `<a href="${absolute ? esc(abs(u.replace(/&amp;/g, "&"))) : u}">${t}</a>`);
const mdParas = (s, absolute = false) => str(s).split(/\n{2,}/).map((p) => `<p>${inlineMd(p.trim(), absolute)}</p>`).join("\n");
const mdAbsLinks = (s) => str(s).replace(/\]\((\/[^)\s]*)\)/g, (_, u) => `](${SITE}${u})`);
const plainFromMd = (s) => str(s).replace(/\[([^\]]+)\]\([^)]+\)/g, "$1").replace(/\*\*(.+?)\*\*/g, "$1");

const OWNER_RE = /^(palash( ranjan| r)? roy|p r roy|p roy|roy,? p(alash)?( r(anjan)?)?)$/;
const isOwner = (n) => OWNER_RE.test(str(n).toLowerCase().replace(/\./g, " ").replace(/\s+/g, " ").replace(/ ,/g, ",").trim());

// ---------------------------------------------------------------- content
const pubsRaw = items(readJson("publications", { items: [] }));
const faqItems = items(readJson("faq", { items: [] })).filter((f) => str(f?.q) && str(f?.a));
const about = readJson("about", {});
const socialsJson = readJson("socials", {});
const socials = Array.isArray(socialsJson?.links) ? socialsJson.links : [];
// The IWSC 2023 awards were once stored as one combined item; the owner split them in two
// (SPEC-round3 §15). An older database copy is split the same way here, so no page shows the
// combined title before the admin migration has run.
const LEGACY_IWSC = /^People's Choice Best Paper Award and Student Encouragement Award/i;
const highlights = items(readJson("highlights", { items: [] })).flatMap((h) => (h && LEGACY_IWSC.test(str(h.title)) ? [
  { ...h, title: "People's Choice Best Paper Award (IWSC 2023)", description: "Awarded by attendee vote to the IWSC 2023 paper on generating semantic and cross-language clones with large language models.", paper_slug: "llm-clone-generation-iwsc" },
  { ...h, title: "Student Encouragement Award (IWSC 2023)", description: "Awarded to Palash Ranjan Roy at IWSC 2023." },
] : [h]));
const blogPosts = items(readJson("blog", { items: [] })).filter((p) => {
  const d = str(p?.draft).toLowerCase();
  return p && (p.slug || p.title) && !(p.draft === true || d === "yes" || d === "true" || d === "1");
}).filter((p) => { const s = str(p.slug) || slugify(p.title); return /^[^/\\?#]+$/.test(s) && s !== "." && s !== ".."; })
  .sort((a, b) => str(b.date).localeCompare(str(a.date)));
const footer = readJson("footer", {});
const research = readJson("research", {});
const metricsJson = readJson("metrics", {});
const settings = readJson("settings", {});
const headings = readJson("headings", {});
const hero = readJson("hero", {});
const leadership = items(readJson("leadership", { items: [] })).filter((l) => l && str(l.role));
const galleryItems = items(readJson("gallery", { items: [] })).filter((g) => g && str(g.image));
const safeUrl = (u) => (/^(https?:\/\/|\/(?!\/))/i.test(str(u)) ? str(u) : "");
// Tools & Benchmarks (SPEC-round3 §8): only items that are themselves the subject of a published
// paper. The paper_slug must name a publication; the slug check happens once pubs are known.
const softwareRaw = items(readJson("software", { items: [] }))
  .filter((s) => s && str(s.name) && str(s.paper_slug) && (safeUrl(s.url) || safeUrl(s.code_url) || safeUrl(s.dataset_url) || safeUrl(s.artifact_url)));
const serviceItems = items(readJson("service", { items: [] })).filter((s) => s && str(s.role));
const newsItems = items(readJson("news", { items: [] })).filter((n) => n && str(n.title));

// Research-area tags (settings.tagVocabulary, admin-editable). Used when the setting is missing.
const DEFAULT_TAGS = [
  ["Software Maintenance & Evolution"], ["Program Comprehension"], ["Code Clones"], ["Refactoring"],
  ["Empirical SE", "Empirical Software Engineering"], ["AI4SE", "AI for Software Engineering"], ["SE4AI", "Software Engineering for AI"],
  ["Green & Sustainable SE"], ["Model Merging"], ["OOD Generalization", "Out-of-Distribution Generalization"], ["Benchmark"], ["Tool"], ["Scientometrics"],
].map(([tag, label]) => ({ tag, label: label || "" }));
const UMBRELLA_TAGS = ["AI4SE", "SE4AI"];
const TAG_VOCAB = (() => {
  const v = Array.isArray(settings.tagVocabulary) ? settings.tagVocabulary : [];
  const list = v.map((t) => (typeof t === "string" ? { tag: stripEmoji(t), label: "" } : { tag: stripEmoji(t?.tag), label: stripEmoji(t?.label) }))
    .filter((t) => t.tag);
  const out = list.length ? list : DEFAULT_TAGS;
  // The two umbrella areas always have their long names (needed for abbr titles and knowsAbout).
  for (const d of DEFAULT_TAGS.filter((x) => UMBRELLA_TAGS.includes(x.tag))) {
    const hit = out.find((t) => t.tag.toLowerCase() === d.tag.toLowerCase());
    if (hit && !hit.label) hit.label = d.label;
  }
  return out;
})();
const vocabFor = (tag) => TAG_VOCAB.find((t) => t.tag.toLowerCase() === str(tag).toLowerCase());
const tagLabel = (tag) => { const v = vocabFor(tag); return v && v.label && v.label !== v.tag ? v.label : ""; };
// "AI for Software Engineering (AI4SE)" for abbreviations, the tag itself otherwise.
const tagLong = (tag) => (tagLabel(tag) ? `${tagLabel(tag)} (${vocabFor(tag).tag})` : vocabFor(tag)?.tag || str(tag));
const tagId = (tag) => `tag-${slugify(tag)}`;
// AI/ML topics (SPEC-round3 §21): second facet, settings.mlTopicVocabulary ({tag, label}).
const ML_VOCAB = (Array.isArray(settings.mlTopicVocabulary) ? settings.mlTopicVocabulary : [])
  .map((t) => (typeof t === "string" ? { tag: stripEmoji(t), label: "" } : { tag: stripEmoji(t?.tag), label: stripEmoji(t?.label) })).filter((t) => t.tag);
const mlVocabFor = (t) => ML_VOCAB.find((v) => v.tag.toLowerCase() === str(t).toLowerCase());
const mlLabel = (t) => { const v = mlVocabFor(t); return v && v.label && v.label !== v.tag ? v.label : ""; };
const mlLong = (t) => mlLabel(t) || mlVocabFor(t)?.tag || str(t);
const mlId = (t) => `ml-${slugify(t)}`;
// Rows of the homepage Research section; the same broad areas head llms.txt and knowsAbout.
const strList = (v) => (Array.isArray(v) ? v : str(v).split(/[,;\n]/)).map((x) => stripEmoji(String(x ?? ""))).filter(Boolean);
const AREA_TAGS = (() => { const v = strList(settings.areaTags); return v.length ? v : ["Software Maintenance & Evolution", "Program Comprehension", "Code Clones", "Refactoring", "Empirical SE", "AI4SE", "SE4AI", "Green & Sustainable SE"]; })();
const ML_AREA_TAGS = (() => { const v = strList(settings.mlAreaTags); return v.length ? v : ["Model Merging", "Model Compression", "Out-of-Distribution Generalization", "Code Language Models", "LLM-Generated Code", "Efficient & Green LLMs"]; })();

// Expected PhD completion (about.education PhD row `expected`, e.g. "Aug 2028").
const MONTHS_LONG = ["January", "February", "March", "April", "May", "June", "July", "August", "September", "October", "November", "December"];
const EDU = (about.education || []).filter((e) => e && e.degree);
const PHD_ROW = EDU.find((e) => /ph\.?\s*d/i.test(str(e.degree)));
const EXPECTED = (() => {
  const raw = stripEmoji(PHD_ROW?.expected || about.expected || hero.expected);
  const iso = raw.match(/^(\d{4})-(\d{2})/);
  if (iso) return ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"][+iso[2] - 1] + " " + iso[1];
  return raw;
})();
// "Aug 2028" -> "August 2028" for prose.
const EXPECTED_LONG = EXPECTED.replace(/^([A-Za-z]{3})[a-z]*\.?(?=\s)/, (m, a) => MONTHS_LONG.find((x) => x.startsWith(a)) || m);

// Documents (CV, statements). Only entries with a URL are shown; settings.resumeUrl still
// works as the CV when documents.json has no CV.
const DOC_LABELS = { cv: "CV", resume: "Résumé", research_statement: "Research Statement", teaching_statement: "Teaching Statement", diversity_statement: "Diversity Statement" };
const documents = items(readJson("documents", { items: [] }))
  .filter((d) => d && safeUrl(d.url))
  .map((d) => ({ kind: str(d.kind) || "other", url: safeUrl(d.url), label: stripEmoji(d.label) || DOC_LABELS[str(d.kind)] || "Document" }));
if (safeUrl(settings.resumeUrl) && !documents.some((d) => d.kind === "cv")) documents.unshift({ kind: "cv", url: safeUrl(settings.resumeUrl), label: "CV" });
const cvDoc = documents.find((d) => d.kind === "cv") || documents.find((d) => d.kind === "resume");

// Navigation labels come from the same headings.json the React site uses.
const hnav = (k, d) => stripEmoji(headings?.[k]?.nav) || d;
// Page titles and intro lines for /faq/, /photos/ and /about/ (admin: Sections & Headings).
const htitle = (k, d) => stripEmoji(headings?.[k]?.title) || d;
const hsub = (k) => stripEmoji(headings?.[k]?.subtitle);
const BLOG_SHOWN = !(headings?.blog?.hidden === true || settings.blogInNav === false);
const NAME_LINE = ((l) => (EXPECTED && !/expected/i.test(l) ? `${l} · expected ${EXPECTED}` : l))(
  (/university/i.test(stripEmoji(hero.line2)) && stripEmoji(hero.line2)) || "PhD Researcher in Computer Science · University of Saskatchewan");
const asSlugs = (v) => (Array.isArray(v) ? v : str(v).split(/[,;\n]/)).map((x) => slugify(x)).filter(Boolean);
const THEMES = Array.isArray(research?.themes) && research.themes.some((t) => t && (t.title || t.label))
  ? research.themes
    .map((t) => ({ id: slugify(t.id || t.title || t.label), name: stripEmoji(t.title || t.label), papers: asSlugs(t.paper_slugs && asSlugs(t.paper_slugs).length ? t.paper_slugs : t.papers),
      summary: stripEmoji(t.summary || t.description), keyResult: stripEmoji(t.key_result), keywords: (Array.isArray(t.keywords) ? t.keywords : str(t.keywords).split(/[,;\n]/)).map(stripEmoji).filter(Boolean) }))
    .filter((t) => t.id && t.name)
    // research.also (slugs; shown on the homepage as the "Also" line) becomes a last theme,
    // so those papers are not left out of /publications/ "By theme" and llms.txt.
    .concat(Array.isArray(research?.also) && research.also.length ? [{ id: "other-work", name: "Other work", papers: asSlugs(research.also) }] : [])
  : FALLBACK_THEMES;

function parseAuthors(p) {
  if (Array.isArray(p.authors_full) && p.authors_full.length) return p.authors_full.map(str).filter(Boolean);
  return str(p.authors).split(/\s*,\s*|\s+and\s+/).map((s) => s.trim()).filter((s) => s && !/^et\.? al\.?$/i.test(s));
}

function normalize(p, i) {
  const doi = normDoi(p.doi);
  const preprint = str(p.preprint);
  let arxiv = str(p.arxiv).replace(/^arxiv:/i, "");
  const am = preprint.match(/arxiv\.org\/(?:abs|pdf)\/(\d{4}\.\d{4,5})/i);
  if (!arxiv && am) arxiv = am[1];
  let pdf = str(p.pdf) || (preprint.startsWith("/") && /\.pdf$/i.test(preprint) ? preprint : "");
  const pdfExists = pdf && (CHECK_ONLY || existsSync(path.join(DIST, decodeURI(pdf))));
  if (pdf && !pdfExists) { console.warn(`  [seo] warn: ${pdf} not found in dist; PDF link omitted`); pdf = ""; }
  const pages = str(p.pages).replace(/^pp?\.\s*/i, "");
  const [fp, lp] = pages.split(/\s*[-–]+\s*/);
  const authors = parseAuthors(p);
  const vt = `${str(p.venue)} ${str(p.track)}`;
  const type = str(p.type).toLowerCase().replace(/[\s-]+/g, "_") || (/thesis|dissertation/i.test(vt) ? "thesis" : /doctoral symposium/i.test(vt) ? "doctoral_symposium" : /journal|proceedings of the acm on|transactions/i.test(p.venue) ? "journal" : /poster/i.test(p.venue) ? "poster" : /workshop/i.test(p.venue) ? "workshop" : "conference");
  const title = str(p.title);
  // Thesis: the repository handle is its persistent identifier (in place of a DOI).
  const hRaw = str(p.handle).replace(/^hdl:\s*/i, "");
  const handle = /^https?:\/\//i.test(hRaw) ? hRaw.replace(/^http:/i, "https:") : hRaw ? `https://hdl.handle.net/${hRaw}` : "";
  const degreeText = [p.dissertation_name, p.in_support_of, p.degree, p.venue].map(str).join(" ");
  const level = /m\.?\s?sc|master/i.test(degreeText) ? "msc" : /ph\.?\s?d|doctor/i.test(degreeText) ? "phd" : "";
  const eduDegree = level && EDU.map((e) => stripEmoji(e.degree)).find((d) => (level === "msc" ? /m\.?\s?sc|master/i : /ph\.?\s?d|doctor/i).test(d));
  const institution = str(p.institution) || str(p.publisher)
    || (str(p.venue).match(/University of [A-Z][A-Za-z]+(?: [A-Z][A-Za-z]+)*|[A-Z][A-Za-z]+(?: [A-Z][A-Za-z]+)* University/) || [""])[0] || str(p.venue);
  // A placeholder ("", " ", "-", "none", "#") is not a link: Slides/Video show only for real URLs.
  const media = (u) => (/^\s*(-+|#|none|null|n\/?a|tbd|todo)?\s*$/i.test(str(u)) ? "" : safeUrl(u));
  const tags = [...new Set((Array.isArray(p.tags) ? p.tags : str(p.tags).split(/[,;\n]/)).map((t) => stripEmoji(String(t ?? ""))).filter(Boolean)
    .map((t) => vocabFor(t)?.tag || t))];
  const ml = [...new Set((Array.isArray(p.ml_topics) ? p.ml_topics : str(p.ml_topics).split(/[;\n]/)).map((t) => stripEmoji(String(t ?? ""))).filter(Boolean)
    .map((t) => mlVocabFor(t)?.tag || t))];
  const equal = (Array.isArray(p.equal_contribution) ? p.equal_contribution : str(p.equal_contribution).split(/[,;\n]/)).map(str).filter(Boolean);
  const slug = str(p.slug) || slugify(title);
  const haystack = [title, ...(p.keywords || [])].join(" ");
  const themes = THEMES.filter((t) => (t.papers ? t.papers.includes(slug) : t.re.test(title))).map((t) => t.id);
  if (!themes.length) THEMES.filter((t) => t.re && t.re.test(haystack)).forEach((t) => themes.push(t.id));
  return {
    raw: p, index: i, title, slug, type, doi, arxiv, pdf,
    year: str(p.year), date: str(p.published_date) || str(p.year),
    venue: str(p.venue), venueFull: str(p.venue_full) || str(p.venue), venueShort: str(p.venue_short) || [str(p.venue).match(/\(([A-Z][A-Za-z-]+)\)/)?.[1], str(p.year)].filter(Boolean).join(" ") || str(p.venue),
    publisher: str(p.publisher), volume: str(p.volume), issue: str(p.issue), issn: str(p.issn),
    firstPage: str(p.first_page) || (fp && /^\d+$/.test(fp) ? fp : ""), lastPage: str(p.last_page) || (lp && /^\d+$/.test(lp) ? lp : ""),
    pages, pagesText: pages.replace(/\s*[-–]+\s*/, "–"), authors, award: stripEmoji(p.award), abstract: str(p.abstract), description: stripEmoji(p.description),
    problem: str(p.problem), relevant: (p.relevant_to || []).map(str).filter(Boolean), keywords: (p.keywords || []).map(str).filter(Boolean),
    code: safeUrl(p.code_url), tool: safeUrl(p.tool_url), toolName: stripEmoji(p.tool_name), artifact: safeUrl(p.artifact_url), dataset: safeUrl(p.dataset_url),
    video: media(p.video), slides: media(p.slides_url), handle, institution,
    dissertationName: stripEmoji(p.dissertation_name) || (level === "msc" ? "M.Sc. thesis" : level === "phd" ? "Ph.D. thesis" : "Thesis"),
    inSupportOf: stripEmoji(p.in_support_of) || stripEmoji(p.degree) || eduDegree || "",
    tags, ml, equal, bibtex: str(p.bibtex), track: str(p.track), themes,
    myRole: stripEmoji(p.my_role), venueRank: stripEmoji(p.venue_rank),
    displayKeywords: (Array.isArray(p.display_keywords) ? p.display_keywords : str(p.display_keywords).split(/[,;\n]/)).map((t) => stripEmoji(String(t ?? ""))).filter(Boolean),
    url: `${SITE}/publications/${str(p.slug) || slugify(title)}/`,
  };
}

// ---------------------------------------------------------------- validation
const KNOWN_TYPES = ["conference", "journal", "workshop", "poster", "doctoral_symposium", "thesis"];
const TYPE_LABEL = { doctoral_symposium: "Doctoral Symposium", thesis: "Thesis", poster: "Poster", workshop: "Workshop", journal: "Journal", conference: "Conference" };
// Same person in two spellings: "Ajmain Inqiad Alam" / "A. I. Alam" / "Alam, A. I.".
const nameParts = (n) => {
  let s = str(n).toLowerCase().replace(/[.]/g, " ").replace(/\s+/g, " ").trim();
  if (s.includes(",")) { const [l, f] = s.split(",").map((x) => x.trim()); s = `${f} ${l}`; }
  const w = s.split(" ").filter(Boolean);
  return { last: w[w.length - 1] || "", first: (w[0] || "")[0] || "", full: w.join(" ") };
};
function sameAuthor(a, b) {
  if (isOwner(a) && isOwner(b)) return true;
  const x = nameParts(a), y = nameParts(b);
  return x.full === y.full || (x.last === y.last && x.first === y.first);
}
const isEqual = (p, a) => p.equal.length > 1 && p.equal.some((n) => sameAuthor(a, n));
const errors = [];
const pubs = pubsRaw.map(normalize);
const seen = new Map();
for (const p of pubs) {
  const label = `publication #${p.index + 1} "${clip(p.title, 60) || "(untitled)"}"`;
  if (!p.title) errors.push(`${label}: missing title`);
  if (!/^\d{4}$/.test(p.year)) errors.push(`${label}: missing or invalid year`);
  if (!p.venue) errors.push(`${label}: missing venue`);
  if (!p.doi && !p.arxiv && !(p.type === "thesis" && p.handle)) errors.push(`${label}: needs a DOI or an arXiv id${p.type === "thesis" ? " (or, for a thesis, a handle)" : ""}`);
  if (p.handle && !/^https:\/\/hdl\.handle\.net\/\d+(\.\d+)*\/\S+$/.test(p.handle)) errors.push(`${label}: handle "${str(p.raw.handle)}" is not a handle (expected https://hdl.handle.net/<prefix>/<id>)`);
  if (!KNOWN_TYPES.includes(p.type)) console.warn(`  [seo] warn: ${label} has unknown type "${p.type}" (known: ${KNOWN_TYPES.join(", ")})`);
  for (const t of p.tags) if (!vocabFor(t)) console.warn(`  [seo] warn: ${label} tag "${t}" is not in settings.tagVocabulary`);
  for (const t of p.ml) if (ML_VOCAB.length && !mlVocabFor(t)) console.warn(`  [seo] warn: ${label} ML topic "${t}" is not in settings.mlTopicVocabulary`);
  for (const n of p.equal) if (!p.authors.some((a) => sameAuthor(a, n))) console.warn(`  [seo] warn: ${label} equal_contribution name "${n}" is not one of its authors`);
  if (p.doi && !/^10\.\d{4,9}\/\S+$/.test(p.doi)) errors.push(`${label}: DOI "${p.doi}" is not a bare DOI (expected 10.xxxx/...)`);
  if (p.arxiv && !/^\d{4}\.\d{4,5}(v\d+)?$/.test(p.arxiv)) errors.push(`${label}: arXiv id "${p.arxiv}" is malformed`);
  if (!/^[a-z0-9]+(-[a-z0-9]+)*$/.test(p.slug)) errors.push(`${label}: slug "${p.slug}" must be lowercase kebab-case`);
  if (seen.has(p.slug)) errors.push(`${label}: duplicate slug "${p.slug}" (also used by #${seen.get(p.slug) + 1})`);
  seen.set(p.slug, p.index);
  if (!p.authors.length) errors.push(`${label}: no authors`);
  if (!Array.isArray(p.raw.authors_full) || !p.raw.authors_full.length) console.warn(`  [seo] warn: ${label} has no authors_full; using the abbreviated author string`);
  if (!p.abstract) console.warn(`  [seo] warn: ${label} has no abstract (Google Scholar needs a visible abstract)`);
}
const slugs = new Set(pubs.map((p) => p.slug));
for (const f of faqItems) {
  for (const m of str(f.a).matchAll(/\/publications\/([a-z0-9-]+)\//g)) {
    if (!slugs.has(m[1])) console.warn(`  [seo] warn: FAQ "${clip(f.q, 50)}" links to unknown paper /publications/${m[1]}/`);
  }
}
if (errors.length) {
  console.error("\n  SEO content validation failed:\n" + errors.map((e) => "   - " + e).join("\n") + "\n");
  console.error("  Fix these in the admin (Publications) or src/content/publications.json.");
  process.exit(1);
}
if (CHECK_ONLY) {
  console.log(`  [seo] content OK: ${pubs.length} publications, ${faqItems.length} FAQ items`);
  process.exit(0);
}
if (!existsSync(path.join(DIST, "index.html"))) {
  console.error(`  [seo] ${DIST}/index.html not found; run vite build first`);
  process.exit(1);
}

pubs.sort((a, b) => b.date.localeCompare(a.date) || a.index - b.index);
// lastmod: an explicit `updated` date on a paper, else its publication date. The homepage and the
// list pages take the newest date of what they show. Pages without a known date get no lastmod.
const paperLastmod = (p) => newest(p.raw.updated, p.date);
const PUBS_LASTMOD = newest(pubs.map(paperLastmod));
const blogLastmod = (b) => newest(b.updated, b.date);
// Dated news/awards/roles (YYYY-MM-DD `date`, SPEC-round3 §13) also change the homepage.
const HOME_LASTMOD = newest(PUBS_LASTMOD, metricsJson.updated, blogPosts.map(blogLastmod),
  [...newsItems, ...highlights, ...serviceItems, ...leadership].map((x) => x && x.date));
const softwareItems = softwareRaw.filter((s) => {
  const ok = slugs.has(slugify(s.paper_slug));
  if (!ok) console.warn(`  [seo] warn: Tools & Benchmarks item "${s.name}" names paper_slug "${s.paper_slug}", which is not a publication; left out`);
  return ok;
});

// ---------------------------------------------------------------- images
// Pixel size from the file header (JPEG incl. EXIF rotation, PNG, GIF, WebP), so pages can
// carry width/height (no layout shift) and JSON-LD can state the real dimensions.
function imageSize(buf) {
  if (!buf || buf.length < 30) return null;
  if (buf.readUInt32BE(0) === 0x89504e47) return { w: buf.readUInt32BE(16), h: buf.readUInt32BE(20) };
  if (buf.toString("ascii", 0, 3) === "GIF") return { w: buf.readUInt16LE(6), h: buf.readUInt16LE(8) };
  if (buf.toString("ascii", 0, 4) === "RIFF" && buf.toString("ascii", 8, 12) === "WEBP") {
    const t = buf.toString("ascii", 12, 16);
    if (t === "VP8 ") return { w: buf.readUInt16LE(26) & 0x3fff, h: buf.readUInt16LE(28) & 0x3fff };
    if (t === "VP8L") { const b = buf.readUInt32LE(21); return { w: (b & 0x3fff) + 1, h: ((b >>> 14) & 0x3fff) + 1 }; }
    if (t === "VP8X") return { w: 1 + buf.readUIntLE(24, 3), h: 1 + buf.readUIntLE(27, 3) };
    return null;
  }
  if (buf[0] !== 0xff || buf[1] !== 0xd8) return null;
  let i = 2, rotated = false;
  while (i + 9 < buf.length) {
    if (buf[i] !== 0xff) { i++; continue; }
    const m = buf[i + 1];
    if (m === 0xff) { i++; continue; }
    if (m === 0xd8 || m === 0x01 || (m >= 0xd0 && m <= 0xd7)) { i += 2; continue; }
    const len = buf.readUInt16BE(i + 2);
    if (m === 0xe1 && buf.toString("ascii", i + 4, i + 10) === "Exif\0\0") {
      try { // EXIF orientation 5-8 means the browser shows the image rotated by 90 degrees.
        const t = i + 10, le = buf.toString("ascii", t, t + 2) === "II";
        const u16 = (o) => (le ? buf.readUInt16LE(o) : buf.readUInt16BE(o));
        const u32 = (o) => (le ? buf.readUInt32LE(o) : buf.readUInt32BE(o));
        const ifd = t + u32(t + 4);
        for (let k = 0, n = u16(ifd); k < n; k++) {
          const e = ifd + 2 + k * 12;
          if (u16(e) === 0x0112) { rotated = u16(e + 8) >= 5 && u16(e + 8) <= 8; break; }
        }
      } catch { /* malformed EXIF: ignore */ }
    }
    if (m >= 0xc0 && m <= 0xcf && m !== 0xc4 && m !== 0xc8 && m !== 0xcc) {
      const h = buf.readUInt16BE(i + 5), w = buf.readUInt16BE(i + 7);
      return rotated ? { w: h, h: w } : { w, h };
    }
    i += 2 + len;
  }
  return null;
}
const safeDecode = (u) => { try { return decodeURI(u); } catch { return u; } };
// Site path ("/images/a b.jpg") -> encoded URL path, so file names with spaces/accents are valid in HTML and XML.
const encPath = (u) => (/^https?:\/\//i.test(u) ? u : encodeURI(safeDecode(u)));
const sizeCache = new Map();
function localImageSize(u) {
  u = str(u);
  if (!u.startsWith("/") || u.startsWith("//")) return null;
  if (sizeCache.has(u)) return sizeCache.get(u);
  const rel = safeDecode(u.split(/[?#]/)[0]);
  let size = null;
  for (const base of [DIST, path.join(ROOT, "public")]) {
    const f = path.join(base, rel);
    if (!f.startsWith(base) || !existsSync(f)) continue;
    try { size = imageSize(readFileSync(f)); } catch { size = null; }
    if (size) break;
  }
  sizeCache.set(u, size);
  return size;
}
// Photos of the owner for Person.image: the hero photo set in the admin, then the bundled portraits.
const HEADSHOTS = [...new Set([safeUrl(hero.photo), safeUrl(about.photo), "/images/palash_roy_headshot.jpg", "/images/palash_roy.jpg", "/images/palash_ranjan_roy.jpg"].filter(Boolean))]
  .map((u) => ({ u, size: localImageSize(u) }))
  .filter((h) => h.size || /^https?:\/\//.test(h.u));
const imageObject = (u, size, extra = {}) => ({ "@type": "ImageObject", contentUrl: abs(encPath(u)), url: abs(encPath(u)), ...(size ? { width: size.w, height: size.h } : {}), ...extra });
// A portrait's photo credit comes from the gallery entry for the same file (admin: Gallery → photo
// credit), so Google's image-licence fields are filled only with what the owner entered.
const galleryCredit = (u) => { const g = galleryItems.find((x) => safeDecode(str(x.image)) === safeDecode(u)); return g ? stripEmoji(g.creditText || g.credit) : ""; };
const PERSON_IMAGES = HEADSHOTS.map((h) => imageObject(h.u, h.size, { caption: NAME, ...(galleryCredit(h.u) ? { creditText: galleryCredit(h.u) } : {}) }));

// ---------------------------------------------------------------- identity graph
function isHiddenSocial(s) { return s.hidden === true || /^(true|yes|1)$/i.test(str(s.hidden)); }
// X/Twitter handle from the (non-hidden) socials.json entry, for twitter:creator.
function xHandle() {
  const x = socials.find((s) => s && !isHiddenSocial(s) && (/^(x|twitter)$/i.test(str(s.platform)) || /\/\/(www\.)?(twitter|x)\.com\//i.test(str(s.url))));
  const m = x && str(x.url).match(/\/\/(?:www\.)?(?:twitter|x)\.com\/([A-Za-z0-9_]{1,15})(?:[/?#]|$)/i);
  return m ? `@${m[1]}` : "";
}
function sameAsList() {
  const out = [];
  const key = (u) => u.toLowerCase().replace(/^https?:\/\/(www\.)?/, "").replace(/\/+$/, "");
  const add = (u) => {
    const url = str(u).replace(/[?&]hl=[a-z-]+$/i, "");
    if (!url || /usask\.ca|doi\.org|arxiv\.org|palashroy\.me/i.test(url)) return; // only pages about him
    if (!out.some((x) => key(x) === key(url))) out.push(url);
  };
  IDS.forEach((i) => add(i.url));
  const shown = socials.filter((s) => s && !isHiddenSocial(s) && /^https?:\/\//.test(str(s.url)));
  if (!socials.some((s) => s && /^https?:\/\//.test(str(s.url)))) EXTRA_SAMEAS.forEach(add);
  shown.forEach((s) => add(s.url));
  return out;
}
// A nomination is not an award: admin `kind: "nomination"`, or the word in its title.
function isNom(h) { return str(h?.kind).toLowerCase() === "nomination" || /nominat|nominee/i.test(stripEmoji(h?.title)); }
// "Title (2024)" + organization -> "Title, Organization (2024)", so each award names who gave it.
const awards = highlights
  .filter((h) => h && stripEmoji(h.title) && !isNom(h))
  .map((h) => {
    const t = stripEmoji(h.title), org = stripEmoji(h.organization);
    const m = t.match(/^(.*?)\s*(\((?:19|20)\d{2}[^)]*\))$/);
    // "Award (IWSC 2023)" with organization "... (IWSC 2023), ...": the venue is named once.
    const mv = !m && t.match(/^(.*?)\s*\(([^()]*?)\s*((?:19|20)\d{2})\)$/);
    const [name, year] = m ? [m[1], m[2]] : mv && mv[2] && org.toLowerCase().includes(mv[2].toLowerCase()) ? [mv[1], `(${mv[3]})`] : [t, ""];
    const orgShort = org && !name.toLowerCase().includes(org.toLowerCase()) ? org : "";
    // Every award ends in its year, "(2026)", even when the organisation already names it.
    const y = year || (/^(19|20)\d{2}$/.test(str(h.year)) ? `(${str(h.year)})` : "");
    return [name, orgShort].filter(Boolean).join(", ") + (y ? " " + y : "");
  });

// knowsAbout: the fixed topics plus the long names of the research areas used on papers
// (always both umbrella areas, AI4SE and SE4AI).
const KNOWS = (() => {
  const out = [...KNOWS_ABOUT];
  const usedTags = new Set(pubs.flatMap((p) => p.tags).map((t) => t.toLowerCase()));
  const usedMl = new Set(pubs.flatMap((p) => p.ml).map((t) => t.toLowerCase()));
  const push = (k) => { if (k && !out.some((x) => x.toLowerCase() === k.toLowerCase())) out.push(k); };
  for (const v of TAG_VOCAB) {
    if (!v.label || v.label === v.tag) continue;
    if (!UMBRELLA_TAGS.includes(v.tag) && !usedTags.has(v.tag.toLowerCase())) continue;
    push(v.label);
  }
  // The broad SE areas and the AI/ML topics of the Research section (SPEC-round3 §20-21).
  for (const t of AREA_TAGS) if (usedTags.has(t.toLowerCase())) push(tagLabel(t) || t);
  for (const t of ML_AREA_TAGS) if (usedMl.has(t.toLowerCase())) push(mlLong(t));
  return out;
})();
// "In the news" (about.json inTheNews, admin: Biography): third-party pages about him. Articles
// (kind "article") become NewsArticle items in Person.subjectOf (never sameAs: they are not his
// profiles); lab profile pages (kind "profile") are listed on /about/ only.
const IN_THE_NEWS = (Array.isArray(about.inTheNews) ? about.inTheNews : [])
  .map((n) => ({ title: stripEmoji(n?.title), url: /^https?:\/\//i.test(str(n?.url)) ? str(n.url) : "", publisher: stripEmoji(n?.publisher), date: isoDay(n?.date), kind: str(n?.kind).toLowerCase() === "profile" ? "profile" : "article" }))
  .filter((n) => n.title && n.url);
const NEWS_ARTICLES = IN_THE_NEWS.filter((n) => n.kind === "article" && n.date).sort((a, b) => b.date.localeCompare(a.date)).map((n) => ({
  "@type": "NewsArticle", headline: clip(n.title, 110), url: n.url, datePublished: n.date,
  ...(n.publisher ? { publisher: { "@type": "Organization", name: n.publisher, url: new URL(n.url).origin + "/" } } : {}),
}));
const PERSON_DESC = SUMMARY + (EXPECTED_LONG ? ` PhD expected ${EXPECTED_LONG}.` : "");
function personNode(full = true) {
  const base = { "@type": "Person", "@id": PERSON_ID, name: NAME, url: `${SITE}/` };
  if (!full) return { ...base, identifier: { "@type": "PropertyValue", propertyID: "ORCID", value: ORCID }, sameAs: [`https://orcid.org/${ORCID}`] };
  return {
    ...base,
    givenName: "Palash", additionalName: "Ranjan", familyName: "Roy",
    alternateName: ALT_NAMES,
    description: PERSON_DESC,
    jobTitle: "PhD Researcher in Computer Science",
    email: `mailto:${EMAIL}`,
    image: PERSON_IMAGES.length ? PERSON_IMAGES : [{ "@type": "ImageObject", url: IMAGE, contentUrl: IMAGE, caption: NAME }],
    affiliation: [ORGS.usask],
    memberOf: [ORGS.srlab, ORGS.ise],
    alumniOf: [ORGS.brac, { "@id": `${SITE}/#usask` }],
    ...(NEWS_ARTICLES.length ? { subjectOf: NEWS_ARTICLES } : {}),
    award: awards,
    knowsAbout: KNOWS,
    identifier: IDS.map((i) => ({ "@type": "PropertyValue", ...i })),
    sameAs: sameAsList(),
    mainEntityOfPage: { "@id": `${SITE}/#profilepage` },
  };
}
const websiteNode = {
  "@type": "WebSite", "@id": WEBSITE_ID, url: `${SITE}/`, name: NAME, inLanguage: "en",
  description: `Research website of ${NAME}: publications, software and contact.`, publisher: { "@id": PERSON_ID }, about: { "@id": PERSON_ID },
};
const homeGraph = {
  "@context": "https://schema.org",
  "@graph": [
    websiteNode,
    {
      "@type": "ProfilePage", "@id": `${SITE}/#profilepage`, url: `${SITE}/`,
      name: `${NAME} | PhD Researcher in Computer Science | University of Saskatchewan`,
      isPartOf: { "@id": WEBSITE_ID }, mainEntity: { "@id": PERSON_ID }, about: { "@id": PERSON_ID },
      ...(HOME_LASTMOD ? { dateModified: HOME_LASTMOD } : {}), inLanguage: "en",
      hasPart: pubs.map((p) => ({ "@type": p.type === "thesis" ? "Thesis" : "ScholarlyArticle", "@id": p.url + "#article", url: p.url, name: p.title })),
    },
    personNode(true),
  ],
};

function injectGraph(file, graph) {
  if (!existsSync(file)) return false;
  let html = readFileSync(file, "utf8");
  const tag = ldScript(graph);
  if (html.includes("<!--SEO-GRAPH-->")) html = html.replace("<!--SEO-GRAPH-->", tag);
  else { console.warn(`  [seo] warn: no <!--SEO-GRAPH--> placeholder in ${path.relative(DIST, file)}; inserting before </head>`); html = html.replace("</head>", tag + "\n</head>"); }
  writeFileSync(file, html);
  return true;
}
injectGraph(path.join(DIST, "index.html"), homeGraph);
// twitter:creator follows the admin-editable X entry in socials.json (removed when none).
function fixCreator(file) {
  if (!existsSync(file)) return;
  const html = readFileSync(file, "utf8"), h = xHandle();
  const out = html.replace(/[ \t]*<meta name="twitter:creator" content="[^"]*"\s*\/?>\n?/, h ? `  <meta name="twitter:creator" content="${esc(h)}" />\n` : "");
  if (out !== html) writeFileSync(file, out);
}
fixCreator(path.join(DIST, "index.html"));
// Prerendered blog pages share the homepage shell: /blog/ gets WebSite + Person; each
// published post also gets a BlogPosting node and an article head (og:type article, its
// cover as og:image, no profile:* or publications.md alternate).
const blogGraph = { "@context": "https://schema.org", "@graph": [websiteNode, { ...personNode(true), mainEntityOfPage: undefined }, breadcrumbLd([["Home", `${SITE}/`], ["Blog", `${SITE}/blog/`]])] };
const absUrl = (u) => (/^https?:\/\//i.test(str(u)) ? str(u) : /^\//.test(str(u)) ? SITE + str(u) : "");
function postGraph(b, slug) {
  const url = `${SITE}/blog/${slug}/`;
  const cover = absUrl(b.cover);
  const tags = (Array.isArray(b.tags) ? b.tags : str(b.tags).split(",")).map((t) => stripEmoji(String(t)).trim()).filter(Boolean);
  return { "@context": "https://schema.org", "@graph": [websiteNode, { ...personNode(true), mainEntityOfPage: undefined }, {
    "@type": "BlogPosting", "@id": `${url}#post`, headline: stripEmoji(b.title), url, mainEntityOfPage: url,
    ...(str(b.summary) ? { description: stripEmoji(b.summary) } : {}),
    ...(isoDay(b.date) ? { datePublished: isoDay(b.date), dateModified: isoDay(b.updated) || isoDay(b.date) } : {}),
    author: { "@id": PERSON_ID }, publisher: { "@id": PERSON_ID },
    ...(cover ? { image: cover } : {}), ...(tags.length ? { keywords: tags } : {}),
    isPartOf: { "@id": WEBSITE_ID }, inLanguage: "en",
  }, breadcrumbLd([["Home", `${SITE}/`], ["Blog", `${SITE}/blog/`], [stripEmoji(b.title), url]])] };
}
function postHead(file, b) {
  let html = readFileSync(file, "utf8");
  const cover = absUrl(b.cover);
  html = html.replace(/<meta property="og:type" content="[^"]*"\s*\/?>/, `<meta property="og:type" content="article" />`
    + (isoDay(b.date) ? `\n  <meta property="article:published_time" content="${isoDay(b.date)}" />` : "")
    + `\n  <meta property="article:author" content="${SITE}/" />`);
  html = html.replace(/[ \t]*<meta property="profile:[a-z_]+" content="[^"]*"\s*\/?>\n?/g, "");
  html = html.replace(/[ \t]*<link rel="alternate" type="text\/markdown" href="\/publications\.md"[^>]*>\n?/g, "");
  if (cover) {
    html = html.replace(/(<meta (?:property|name)="(?:og:image|twitter:image)" content=")[^"]*(")/g, `$1${esc(cover)}$2`)
      .replace(/[ \t]*<meta property="og:image:(?:width|height)" content="[^"]*"\s*\/?>\n?/g, "")
      .replace(/(<meta (?:property|name)="(?:og:image:alt|twitter:image:alt)" content=")[^"]*(")/g, `$1${esc(`Cover image: ${stripEmoji(b.title)}`)}$2`);
  }
  writeFileSync(file, html);
}
const postBySlug = new Map(blogPosts.map((b) => [str(b.slug) || slugify(b.title), b]));
(function walk(dir) {
  if (!existsSync(dir)) return;
  for (const f of readdirSync(dir)) {
    const p = path.join(dir, f);
    if (statSync(p).isDirectory()) walk(p);
    else if (f === "index.html") {
      const rel = path.relative(path.join(DIST, "blog"), path.dirname(p));
      const post = rel && !rel.includes(path.sep) ? postBySlug.get(rel) : undefined;
      injectGraph(p, post ? postGraph(post, rel) : blogGraph);
      if (post) postHead(p, post);
      fixCreator(p);
    }
  }
})(path.join(DIST, "blog"));

// ---------------------------------------------------------------- page shell
const CSS = `@font-face{font-family:"Source Serif 4";font-weight:600;font-style:normal;font-display:swap;src:url(/fonts/source-serif-4-latin-600-normal.woff2) format("woff2")}
@font-face{font-family:Inter;font-weight:400;font-style:normal;font-display:swap;src:url(/fonts/inter-latin-400-normal.woff2) format("woff2")}
@font-face{font-family:Inter;font-weight:500;font-style:normal;font-display:swap;src:url(/fonts/inter-latin-500-normal.woff2) format("woff2")}
@font-face{font-family:Inter;font-weight:600;font-style:normal;font-display:swap;src:url(/fonts/inter-latin-600-normal.woff2) format("woff2")}
:root{--bg:#FAFAF7;--surface:#FFFFFF;--chip:#F1F0EB;--text:#1A1A1A;--muted:#555B65;--border:#E4E2DC;--field-border:#8A8F98;--accent:#1F4E79;--accent-hover:#163A5C;--award:#8A5A00;color-scheme:light}
@media (prefers-color-scheme:dark){:root:not([data-theme=light]){--bg:#121417;--surface:#1A1D21;--chip:#22262B;--text:#E8E6E1;--muted:#A0A4AB;--border:#2A2E34;--field-border:#6B7380;--accent:#8DB4DC;--accent-hover:#A9C7E6;--award:#E0B867;color-scheme:dark}}
:root[data-theme=dark]{--bg:#121417;--surface:#1A1D21;--chip:#22262B;--text:#E8E6E1;--muted:#A0A4AB;--border:#2A2E34;--field-border:#6B7380;--accent:#8DB4DC;--accent-hover:#A9C7E6;--award:#E0B867;color-scheme:dark}
*{box-sizing:border-box}
html{-webkit-text-size-adjust:100%}
body{margin:0;background:var(--bg);color:var(--text);font:17px/1.6 Inter,system-ui,-apple-system,"Segoe UI",Roboto,sans-serif}
a{color:var(--accent);text-decoration:underline;text-decoration-thickness:1px;text-underline-offset:2px;transition:color .15s}
a:hover{color:var(--accent-hover)}
:focus-visible{outline:2px solid var(--accent);outline-offset:2px;border-radius:2px}
.skip{position:absolute;left:-999px;top:0;background:var(--surface);padding:8px 12px;z-index:10}
.skip:focus{left:16px;top:8px}
.wrap{max-width:960px;margin:0 auto;padding-left:20px;padding-right:20px}
@media (min-width:640px){.wrap{padding-left:32px;padding-right:32px}}
header.site{position:relative;border-bottom:1px solid var(--border);background:var(--bg)}
header.site .wrap{display:flex;flex-wrap:nowrap;align-items:center;justify-content:space-between;gap:16px;min-height:60px}
.wordmark{display:inline-flex;align-items:center;min-height:40px;font-family:"Source Serif 4",Georgia,serif;font-weight:600;font-size:19px;letter-spacing:-.005em;color:var(--text);text-decoration:none;white-space:nowrap}
.wordmark:hover{color:var(--accent)}
nav.site{display:flex;gap:24px;font-size:15px;font-weight:500}
nav.site a{color:var(--muted);text-decoration:none;display:inline-flex;align-items:center;min-height:44px;padding:0 2px}
nav.site a:hover{color:var(--text)}
.hdr-r{display:flex;align-items:center;gap:8px}
.tt{display:inline-flex;align-items:center;justify-content:center;width:44px;height:44px;padding:0;border:1px solid transparent;border-radius:4px;background:transparent;color:var(--muted);cursor:pointer;transition:color .15s,border-color .15s}
.tt:hover{color:var(--text);border-color:var(--border)}
.tt .i-sun{display:none}
:root[data-theme=dark] .tt .i-sun{display:block}:root[data-theme=dark] .tt .i-moon{display:none}
@media (prefers-color-scheme:dark){:root:not([data-theme=light]) .tt .i-sun{display:block}:root:not([data-theme=light]) .tt .i-moon{display:none}}
details.menu{display:none}
details.menu>summary{list-style:none;display:inline-flex;align-items:center;gap:6px;min-height:44px;padding:0 12px;border:1px solid var(--field-border);border-radius:4px;font-size:15px;font-weight:500;color:var(--text);cursor:pointer}
details.menu>summary:hover{border-color:var(--accent);color:var(--accent)}
details.menu>summary::-webkit-details-marker{display:none}
details.menu>nav{position:absolute;left:0;right:0;top:100%;z-index:20;background:var(--bg);border-bottom:1px solid var(--border);padding:4px 20px 12px}
details.menu>nav a{display:flex;align-items:center;min-height:44px;border-bottom:1px solid var(--border);color:var(--text);text-decoration:none;font-weight:500}
details.menu>nav a:last-child{border-bottom:0}
details.menu .grp{display:block;padding:14px 0 2px;font-size:13px;font-weight:600;color:var(--muted)}
nav.site a[aria-current],details.menu>nav a[aria-current]{color:var(--text);text-decoration:underline;text-decoration-thickness:2px;text-underline-offset:6px}
.btn{display:inline-flex;align-items:center;min-height:40px;padding:0 14px;border-radius:4px;background:var(--accent);color:var(--bg);text-decoration:none;font-size:15px;font-weight:600;white-space:nowrap;transition:background-color .15s}
.btn:hover{background:var(--accent-hover);color:var(--bg)}
@media (max-width:767px){nav.site{display:none}details.menu{display:block}}
@media (max-width:479px){.hdr-r .btn{display:none}}
@media (max-width:379px){.wordmark{font-size:17px}details.menu .menu-ic{display:none}}
@media (min-width:480px){details.menu .m-cv{display:none}}
main.wrap{padding-top:40px;padding-bottom:64px}
main>*{max-width:720px}
main{overflow-wrap:anywhere}
h1,h2{font-family:"Source Serif 4",Georgia,serif;font-weight:600;line-height:1.25}
h1{font-size:33px;margin:0 0 16px}
h2{font-size:24px;margin:48px 0 12px}
h3{font-size:17px;font-weight:600;margin:24px 0 6px;line-height:1.4}
@media (min-width:720px){h1{font-size:41px}h2{font-size:26px}}
p{margin:0 0 14px}
.crumbs{font-size:14px;color:var(--muted);margin-bottom:20px}
.crumbs a{color:var(--muted)}
.meta{color:var(--muted);margin:0 0 6px}
.authors{margin:0 0 6px}
.award{color:var(--award);font-weight:600;margin:8px 0}
.links{display:flex;flex-wrap:wrap;gap:8px;margin:20px 0 8px;padding:0;list-style:none}
.links a{display:inline-block;padding:8px 14px;border:1px solid var(--border);border-radius:6px;text-decoration:none;font-size:15px;font-weight:500;background:var(--surface)}
.links a:hover{border-color:var(--accent)}
.small{font-size:14px;color:var(--muted)}
ul.plain{padding-left:20px}
ul.plain li{margin-bottom:6px}
pre{background:var(--surface);border:1px solid var(--border);border-radius:6px;padding:14px;overflow-x:auto;font:13px/1.5 ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;white-space:pre;overflow-wrap:normal}
.entry{margin:0 0 22px}
.sr{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}
ul.tags{display:flex;flex-wrap:wrap;gap:6px;list-style:none;padding:0;margin:6px 0}
ul.tags li{font-size:13px;line-height:1.5;padding:1px 8px;background:var(--chip);border-radius:3px;color:var(--muted);cursor:default}
ul.tags abbr[title]{text-decoration:none}
.role{color:var(--muted);margin:4px 0 0}
.rank{color:var(--muted)}
.cards{display:grid;grid-template-columns:minmax(0,1fr);gap:16px;list-style:none;padding:0;margin:12px 0}
@media (min-width:720px){.cards{grid-template-columns:repeat(2,minmax(0,1fr))}}
.card{background:var(--surface);border:1px solid var(--border);border-radius:6px;padding:18px 20px;transition:border-color .15s;min-width:0}
.card:hover,.card:focus-within{border-color:var(--field-border)}
.card h3{margin:0;font-size:17px;font-weight:600;line-height:1.35}
.card p{margin:6px 0 0;font-size:15px}
.card .res{color:var(--muted);font-size:14px}
.card .pp{font-size:14px;color:var(--muted);margin-top:10px}

abbr[title]{text-decoration:underline dotted;text-underline-offset:2px;cursor:help}
.eq{margin:2px 0 6px}
.tagf{display:flex;flex-wrap:wrap;align-items:center;gap:6px;margin:4px 0 6px}
.tagf[hidden],.tagf-w[hidden]{display:none}
.tagf-w{margin:4px 0 6px}
.tagf-d summary{display:list-item;padding:11px 0;line-height:22px;cursor:pointer;font-size:15px;font-weight:500;width:max-content;max-width:100%}
.tagf-d summary .n{font-weight:400;color:var(--muted);margin-left:4px}
.tagf-d .tagf{margin:2px 0 10px}
.tagf-l{font-size:14px;color:var(--muted);margin-right:2px}
.tagf button{min-height:44px;padding:0 12px;border:1px solid var(--field-border);border-radius:4px;background:transparent;color:var(--text);font:inherit;font-size:14px;font-weight:500;cursor:pointer;transition:background-color .15s,color .15s,border-color .15s}
.tagf button:hover{border-color:var(--accent);color:var(--accent)}
.tagf button[aria-pressed=true]{background:var(--accent);border-color:var(--accent);color:var(--bg)}
.tagf button .n{font-weight:400;margin-left:5px}
.tagf button abbr{text-decoration:none}
#tagf-s:empty{display:none}
section.yr[hidden],.entry[hidden]{display:none}
.entry .t{font-weight:600}
.entry .v{font-style:italic}
dl.faq dt{font-weight:600;margin-top:28px}
dl.faq dd{margin:6px 0 0}
details.abs{margin-top:4px;font-size:15px}
details.idx{margin:40px 0 8px;border-top:1px solid var(--border);padding-top:8px}
details.idx>summary{cursor:pointer;display:list-item;padding:11px 0;font-weight:500;width:max-content;max-width:100%}
details.idx h4{font-size:15px;font-weight:600;margin:16px 0 4px}
details.abs>summary{cursor:pointer;color:var(--muted);padding:6px 0;width:max-content}
details.abs>p{margin:6px 0 0;max-width:68ch}
ul.roles{list-style:none;padding:0;margin:0}
ul.roles li{display:grid;grid-template-columns:9.5em 1fr;gap:4px 16px;padding:8px 0;border-bottom:1px solid var(--border)}
ul.roles li:last-child{border-bottom:0}
ul.roles .d{color:var(--muted);font-size:15px;font-variant-numeric:tabular-nums}
ul.roles .small{display:inline-block;margin-top:2px;line-height:1.5}
@media (max-width:519px){ul.roles li{grid-template-columns:1fr;gap:0}}
.gallery{columns:3 260px;column-gap:20px;margin-top:28px}
.gallery figure{break-inside:avoid;margin:0 0 24px}
.gallery a.ph{display:block;border-radius:6px;overflow:hidden;background:var(--surface);border:1px solid var(--border)}
.gallery img{display:block;width:100%;height:auto}
.gallery img.noar{aspect-ratio:4/3;object-fit:cover}
.gallery figcaption{margin-top:8px;font-size:15px;line-height:1.5}
.gallery figcaption strong{display:block;font-weight:600}
.gallery figcaption .small{display:block;margin-top:2px}
.post{margin:0 0 28px;padding-bottom:24px;border-bottom:1px solid var(--border)}
.post:last-child{border-bottom:0}
.post h2{font-family:Inter,system-ui,sans-serif;font-size:20px;margin:4px 0}
.empty{padding:28px 20px;border:1px dashed var(--border);border-radius:6px;color:var(--muted)}
footer.site{border-top:1px solid var(--border);margin-top:16px;padding:40px 0;font-size:15px;color:var(--muted)}
footer.site a{color:var(--accent);text-decoration:none;display:inline-flex;align-items:center;gap:6px;min-height:44px}
footer.site a:hover{color:var(--accent-hover);text-decoration:underline;text-underline-offset:3px}
footer.site .row{display:flex;flex-wrap:wrap;align-items:center;gap:0 20px}
footer.site nav.pages{margin-top:12px;padding-top:12px;border-top:1px solid var(--border)}
footer.site .onhome{gap:0 16px;font-size:13px}
footer.site .copy{margin-top:16px;font-size:13px}
footer.site .f-name{font-family:"Source Serif 4",Georgia,serif;font-weight:600;font-size:17px;color:var(--text);margin-right:4px}
footer.site ul.prof{gap:0 16px;margin:0}
footer.site ul.prof a{color:var(--muted);gap:8px}
footer.site ul.prof a:hover{color:var(--accent);text-decoration:none}
footer.site ul.prof a:hover span{text-decoration:underline;text-underline-offset:3px}
ul.docs{display:flex;flex-wrap:wrap;gap:8px;margin:10px 0 6px;padding:0;list-style:none}
ul.docs a,footer.site ul.docs a{padding:0 12px;border:1px solid var(--border);border-radius:4px;background:var(--surface);color:var(--text);text-decoration:none;font-weight:500}
ul.docs a:hover,footer.site ul.docs a:hover{border-color:var(--accent);color:var(--accent)}
ul.prof{display:flex;flex-wrap:wrap;gap:0 18px;margin:4px 0 0;padding:0;list-style:none}
.ic{flex:none}
.nw{white-space:nowrap}
.tp{position:fixed;right:16px;bottom:16px;z-index:30;display:flex;align-items:center;gap:12px;max-width:calc(100% - 32px);padding:10px 8px 10px 16px;background:var(--surface);color:var(--text);border:1px solid var(--field-border);border-radius:6px;font-size:15px}
.tp-m{display:flex;flex-wrap:nowrap;align-items:center;gap:12px;min-width:0}
#tp-l{font-weight:600}
.tp-b{display:flex;flex-wrap:wrap;gap:6px}
.tp button{min-height:44px;min-width:44px;padding:0 14px;border:1px solid var(--field-border);border-radius:4px;background:transparent;color:var(--text);font:inherit;font-size:15px;font-weight:500;cursor:pointer;transition:background-color .15s,color .15s,border-color .15s}
.tp button:hover{border-color:var(--accent);color:var(--accent)}
.tp button[aria-pressed=true]{background:var(--accent);color:var(--bg);border-color:var(--accent)}
.tp .tp-x{display:inline-flex;align-items:center;justify-content:center;padding:0;border-color:transparent;background:transparent;color:var(--muted)}
@media (max-width:639px){.tp{left:0;right:0;bottom:0;max-width:none;border-radius:0;border-width:1px 0 0;padding:8px 8px 8px 16px;gap:8px}#tp-l{font-size:14px}.tp-m{flex:1 1 auto;gap:8px}.tp-b{flex:1 1 auto;flex-wrap:nowrap;gap:4px}.tp-b button{flex:1 1 auto;padding:0 8px;font-size:14px;white-space:nowrap}.tp .tp-x{margin-left:auto}}
@media print{header.site nav,header.site .hdr-r,.links,.skip,.tp,.tagf,ul.docs,ul.prof{display:none}body{background:#fff;color:#000}a{color:#000}main a[href^="http"]::after{content:" (" attr(href) ")";font-size:12px}}
@media (prefers-reduced-motion:reduce){*{transition:none!important}}`;
const THEME_JS = `(function(){try{var t=localStorage.getItem("theme");if(t==="light"||t==="dark")document.documentElement.dataset.theme=t;}catch(e){}})();`;
// Theme toggle: same storage key ("theme") and data-theme attribute as the React site.
const TOGGLE_JS = `(function(){var b=document.getElementById("tt");if(!b)return;var r=document.documentElement;function dark(){return r.dataset.theme?r.dataset.theme==="dark":!!(window.matchMedia&&matchMedia("(prefers-color-scheme: dark)").matches)}function lab(){var l=dark()?"Switch to light theme":"Switch to dark theme";b.setAttribute("aria-label",l);b.title=l}lab();b.addEventListener("themechange",lab);b.addEventListener("click",function(){var n=dark()?"light":"dark";r.dataset.theme=n;try{localStorage.setItem("theme",n)}catch(e){}lab();var p=document.getElementById("tp");if(p){p.remove();r.style.scrollPaddingBottom=""}})})();`;
// First-visit display prompt (SPEC-round2 §8): non-blocking, shown ~1.5s after load only when no
// theme was ever stored; any choice or closing stores "light", "dark" or "system" so it never returns.
// Not shown to automated browsers (prerender) and hidden in print. The admin turns it off with
// settings.themePrompt = false.
const PROMPT_JS = `(function(){try{if(localStorage.getItem("theme")!==null)return}catch(e){return}if(navigator.webdriver)return;setTimeout(function(){var r=document.documentElement,d=document.createElement("div");d.className="tp";d.id="tp";d.setAttribute("role","dialog");d.setAttribute("aria-modal","false");d.setAttribute("aria-labelledby","tp-l");d.innerHTML='<div class="tp-m"><span id="tp-l">Display</span><div class="tp-b" role="group" aria-labelledby="tp-l"><button type="button" data-v="light">Light</button><button type="button" data-v="dark">Dark</button><button type="button" data-v="system">Match system</button></div></div><button type="button" class="tp-x" aria-label="Close display options"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg></button>';function store(v){try{localStorage.setItem("theme",v)}catch(e){}}function close(){var t=document.getElementById("tt");if(t&&d.contains(document.activeElement))t.focus();d.remove();r.style.scrollPaddingBottom="";document.removeEventListener("keydown",key)}function key(e){if(e.key!=="Escape"||e.defaultPrevented||document.querySelector('[aria-modal="true"]')||document.querySelector("details.menu[open]"))return;store(r.dataset.theme==="light"||r.dataset.theme==="dark"?r.dataset.theme:"system");close()}var cur=r.dataset.theme||"system";d.querySelectorAll("[data-v]").forEach(function(b){b.setAttribute("aria-pressed",String(b.getAttribute("data-v")===cur));b.addEventListener("click",function(){var v=b.getAttribute("data-v");if(v==="system")delete r.dataset.theme;else r.dataset.theme=v;store(v);close();var t=document.getElementById("tt");if(t)t.dispatchEvent(new Event("themechange"))})});d.querySelector(".tp-x").addEventListener("click",function(){store("system");close()});document.addEventListener("keydown",key);var sk=document.querySelector(".skip");if(sk&&sk.parentNode===document.body)document.body.insertBefore(d,sk.nextSibling);else document.body.insertBefore(d,document.body.firstChild);r.style.scrollPaddingBottom=(d.offsetHeight+16)+"px"},1500)})();`;
const SHOW_PROMPT = settings.themePrompt !== false && !/^(true|yes|1)$/i.test(str(settings.themePromptOff));

// Navigation: the same five items and order as the React site (IA-decision.md). On these
// pages Publications points at /publications/; the homepage-only sections are listed under
// "On the homepage" in the mobile menu and the footer.
const hn = (keys, d, ignore) => { for (const k of keys) { const v = hnav(k, ""); if (v && !(ignore && ignore.test(v))) return v; } return d; };
const NAV_ITEMS = [
  ["research", hn(["research", "about"], "Research", /^(about|home)$/i), "/#research"],
  ["publications", hn(["publications", "portfolio"], "Publications", /^portfolio$/i), "/publications/"],
  ["experience", hn(["experience"], "Experience"), "/#experience"],
  ...(BLOG_SHOWN ? [["blog", hn(["blog"], "Blog"), "/blog/"]] : []),
  ["contact", hn(["contact"], "Contact", /^collaborate$/i), "/#contact"],
];
// Shown only when the homepage has that section (same conditions as the React site, roughly).
// Tools & Benchmarks lists only software.json items whose paper exists (SPEC-round3 §8); an older
// "Software ..." nav label from the database is replaced by the new name.
const TOOLS_LABEL = hn(["software"], "Tools & Benchmarks", /^software\b/i);
const ON_HOME = [
  ...(softwareItems.length ? [["Software", TOOLS_LABEL, "/#software"]] : []),
  ...(highlights.some((h) => h && stripEmoji(h.title) && !isNom(h)) ? [["Awards", hn(["awards", "highlights"], "Awards"), "/#awards"]] : []),
  ...(items(readJson("news", { items: [] })).length ? [["News", hn(["news"], "News"), "/#news"]] : []),
];
// The footer lists every homepage section (same 7 as the React footer); the mobile menu adds
// only the three that are not in the main navigation.
const FOOTER_ON_HOME = [
  ["research", NAV_ITEMS[0][1], "/#research"],
  ["publications", NAV_ITEMS[1][1], "/#publications"],
  ...ON_HOME.filter(([k]) => k === "Software"),
  ["experience", hn(["experience"], "Experience"), "/#experience"],
  ...ON_HOME.filter(([k]) => k !== "Software"),
  ["contact", hn(["contact"], "Contact", /^collaborate$/i), "/#contact"],
];
const navLinks = (active) => NAV_ITEMS.map(([id, l, u]) => `<a href="${u}"${id === active ? ` aria-current="page"` : ""}>${esc(l)}</a>`).join("");
const MENU_ICON = `<svg class="menu-ic" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/></svg>`;
const SUN = `<svg class="i-sun" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2.5v2M12 19.5v2M4.6 4.6l1.4 1.4M18 18l1.4 1.4M2.5 12h2M19.5 12h2M4.6 19.4 6 18M18 6l1.4-1.4"/></svg>`;
const MOON = `<svg class="i-moon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round" aria-hidden="true"><path d="M20 14.5A8 8 0 0 1 9.5 4a8 8 0 1 0 10.5 10.5Z"/></svg>`;

// Monochrome profile logos (inline SVG, inherit the text colour; no brand colours, no icon CDN).
// Same glyphs, labels and order as ProfileIcon / PLATFORM_ORDER in src/App.tsx.
const ICON_PATHS = {
  linkedin: "M0 1.146C0 .513.526 0 1.175 0h13.65C15.474 0 16 .513 16 1.146v13.708c0 .633-.526 1.146-1.175 1.146H1.175C.526 16 0 15.487 0 14.854V1.146zm4.943 12.248V6.169H2.542v7.225h2.401zm-1.2-8.212c.837 0 1.358-.554 1.358-1.248-.015-.709-.52-1.248-1.342-1.248-.822 0-1.359.54-1.359 1.248 0 .694.521 1.248 1.327 1.248h.016zm4.908 8.212V9.359c0-.216.016-.432.08-.586.173-.431.568-.878 1.232-.878.869 0 1.216.662 1.216 1.634v3.865h2.401V9.25c0-2.22-1.184-3.252-2.764-3.252-1.274 0-1.845.7-2.165 1.193v.025h-.016a5.54 5.54 0 0 1 .016-.025V6.169h-2.4c.03.678 0 7.225 0 7.225h2.4z",
  github: "M8 0C3.58 0 0 3.58 0 8c0 3.54 2.29 6.53 5.47 7.59.4.07.55-.17.55-.38 0-.19-.01-.82-.01-1.49-2.01.37-2.53-.49-2.69-.94-.09-.23-.48-.94-.82-1.130-.28-.15-.68-.52-.01-.53.63-.01 1.08.58 1.23.82.72 1.21 1.87.87 2.33.66.07-.52.28-.87.51-1.07-1.78-.2-3.64-.89-3.64-3.95 0-.87.31-1.59.82-2.15-.08-.2-.36-1.020.08-2.12 0 0 .67-.21 2.2.82.64-.18 1.32-.27 2-.27.68 0 1.36.09 2 .27 1.53-1.04 2.2-.82 2.2-.82.44 1.1.16 1.92.08 2.12.51.56.82 1.27.82 2.15 0 3.07-1.87 3.75-3.65 3.95.29.25.54.73.54 1.48 0 1.07-.01 1.93-.01 2.2 0 .21.15.46.55.38A8.012 8.012 0 0 0 16 8c0-4.42-3.58-8-8-8z",
  facebook: "M16 8.049c0-4.446-3.582-8.05-8-8.05C3.58 0-.002 3.603-.002 8.05c0 4.017 2.926 7.347 6.75 7.951v-5.625h-2.03V8.05H6.75V6.275c0-2.017 1.195-3.131 3.022-3.131.876 0 1.791.157 1.791.157v1.98h-1.009c-.993 0-1.303.621-1.303 1.258v1.51h2.218l-.354 2.326H9.25V16c3.824-.604 6.75-3.934 6.75-7.951z",
  instagram: "M8 0C5.829 0 5.556.01 4.703.048 3.85.088 3.269.222 2.76.42a3.917 3.917 0 0 0-1.417.923A3.927 3.927 0 0 0 .42 2.76C.222 3.268.087 3.85.048 4.7.01 5.555 0 5.827 0 8.001c0 2.172.01 2.444.048 3.297.04.852.174 1.433.372 1.942.205.526.478.972.923 1.417.444.445.89.719 1.416.923.51.198 1.09.333 1.942.372C5.555 15.990 5.827 16 8 16s2.444-.01 3.298-.048c.78-.035 1.204-.166 1.486-.275.373-.145.64-.319.92-.599.28-.28.453-.546.598-.92.11-.281.24-.705.276-1.485.038-.843.047-1.096.047-3.232s-.009-2.39-.047-3.233c-.036-.78-.166-1.203-.276-1.485a2.478 2.478 0 0 0-.598-.92 2.48 2.48 0 0 0-.92-.598c-.282-.11-.705-.24-1.486-.276C10.445.01 10.173 0 8 0zm0 1.442c2.136 0 2.389.008 3.232.046.78.036 1.204.166 1.486.276.373.145.64.319.92.599.28.28.453.546.598.92.11.282.24.705.276 1.486.038.843.046 1.096.046 3.232s-.008 2.389-.046 3.232c-.036.78-.166 1.204-.276 1.486a2.5 2.5 0 0 1-.598.92c-.28.28-.546.453-.92.598-.282.11-.705.24-1.486.276-.843.038-1.096.046-3.232.046s-2.389-.008-3.232-.046c-.78-.036-1.204-.166-1.486-.276a2.5 2.5 0 0 1-.92-.598 2.5 2.5 0 0 1-.598-.92c-.11-.282-.24-.705-.276-1.486-.038-.843-.046-1.096-.046-3.232s.008-2.389.046-3.232c.036-.78.166-1.204.276-1.486.145-.373.319-.64.599-.92.28-.28.546-.453.92-.598.282-.11.705-.24 1.486-.276C5.611 1.450 5.864 1.442 8 1.442zm0 2.452a4.108 4.108 0 1 0 0 8.215 4.108 4.108 0 0 0 0-8.215zm0 6.775a2.667 2.667 0 1 1 0-5.334 2.667 2.667 0 0 1 0 5.334zm5.23-6.937a.96.96 0 1 1-1.92 0 .96.96 0 0 1 1.92 0z",
  x: "M12.6.75h2.454l-5.36 6.142L16 15.25h-4.937l-3.867-5.07-4.425 5.07H.316l5.733-6.57L0 .75h5.063l3.495 4.633L12.601.75Zm-.86 13.028h1.36L4.323 2.145H2.865z",
  youtube: "M8.051 1.999h.089c.822.003 4.987.033 6.110.335a2.01 2.01 0 0 1 1.415 1.42c.101.38.172.883.22 1.402l.01.104.022.26.008.104c.065.914.073 1.77.074 1.957v.075c-.001.194-.01 1.108-.082 2.06l-.008.105-.009.104c-.05.572-.124 1.14-.235 1.558a2.01 2.01 0 0 1-1.415 1.42c-1.16.312-5.569.334-6.18.335h-.142c-.309 0-1.587-.006-2.927-.052l-.17-.006-.087-.004-.171-.007-.171-.007c-1.11-.049-2.167-.128-2.654-.26a2.01 2.01 0 0 1-1.415-1.419c-.111-.417-.185-.986-.235-1.558L.09 9.820l-.008-.104A31 31 0 0 1 0 7.680v-.123c.002-.215.01-.958.064-1.778l.007-.103.003-.052.008-.104.022-.26.01-.104c.048-.519.119-1.023.22-1.402a2.01 2.01 0 0 1 1.415-1.42c.487-.13 1.544-.21 2.654-.26l.17-.007.172-.006.086-.004.171-.006A100 100 0 0 1 7.858 2h.193zM6.4 5.209v4.818l4.157-2.408z",
  email: "M0 4a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2zm2-1a1 1 0 0 0-1 1v.217l7 4.2 7-4.2V4a1 1 0 0 0-1-1zm13 2.383-4.708 2.825L15 11.105zm-.034 6.876-5.64-3.471L8 9.583l-1.326-.795-5.64 3.470A1 1 0 0 0 2 13h12a1 1 0 0 0 .966-.741M1 11.105l4.708-2.897L1 5.383z",
};
const PLATFORM_LABEL = {
  scholar: "Google Scholar", orcid: "ORCID", dblp: "dblp", github: "GitHub", linkedin: "LinkedIn",
  researchgate: "ResearchGate", semanticscholar: "Semantic Scholar", openalex: "OpenAlex", website: "Website",
  twitter: "X", x: "X", youtube: "YouTube", facebook: "Facebook", instagram: "Instagram", email: "Email",
};
const PLATFORM_ORDER = ["scholar", "orcid", "dblp", "github", "linkedin", "researchgate", "semanticscholar", "openalex", "x", "twitter", "facebook", "instagram", "youtube", "website"];
function icon(platform) {
  const p = platform === "twitter" ? "x" : platform;
  const open = (attrs) => `<svg class="ic" width="16" height="16" viewBox="0 0 16 16" aria-hidden="true" focusable="false" ${attrs}>`;
  if (ICON_PATHS[p]) return `${open(`fill="currentColor"`)}<path d="${ICON_PATHS[p]}"/></svg>`;
  if (p === "orcid") return `${open(`fill="none" stroke="currentColor"`)}<circle cx="8" cy="8" r="7.2" stroke-width="1.3"/><path d="M5.3 6.6v5" stroke-width="1.4"/><circle cx="5.3" cy="4.6" r=".85" fill="currentColor" stroke="none"/><path d="M7.6 6.6h1.5a2.5 2.5 0 0 1 0 5H7.6z" stroke-width="1.3"/></svg>`;
  if (p === "scholar") return `${open(`fill="currentColor"`)}<path d="M8 1 0 6.4h16z"/><circle cx="8" cy="10.6" r="4"/></svg>`;
  if (p === "researchgate") return `${open(`fill="none" stroke="currentColor"`)}<rect x=".8" y=".8" width="14.4" height="14.4" rx="2" stroke-width="1.3"/><text x="8" y="11" text-anchor="middle" font-size="7" font-weight="700" fill="currentColor" stroke="none" font-family="Inter,system-ui,sans-serif">RG</text></svg>`;
  if (p === "dblp") return `${open(`fill="none" stroke="currentColor" stroke-width="1.3"`)}<ellipse cx="8" cy="3.4" rx="5.6" ry="2"/><path d="M2.4 3.4v9.2c0 1.1 2.5 2 5.6 2s5.6-.9 5.6-2V3.4"/><path d="M2.4 8c0 1.1 2.5 2 5.6 2s5.6-.9 5.6-2"/></svg>`;
  return `${open(`fill="none" stroke="currentColor" stroke-width="1.3"`)}<circle cx="8" cy="8" r="7"/><path d="M1 8h14M8 1c2 2 2.8 4.4 2.8 7S10 13 8 15C6 13 5.2 10.6 5.2 8S6 3 8 1z"/></svg>`;
}
// The platform field is what the admin sets; older rows without it are recognised by label/URL.
function platformOf(s) {
  const given = str(s.platform).toLowerCase();
  if (given) return given;
  const p = str(s.label).toLowerCase().replace(/[^a-z]/g, ""), u = str(s.url).toLowerCase();
  const has = (re) => re.test(p) || re.test(u);
  for (const k of ["orcid", "dblp", "github", "linkedin", "researchgate", "facebook", "instagram", "youtube", "openalex"]) if (has(new RegExp(k))) return k;
  if (has(/semanticscholar/)) return "semanticscholar";
  if (has(/scholar/)) return "scholar";
  if (p === "x" || /twitter/.test(p) || /\/\/(www\.)?(twitter|x)\.com\//.test(u)) return "x";
  return "website";
}
// Profiles from socials.json, skipping hidden ones (admin-editable), in the React site's order.
const rank = (k) => { const i = PLATFORM_ORDER.indexOf(k); return i < 0 ? 99 : i; };
let profileLinks = socials
  .filter((s) => s && !(s.hidden === true || /^(true|yes|1)$/i.test(str(s.hidden))) && /^https?:\/\//i.test(str(s.url)))
  .map((s) => { const k = platformOf(s); return { k, label: stripEmoji(s.label) || PLATFORM_LABEL[k] || "Link", url: str(s.url).replace(/[?&]hl=[a-z-]+$/i, "") }; })
  .filter((s) => s.k !== "email")
  .sort((a, b) => rank(a.k) - rank(b.k));
if (!profileLinks.length) {
  profileLinks = [
    ["scholar", "https://scholar.google.com/citations?user=Vy_sw5UAAAAJ"], ["orcid", `https://orcid.org/${ORCID}`],
    ["dblp", "https://dblp.org/pid/355/4465.html"], ["github", "https://github.com/Roy101"], ["linkedin", "https://www.linkedin.com/in/palashranjanroy/"],
  ].map(([k, url]) => ({ k, label: PLATFORM_LABEL[k], url }));
}
const profileLink = (p) => `<a href="${esc(p.url)}" aria-label="${esc(p.k === "email" ? `Email ${EMAIL}` : `${p.label} profile`)}">${icon(p.k)}<span>${esc(p.label)}</span></a>`;
const profileByKind = (k) => profileLinks.find((p) => p.k === k);
const FOOTER_PAGES = [
  ["Publications", "/publications/"],
  ...(galleryItems.length ? [[hnav("photos", "Photos"), "/photos/"]] : []),
  ...(faqItems.length ? [[hnav("faq", "Research FAQ"), "/faq/"]] : []),
  [hnav("aboutPage", "About"), "/about/"],
  ...(BLOG_SHOWN || blogPosts.length ? [[hn(["blog"], "Blog"), "/blog/"]] : []),
];
const COPYRIGHT = esc(stripEmoji(footer.copyright) || `© ${new Date().getFullYear()} ${NAME}`);
// Same layout and link styles as SiteFooter in src/App.tsx: name · email · CV, profile logos,
// a divider, the site pages, then "On the homepage" and the copyright in small text.
const footerHtml = () => `<footer class="site"><div class="wrap">
<div class="row"><span class="f-name">${esc(stripEmoji(footer.name) || NAME)}</span><a href="mailto:${EMAIL}">${EMAIL}</a>${cvDoc ? `<a href="${esc(cvDoc.url)}"${/\.pdf([?#]|$)/i.test(cvDoc.url) ? ` type="application/pdf"` : ""}>CV (PDF)</a>` : ""}</div>
${footer.showSocials !== false && profileLinks.length ? `<ul class="prof" aria-label="Profiles">${profileLinks.map((p) => `<li>${profileLink(p)}</li>`).join("")}</ul>` : ""}
<nav class="row pages" aria-label="Site pages">${FOOTER_PAGES.map(([l, u]) => `<a href="${u}">${esc(l)}</a>`).join("")}</nav>
<nav class="row onhome" aria-labelledby="f-onhome"><span id="f-onhome">On the homepage:</span>${FOOTER_ON_HOME.map(([, l, u]) => `<a href="${u}">${esc(l)}</a>`).join("")}</nav>
<div class="copy">${COPYRIGHT}</div>
</div></footer>`;

// <title> is kept short (≤ 60 characters where the content allows); og:title / twitter:title may
// carry the full name of the page (e.g. the complete paper title) via ogTitle.
function page({ title, ogTitle = "", description, canonical, head = "", body, jsonld, ogType = "website", crumbs = "", active = "", wide = false, robots = "" }) {
  const nav = navLinks(active);
  return `<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>${esc(title)}</title>
<meta name="description" content="${esc(description)}">
${canonical ? `<link rel="canonical" href="${esc(canonical)}">\n<meta name="robots" content="${robots || "index, follow, max-image-preview:large"}">` : `<meta name="robots" content="noindex">`}
<meta name="author" content="${NAME}">
<meta property="og:type" content="${ogType}">
<meta property="og:site_name" content="${NAME}">
<meta property="og:title" content="${esc(ogTitle || title)}">
<meta property="og:description" content="${esc(description)}">
${canonical ? `<meta property="og:url" content="${esc(canonical)}">\n` : ""}<meta property="og:image" content="${IMAGE}">
<meta property="og:image:alt" content="Photograph of ${NAME}">
<meta name="twitter:card" content="summary">
<meta name="twitter:title" content="${esc(ogTitle || title)}">
<meta name="twitter:description" content="${esc(description)}">
<meta name="twitter:image" content="${IMAGE}">
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="icon" href="/favicon.ico" sizes="32x32">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">
<link rel="preload" href="/fonts/source-serif-4-latin-600-normal.woff2" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="/fonts/inter-latin-400-normal.woff2" as="font" type="font/woff2" crossorigin>
${head}<script>${THEME_JS}</script>
<style>${CSS}${wide ? "\nmain>.wide{max-width:none}" : ""}</style>
${jsonld ? ldScript(jsonld) + "\n" : ""}</head>
<body>
<a class="skip" href="#main">Skip to content</a>
<header class="site"><div class="wrap">
<a class="wordmark" href="/">${NAME}</a>
<nav class="site" aria-label="Primary">${nav}</nav>
<div class="hdr-r">
${cvDoc ? `<a class="btn" href="${esc(cvDoc.url)}">CV</a>\n` : ""}<button type="button" class="tt" id="tt" aria-label="Switch to dark theme" title="Switch to dark theme">${MOON}${SUN}</button>
<details class="menu"><summary>${MENU_ICON}<span>Menu</span></summary><nav aria-label="Site menu">${nav}${cvDoc ? `<a class="m-cv" href="${esc(cvDoc.url)}">CV</a>` : ""}${ON_HOME.length ? `<span class="grp">On the homepage</span>` : ""}${ON_HOME.map(([, l, u]) => `<a href="${u}">${esc(l)}</a>`).join("")}</nav></details>
</div>
</div></header>
<main id="main" class="wrap">
${crumbs}${body}
</main>
${footerHtml()}
<script>${TOGGLE_JS}${SHOW_PROMPT ? PROMPT_JS : ""}</script>
</body>
</html>
`;
}
// ---------------------------------------------------------------- per-paper output
// Visible author lines keep the published order; co-first authors get an asterisk and a note.
// (citation_author, JSON-LD author and BibTeX use p.authors as published, without marks.)
const EQ_NOTE = "* Equal contribution (co-first authors)";
const authorsHtml = (p) => p.authors.map((a) => {
  const n = esc(a) + (isEqual(p, a) ? `<sup aria-hidden="true">*</sup><span class="sr"> (equal contribution)</span>` : "");
  return isOwner(a) ? `<strong>${n}</strong>` : n;
}).join(", ");
const eqNoteHtml = (p) => (p.authors.some((a) => isEqual(p, a)) ? `<p class="small eq">${esc(EQ_NOTE)}</p>` : "");
const authorsMd = (p) => p.authors.map((a) => { const n = a + (isEqual(p, a) ? "\\*" : ""); return isOwner(a) ? `**${n}**` : n; }).join(", ");
const eqMd = (p) => (p.authors.some((a) => isEqual(p, a)) ? EQ_NOTE.replace("*", "\\*") + "." : "");
// Plain sentence for llms.txt: "co-first author (equal contribution with Ajmain Inqiad Alam)".
const eqSentence = (p) => {
  const eq = p.authors.filter((a) => isEqual(p, a));
  if (eq.length < 2) return "";
  if (!eq.some(isOwner)) return `${eq.join(" and ")} contributed equally (co-first authors).`;
  const others = eq.filter((a) => !isOwner(a));
  return `${NAME} is co-first author (equal contribution with ${others.join(" and ")})${isOwner(p.authors[0]) ? " and the first-listed author" : ""}.`;
};
// One merged keyword row per paper (SPEC-round3 §22; the same rules as pubKeywords in App.tsx):
// narrow SE tags and specific ML topics (alternating) → kind tags (Tool, Benchmark) → broad SE
// areas → AI4SE/SE4AI → generic model terms (only when fewer than 4 others). A term with a more
// specific synonym present is dropped; terms with the same long label appear once.
// display_keywords, when set, is used verbatim. Lists show at most 4; paper pages show all.
// (The data, JSON-LD, llms files and the /publications/ filter keep tags and ml_topics apart.)
const KW_BROAD = new Set(["software maintenance & evolution", "program comprehension", "empirical se"]);
const KW_UMBRELLA = new Set(["ai4se", "se4ai"]);
const KW_KIND = new Set(["tool", "benchmark"]);
const KW_GENERIC = new Set(["code language models", "large language models"]);
const KW_SPECIFIC = { "model merging": ["task arithmetic (ties, dare)"], prompting: ["prompting (few-shot)"], benchmark: ["benchmarks & datasets"] };
const kwLabel = (t) => tagLabel(t) || mlLabel(t);
const kwKey = (t) => (kwLabel(t) || t).toLowerCase();
function keywordsOf(p, max = 0) {
  if (p.displayKeywords.length) return max ? p.displayKeywords.slice(0, max) : p.displayKeywords;
  const lc = (t) => t.toLowerCase();
  const narrow = p.tags.filter((t) => ![KW_BROAD, KW_UMBRELLA, KW_KIND, KW_GENERIC].some((set) => set.has(lc(t))));
  const specific = p.ml.filter((t) => !KW_GENERIC.has(lc(t)));
  const alt = [];
  for (let i = 0; i < Math.max(narrow.length, specific.length); i++) { if (narrow[i]) alt.push(narrow[i]); if (specific[i]) alt.push(specific[i]); }
  const all = [...alt, ...p.tags.filter((t) => KW_KIND.has(lc(t))), ...p.tags.filter((t) => KW_BROAD.has(lc(t))), ...p.tags.filter((t) => KW_UMBRELLA.has(lc(t))),
    ...[...p.tags, ...p.ml].filter((t) => KW_GENERIC.has(lc(t)))];
  const present = new Set(all.map(lc));
  const seen = new Set(), out = [];
  for (const t of all) {
    const k = kwKey(t);
    if (seen.has(k) || (KW_SPECIFIC[lc(t)] || []).some((x) => present.has(x))) continue;
    if (KW_GENERIC.has(lc(t)) && out.length >= 4) continue;
    seen.add(k); out.push(t);
  }
  return max ? out.slice(0, max) : out;
}
const tagLi = (t, label) => `<li>${label ? `<abbr title="${esc(label)}">${esc(t)}</abbr>` : esc(t)}</li>`;
const chipsHtml = (list) => (list.length ? `<ul class="tags" aria-label="Keywords">${list.map((t) => tagLi(t, kwLabel(t))).join("")}</ul>\n` : "");
const tagsHtml = (p, max = 4) => chipsHtml(keywordsOf(p, max));
const areasText = (p) => p.tags.map(tagLong).join(", ");
const mlText = (p) => p.ml.map(mlLong).join(", ");
const venueLine = (p) => (p.type === "thesis" ? [p.dissertationName, p.institution, p.year] : [p.venueFull, (p.track || p.type === "doctoral_symposium") && !p.venueFull.toLowerCase().includes((p.track || "Doctoral Symposium").toLowerCase()) && (p.track || "Doctoral Symposium"), p.volume && `vol. ${p.volume}${p.issue ? ` (${p.issue})` : ""}`, p.pages && `pp. ${p.pagesText}`, p.year]).filter(Boolean).join(", ");
const hwDate = (p) => (/^\d{4}-\d{2}-\d{2}$/.test(p.date) ? p.date.replace(/-/g, "/") : p.year);

// Link labels say what each artifact is (SPEC-round3 §8): Code (code_url), Replication package
// (artifact_url), Tool (tool_url; "Tool (CLEAR)" when it names a GitHub repository or tool_name
// is set), Dataset (dataset_url). Slides and Video only when set. A URL is listed once.
const shortTitle = (p) => { const c = p.title.split(/[:?]/)[0].trim(); return c.length <= 60 ? c : clip(p.title, 60); };
function linkList(p) {
  const l = [];
  const add = (label, u, aria) => { if (u && !l.some((x) => x[1] === u)) l.push([label, u, aria]); };
  const repo = (str(p.tool).match(/^https?:\/\/github\.com\/[^/]+\/([^/?#]+)/i) || [])[1];
  const toolLabel = p.toolName ? `Tool (${p.toolName})` : repo ? `Tool (${repo})` : "Tool";
  add("PDF", p.pdf);
  add("DOI", p.doi && `https://doi.org/${p.doi}`);
  add("arXiv", p.arxiv && `https://arxiv.org/abs/${p.arxiv}`);
  // A thesis's full text is in its university repository (USask HARVEST for handle prefix 10388).
  if (p.handle) {
    const repo = /hdl\.handle\.net\/10388\//i.test(p.handle) ? "USask HARVEST" : "";
    add(repo ? `Full text (${repo})` : "Full text", p.handle, `Full text of ${p.type === "thesis" ? `the ${p.dissertationName}` : shortTitle(p)}${repo ? ` on ${repo}` : ""}`);
  }
  // A GitHub tool repository that is also the code link is listed once, as the tool.
  if (!(repo && p.tool === p.code)) add("Code", p.code);
  add("Replication package", p.artifact);
  add(toolLabel, p.tool);
  add("Dataset", p.dataset);
  add("Slides", p.slides, `Slides for ${shortTitle(p)}`);
  add("Video", p.video, `Video for ${shortTitle(p)}`);
  return l;
}

const absParas = (t) => str(t).split(/\n\s*\n/).map((x) => x.trim()).filter(Boolean).map((x) => `<p>${esc(x)}</p>`).join("\n");
// "IEEE ICSME 2025, Doctoral Symposium, pp. 1–5, 2025 · IEEE"; a thesis: "M.Sc. thesis, University of Saskatchewan, 2025".
const trackOf = (p) => p.track || (p.type === "doctoral_symposium" ? TYPE_LABEL.doctoral_symposium : "");
function metaLine(p) {
  if (p.type === "thesis") return `<span class="v"><em>${esc(p.dissertationName)}</em></span>, ${esc(p.institution)}, ${esc(p.year)}`;
  const tr = trackOf(p);
  return `<span class="v"><em>${esc(p.venueFull)}</em></span>${tr && !p.venueFull.toLowerCase().includes(tr.toLowerCase()) ? `, ${esc(tr)}` : ""}${p.volume ? `, vol. ${esc(p.volume)}${p.issue ? ` (${esc(p.issue)})` : ""}` : ""}${p.pages ? `, <span class="nw">pp. ${esc(p.pagesText)}</span>` : ""}, ${esc(p.year)}${p.publisher ? ` · ${esc(p.publisher)}` : ""}${p.venueRank ? ` · <span class="rank">${esc(p.venueRank)}</span>` : ""}`;
}

function bibtexFor(p) {
  if (p.bibtex) return p.bibtex;
  const last = (n) => (n.includes(",") ? n.split(",")[0] : n.split(/\s+/).pop()).replace(/[^A-Za-z]/g, "");
  const key = `${last(p.authors[0] || "anon").toLowerCase()}${p.year}${(p.title.match(/[A-Za-z]{4,}/) || ["paper"])[0].toLowerCase()}`;
  const bibName = (n) => (n.includes(",") ? n : `${n.split(/\s+/).pop()}, ${n.split(/\s+/).slice(0, -1).join(" ")}`);
  const journal = p.type === "journal";
  if (p.type === "thesis") {
    const phd = /ph\.?\s?d|doctor/i.test(p.dissertationName);
    const tf = [["title", `{${p.title}}`], ["author", p.authors.map(bibName).join(" and ")], ["school", p.institution], ["year", p.year],
      p.handle && ["url", p.handle], p.doi && ["doi", p.doi]].filter(Boolean);
    return `@${phd ? "phdthesis" : "mastersthesis"}{${key},\n${tf.map(([k, v]) => `  ${k} = {${v}}`).join(",\n")}\n}`;
  }
  const f = [
    ["title", `{${p.title}}`], ["author", p.authors.map(bibName).join(" and ")],
    [journal ? "journal" : "booktitle", p.venueFull], ["year", p.year],
    p.volume && ["volume", p.volume], p.issue && ["number", p.issue],
    p.pages && ["pages", p.pages.replace(/\s*[-–]+\s*/, "--")], p.publisher && ["publisher", p.publisher],
    p.doi && ["doi", p.doi], p.arxiv && ["eprint", p.arxiv], p.arxiv && ["archivePrefix", "arXiv"],
  ].filter(Boolean);
  return `@${journal ? "article" : "inproceedings"}{${key},\n${f.map(([k, v]) => `  ${k} = {${v}}`).join(",\n")}\n}`;
}

function highwire(p) {
  const m = (n, c) => (c ? `<meta name="${n}" content="${esc(c)}">\n` : "");
  let h = m("citation_title", p.title);
  for (const a of p.authors) h += m("citation_author", a);
  h += m("citation_publication_date", hwDate(p));
  if (p.type === "thesis") h += m("citation_dissertation_institution", p.institution) + m("citation_dissertation_name", p.dissertationName);
  else if (p.type === "journal") h += m("citation_journal_title", p.venueFull) + m("citation_issn", p.issn) + m("citation_volume", p.volume) + m("citation_issue", p.issue);
  else h += m("citation_conference_title", p.venueFull);
  h += m("citation_firstpage", p.firstPage) + m("citation_lastpage", p.lastPage) + (p.type === "thesis" ? "" : m("citation_publisher", p.publisher));
  h += m("citation_doi", p.doi);
  if (p.type === "thesis") h += m("citation_public_url", p.handle);
  if (p.pdf) h += m("citation_pdf_url", abs(p.pdf));
  h += m("citation_abstract_html_url", p.url);
  h += m("citation_language", "en");
  return h;
}

// Short <title> for a paper page (≤ 60 characters where possible), most informative first:
// full title + venue + name, full title + venue, full title, then the part before the colon
// ("MergeSE", "GPTCloneBench") + venue. A paper title that is itself longer than 60 characters is
// kept whole rather than cut (the full title is what people search for).
function paperTitle(p) {
  const pre = p.title.includes(":") ? p.title.split(":")[0].trim() : "";
  const c = [`${p.title} · ${p.venueShort} | ${NAME}`, `${p.title} · ${p.venueShort}`, `${p.title} | ${NAME}`, p.title,
    ...(pre && pre.length >= 6 ? [`${pre} · ${p.venueShort} | ${NAME}`, `${pre} · ${p.venueShort}`] : [])];
  return c.find((t) => t.length <= 60) || p.title;
}
function articleNode(p) {
  const container = p.type === "journal"
    ? { "@type": "Periodical", name: p.venueFull, ...(p.issn ? { issn: p.issn } : {}) }
    : { "@type": "Book", name: p.venueFull };
  const sameAs = [p.doi && `https://doi.org/${p.doi}`, p.arxiv && `https://arxiv.org/abs/${p.arxiv}`, p.handle].filter(Boolean);
  const thesis = p.type === "thesis";
  const node = {
    "@type": thesis ? "Thesis" : "ScholarlyArticle",
    "@id": p.url + "#article", url: p.url, mainEntityOfPage: p.url,
    headline: clip(p.title, 110), name: p.title, datePublished: p.date, inLanguage: "en",
    author: p.authors.map((a) => (isOwner(a) ? { "@id": PERSON_ID } : { "@type": "Person", name: a })),
    ...(thesis ? {} : { isPartOf: container }),
  };
  if (thesis) {
    if (p.inSupportOf) node.inSupportOf = p.inSupportOf;
    if (p.institution) node.sourceOrganization = /saskatchewan/i.test(p.institution) ? ORGS.usask : { "@type": "CollegeOrUniversity", name: p.institution };
    if (p.institution) node.publisher = /saskatchewan/i.test(p.institution) ? { "@id": `${SITE}/#usask` } : { "@type": "CollegeOrUniversity", name: p.institution };
    if (p.handle) (node.identifier ||= []).push({ "@type": "PropertyValue", propertyID: "Handle", value: p.handle.replace(/^https:\/\/hdl\.handle\.net\//, ""), url: p.handle });
  } else if (p.type !== "journal") node.publication = { "@type": "PublicationEvent", name: p.venueShort };
  if (p.type === "doctoral_symposium" || p.track) node.genre = p.track || TYPE_LABEL[p.type];
  // Volume and issue belong to PublicationVolume / PublicationIssue, not to the article.
  if (!thesis && (p.volume || p.issue)) {
    let part = container;
    if (p.volume) part = { "@type": "PublicationVolume", volumeNumber: String(p.volume), isPartOf: part };
    if (p.issue) part = { "@type": "PublicationIssue", issueNumber: String(p.issue), isPartOf: part };
    node.isPartOf = part;
  }
  if (p.firstPage) { node.pageStart = p.firstPage; if (p.lastPage) node.pageEnd = p.lastPage; }
  if (p.publisher && !thesis) node.publisher = { "@type": "Organization", name: p.publisher };
  if (p.doi) (node.identifier ||= []).unshift({ "@type": "PropertyValue", propertyID: "DOI", value: p.doi, url: `https://doi.org/${p.doi}` });
  if (p.arxiv) (node.identifier ||= []).push({ "@type": "PropertyValue", propertyID: "arXiv", value: p.arxiv, url: `https://arxiv.org/abs/${p.arxiv}` });
  if (sameAs.length) node.sameAs = sameAs;
  if (p.abstract) node.abstract = p.abstract;
  else if (p.description) node.description = p.description;
  // Research-area tags: their long names join the keywords and become DefinedTerms in `about`.
  const kw = [...p.keywords.filter((k) => !isOwner(k)), ...p.tags.map((t) => tagLabel(t) || t), ...p.ml.map(mlLong)];
  const kwUniq = kw.filter((k, i) => kw.findIndex((x) => x.toLowerCase() === k.toLowerCase()) === i);
  if (kwUniq.length) node.keywords = kwUniq;
  const about = [
    ...p.tags.map((t) => ({ "@type": "DefinedTerm", name: tagLabel(t) || t, termCode: vocabFor(t)?.tag || t, inDefinedTermSet: `${SITE}/publications/#areas` })),
    ...p.ml.filter((t) => !p.tags.some((x) => (tagLabel(x) || x).toLowerCase() === mlLong(t).toLowerCase()))
      .map((t) => ({ "@type": "DefinedTerm", name: mlLong(t), termCode: t, inDefinedTermSet: `${SITE}/publications/#ml-topics` })),
    ...p.relevant.map((r) => ({ "@type": "Thing", name: r })),
  ];
  if (about.length) node.about = about;
  const eq = p.authors.filter((a) => isEqual(p, a));
  if (eq.length > 1) node.creditText = `${eq.join(" and ")} contributed equally (co-first authors).`;
  // Slides: a plain related document. (No VideoObject: Google requires a thumbnail and upload
  // date for it, which the content does not have.)
  if (p.slides) node.subjectOf = { "@type": "PresentationDigitalDocument", name: `Slides: ${p.title}`, url: abs(p.slides) };
  if (p.award) node.award = p.award;
  if (p.pdf) node.encoding = { "@type": "MediaObject", contentUrl: abs(p.pdf), encodingFormat: "application/pdf" };
  return node;
}

for (const p of pubs) {
  const links = linkList(p);
  const bib = bibtexFor(p);
  const desc = metaDesc(p.problem || p.description || firstSentence(p.abstract) || p.title);
  const body = `<article>
<h1>${esc(p.title)}</h1>
<p class="authors">${authorsHtml(p)}</p>
${eqNoteHtml(p)}<p class="meta">${metaLine(p)}</p>
${p.award ? `<p class="award">${esc(p.award)}</p>\n` : ""}${p.myRole ? `<p class="small role">Role: ${esc(p.myRole)}</p>\n` : ""}${tagsHtml(p, 0)}<p class="small">${NAME} (published as Palash Roy / Palash R. Roy) · <a href="https://orcid.org/${ORCID}">ORCID ${ORCID}</a></p>
${links.length ? `<ul class="links">${links.map(([l, u, aria]) => `<li><a href="${esc(u)}"${aria ? ` aria-label="${esc(aria)}"` : ""}>${esc(l)}</a></li>`).join("")}</ul>` : ""}
${p.abstract ? `<h2>Abstract</h2>\n${absParas(p.abstract)}` : p.description ? `<h2>Summary</h2>\n<p>${esc(p.description)}</p>` : ""}
${p.problem ? `<h2>Problem addressed</h2>\n<p>${esc(p.problem)}</p>` : ""}
${p.relevant.length ? `<h2>Relevant to</h2>\n<ul class="plain">${p.relevant.map((r) => `<li>${esc(r)}</li>`).join("")}</ul>` : ""}
<h2>Cite</h2>
<pre tabindex="0" aria-label="BibTeX">${esc(bib)}</pre>
<p class="small"><a href="/publications/${p.slug}/cite.bib" download>Download BibTeX</a> · <a href="/publications/${p.slug}.md">Markdown version</a></p>
</article>`;
  const crumbs = `<p class="crumbs"><a href="/">Home</a> / <a href="/publications/">Publications</a> / ${esc(p.venueShort)}</p>\n`;
  const graph = {
    "@context": "https://schema.org",
    "@graph": [
      articleNode(p),
      personNode(false),
      breadcrumbLd([["Home", `${SITE}/`], ["Publications", `${SITE}/publications/`], [p.title, p.url]]),
    ],
  };
  write(`publications/${p.slug}/index.html`, page({
    title: paperTitle(p), ogTitle: `${p.title} · ${p.venueShort} | ${NAME}`, description: desc, canonical: p.url, ogType: "article", crumbs, body, jsonld: graph, active: "publications",
    head: highwire(p) + `<link rel="alternate" type="text/markdown" href="/publications/${p.slug}.md">\n`,
  }));
  write(`publications/${p.slug}/cite.bib`, bib + "\n");
  write(`publications/${p.slug}.md`, paperMd(p, bib, 1));
}

function paperMd(p, bib, level) {
  const H = "#".repeat(level);
  const links = linkList(p).map(([l, u]) => `- ${l}: ${abs(u)}`).join("\n");
  return `${H} ${p.title}

${authorsMd(p)}. *${venueLine(p)}*.${p.venueRank ? ` Venue ranking: ${p.venueRank}.` : ""}${eqMd(p) ? `\n\n${eqMd(p)}${eqSentence(p) ? " " + eqSentence(p) : ""}` : ""}${p.award ? `\n\nAward: ${p.award}.` : ""}${p.myRole ? `\n\nRole of ${NAME}: ${p.myRole}` : ""}${p.tags.length ? `\n\nAreas: ${areasText(p)}.` : ""}${p.ml.length ? `\n\nML topics: ${mlText(p)}.` : ""}

Page: ${p.url}
${links ? "\n" + links + "\n" : ""}
${p.abstract ? `${H}# Abstract\n\n${p.abstract}\n` : p.description ? `${H}# Summary\n\n${p.description}\n` : ""}${p.problem ? `\n${H}# Problem addressed\n\n${p.problem}\n` : ""}${p.relevant.length ? `\n${H}# Relevant to research on\n\n${p.relevant.map((r) => `- ${r}`).join("\n")}\n` : ""}
${H}# BibTeX

\`\`\`bibtex
${bib}
\`\`\`
`;
}

// ---------------------------------------------------------------- publications index
const companionOf = (p) => slugify(p.raw?.companion_of || p.raw?.poster_of || "");
const byYear = [...new Set(pubs.map((p) => p.year))].sort((a, b) => b.localeCompare(a));
// Papers of one year in list order, except that a poster or companion entry comes right after
// its main paper (companion_of), and other posters after the full papers.
function yearPubs(y) {
  const list = pubs.filter((p) => p.year === y);
  const minor = (p) => p.type === "poster" || !!companionOf(p);
  const out = list.filter((p) => !minor(p));
  list.filter(minor).forEach((c) => {
    const at = out.findIndex((p) => p.slug === companionOf(c));
    if (at >= 0) { let j = at + 1; while (j < out.length && companionOf(out[j]) === companionOf(c)) j++; out.splice(j, 0, c); } else out.push(c);
  });
  return out;
}
// A theme's papers in the order of its paper_slugs (as on the homepage), each poster or
// companion right after its main paper.
const themePapers = (t) => {
  const order = (p) => { const i = (t.papers || []).indexOf(p.slug); return i < 0 ? 999 : i; };
  const all = pubs.filter((p) => p.themes.includes(t.id));
  const main = all.filter((p) => !(companionOf(p) && all.some((q) => q.slug === companionOf(p)))).sort((a, b) => order(a) - order(b));
  return main.flatMap((p) => [p, ...all.filter((c) => companionOf(c) === p.slug)]);
};
const themesUsed = THEMES.map((t) => ({ ...t, papers: themePapers(t) })).filter((t) => t.papers.length);
// Theme cards (SPEC-round3 §22), as on the homepage: title, one-sentence summary, key result,
// keyword chips and short venue links ("ICSME '25", "MergeSE (ASE '26 Tool)").
const shortVenue = (p) => {
  let v = str(p.venueShort || p.year).replace(/\s*\([^()]*\)/g, "").replace(/\bDoctoral Symposium\b/i, "DS").replace(/\bTool Track\b/i, "Tool")
    .replace(/\bResearch Track\b/i, "").replace(/\b(?:19|20)(\d{2})\b/g, "'$1").replace(/\s{2,}/g, " ").trim();
  if (/distinguished paper/i.test(p.award)) v += " · Distinguished Paper";
  const sw = softwareRaw.find((x) => slugify(x.paper_slug) === p.slug);
  const n = sw && stripEmoji(sw.name);
  return n && n.split(/\s+/).length <= 2 ? `${n} (${v})` : v;
};
function themeCardsHtml() {
  const cards = themesUsed.filter((t) => t.id !== "other-work");
  if (!cards.length) return "";
  const other = themesUsed.find((t) => t.id === "other-work");
  const ref = (p) => {
    const lead = p.type === "poster" ? "Poster" : shortVenue(p);
    const tool = p.tool && p.toolName && !softwareRaw.some((x) => slugify(x.paper_slug) === p.slug) ? ` · <a href="${esc(p.tool)}">${esc(p.toolName)}</a>` : "";
    return `<a href="/publications/${p.slug}/" aria-label="${esc(`${lead}: ${p.title}`)}">${esc(lead)}</a>${tool}`;
  };
  return `<ul class="cards">${cards.map((t) => {
    const chips = (t.keywords.length ? t.keywords : keywordsOf(t.papers[0], 3)).slice(0, 4);
    return `<li class="card"><h3>${esc(t.name)}</h3>${t.summary ? `<p>${esc(t.summary)}</p>` : ""}${t.keyResult ? `<p class="res">${esc(t.keyResult)}</p>` : ""}${chipsHtml(chips)}<p class="pp">${t.papers.map(ref).join(" · ")}</p></li>`;
  }).join("")}</ul>${other ? `\n<p class="small">Also: ${other.papers.map(ref).join(" · ")}</p>` : ""}`;
}
// "FSE 2026 (PACMSE)" -> "FSE 2026, PACMSE" where the venue is itself shown in parentheses.
const venueInline = (v) => str(v).replace(/\s*\(([^()]+)\)/g, ", $1");
const entryHtml = (p) => `<div class="entry"${p.tags.length ? ` data-tags="${esc(p.tags.join("|"))}"` : ""}${p.ml.length ? ` data-ml="${esc(p.ml.join("|"))}"` : ""}><div class="t"><a href="/publications/${p.slug}/">${esc(p.title)}</a></div>
<div>${authorsHtml(p)}</div>
${eqNoteHtml(p)}<div class="meta"><span class="v">${esc(p.venueShort)}</span>${p.type === "doctoral_symposium" && !/doctoral/i.test(p.venueShort) ? ` · ${TYPE_LABEL.doctoral_symposium}` : ""}${p.venueRank ? ` · <span class="rank">${esc(p.venueRank)}</span>` : ""}${p.award ? ` · <span class="award">${esc(p.award)}</span>` : ""}</div>
${p.myRole ? `<div class="small role">Role: ${esc(p.myRole)}</div>\n` : ""}${tagsHtml(p)}<div class="small">${[[`Paper page`, `/publications/${p.slug}/`], ...linkList(p), ["BibTeX", `/publications/${p.slug}/cite.bib`]].map(([l, u, aria]) => `<a href="${esc(u)}" aria-label="${esc(aria || `${l}: ${p.title}`)}">${esc(l)}</a>`).join(" · ")}</div>
${p.abstract ? `<details class="abs"><summary>Abstract</summary>${absParas(p.abstract)}</details>` : ""}</div>`;
// Research areas in use, vocabulary order first (SPEC-round3 §17).
const areasUsed = (() => {
  const used = [...new Set(pubs.flatMap((p) => p.tags))];
  const order = (t) => { const i = TAG_VOCAB.findIndex((v) => v.tag.toLowerCase() === t.toLowerCase()); return i < 0 ? 999 : i; };
  return used.sort((x, y) => order(x) - order(y)).map((tag) => ({ tag, papers: pubs.filter((p) => p.tags.includes(tag)) }));
})();
const mlUsed = (() => {
  const used = [...new Set(pubs.flatMap((p) => p.ml))];
  const order = (t) => { const i = ML_VOCAB.findIndex((v) => v.tag.toLowerCase() === t.toLowerCase()); return i < 0 ? 999 : i; };
  return used.sort((x, y) => order(x) - order(y)).map((tag) => ({ tag, papers: pubs.filter((p) => p.ml.includes(tag)) }));
})();
const mlName = (t) => (mlLabel(t) ? `<abbr title="${esc(mlLabel(t))}">${esc(t)}</abbr>` : esc(t));
const tagName = (t) => (tagLabel(t) ? `<abbr title="${esc(tagLabel(t))}">${esc(t)}</abbr>` : esc(t));
// Tag filter for /publications/: plain buttons with aria-pressed. The group is hidden until this
// script runs, so without JavaScript the page simply lists everything (and the "By research
// area" index below works as plain links). ?tag=AI4SE preselects an area.
const FILTER_JS = `(function(){var g=document.getElementById("tagf");if(!g)return;var bs=g.querySelectorAll("button"),es=document.querySelectorAll("#by-year .entry"),ys=document.querySelectorAll("#by-year section.yr"),st=document.getElementById("tagf-s"),total=es.length;function apply(f,t,push){var n=0;bs.forEach(function(b){b.setAttribute("aria-pressed",String(t?(b.getAttribute("data-f")===f&&b.getAttribute("data-tag")===t):b.getAttribute("data-tag")===""))});es.forEach(function(e){var on=!t||(e.getAttribute(f==="ml"?"data-ml":"data-tags")||"").split("|").indexOf(t)>=0;e.hidden=!on;if(on)n++});ys.forEach(function(y){y.hidden=!y.querySelector(".entry:not([hidden])")});var b=null;bs.forEach(function(x){if(x.getAttribute("data-f")===f&&x.getAttribute("data-tag")===t)b=x});if(b&&b.closest("details"))b.closest("details").open=true;st.textContent=t?"Showing "+n+" of "+total+" publications: "+(b?b.getAttribute("data-name"):t)+".":"";if(push&&history.replaceState){var u=new URL(location.href);u.searchParams.delete("tag");u.searchParams.delete("ml");if(t)u.searchParams.set(f,t);history.replaceState(null,"",u.pathname+u.search+u.hash)}}bs.forEach(function(b){b.addEventListener("click",function(){apply(b.getAttribute("data-f")||"tag",b.getAttribute("data-tag"),true)})});g.hidden=false;var sp=null;try{sp=new URLSearchParams(location.search)}catch(e){}if(sp){["tag","ml"].forEach(function(f){var q=sp.get(f);if(!q)return;var m=null;bs.forEach(function(b){if(b.getAttribute("data-f")===f&&b.getAttribute("data-tag").toLowerCase()===q.toLowerCase())m=b.getAttribute("data-tag")});if(m)apply(f,m,false)})}})();`;
{
  // Two groups (SPEC-round3 §21): Software Engineering areas and AI/ML topics; "All" resets both.
  const filterHtml = areasUsed.length || mlUsed.length ? `<div id="tagf" class="tagf-w" role="group" aria-label="Filter publications by topic" hidden>
<div class="tagf"><button type="button" data-f="" data-tag="" data-name="All" aria-pressed="true">All</button></div>
${areasUsed.length ? `<details class="tagf-d"><summary>Software Engineering areas <span class="n">(${areasUsed.length})</span></summary><div class="tagf" role="group" aria-label="Software Engineering areas">${areasUsed.map((a) => `<button type="button" data-f="tag" data-tag="${esc(a.tag)}" data-name="${esc(tagLong(a.tag))}" aria-pressed="false">${tagName(a.tag)}<span class="n"><span class="sr">, </span>${a.papers.length}<span class="sr"> publication${a.papers.length === 1 ? "" : "s"}</span></span></button>`).join("")}</div></details>\n` : ""}${mlUsed.length ? `<details class="tagf-d"><summary>AI/ML topics <span class="n">(${mlUsed.length})</span></summary><div class="tagf" role="group" aria-label="AI/ML topics">${mlUsed.map((a) => `<button type="button" data-f="ml" data-tag="${esc(a.tag)}" data-name="${esc(mlLong(a.tag))}" aria-pressed="false">${mlName(a.tag)}<span class="n"><span class="sr">, </span>${a.papers.length}<span class="sr"> publication${a.papers.length === 1 ? "" : "s"}</span></span></button>`).join("")}</div></details>\n` : ""}</div>
<p id="tagf-s" class="small" role="status"></p>\n` : "";
  const body = `<h1>Publications</h1>
<p>All peer-reviewed papers and posters by ${NAME} (published as Palash Roy / Palash R. Roy, <a href="https://orcid.org/${ORCID}">ORCID ${ORCID}</a>)${pubs.some((p) => p.type === "thesis") ? ", and his thesis" : ""}. Each paper page has the abstract, links to the DOI, arXiv, code and artifacts, and BibTeX.</p>
${themesUsed.length ? `<p class="small">Jump to: <a href="#by-year">By year</a> · <a href="#by-theme">By theme</a></p>` : ""}
<div id="by-year">
<h2 id="by-year-h">All publications by year</h2>
${filterHtml}${byYear.map((y) => `<section class="yr" aria-labelledby="y${y}"><h3 id="y${y}">${y}</h3>\n${yearPubs(y).map(entryHtml).join("\n")}</section>`).join("\n")}
</div>
<h2 id="by-theme">By theme</h2>
<p class="small">Each paper is listed under its themes here and in full, with links and abstract, <a href="#by-year">by year</a> above.</p>
${themesUsed.map((t) => `<h3 id="${t.id}">${esc(t.name)}</h3>\n<ul class="plain">${t.papers.map((p) => `<li><a href="/publications/${p.slug}/">${esc(p.title)}</a> <span class="small">(${esc(venueInline(p.venueShort))})</span></li>`).join("")}</ul>`).join("\n")}
${areasUsed.length || mlUsed.length ? `<details class="idx" id="topic-index"><summary>Index by research area and AI/ML topic</summary>
<p class="small">Every publication under each of its topics. The filter above shows the same lists.</p>
${areasUsed.length ? `<h3 id="areas">Research areas</h3>
${areasUsed.map((a) => `<h4 id="${tagId(a.tag)}">${tagLabel(a.tag) ? `${esc(tagLabel(a.tag))} (${esc(a.tag)})` : esc(a.tag)}</h4>\n<ul class="plain">${a.papers.map((p) => `<li><a href="/publications/${p.slug}/">${esc(p.title)}</a> <span class="small">(${esc(venueInline(p.venueShort))})</span></li>`).join("")}</ul>`).join("\n")}` : ""}
${mlUsed.length ? `<h3 id="ml-topics">AI/ML topics</h3>
${mlUsed.map((a) => `<h4 id="${mlId(a.tag)}">${mlLabel(a.tag) ? `${esc(mlLabel(a.tag))} (${esc(a.tag)})` : esc(a.tag)}</h4>\n<ul class="plain">${a.papers.map((p) => `<li><a href="/publications/${p.slug}/">${esc(p.title)}</a> <span class="small">(${esc(venueInline(p.venueShort))})</span></li>`).join("")}</ul>`).join("\n")}` : ""}
</details>` : ""}
<p class="small"><a href="/publications.md">Markdown version</a></p>
${areasUsed.length || mlUsed.length ? `<script>${FILTER_JS}</script>` : ""}`;
  write("publications/index.html", page({
    title: `Publications | ${NAME}`, canonical: `${SITE}/publications/`,
    description: `Publications by ${NAME} on code clones, clone detection, LLMs for software engineering, model compression and model merging, with BibTeX.`,
    crumbs: `<p class="crumbs"><a href="/">Home</a> / Publications</p>\n`, body, active: "publications",
    head: `<link rel="alternate" type="text/markdown" href="/publications.md">\n`,
    jsonld: {
      "@context": "https://schema.org",
      "@graph": [
        { "@type": "CollectionPage", "@id": `${SITE}/publications/#page`, url: `${SITE}/publications/`, name: `Publications of ${NAME}`, isPartOf: { "@id": WEBSITE_ID }, about: { "@id": PERSON_ID },
          mainEntity: { "@type": "ItemList", itemListElement: pubs.map((p, i) => ({ "@type": "ListItem", position: i + 1, url: p.url, name: p.title })) } },
        websiteNode, personNode(false), breadcrumbLd([["Home", `${SITE}/`], ["Publications", `${SITE}/publications/`]]),
      ],
    },
  }));
  write("publications.md", `# Publications of ${NAME}

${NAME} (published as Palash Roy / Palash R. Roy; ORCID ${ORCID}). Newest first.

${byYear.map((y) => `## ${y}\n\n${yearPubs(y).map((p) => `- [${p.title}](${SITE}/publications/${p.slug}.md). ${authorsMd(p)}. *${p.venueShort}*.${eqMd(p) ? ` ${eqMd(p)}` : ""}${p.award ? ` ${p.award}.` : ""}${p.tags.length ? ` Areas: ${p.tags.join(", ")}.` : ""}${p.ml.length ? ` ML topics: ${p.ml.join(", ")}.` : ""}${p.doi ? ` DOI: ${p.doi}.` : ""}${p.arxiv ? ` arXiv: ${p.arxiv}.` : ""}${p.handle ? ` Handle: ${p.handle}.` : ""}${p.slides ? ` Slides: ${abs(p.slides)}.` : ""}${p.video ? ` Video: ${abs(p.video)}.` : ""}`).join("\n")}`).join("\n\n")}
`);
}

// ---------------------------------------------------------------- FAQ
if (faqItems.length) {
  const FAQ_T = htitle("faq", "Research FAQ");
  const body = `<h1>${esc(FAQ_T)}</h1>
<p>${hsub("faq") ? inlineMd(hsub("faq")) : `Short, factual answers to questions this research addresses, with pointers to related work by other researchers. Written by ${NAME}.`}</p>
<dl class="faq">
${faqItems.map((f, i) => `<dt id="q${i + 1}">${esc(f.q)}</dt>\n<dd>${mdParas(f.a)}</dd>`).join("\n")}
</dl>
<p class="small"><a href="/faq.md">Markdown version</a></p>`;
  write("faq/index.html", page({
    title: `${FAQ_T === "Research FAQ" ? "Research FAQ: Code Clones and LLMs" : FAQ_T} | ${NAME}`, canonical: `${SITE}/faq/`,
    ogTitle: `${FAQ_T === "Research FAQ" ? "Research FAQ: code clones and LLMs for software engineering" : FAQ_T} | ${NAME}`,
    description: metaDesc(`Answers on clone benchmarks, detecting clones in LLM-generated code, compressing code LLMs and merging fine-tuned models, with links to the papers.`),
    crumbs: `<p class="crumbs"><a href="/">Home</a> / ${esc(FAQ_T)}</p>\n`, body,
    head: `<link rel="alternate" type="text/markdown" href="/faq.md">\n`,
    jsonld: {
      "@context": "https://schema.org",
      "@graph": [
        { "@type": "FAQPage", "@id": `${SITE}/faq/#page`, url: `${SITE}/faq/`, isPartOf: { "@id": WEBSITE_ID }, author: { "@id": PERSON_ID },
          mainEntity: faqItems.map((f) => ({ "@type": "Question", name: f.q, acceptedAnswer: { "@type": "Answer", text: mdParas(f.a, true) } })) },
        websiteNode, personNode(false), breadcrumbLd([["Home", `${SITE}/`], [FAQ_T, `${SITE}/faq/`]]),
      ],
    },
  }));
  write("faq.md", `# Research FAQ: ${NAME}\n\n${faqItems.map((f) => `## ${f.q}\n\n${mdAbsLinks(f.a)}`).join("\n\n")}\n`);
}

// ---------------------------------------------------------------- about
// The full profile: biography, nominations, every leadership role (incl. ROBU) and the
// references note live here, so the homepage can stay short (IA-decision.md).
const placeText = (s) => stripEmoji(s).replace(/\s*\|\s*/g, ", ");
// Same period format as the homepage (fmtPeriod in App.tsx): en dash, "May–Aug 2022".
function fmtPeriod(v) {
  const t = stripEmoji(v).replace(/\s+/g, " ").trim();
  if (!t || /^\d{4}-\d{2}(-\d{2})?$/.test(t)) return t;
  const parts = t.split(/\s*[-–—]\s*|\s+to\s+/i).map((x) => x.trim()).filter(Boolean);
  if (parts.length !== 2) return t;
  const [a, b] = parts, ym = /^([A-Za-z]+\.?)\s+((?:19|20)\d{2})$/;
  const ma = a.match(ym), mb = b.match(ym);
  if (ma && mb && ma[2] === mb[2]) return `${ma[1]}–${mb[1]} ${mb[2]}`;
  if (mb && /^[A-Za-z]+\.?$/.test(a)) return `${a}–${b}`;
  return `${a} – ${b}`;
}
// "University of Saskatchewan | 2024 - Present" -> "University of Saskatchewan, 2024–Present"
const placeFmt = (s) => { const p = stripEmoji(s).split("|").map((x) => x.trim()).filter(Boolean); return p.length > 1 ? `${p.slice(0, -1).join(", ")}, ${fmtPeriod(p[p.length - 1])}` : p.join(""); };
// One row per role: entries with the same role and organization are merged, periods listed together.
function mergeRoles(list) {
  const m = new Map();
  for (const l of list) {
    const k = [l.role, l.organization || l.place].map((x) => stripEmoji(x).toLowerCase().replace(/[^a-z0-9]+/g, " ").trim()).join("|");
    const period = fmtPeriod(l.period);
    if (m.has(k)) { if (period && !m.get(k).periods.includes(period)) m.get(k).periods.push(period); } else m.set(k, { ...l, periods: period ? [period] : [] });
  }
  return [...m.values()];
}
// Teaching & mentoring rows (about.experience categories teaching/mentoring/supervision and the
// service.json supervision/mentoring items), invited talks and reviewing (service.json).
const TEACH_RE = /^(teaching|mentoring|mentorship|supervision)$/i;
const sortKey = (x) => { const d = isoDay(x.date) || str(x.date); if (d) return d; const ys = (str(x.period || x.place).match(/(?:19|20)\d{2}/g) || []); return /present|current|ongoing/i.test(str(x.period || x.place)) ? "9999" : ys.pop() || ""; };
const orderNum = (v) => { const n = Number(str(v)); return str(v) !== "" && Number.isFinite(n) ? n : Infinity; };
const roleOrg = (x) => stripEmoji(x.venue || x.organization || x.org || "");
const teachRows = [
  ...(about.experience || []).filter((e) => e && e.role && TEACH_RE.test(str(e.category))).map((e) => {
    const parts = stripEmoji(e.place).split("|").map((t) => t.trim()).filter(Boolean);
    return { role: stripEmoji(e.role), org: parts.length > 1 ? parts.slice(0, -1).join(", ") : stripEmoji(e.organization || parts[0] || ""), period: fmtPeriod(parts.length > 1 ? parts[parts.length - 1] : e.period), description: stripEmoji(e.description), details: stripEmoji(e.details), date: e.date, order: orderNum(e.order) };
  }),
  ...serviceItems.filter((x) => /^(supervision|mentoring|mentorship|teaching)$/i.test(str(x.category))).map((x) => ({ role: stripEmoji(x.role), org: roleOrg(x), period: fmtPeriod(x.period), description: stripEmoji(x.description), details: stripEmoji(x.details), date: x.date, order: orderNum(x.order) })),
// Same order as the homepage Teaching & Mentoring list: the admin's `order` (1 = first), then newest.
].sort((a, b) => (a.order - b.order) || sortKey(b).localeCompare(sortKey(a)));
const researchRows = (about.experience || []).filter((e) => e && e.role && !TEACH_RE.test(str(e.category)));
const talks = serviceItems.filter((x) => str(x.category).toLowerCase() === "talk")
  .map((x) => ({ title: stripEmoji(x.description), role: stripEmoji(x.role), venue: roleOrg(x), period: fmtPeriod(x.period), date: x.date, url: safeUrl(x.link || x.url) }))
  .filter((t) => t.title).sort((a, b) => sortKey(b).localeCompare(sortKey(a)));
// "Invited Talk (Guest Speaker)" -> "Guest Speaker"; plain "Invited Talk" adds nothing.
const talkRole = (t) => { const m = t.role.match(/^invited talk\s*\((.+)\)$/i); return m ? m[1] : /^invited talk$/i.test(t.role) ? "" : t.role; };
// Thesis of a degree row: the education row's own thesis fields, else the thesis publication
// for that degree level.
const thesisFor = (e) => {
  const deg = stripEmoji(e.degree);
  const lvl = /m\.?\s?sc|master/i.test(deg) ? /m\.?\s?sc|master/i : /ph\.?\s?d|doctor/i.test(deg) ? /ph\.?\s?d|doctor/i : null;
  const pub = (str(e.thesis_slug) && pubs.find((p) => p.slug === slugify(e.thesis_slug)))
    || (lvl && pubs.find((p) => p.type === "thesis" && lvl.test(`${p.dissertationName} ${p.inSupportOf}`)));
  if (pub) return { title: pub.title, url: `/publications/${pub.slug}/` };
  const t = stripEmoji(e.thesis || e.thesis_title);
  return t ? { title: t, url: safeUrl(e.thesis_url) } : null;
};
// "University of Saskatchewan, 2024 – Aug 2028 (expected)" for the PhD row when expected is set.
const eduPlace = (e) => {
  const exp = e === PHD_ROW ? EXPECTED : stripEmoji(e.expected);
  const p = placeFmt(e.place);
  if (!exp) return p;
  return /present|current/i.test(p) ? p.replace(/[-–]\s*(present|current)\b/i, `– ${exp} (expected)`).replace(/(\d)–\s/, "$1 – ") : `${p} (expected ${exp})`;
};

const nominations = highlights.filter((h) => h && isNom(h));
// Service rows other than talks, teaching and reviewing (University governance, organizing).
const serviceRows = serviceItems.filter((x) => !/^(talk|review|reviewing|supervision|mentoring|mentorship|teaching)$/i.test(str(x.category)))
  .map((x) => ({ role: stripEmoji(x.role), org: roleOrg(x), period: fmtPeriod(x.period), description: [stripEmoji(x.description), stripEmoji(x.details)].filter(Boolean).join(" ") }));
const reviewer = serviceItems.find((x) => /^(review|reviewing)$/i.test(str(x.category)) && str(x.venues));
const reviewerLine = reviewer ? `${stripEmoji(reviewer.role) || "Reviewer"}: ${stripEmoji(reviewer.venues)}${str(reviewer.period) ? ` (${fmtPeriod(reviewer.period)})` : ""}` : "";
const newsDate = (d) => { const m = str(d).match(/^(\d{4})-(\d{2})/); return m ? `${["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"][+m[2] - 1]} ${m[1]}` : ""; };
const newsRows = [...IN_THE_NEWS.filter((n) => n.kind === "article").sort((a, b) => b.date.localeCompare(a.date)), ...IN_THE_NEWS.filter((n) => n.kind === "profile")];
{
  const paras = (about.paragraphs || []).map(stripEmoji).filter(Boolean);
  const edu = (about.education || []).filter((e) => e && e.degree);
  const lead = mergeRoles(leadership);
  const refs = headings?.references || {};
  const refNote = [stripEmoji(refs.subtitle), stripEmoji(refs.note)].filter(Boolean);
  const roleRow = (period, what) => `<li><span class="d">${esc(period)}</span><span>${what}</span></li>`;
  const body = `<h1>${NAME}</h1>
<p class="meta">${esc(NAME_LINE)}</p>
<p class="small">Published as Palash Roy / Palash R. Roy · <a href="https://orcid.org/${ORCID}">ORCID ${ORCID}</a></p>
${paras.map((p) => `<p>${inlineMd(p)}</p>`).join("\n")}
${galleryItems.length ? `<p><a href="/photos/">Photos</a></p>` : ""}
<h2>Research</h2>
${themeCardsHtml()}
<p><a href="/publications/">All publications</a>${faqItems.length ? ` · <a href="/faq/">Research FAQ</a>` : ""}</p>
${edu.length ? `<h2>Education</h2>\n<ul class="plain">${edu.map((e) => { const th = thesisFor(e); return `<li>${esc(stripEmoji(e.degree))}, ${esc(eduPlace(e))}${th ? `<br><span class="small">Thesis: ${th.url ? `<a href="${esc(th.url)}">${esc(th.title)}</a>` : esc(th.title)}</span>` : ""}</li>`; }).join("")}</ul>` : ""}
${teachRows.length ? `<h2>Teaching and mentoring</h2>\n<ul class="roles">${teachRows.map((t) => roleRow(t.period, `${esc(t.role)}${t.org ? `, ${esc(t.org)}` : ""}${t.description || t.details ? `<br><span class="small">${esc([t.description, t.details].filter(Boolean).join(" "))}</span>` : ""}`)).join("")}</ul>` : ""}
${researchRows.length ? `<h2>Research positions</h2>\n<ul class="plain">${researchRows.map((e) => `<li>${esc(stripEmoji(e.role))}, ${esc(placeFmt(e.place))}</li>`).join("")}</ul>` : ""}
${talks.length ? `<h2>Invited talks</h2>\n<ul class="roles">${talks.map((t) => roleRow(t.period, `${t.url ? `<a href="${esc(t.url)}">${esc(t.title)}</a>` : esc(t.title)}${t.venue ? `, ${esc(t.venue)}` : ""}${talkRole(t) ? ` <span class="small">(${esc(talkRole(t))})</span>` : ""}`)).join("")}</ul>` : ""}
${lead.length ? `<h2>Leadership</h2>\n<ul class="roles">${lead.map((l) => roleRow(l.periods.join(", "), `${esc(stripEmoji(l.role))}, ${safeUrl(l.link) ? `<a href="${esc(l.link)}">${esc(stripEmoji(l.organization))}</a>` : esc(stripEmoji(l.organization))}${str(l.place) ? `, ${esc(placeText(l.place))}` : ""}`)).join("")}</ul>` : ""}
${awards.length ? `<h2>Awards</h2>\n<ul class="plain">${awards.map((a) => `<li>${esc(a)}</li>`).join("")}</ul>` : ""}
${serviceRows.length || reviewerLine ? `<h2>Service</h2>\n<ul class="roles">${serviceRows.map((r) => roleRow(r.period, `${esc(r.role)}${r.org ? `, ${esc(r.org)}` : ""}${r.description ? `<br><span class="small">${esc(r.description)}</span>` : ""}`)).join("")}${reviewerLine ? roleRow(fmtPeriod(reviewer.period), esc(`${stripEmoji(reviewer.role) || "Reviewer"}: ${stripEmoji(reviewer.venues)}`)) : ""}</ul>` : ""}
${nominations.length ? `<h2>Nominations</h2>\n<ul class="plain">${nominations.map((n) => `<li>${esc(stripEmoji(n.description) || stripEmoji(n.title))}${str(n.organization) ? ` <span class="small">(${esc(stripEmoji(n.organization))})</span>` : ""}</li>`).join("")}</ul>` : ""}
${newsRows.length ? `<h2>In the news</h2>\n<ul class="roles">${newsRows.map((n) => roleRow(n.kind === "profile" ? "Profile" : newsDate(n.date), `<a href="${esc(n.url)}">${esc(n.title)}</a>${n.publisher ? `, ${esc(n.publisher)}` : ""}`)).join("")}</ul>` : ""}
${refNote.length ? `<h2>${esc(stripEmoji(refs.title) || "References")}</h2>\n${refNote.map((r) => `<p>${inlineMd(r)}</p>`).join("\n")}` : ""}
<h2>Profiles and contact</h2>
<ul class="plain"><li>Email: <a href="mailto:${EMAIL}">${EMAIL}</a></li>${IDS.map((i) => `<li>${i.propertyID}: <a href="${esc(i.url)}">${esc(i.value)}</a></li>`).join("")}</ul>`;
  write("about/index.html", page({
    title: `About ${NAME}: Biography, Education, Awards`, canonical: `${SITE}/about/`, ogType: "profile",
    ogTitle: `About ${NAME} | PhD Researcher, University of Saskatchewan`,
    description: metaDesc(`Biography of ${NAME} (Palash Roy), PhD researcher at the University of Saskatchewan: education, teaching, leadership, awards and news coverage.`),
    crumbs: `<p class="crumbs"><a href="/">Home</a> / ${esc(htitle("aboutPage", "About"))}</p>\n`, body,
    jsonld: {
      "@context": "https://schema.org",
      "@graph": [
        { "@type": "AboutPage", "@id": `${SITE}/about/#page`, url: `${SITE}/about/`, name: `About ${NAME}`, isPartOf: { "@id": WEBSITE_ID }, mainEntity: { "@id": PERSON_ID }, about: { "@id": PERSON_ID } },
        websiteNode, { ...personNode(true), mainEntityOfPage: undefined }, breadcrumbLd([["Home", `${SITE}/`], [htitle("aboutPage", "About"), `${SITE}/about/`]]),
      ],
    },
  }));
}

// ---------------------------------------------------------------- photos
// gallery.json -> /photos/. Captions and alt text come from the admin (title, description,
// altText); `featuresOwner` (true/false) says whether he is in the photo. When it is not set,
// a photo counts as his when its alt text, title or description names him.
const MONTHS = ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"];
const showDate = (d) => { const m = str(d).match(/^(\d{4})-(\d{2})/); return m ? `${MONTHS[+m[2] - 1] || ""} ${m[1]}`.trim() : str(d); };
const photos = galleryItems.map((g) => {
  const src = safeUrl(g.image);
  if (!src) return null;
  const title = stripEmoji(g.title), desc = stripEmoji(g.description), altText = stripEmoji(g.altText);
  const owner = typeof g.featuresOwner === "boolean" ? g.featuresOwner : /palash/i.test([g.altText, g.title, g.description].join(" "));
  const alt = altText || [title, desc].filter(Boolean).join(". ") || (owner ? `Photo of ${NAME}` : "Photo");
  const caption = owner && !/palash/i.test(alt) ? `${NAME}: ${alt}` : alt;
  // Visible caption: the admin's caption, else the alt text (third person, names him), else the
  // description. Older descriptions are first person ("my final address"), so they come last.
  const visible = stripEmoji(g.caption) || altText || desc;
  return { src, url: encPath(src), size: localImageSize(src), title, desc, alt, caption, visible, owner, date: str(g.date), credit: stripEmoji(g.creditText || g.credit) };
}).filter(Boolean);
for (const ph of photos) if (!ph.size) console.warn(`  [seo] warn: photo ${ph.src} is not in the build (or unreadable); no width/height on /photos/`);
if (photos.length) {
  const fig = (ph, i) => `<figure>
<a class="ph" href="${esc(ph.url)}"><img src="${esc(ph.url)}" alt="${esc(ph.alt)}"${ph.size ? ` width="${ph.size.w}" height="${ph.size.h}"` : ` class="noar"`} loading="${i < 3 ? "eager" : "lazy"}" decoding="async"></a>
<figcaption>${ph.title ? `<strong>${esc(ph.title)}</strong>` : ""}${ph.visible ? `${esc(ph.visible)}` : ""}${((meta) => (meta.length ? `<span class="small">${meta.join(" · ")}</span>` : ""))([ph.owner && !/palash/i.test(ph.title + " " + ph.visible) && `Pictured: ${NAME}`, esc(showDate(ph.date)), ph.credit && `Photo: ${esc(ph.credit)}`].filter(Boolean))}</figcaption>
</figure>`;
  const PH_T = htitle("photos", "Photos");
  const body = `<h1>${esc(PH_T)}</h1>
<p>${hsub("photos") ? inlineMd(hsub("photos")) : `Photos of ${NAME} at university events and on his travels.`}</p>
<div class="gallery wide">
${photos.map(fig).join("\n")}
</div>`;
  write("photos/index.html", page({
    title: `${PH_T} | ${NAME}`, canonical: `${SITE}/photos/`, wide: true,
    description: metaDesc(`Photos of ${NAME}, PhD researcher at the University of Saskatchewan: ${photos.filter((p) => p.owner).map((p) => p.title).filter(Boolean).join(", ")}.`),
    crumbs: `<p class="crumbs"><a href="/">Home</a> / ${esc(PH_T)}</p>\n`, body,
    jsonld: {
      "@context": "https://schema.org",
      "@graph": [
        { "@type": "ImageGallery", "@id": `${SITE}/photos/#gallery`, url: `${SITE}/photos/`, name: `Photos of ${NAME}`, inLanguage: "en",
          isPartOf: { "@id": WEBSITE_ID }, about: { "@id": PERSON_ID },
          associatedMedia: photos.map((ph) => imageObject(ph.src, ph.size, {
            name: ph.title || undefined, caption: ph.caption, description: ph.desc || undefined,
            ...(ph.owner ? { about: { "@id": PERSON_ID } } : {}),
            ...(ph.credit ? { creditText: ph.credit } : {}),
            ...(/^\d{4}(-\d{2}){0,2}$/.test(ph.date) ? { dateCreated: ph.date } : {}),
          })) },
        websiteNode, { ...personNode(false), image: PERSON_IMAGES }, breadcrumbLd([["Home", `${SITE}/`], [PH_T, `${SITE}/photos/`]]),
      ],
    },
  }));
}

// ---------------------------------------------------------------- blog (fallback)
// prerender.mjs snapshots the React blog (index with its empty state, and each published post).
// If that did not happen, write a plain blog index here so /blog/ is never a broken route.
if (!existsSync(path.join(DIST, "blog", "index.html"))) {
  const bh = headings?.blog || {};
  const postSlug = (b) => str(b.slug) || slugify(b.title);
  const body = `<h1>${esc(stripEmoji(bh.title) || "Blog")}</h1>
${stripEmoji(bh.subtitle) ? `<p class="meta">${inlineMd(stripEmoji(bh.subtitle))}</p>` : ""}
${blogPosts.length ? blogPosts.map((b) => `<article class="post"><p class="small">${esc(showDate(b.date))}</p><h2><a href="/blog/${esc(postSlug(b))}/">${esc(stripEmoji(b.title))}</a></h2>${str(b.summary) ? `<p>${esc(stripEmoji(b.summary))}</p>` : ""}</article>`).join("\n")
    : `<p class="empty">Posts coming soon.</p>`}`;
  write("blog/index.html", page({
    title: `${stripEmoji(bh.title) || "Blog"} | ${NAME}`, canonical: `${SITE}/blog/`, active: "blog",
    robots: blogPosts.length ? "" : "noindex, follow",
    description: metaDesc([plainFromMd(stripEmoji(bh.subtitle)), `Posts by ${NAME}, PhD researcher in Computer Science at the University of Saskatchewan.`].filter(Boolean).join(" ")),
    crumbs: `<p class="crumbs"><a href="/">Home</a> / Blog</p>\n`, body,
    jsonld: { "@context": "https://schema.org", "@graph": [websiteNode, personNode(false), breadcrumbLd([["Home", `${SITE}/`], ["Blog", `${SITE}/blog/`]])] },
  }));
  console.warn("  [seo] warn: no prerendered /blog/ found; wrote the static fallback blog index");
}

// ---------------------------------------------------------------- maintenance page
// Served by .htaccess with HTTP 503 + Retry-After while /.maintenance exists (SPEC-round2 §7).
// Self-contained (inline CSS; only /fonts/ and the favicon, which stay reachable). The admin
// replaces only the text between the marker comments (HTML-escaped) when it turns maintenance on.
{
  const scholar = profileByKind("scholar") || { k: "scholar", label: "Google Scholar", url: "https://scholar.google.com/citations?user=Vy_sw5UAAAAJ" };
  const orcid = profileByKind("orcid") || { k: "orcid", label: "ORCID", url: `https://orcid.org/${ORCID}` };
  write("maintenance.html", `<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Back soon | ${NAME}</title>
<meta name="robots" content="noindex">
<meta name="description" content="The website of ${NAME} is undergoing maintenance.">
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="icon" href="/favicon.ico" sizes="32x32">
<script>${THEME_JS}</script>
<style>${CSS}
.maint{min-height:100vh;display:flex;flex-direction:column;justify-content:center;padding-top:48px;padding-bottom:48px}
.maint .until:empty{display:none}
.maint ul.prof a{color:var(--muted);display:inline-flex;align-items:center;gap:6px;min-height:40px}
.maint ul.prof a:hover{color:var(--accent)}</style>
</head>
<body>
<main id="main" class="wrap maint">
<h1>${NAME}</h1>
<p class="meta">${esc(NAME_LINE)}</p>
<p><!--MAINT-MESSAGE-->The site is undergoing maintenance — back soon.<!--/MAINT-MESSAGE--></p>
<p class="small until"><!--MAINT-UNTIL--><!--/MAINT-UNTIL--></p>
<ul class="prof" aria-label="Contact and profiles"><li><a href="mailto:${EMAIL}">${icon("email")}<span>${EMAIL}</span></a></li><li>${profileLink(scholar)}</li><li>${profileLink(orcid)}</li></ul>
</main>
</body>
</html>
`);
}

// ---------------------------------------------------------------- 404
write("404.html", page({
  title: `Page not found | ${NAME}`, description: "This page does not exist.", canonical: "",
  body: `<h1>Page not found</h1>
<p>There is no page at this address. It may have moved.</p>
<ul class="plain"><li><a href="/">Home</a></li>${FOOTER_PAGES.map(([l, u]) => `<li><a href="${u}">${esc(l)}</a></li>`).join("")}</ul>`,
}));

// ---------------------------------------------------------------- tools & benchmarks
// Only software.json items that are themselves the subject of a published paper (a tool,
// benchmark or dataset paper; SPEC-round3 §8). Code and replication packages of other papers
// stay on their paper entries.
const shortName = (p) => { const c = p.title.split(":")[0].trim(); return c.length <= 40 && c !== p.title ? c : p.title; };
const SW_KIND = { tool: "tool", dataset: "dataset", benchmark: "benchmark" };
// Description cut at a sentence end when it is long (never mid-word with "…").
const swDesc = (d) => {
  const t = stripEmoji(d).replace(/\s+/g, " ").trim();
  if (t.length <= 320) return t.replace(/[.\s]+$/, "");
  const cut = t.slice(0, 320), end = cut.lastIndexOf(". ");
  return (end > 80 ? cut.slice(0, end) : clip(t, 320)).replace(/[.\s]+$/, "");
};
const software = softwareItems.map((s) => {
  const paper = pubs.find((p) => p.slug === slugify(s.paper_slug));
  const kind = SW_KIND[str(s.publication_kind).toLowerCase()] || SW_KIND[str(s.kind).toLowerCase()] || "tool";
  const links = [];
  const add = (label, u) => { u = safeUrl(u); if (u && !links.some((l) => l[1] === u)) links.push([label, u]); };
  add(kind === "tool" ? "Tool" : kind === "benchmark" ? "Benchmark" : "Dataset", s.url);
  add("Code", s.code_url);
  add(/zenodo/i.test(str(s.dataset_url)) ? "Zenodo" : "Dataset", s.dataset_url);
  add(/zenodo|10\.5281\//i.test(str(s.artifact_url)) ? "Zenodo" : "Replication package", s.artifact_url);
  return { name: stripEmoji(s.name), kind, desc: swDesc(s.description), paper, links };
}).filter((s) => s.links.length);
const TOOLS_HEADING = TOOLS_LABEL;

// ---------------------------------------------------------------- llms.txt / llms-full.txt
const intro = `> ${NAME} (published as Palash Roy, Palash R. Roy and P. R. Roy; ORCID ${ORCID}) is a ${PERSON_DESC}`;
const eqPapers = pubs.filter((p) => p.authors.some((a) => isOwner(a) && isEqual(p, a)));
const prose = [
  `He works in the Software Research Lab (SRLab) and the Interactive Software Engineering and Analytics (iSE) Lab at the University of Saskatchewan, Canada, supervised by Dr. Kevin Schneider.`,
  eqPapers.length ? `He is co-first author (equal contribution) of ${eqPapers.map((p) => `${shortName(p)} (${p.venueShort}; with ${p.authors.filter((a) => isEqual(p, a) && !isOwner(a)).join(" and ")})`).join(" and of ")}.` : "",
  `Disambiguation: he is identified by ORCID ${ORCID}, dblp 355/4465, OpenAlex A5058812722 and Semantic Scholar 2051272535. Other researchers named Palash Roy are different people.`,
  `Each paper below has an HTML page with the abstract and BibTeX and a Markdown version (linked here). Contact: ${EMAIL}.`,
].filter(Boolean).join("\n\n");
const paperLine = (p) => `- [${p.title}](${SITE}/publications/${p.slug}.md): ${p.venueShort}${p.type === "doctoral_symposium" && !/doctoral/i.test(p.venueShort) ? " (Doctoral Symposium)" : ""}.${p.award ? ` ${p.award}.` : ""}${eqSentence(p) ? ` ${eqSentence(p)}` : ""} ${clip(p.problem || p.description || firstSentence(p.abstract), 240)}${p.doi ? ` DOI ${p.doi}.` : ""}${p.arxiv ? ` arXiv ${p.arxiv}.` : ""}${p.handle ? ` Handle ${p.handle}.` : ""}`.replace(/\s+$/, "");
// Papers grouped by the two umbrella areas, AI for Software Engineering (AI4SE) and Software
// Engineering for AI (SE4AI); a paper in both is listed under both. Papers in neither go under
// their first area. Without any tags (an older database copy) the research themes are used.
let paperSections;
if (pubs.some((p) => p.tags.length)) {
  const groups = [];
  for (const u of UMBRELLA_TAGS) {
    const ps = pubs.filter((p) => p.tags.includes(u));
    if (ps.length) groups.push([tagLong(u), ps]);
  }
  const rest = pubs.filter((p) => !p.tags.some((t) => UMBRELLA_TAGS.includes(t)));
  for (const p of rest) {
    const h = p.tags.length ? tagLong(p.tags[0]) : "Other work";
    const g = groups.find((x) => x[0] === h);
    if (g) g[1].push(p); else groups.push([h, [p]]);
  }
  paperSections = groups.map(([h, ps]) => `## Papers: ${h}\n\n${ps.map(paperLine).join("\n")}`);
} else {
  const listed = new Set();
  paperSections = themesUsed.map((t) => {
    const ps = t.papers.filter((p) => !listed.has(p.slug));
    ps.forEach((p) => listed.add(p.slug));
    return ps.length ? `## Papers: ${t.name}\n\n${ps.map(paperLine).join("\n")}` : "";
  }).filter(Boolean);
  const rest = pubs.filter((p) => !listed.has(p.slug));
  if (rest.length) paperSections.push(`## Papers: other\n\n${rest.map(paperLine).join("\n")}`);
}
// Index of the broad SE areas and of the AI/ML topics (SPEC-round3 §20-21), so area- and
// ML-phrased queries find the papers. Each paper appears in full once, above.
const refLine = (ps) => ps.map((p) => `[${shortName(p)}](${SITE}/publications/${p.slug}.md) (${p.venueShort})`).join("; ");
const seIndex = AREA_TAGS.map((t) => [tagLong(t), pubs.filter((p) => p.tags.some((x) => x.toLowerCase() === t.toLowerCase()))]).filter(([, ps]) => ps.length);
const mlIndex = mlUsed.map((a) => [mlLong(a.tag), a.papers]);
if (seIndex.length) paperSections.push(`## Research areas (software engineering)\n\n${seIndex.map(([h, ps]) => `- ${h}: ${refLine(ps)}`).join("\n")}`);
if (mlIndex.length) paperSections.push(`## AI/ML research\n\nThe same papers grouped by machine-learning topic.\n\n${mlIndex.map(([h, ps]) => `- ${h}: ${refLine(ps)}`).join("\n")}`);
const swLine = (s) => `- [${s.name}](${s.links[0][1]}): ${s.desc ? s.desc + ". " : ""}${s.kind === "tool" ? "Tool" : s.kind === "benchmark" ? "Benchmark" : "Dataset"} paper: ${s.paper.venueShort} (${s.paper.url}).${s.links.slice(1).map(([l, u]) => ` ${l}: ${u}.`).join("")}`;
const awardLines = awards.map((a) => `- ${a}`).join("\n");
const teachLine = (t) => { const d = [t.description, t.details].filter(Boolean).join(" "); return `- ${t.role}${t.org ? `, ${t.org}` : ""}${t.period ? ` (${t.period})` : ""}${d ? `: ${d.replace(/[.\s]+$/, "")}.` : "."}`; };
const talkLine = (t) => `- ${t.period ? `${t.period}: ` : ""}"${t.title}"${t.venue ? `, ${t.venue}` : ""}${talkRole(t) ? ` (${talkRole(t)})` : ""}.${t.url ? ` ${t.url}` : ""}`;

// Admin-editable profiles (socials.json, non-hidden) not already covered by the scholarly IDs.
const LLMS_PROFILES = profileLinks.filter((p) => !["scholar", "orcid", "dblp"].includes(p.k) && !IDS.some((i) => i.url === p.url));
const blogLine = (b) => `- [${stripEmoji(b.title)}](${SITE}/blog/${encodeURIComponent(str(b.slug) || slugify(b.title))}/)${isoDay(b.date) ? ` (${isoDay(b.date)})` : ""}${str(b.summary) ? `: ${clip(stripEmoji(b.summary), 240)}` : ""}`;
const llms = `# ${NAME}

${intro}

${prose}

${paperSections.join("\n\n")}
${software.length ? `\n## ${TOOLS_HEADING}\n\n${software.map(swLine).join("\n")}\n` : ""}${awards.length ? `\n## Awards\n\n${awardLines}\n` : ""}${teachRows.length ? `\n## Teaching and mentoring\n\n${teachRows.map(teachLine).join("\n")}\n` : ""}${talks.length ? `\n## Invited talks\n\n${talks.map(talkLine).join("\n")}\n` : ""}${blogPosts.length ? `\n## Blog\n\n${blogPosts.map(blogLine).join("\n")}\n` : ""}${faqItems.length ? `\n## Research questions\n\n- [Questions this research addresses](${SITE}/faq.md): ${faqItems.map((f) => f.q).join(" ")}\n` : ""}
## Profiles

${IDS.map((i) => `- [${i.propertyID}](${i.url}): ${i.value}`).join("\n")}
${LLMS_PROFILES.map((p) => `- [${p.label}](${p.url})`).join("\n")}

## Optional

- [Full text of paper summaries, abstracts and BibTeX](${SITE}/llms-full.txt)
- [Publication list](${SITE}/publications.md)
- [About page](${SITE}/about/)
${photos.length ? `- [Photos](${SITE}/photos/)\n` : ""}${documents.map((d) => `- [${d.label}](${abs(d.url)})\n`).join("")}`;
write("llms.txt", llms);

const bio = (about.paragraphs || []).map((s) => plainFromMd(stripEmoji(s))).filter(Boolean).join("\n\n");
const eduLines = EDU.map((e) => { const th = thesisFor(e); return `- ${stripEmoji(e.degree)}, ${eduPlace(e)}${th ? `. Thesis: "${th.title}"${th.url ? ` (${abs(th.url)})` : ""}` : ""}.`; }).join("\n");
const llmsFull = `# ${NAME}

${intro}

${prose}
${bio ? `\n## Biography\n\n${bio}\n` : ""}${EDU.length ? `\n## Education\n\n${eduLines}\n` : ""}
## Publications

${pubs.map((p) => paperMd(p, bibtexFor(p), 3)).join("\n")}
${software.length ? `\n## ${TOOLS_HEADING}\n\n${software.map((s) => `- ${s.name}: ${s.desc ? s.desc + ". " : ""}${s.kind === "tool" ? "Tool" : s.kind === "benchmark" ? "Benchmark" : "Dataset"} paper: ${s.paper.title}, ${s.paper.venueShort} (${s.paper.url}). Links: ${s.links.map(([l, u]) => `${l} ${u}`).join("; ")}.`).join("\n")}\n` : ""}${awards.length ? `\n## Awards\n\n${awardLines}\n` : ""}${teachRows.length ? `\n## Teaching and mentoring\n\n${teachRows.map(teachLine).join("\n")}\n` : ""}${talks.length ? `\n## Invited talks\n\n${talks.map(talkLine).join("\n")}\n` : ""}${researchRows.length ? `\n## Research and professional positions\n\n${researchRows.map((e) => `- ${stripEmoji(e.role)}, ${placeFmt(e.place)}${str(e.description) ? `: ${stripEmoji(e.description).replace(/[.\s]+$/, "")}.` : "."}`).join("\n")}\n` : ""}${leadership.length || serviceRows.length || reviewerLine ? `\n## Leadership and service\n\n${[
  ...mergeRoles(leadership).map((l) => `- ${stripEmoji(l.role)}, ${stripEmoji(l.organization)}${str(l.place) ? `, ${placeText(l.place)}` : ""}${l.periods.length ? ` (${l.periods.join(", ")})` : ""}${str(l.description) ? `: ${stripEmoji(l.description).replace(/[.\s]+$/, "")}.` : "."}${(Array.isArray(l.highlights) ? l.highlights : []).map((h) => `\n  - ${stripEmoji(h)}`).join("")}`),
  ...serviceRows.map((r) => `- ${r.role}${r.org ? `, ${r.org}` : ""}${r.period ? ` (${r.period})` : ""}${r.description ? `: ${r.description.replace(/[.\s]+$/, "")}.` : "."}`),
  ...(reviewerLine ? [`- ${reviewerLine}.`] : []),
].join("\n")}\n` : ""}${nominations.length ? `\n## Nominations\n\n${nominations.map((n) => `- ${stripEmoji(n.title)}: ${stripEmoji(n.description)}`).join("\n")}\n` : ""}${blogPosts.length ? `\n## Blog\n\n${blogPosts.map(blogLine).join("\n")}\n` : ""}${newsRows.length ? `\n## In the news\n\n${newsRows.map((n) => `- [${n.title}](${n.url})${n.publisher ? `, ${n.publisher}` : ""}${n.kind === "article" && n.date ? ` (${n.date})` : n.kind === "profile" ? " (lab profile page)" : ""}`).join("\n")}\n` : ""}${faqItems.length ? `\n## Research questions\n\n${faqItems.map((f) => `### ${f.q}\n\n${mdAbsLinks(f.a)}`).join("\n\n")}\n` : ""}
## Profiles

${IDS.map((i) => `- ${i.propertyID}: ${i.url}`).join("\n")}
${LLMS_PROFILES.map((p) => `- ${p.label}: ${p.url}`).join("\n")}
`;
write("llms-full.txt", llmsFull);

// ---------------------------------------------------------------- sitemap
// Image entries (xmlns:image): the portraits on the homepage, the gallery on /photos/.
const imgLocs = (list) => list.map((u) => abs(encPath(u)));
const urls = [
  [`${SITE}/`, "1.0", HOME_LASTMOD, imgLocs(HEADSHOTS.slice(0, 1).map((h) => h.u))], [`${SITE}/publications/`, "0.9", PUBS_LASTMOD],
  ...pubs.map((p) => [p.url, "0.8", paperLastmod(p)]),
  ...(faqItems.length ? [[`${SITE}/faq/`, "0.6", newest(readJson("faq", {}).updated, PUBS_LASTMOD)]] : []),
  [`${SITE}/about/`, "0.6", newest(about.updated, HOME_LASTMOD)],
  // lastmod is always a full date (YYYY-MM-DD): gallery.json `updated`, else the newest photo with
  // a full date. Year- or month-only photo dates are not a modification date, so then none is given.
  ...(photos.length ? [[`${SITE}/photos/`, "0.5", newest(readJson("gallery", {}).updated, photos.map((p) => p.date)), imgLocs(photos.map((p) => p.src))]] : []),
  // The empty blog index is noindex, so it is listed only once a post is published.
  ...(blogPosts.length ? [[`${SITE}/blog/`, "0.5", newest(blogPosts.map(blogLastmod))], ...blogPosts.map((b) => [`${SITE}/blog/${encodeURIComponent(str(b.slug) || slugify(b.title))}/`, "0.5", blogLastmod(b), imgLocs([safeUrl(b.cover)].filter(Boolean))])] : []),
  ...pubs.filter((p) => p.pdf).map((p) => [abs(p.pdf), "0.5", paperLastmod(p)]),
];
const locEsc = (u) => esc(encodeURI(safeDecode(u)));
write("sitemap.xml", `<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">
${urls.map(([u, pr, lm, imgs = []]) => `  <url><loc>${locEsc(u)}</loc>${lm ? `<lastmod>${lm}</lastmod>` : ""}<priority>${pr}</priority>${[...new Set(imgs)].map((i) => `<image:image><image:loc>${locEsc(i)}</image:loc></image:image>`).join("")}</url>`).join("\n")}
</urlset>
`);
// Stale files from older builds that are no longer generated.
for (const f of ["sitemap-index.xml", "about.html", "_redirects"]) rmSync(path.join(DIST, f), { force: true });

console.log(`  [seo] wrote ${pubs.length} paper pages, publications index, ${faqItems.length ? "FAQ, " : ""}about, ${photos.length ? `photos (${photos.length}), ` : ""}404, maintenance, llms.txt, llms-full.txt, sitemap (${urls.length} URLs)`);
