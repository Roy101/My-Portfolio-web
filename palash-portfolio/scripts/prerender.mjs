// Prerender the built SPA to static HTML so crawlers and AI engines (which do not
// run JavaScript) get the full content. Runs after `vite build`, in local and CI builds.
// Also prerenders the blog: /blog and one static page per published post.
import http from "http";
import { readFile, writeFile, mkdir, rm } from "fs/promises";
import path from "path";
import puppeteer from "puppeteer";

const DIST = path.resolve("dist");
const SITE = "https://palashroy.me";
const PORT = 8123;
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

await new Promise((r) => server.listen(PORT, r));

const browser = await puppeteer.launch({
  headless: "new",
  args: ["--no-sandbox", "--disable-gpu", "--disable-dev-shm-usage"],
});

async function snap(route, outFile) {
  const page = await browser.newPage();
  await page.setViewport({ width: 1280, height: 1000 });
  await page.goto(`http://localhost:${PORT}${route}`, { waitUntil: "networkidle0" });
  await new Promise((r) => setTimeout(r, 1200));
  for (let y = 0; y <= 9000; y += 700) {
    await page.evaluate((v) => window.scrollTo(0, v), y);
    await new Promise((r) => setTimeout(r, 70));
  }
  // Strip any analytics tags injected by the lazy-loader during prerender.
  await page.evaluate(() => {
    document.querySelectorAll('script[src*="googletagmanager.com"], script[src*="google-analytics.com"]').forEach((s) => s.remove());
  });
  const html = await page.content();
  await mkdir(path.dirname(outFile), { recursive: true });
  await writeFile(outFile, html);
  await page.close();
  return html.length;
}

// 1) Homepage
const homeBytes = await snap("/", path.join(DIST, "index.html"));
console.log(`  prerendered index.html (${homeBytes} bytes)`);

// 2) Blog — read the baked posts to know which URLs to generate.
const slugify = (s) => String(s || "").toLowerCase().trim().replace(/[^a-z0-9]+/g, "-").replace(/^-+|-+$/g, "");
const isDraft = (p) => { const d = String(p?.draft ?? "").toLowerCase(); return p?.draft === true || d === "yes" || d === "true" || d === "1"; };
let posts = [];
try {
  const raw = JSON.parse(await readFile(path.resolve("src/content/blog.json"), "utf8"));
  posts = (Array.isArray(raw.items) ? raw.items : []).filter((p) => p && (p.slug || p.title) && !isDraft(p));
} catch { /* no blog content */ }

const blogUrls = [];
// Clear any previously generated blog pages so stale/empty files are never served
// as the SPA shell during this run.
await rm(path.join(DIST, "blog"), { recursive: true, force: true });
if (posts.length) {
  await snap("/blog/", path.join(DIST, "blog", "index.html"));
  blogUrls.push(`${SITE}/blog`);
  for (const p of posts) {
    const slug = (p.slug && p.slug.trim()) ? p.slug.trim() : slugify(p.title);
    await snap(`/blog/${slug}/`, path.join(DIST, "blog", slug, "index.html"));
    blogUrls.push(`${SITE}/blog/${slug}`);
  }
  console.log(`  prerendered blog: /blog + ${posts.length} post(s)`);
}

// 3) Add the blog URLs to sitemap.xml (dist copy) for discovery.
if (blogUrls.length) {
  try {
    const smPath = path.join(DIST, "sitemap.xml");
    let sm = await readFile(smPath, "utf8");
    if (!sm.includes("/blog</loc>") && sm.includes("</urlset>")) {
      const today = new Date().toISOString().slice(0, 10);
      const entries = blogUrls.map((u) =>
        `  <url>\n    <loc>${u}</loc>\n    <lastmod>${today}</lastmod>\n    <changefreq>monthly</changefreq>\n    <priority>0.7</priority>\n  </url>`
      ).join("\n");
      sm = sm.replace("</urlset>", `${entries}\n</urlset>`);
      await writeFile(smPath, sm);
      console.log(`  added ${blogUrls.length} blog URL(s) to sitemap.xml`);
    }
  } catch { /* sitemap optional */ }
}

await browser.close();
server.close();
