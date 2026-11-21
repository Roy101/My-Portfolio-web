// Prerender the built SPA to static HTML so crawlers and AI engines (which do not
// run JavaScript) get the full content. Runs after `vite build`, in local and CI builds.
// Also prerenders the blog: /blog (always, so the index with its "coming soon" empty state is
// a real page) and one static page per published post. Posts added in the CMS after a build
// are still served by the React app through the .htaccess blog fallback.
// Order in the build: vite build -> prerender.mjs -> generate-seo.mjs. This script only
// writes dist/index.html and dist/blog/**; the paper pages, FAQ, about page, sitemap and
// llms files are generated afterwards by generate-seo.mjs (which also fills the
// <!--SEO-GRAPH--> placeholder these snapshots keep).
import http from "http";
import { readFile, writeFile, mkdir, rm } from "fs/promises";
import path from "path";
import puppeteer from "puppeteer";

const DIST = path.resolve(process.env.DIST || "dist");
const SITE = "https://palashroy.me";
// Any free port (several builds may run on one machine).
let PORT = 0;
const MIME = {
  ".html": "text/html", ".js": "text/javascript", ".css": "text/css",
  ".json": "application/json", ".jpg": "image/jpeg", ".jpeg": "image/jpeg",
  ".png": "image/png", ".svg": "image/svg+xml", ".webp": "image/webp",
  ".pdf": "application/pdf", ".xml": "application/xml", ".txt": "text/plain",
  ".ico": "image/x-icon", ".woff2": "font/woff2",
};

// Serve dist; fall back to index.html for extensionless routes (so /blog/<slug>
// loads the SPA shell and the client renders the post before we capture it).
const server = http.createServer(async (req, res) => {
  const original = decodeURIComponent(req.url.split("?")[0]);
  const u = original.endsWith("/") ? original + "index.html" : original;
  try {
    const data = await readFile(path.join(DIST, u));
    res.writeHead(200, { "Content-Type": MIME[path.extname(u)] || "application/octet-stream" });
    res.end(data);
  } catch {
    // A route (no real file extension on the original path) → serve the SPA shell
    // so the client can render it. Missing real assets (.js/.png/…) still 404.
    if (!path.extname(original.replace(/\/$/, ""))) {
      try {
        const shell = await readFile(path.join(DIST, "index.html"));
        res.writeHead(200, { "Content-Type": "text/html" });
        res.end(shell);
        return;
      } catch { /* fall through */ }
    }
    res.writeHead(404); res.end();
  }
});

await new Promise((r) => server.listen(0, "127.0.0.1", r));
PORT = server.address().port;

const browser = await puppeteer.launch({
  headless: "new",
  args: ["--no-sandbox", "--disable-gpu", "--disable-dev-shm-usage"],
});

async function snap(route, outFile, canonical, robots) {
  const page = await browser.newPage();
  await page.setViewport({ width: 1280, height: 1000 });
  await page.goto(`http://localhost:${PORT}${route}`, { waitUntil: "networkidle0" });
  await new Promise((r) => setTimeout(r, 1200));
  for (let y = 0; y <= 9000; y += 700) {
    await page.evaluate((v) => window.scrollTo(0, v), y);
    await new Promise((r) => setTimeout(r, 70));
  }
  // Back to the top, so the snapshot shows no "current section" (aria-current) in the nav:
  // a visitor lands on the hero, and React keeps server attributes it agrees with.
  await page.evaluate(() => window.scrollTo(0, 0));
  await new Promise((r) => setTimeout(r, 150));
  // Strip any analytics tags injected by the lazy-loader during prerender.
  await page.evaluate(() => {
    document.querySelectorAll('[aria-current="location"]').forEach((el) => el.removeAttribute("aria-current"));
    document.querySelectorAll('script[src*="googletagmanager.com"], script[src*="google-analytics.com"]').forEach((s) => s.remove());
    // Don't bake the headless browser's theme into the HTML; the inline script in
    // <head> sets data-theme per visitor (saved choice, else the OS preference).
    document.documentElement.removeAttribute("data-theme");
  });
  // Each snapshot is its own page: point canonical / og:url at its own URL (the shell
  // they are captured from carries the homepage values).
  if (canonical) {
    await page.evaluate((u) => {
      document.querySelector('link[rel="canonical"]')?.setAttribute("href", u);
      document.querySelector('meta[property="og:url"]')?.setAttribute("content", u);
    }, canonical);
  }
  // An empty blog index is kept out of search results until there is something to read.
  if (robots) {
    await page.evaluate((v) => {
      let m = document.querySelector('meta[name="robots"]');
      if (!m) { m = document.createElement("meta"); m.setAttribute("name", "robots"); document.head.appendChild(m); }
      m.setAttribute("content", v);
    }, robots);
  }
  const html = await page.content();
  await mkdir(path.dirname(outFile), { recursive: true });
  await writeFile(outFile, html);
  await page.close();
  return html.length;
}

// 1) Homepage
const homeBytes = await snap("/", path.join(DIST, "index.html"), `${SITE}/`);
console.log(`  prerendered index.html (${homeBytes} bytes)`);

// 2) Blog — read the baked posts to know which URLs to generate.
const slugify = (s) => String(s || "").toLowerCase().trim().replace(/[^a-z0-9]+/g, "-").replace(/^-+|-+$/g, "");
const isDraft = (p) => { const d = String(p?.draft ?? "").toLowerCase(); return p?.draft === true || d === "yes" || d === "true" || d === "1"; };
let posts = [];
try {
  const raw = JSON.parse(await readFile(path.resolve("src/content/blog.json"), "utf8"));
  posts = (Array.isArray(raw.items) ? raw.items : []).filter((p) => p && (p.slug || p.title) && !isDraft(p));
} catch { /* no blog content */ }

// Clear any previously generated blog pages so stale/empty files are never served
// as the SPA shell during this run.
await rm(path.join(DIST, "blog"), { recursive: true, force: true });
await snap("/blog/", path.join(DIST, "blog", "index.html"), `${SITE}/blog/`, posts.length ? "" : "noindex, follow");
for (const p of posts) {
  const slug = (p.slug && p.slug.trim()) ? p.slug.trim() : slugify(p.title);
  if (!/^[^/\\?#]+$/.test(slug) || slug === "." || slug === "..") { console.warn(`  skipped blog post with unusable slug "${slug}"`); continue; }
  await snap(`/blog/${encodeURIComponent(slug)}/`, path.join(DIST, "blog", slug, "index.html"), `${SITE}/blog/${encodeURIComponent(slug)}/`);
}
console.log(`  prerendered blog: /blog${posts.length ? ` + ${posts.length} post(s)` : " (no published posts: empty state, noindex)"}`);
// Blog URLs are added to sitemap.xml by generate-seo.mjs.

await browser.close();
server.close();
