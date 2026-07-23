// Prerender the built SPA to static HTML so crawlers and AI engines (which do not
// run JavaScript) get the full content. Runs after `vite build`, in local and CI builds.
import http from "http";
import { readFile, writeFile } from "fs/promises";
import path from "path";
import puppeteer from "puppeteer";

const DIST = path.resolve("dist");
const PORT = 8123;
const MIME = {
  ".html": "text/html", ".js": "text/javascript", ".css": "text/css",
  ".json": "application/json", ".jpg": "image/jpeg", ".jpeg": "image/jpeg",
  ".png": "image/png", ".svg": "image/svg+xml", ".webp": "image/webp",
  ".pdf": "application/pdf", ".xml": "application/xml", ".txt": "text/plain",
  ".ico": "image/x-icon", ".woff2": "font/woff2",
};

const server = http.createServer(async (req, res) => {
  let u = decodeURIComponent(req.url.split("?")[0]);
  if (u.endsWith("/")) u += "index.html";
  try {
    const data = await readFile(path.join(DIST, u));
    res.writeHead(200, { "Content-Type": MIME[path.extname(u)] || "application/octet-stream" });
    res.end(data);
  } catch {
    res.writeHead(404); res.end();
  }
});

await new Promise((r) => server.listen(PORT, r));

const browser = await puppeteer.launch({
  headless: "new",
  args: ["--no-sandbox", "--disable-gpu", "--disable-dev-shm-usage"],
});
const page = await browser.newPage();
await page.setViewport({ width: 1280, height: 1000 });
await page.goto(`http://localhost:${PORT}/`, { waitUntil: "networkidle0" });
await new Promise((r) => setTimeout(r, 1200));
// scroll to trigger lazy-loaded carousel content
for (let y = 0; y <= 9000; y += 700) {
  await page.evaluate((v) => window.scrollTo(0, v), y);
  await new Promise((r) => setTimeout(r, 70));
}
const html = await page.content();
await writeFile(path.join(DIST, "index.html"), html);
await browser.close();
server.close();
console.log(`  prerendered index.html (${html.length} bytes)`);
