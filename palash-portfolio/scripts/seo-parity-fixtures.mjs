// Content fixtures for scripts/seo-parity.mjs. Each `apply(c, { addFile })` mutates a copy of
// src/content (section name -> parsed JSON); addFile(rel, fromRel) adds a file to the dist both
// renderers see (e.g. an image that exists only in /uploads). All strings here are made up.
const items = (c, s) => (c[s] && Array.isArray(c[s].items) ? c[s].items : (c[s] = { items: [] }).items);
const HOSTILE = `<script>alert(1)</script> "quoted" & 'single' $' $& $1   line sep </script> <img src=x onerror=alert(1)>`;
const LONG = "Mixed Unicode: ünïcödé ✓ — en–dash “curly” café 🙂 👩‍💻 🇨🇦 © ® ™  nbsp  and astral 𝒜𝒷𝒸 characters placed near the meta description cut so the UTF-16 length matters here 𝒜𝒷𝒸𝒹𝑒𝒻 end.";

export const CORPUS = [
  "", " ", "0", "false", "plain text", "  padded   ", "﻿lead", "Title: With Colon? And more", "A sentence. Another one! Third?", "No end",
  "Ünïcödé Ça Va", "İstanbul DOTTED", "ǅungla", "🙂 smile", "👩‍💻 dev", "🇨🇦 flag", "©®™ marks", "keycap 1️⃣", "‼ double",
  "x".repeat(170), "word ".repeat(40), LONG, LONG + LONG, HOSTILE, "a&b<c>d\"e", "javascript:alert(1)", "/images/a b.jpg", "/images/Plaza de Bolívar.jpg",
  "%E0%A4%A", "%2F%3F%23", "/x%20y", "100%", "https://x.com/Name_1?x=1#h", "2026-11-21", "2026-11", "2026", "2026-11-21T10:00:00Z", "Nov 2026",
  "Palash Ranjan Roy", "P. R. Roy", "Roy, Palash", "roy, p. r.", "Palash Roy", "palash  r.  roy", "Ajmain Inqiad Alam",
  "tab\there", "new\nline", "cr\rhere", "line sep", "　ideographic　", "zero​width", "a­b",
];

const paper = (o) => ({
  title: "A Study of Things", authors: "P. R. Roy and A. Other", venue: "Proceedings of the 1st Workshop on Things (WOT)", year: "2026", doi: "10.1145/0000000.0000001",
  authors_full: ["Palash Ranjan Roy", "Alex Other"], abstract: "We study things. Things are studied.\n\nSecond paragraph.", ...o,
});

export const FIXTURES = [
  { name: "baseline", apply: () => {} },
  { name: "new-paper", apply: (c) => {
    const p = items(c, "publications");
    p.unshift(paper({ title: "Brand New Paper: Live Pages Without Rebuilds", slug: "brand-new-paper", type: "conference", venue: "IEEE International Conference on Software Maintenance and Evolution (ICSME)", venue_short: "ICSME 2026",
      published_date: "2026-11-21", pages: "pp. 10–20", publisher: "IEEE", pdf: "/papers/CTT.pdf", selected: true, tags: ["Code Clones", "AI4SE"], ml_topics: ["Code Language Models"], arxiv: "2611.01234v2",
      equal_contribution: ["Palash Ranjan Roy", "Alex Other"], slides_url: "/uploads/slides.pdf", video: "https://youtu.be/abcdefghijk", code_url: "https://github.com/x/BrandTool", tool_url: "https://github.com/x/BrandTool", relevant_to: ["clone research"], keywords: ["things"], problem: "Pages went stale until the next build.", my_role: "Lead author", venue_rank: "CORE A" }));
    p.push(paper({ title: "A Doctoral Thesis on Clones", slug: "phd-thesis", type: "thesis", doi: "", venue: "University of Saskatchewan", degree: "Doctor of Philosophy", handle: "10388/99999", year: "2028", authors_full: ["Palash Ranjan Roy"] }));
    p.push(paper({ title: "Poster of the New Paper", slug: "brand-new-paper-poster", type: "poster", companion_of: "brand-new-paper", venue: "ICSME 2026 Posters", doi: "", arxiv: "2611.01235" }));
    p.push(paper({ title: "Journal Thing", slug: "journal-thing", type: "journal", venue: "ACM Transactions on Things", volume: "3", issue: "2", issn: "1234-5678", first_page: "1", last_page: "30", published_date: "2025-01-02" }));
  } },
  { name: "deleted-paper", apply: (c) => { const p = items(c, "publications"); p.splice(1, 1); } },
  { name: "renamed-slug", apply: (c) => { items(c, "publications")[0].slug = "renamed-paper-slug"; } },
  { name: "invalid-paper", note: "no venue: both must refuse", apply: (c) => { items(c, "publications").push(paper({ title: "Half Finished", slug: "half-finished", venue: "" })); } },
  { name: "no-doi-paper", note: "accepted paper without DOI, short venue or summary (warning only)", apply: (c) => {
    items(c, "publications").unshift(paper({ title: "Accepted Paper Without a DOI Yet", slug: "", doi: "", venue: "Owner Journey Conference", abstract: "", pdf: "/uploads/doc-1.pdf" }));
    items(c, "publications").push(paper({ title: "Year In Venue", slug: "year-in-venue", doi: "", arxiv: "", venue: "Workshop 2026 on Things", abstract: "" }));
  } },
  { name: "unsafe-links", note: "javascript:, //host and /\\host links; impossible dates; control characters in a post slug", apply: (c) => {
    items(c, "faq").push({ q: "Links?", a: "[js](javascript:alert(1)) [JS](JaVaScRiPt:alert(1)) [proto](//evil.example/x) [bs](/\\evil.example/x) [ok](/publications/) [mail](mailto:a@b.c) [hash](#top) [data](data:text/html,x)" });
    c.headings.publicationsPage = { ...(c.headings.publicationsPage || {}), intro: "See [x](javascript:alert(1)) and [y](/\\evil.example)." };
    c.documents = { items: [{ label: "CV", url: "/\\evil.example/cv.pdf", kind: "cv" }, { label: "Statement", url: "/uploads/s.pdf", kind: "research_statement" }] };
    const b = items(c, "blog");
    b.push({ title: "Tab Post", slug: "tab\tpost", date: "2026-02-31", body: "x" }, { title: "Del Post", slug: "del\u007fpost", date: "2026-11-01", body: "x" }, { title: "Bad Date", slug: "bad-date", date: "2062-13-01", summary: "s", body: "x" });
    items(c, "news").unshift({ date: "2026-02-30", title: "Impossible date news" });
    items(c, "publications")[0].published_date = "2026-04-31";
  } },
  { name: "awards", apply: (c) => {
    const h = items(c, "highlights");
    h.unshift({ title: "Best Thing Award (2026)", organization: "Thing Society", year: "2026", date: "2026-11-20", kind: "award", description: "For things." });
    h.push({ title: "Nominated for a Teaching Award", organization: "USask", kind: "nomination", description: "Nominated for teaching." });
    h.push({ title: "Award (IWSC 2024)", organization: "Workshop (IWSC 2024), Somewhere", year: "2024" });
    h.push({ title: "People's Choice Best Paper Award and Student Encouragement Award (IWSC 2023)", organization: "IWSC" });
  } },
  { name: "sections", apply: (c) => {
    items(c, "news").unshift({ date: "2026-11-21", title: "A new thing happened", description: "Details." });
    const s = items(c, "service");
    s.push({ category: "talk", role: "Invited Talk (Guest Speaker)", description: "Talking About Things", venue: "Some University", period: "Nov 2026", date: "2026-11-02", link: "https://example.org/talk" });
    s.push({ category: "supervision", role: "Mentor", description: "Mentored a student", venue: "SRLab", period: "May 2024 - Aug 2024", order: "1" });
    s.push({ category: "review", role: "Reviewer", venues: "ICSME, SANER", period: "2024 – 2026" });
    s.push({ category: "organizing", role: "Organizer", description: "Organized a thing.", details: "With friends", venue: "Thing Conf", period: "2025" });
    items(c, "leadership").push({ role: "President", organization: "Some Society", place: "Saskatoon | 2024 - Present", period: "2024 - Present", description: "Led things.", highlights: ["One", "Two"], link: "https://example.org" });
    c.about.inTheNews = [...(c.about.inTheNews || []), { title: "A profile page", url: "https://example.org/people", kind: "profile" }, { title: "News story", url: "https://news.example.org/a", publisher: "News Org", date: "2026-10-01" }];
  } },
  { name: "faq-empty", apply: (c) => { c.faq = { items: [] }; } },
  { name: "faq-new", apply: (c) => { items(c, "faq").push({ q: "What is a **new** question?", a: "An answer with a [link](/publications/mergese/) and **bold**.\n\nSecond [ext](https://example.org/a?b=1&c=2) paragraph; unknown /publications/nope/ link." }); } },
  { name: "gallery-empty", apply: (c) => { c.gallery = { items: [] }; } },
  { name: "gallery-upload", apply: (c, { addFile }) => {
    addFile("uploads/img-abc123.jpg", "images/gsa_gala/gala-final-speech.jpg");
    addFile("uploads/pic two é.png", "images/placeholder-man.png");
    items(c, "gallery").unshift({ image: "/uploads/img-abc123.jpg", title: "Uploaded Photo", description: "Uploaded in the admin.", featuresOwner: true, date: "2026-11-21", creditText: "A Friend" });
    items(c, "gallery").push({ image: "/uploads/pic%20two%20%C3%A9.png", title: "", altText: "", description: "", date: "2025-03" });
    items(c, "gallery").push({ image: "/uploads/missing.jpg", title: "Missing file" });
  } },
  { name: "blog", apply: (c) => {
    const b = items(c, "blog");
    b.push({ title: "First Real Post 🙂", slug: "first-real-post", date: "2026-11-20", summary: "A summary.", cover: "/images/palash_roy.jpg", tags: "meta, research", body: "Hello **world**." });
    b.push({ title: "Second post", date: "2026-11-21", updated: "2026-11-22", tags: ["a", "b"], body: "x" });
    b.push({ title: "Café Unicode", slug: "café", date: "2026-01-01", body: "y" });
    b.push({ title: "Bad slug", slug: "a/b", date: "2026-01-02" });
  } },
  { name: "socials-hidden-x", apply: (c) => { for (const s of c.socials.links) if (s.platform === "x") s.hidden = "yes"; c.socials.links.push({ label: "My Website", url: "https://example.org/?hl=en" }); } },
  { name: "socials-none", apply: (c) => { c.socials = { links: [] }; } },
  { name: "documents-cv", apply: (c) => { c.documents = { items: [{ label: "", url: "/uploads/cv.pdf", kind: "cv" }, { label: "Research Statement", url: "/uploads/rs.pdf", kind: "research_statement" }, { label: "Bad", url: "javascript:alert(1)", kind: "other" }] }; } },
  { name: "resume-setting", apply: (c) => { c.settings.resumeUrl = "/uploads/resume.pdf"; c.documents = { items: [] }; } },
  { name: "hero-photo", apply: (c) => { c.hero.photo = "/images/palash_roy.jpg"; } },
  { name: "settings-seo", apply: (c) => { c.settings.seo = { title: "Custom Home Title & More", description: "Custom \"description\" <b>", ogTitle: "OG title", ogDescription: "", twitterTitle: "TW", twitterDescription: "TWD", image: "/images/palash_roy.jpg", imageAlt: "Alt text" }; } },
  { name: "settings-seo-ext-image", apply: (c) => { c.settings.seo = { image: "https://example.org/x.jpg" }; } },
  { name: "themes-missing", apply: (c) => { delete c.research.themes; delete c.research.also; } },
  { name: "no-tags", apply: (c) => { for (const p of items(c, "publications")) { delete p.tags; delete p.ml_topics; } } },
  { name: "vocab-strings", apply: (c) => { c.settings.tagVocabulary = ["Code Clones", "AI4SE", { tag: "SE4AI", label: "" }]; c.settings.areaTags = "Code Clones; AI4SE"; c.settings.mlTopicVocabulary = []; c.settings.themePrompt = false; } },
  { name: "headings", apply: (c) => { c.headings.faq = { nav: "Questions", title: "Questions & Answers", subtitle: "See [papers](/publications/)." }; c.headings.photos = { nav: "Pictures", title: "Pictures" }; c.headings.blog = { ...(c.headings.blog || {}), hidden: true }; c.footer = { ...c.footer, copyright: "© Someone 🙂", name: "Footer Name", showSocials: false }; } },
  { name: "expected-iso", apply: (c) => { const e = (c.about.education || []).find((x) => /ph\.?\s*d/i.test(x.degree)); if (e) e.expected = "2028-08"; c.hero.line2 = "Researcher at the University of Somewhere"; } },
  { name: "hostile-strings", apply: (c) => {
    const p = items(c, "publications");
    p[0].title = "Hostile " + HOSTILE; p[0].abstract = HOSTILE + "\n\n" + LONG; p[0].description = LONG; p[0].venue_short = "V<1> & \"2\""; p[0].award = "Award $' & <b>";
    items(c, "faq").push({ q: HOSTILE, a: HOSTILE + " [x](javascript:alert(1)) [y](/a\"b)" });
    items(c, "gallery").push({ image: "/images/palash_roy.jpg?x=<y>", title: HOSTILE, description: LONG, altText: HOSTILE });
    c.about.paragraphs = [HOSTILE, LONG, "**bold** [l](/x) [abs](https://e.org/?a=1&b=2)"];
    items(c, "highlights").push({ title: HOSTILE + " (2025)", organization: "<Org>" });
    c.headings.blog = { title: "Blog " + HOSTILE, subtitle: HOSTILE };
  } },
  { name: "unicode", apply: (c) => {
    const p = items(c, "publications");
    p[1].title = LONG; p[1].problem = LONG + LONG; p[2].abstract = "Über café. Naïve résumé! " + LONG;
    items(c, "news").push({ title: "🙂", date: "2026" });
    c.software = { items: [{ name: "Tool 🙂 X", paper_slug: p[0].slug, url: "https://example.org/tool", code_url: "https://github.com/x/y", dataset_url: "https://zenodo.org/records/1", artifact_url: "https://doi.org/10.5281/zenodo.2", description: LONG + " " + LONG + ". " + LONG, kind: "benchmark" }, { name: "Orphan", paper_slug: "no-such-paper", url: "https://example.org" }] };
  } },
  { name: "loose-types", note: "numbers, strings for lists, inferred types", apply: (c) => {
    const p = items(c, "publications");
    p.push({ title: "Inferred Journal Paper", authors: "A. Person, P. R. Roy and B. Person et al.", venue: "Proceedings of the ACM on Software Engineering", year: 2025, doi: "https://doi.org/10.1145/1234567.8901234", pages: "12--15", tags: "Code Clones; Refactoring, Unknown Tag", ml_topics: "Model Merging; Something", equal_contribution: "A. Person, Palash Roy", display_keywords: "", award: "Distinguished Paper Award 🏆" });
    p.push({ title: "Workshop Thing?", authors: "P. Roy", venue: "2nd Workshop on Stuff", year: "2024", arxiv: "arXiv:2401.00001", preprint: "https://arxiv.org/abs/2401.00001", slides_url: "-", video: "none", published_date: "2024-05-06" });
    p.push({ title: "Doctoral Symposium Paper", authors_full: ["Palash Ranjan Roy"], venue: "ICSE 2027 Doctoral Symposium", year: "2027", doi: "10.1109/ICSE.2027.1", track: "", display_keywords: ["One", "Two", "Three", "Four", "Five"] });
    items(c, "highlights").push({ title: 2025, year: 2025 });
    c.settings.themePromptOff = "yes";
  } },
  // ---- admin-editable identity, SEO texts, headings, footer, documents, profile photo (round C)
  { name: "identity-defaults", note: "every default stated explicitly (as the Identity panel saves it)", apply: (c) => {
    c.identity = { name: "Palash Ranjan Roy", givenName: "Palash", additionalName: "Ranjan", familyName: "Roy", alternateNames: ["Palash Roy", "Palash R. Roy", "Palash R Roy", "P. R. Roy", "পলাশ রঞ্জন রায়"],
      publishedAs: "Palash Roy / Palash R. Roy", showPublishedAs: true, orcid: "0000-0001-9470-4233", email: "palash.roy@usask.ca", jobTitle: "PhD Researcher in Computer Science", pronoun: "he",
      affiliation: [{ id: "usask", type: "CollegeOrUniversity", name: "University of Saskatchewan", url: "https://www.usask.ca/", sameAs: "https://www.wikidata.org/wiki/Q1514848" }],
      memberOf: [{ name: "Software Research Lab (SRLab)", url: "https://srlab.usask.ca/" }, { name: "Interactive Software Engineering and Analytics (iSE) Lab", url: "https://ise.usask.ca/" }],
      alumniOf: [{ id: "brac", name: "BRAC University", url: "https://www.bracu.ac.bd/", sameAs: "https://www.wikidata.org/wiki/Q4836325" }, { name: "University of Saskatchewan" }] };
  } },
  { name: "identity-custom", apply: (c) => {
    c.identity = { name: "Dr. Pat Q. Example", givenName: "Pat", additionalName: "Q.", familyName: "Example", alternateNames: ["Pat Example", "P. Q. Example", "P Q Example", "Patricia Example", "パット"],
      publishedAs: "Pat Example", orcid: "0000-0002-1825-009X", email: "pat@example.org", jobTitle: "Assistant Professor of Computer Science", pronoun: "she",
      summary: "Assistant professor working on code clones.", labsLine: "She leads the Clone Lab at Example University.", disambiguation: "Disambiguation: ORCID 0000-0002-1825-009X.",
      identifiers: [{ propertyID: "ORCID", value: "0000-0002-1825-009X", url: "https://orcid.org/0000-0002-1825-009X" }, { propertyID: "Staff ID", value: "12345" }, { propertyID: "", value: "x" }, "junk"],
      affiliation: [{ name: "Example University", url: "https://example.edu/", sameAs: "https://www.wikidata.org/wiki/Q1" }, { name: "" }],
      memberOf: [{ name: "Clone Lab", url: "javascript:alert(1)" }], alumniOf: [{ name: "Example University" }, { name: "Old College", type: "CollegeOrUniversity" }] };
    c.settings.seo = { websiteDescription: "Site of Pat.", knowsAbout: ["Clones", "  ", 5, "Testing"] };
    items(c, "publications").push(paper({ title: "Pat's Thesis", slug: "pat-thesis", type: "thesis", doi: "", venue: "Example University", handle: "10388/1", authors_full: ["Pat Example"], authors: "Pat Example" }));
  } },
  { name: "identity-hidden-pub", apply: (c) => { c.identity = { showPublishedAs: false, alternateNames: ["Only One"], pronoun: "they", email: "bad email", orcid: "1234" }; } },
  { name: "identity-hostile", apply: (c) => { c.identity = { name: HOSTILE, alternateNames: [HOSTILE, LONG], publishedAs: HOSTILE, jobTitle: LONG, summary: HOSTILE, labsLine: LONG, identifiers: [{ propertyID: HOSTILE, value: "<v>", url: "https://e.org/\"x" }], affiliation: [{ name: HOSTILE, url: "https://e.org/a?b=1&c=2" }] }; c.hero.photoAlt = HOSTILE; } },
  { name: "seo-pages", apply: (c) => {
    c.settings.seo = { imageAlt: "Sharing alt", pages: { publications: { title: "All Papers | Pat", ogTitle: "OG papers", description: "Pub desc " + LONG }, faq: { title: "FAQ T", ogTitle: "FAQ OG", description: "FAQ D" },
      about: { title: "About T", ogTitle: "About OG", description: "About D" }, photos: { title: "Photos T", description: "Photos D" }, blog: { title: "Blog T", description: "Blog D" } } };
  } },
  { name: "headings-new", apply: (c) => {
    c.headings.portfolio = { ...c.headings.portfolio, title: "Papers", pageIntro: "Papers by **{name}** ({publishedAs}, ORCID {orcid}); see [FAQ](/faq/)." };
    c.headings.notFound = { title: "Nothing here", description: "Gone.", text: "Try the [home page](/) or write to {email}." };
    c.headings.blog = { ...(c.headings.blog || {}), emptyTitle: "No posts yet" };
    c.footer = { ...c.footer, copyright: "© {year} {name} · All rights reserved", cvLabel: "Curriculum vitae", cvNavLabel: "Résumé" };
    c.settings.maintenance = { on: false, title: "Down for upgrades" };
    c.documents = { items: [{ label: "CV", url: "/uploads/doc-cv.pdf", kind: "cv" }, { label: "EDI Statement", url: "/uploads/edi.pdf", kind: "edi_statement" }, { label: "Hidden one", url: "/uploads/h.pdf", kind: "other", hidden: true }, { label: "", url: "/uploads/tp.pdf", kind: "teaching_portfolio" }] };
  } },
  { name: "cv-not-pdf", apply: (c) => { c.documents = { items: [{ label: "CV", url: "https://example.org/cv", kind: "cv" }] }; } },
  { name: "photo-set", apply: (c, { addFile }) => {
    for (const w of [200, 240, 480, 640]) addFile(`uploads/headshot-abc-${w}w.webp`, "images/palash_roy_headshot.webp");
    addFile("uploads/headshot-abc.jpg", "images/palash_roy_headshot.jpg");
    c.hero.photo = "/uploads/headshot-abc.jpg";
    c.hero.photoAlt = "Portrait of the owner";
    c.hero.photoSet = { jpg: "/uploads/headshot-abc.jpg", w: 640, h: 552, type: "image/webp", srcset: [200, 240, 480, 640].map((w) => ({ w, url: `/uploads/headshot-abc-${w}w.webp` })).concat([{ w: "9", url: "/x.webp" }, { w: 10, url: "javascript:1" }]) };
  } },
  { name: "photo-set-stale", note: "hero.photo replaced by hand: the old set is ignored", apply: (c) => { c.hero.photo = "/images/palash_roy.jpg"; c.hero.photoSet = { jpg: "/uploads/old.jpg", srcset: [{ w: 200, url: "/uploads/old-200w.webp" }] }; } },
  { name: "name-change-home", note: "homepage shell carries the build name", apply: (c) => { c.identity = { name: "Another Name", email: "another@example.org", givenName: "Another", familyName: "Name" }; } },
  { name: "empty-content", note: "almost nothing in the database", apply: (c) => {
    for (const s of ["highlights", "news", "service", "leadership", "gallery", "faq", "blog", "software"]) c[s] = { items: [] };
    c.about = {}; c.hero = {}; c.headings = {}; c.footer = {}; c.settings = {}; c.research = {}; c.socials = {}; c.documents = { items: [] };
    c.publications = { items: [items(c, "publications")[0]] };
  } },
];
