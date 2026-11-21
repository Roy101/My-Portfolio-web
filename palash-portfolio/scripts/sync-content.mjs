// Pull the latest content from the live database so the prerendered HTML + structured
// data reflect what's in /admin. Fail-safe: if the site/API is unreachable, keep the
// committed files. Runs first in the build.
// Set SKIP_SYNC=1 to build from the committed src/content/*.json without contacting the site.
import { writeFileSync } from "fs";
import { fileURLToPath } from "url";

const API = "https://palashroy.me/api/content.php";
const dir = fileURLToPath(new URL("../src/content/", import.meta.url));
const LIST = ["publications", "highlights", "news", "media", "gallery", "leadership", "service", "references", "blog", "faq"];

if (process.env.SKIP_SYNC === "1") {
  console.log("  content sync skipped (SKIP_SYNC=1; using committed files)");
  process.exit(0);
}

try {
  const res = await fetch(API, { headers: { "Cache-Control": "no-cache" } });
  if (!res.ok) throw new Error("HTTP " + res.status);
  const d = await res.json();
  if (!d || d.error) throw new Error("bad payload");
  let n = 0;
  for (const s of LIST) {
    // List sections arrive as arrays; accept an {items: [...]} object too (e.g. faq).
    const list = Array.isArray(d[s]) ? d[s] : Array.isArray(d[s]?.items) ? d[s].items : null;
    // Never replace committed content with an empty list from a section the DB lacks.
    if (list && (list.length || s !== "faq")) {
      writeFileSync(dir + s + ".json", JSON.stringify({ items: list }, null, 2) + "\n");
      n++;
    }
  }
  for (const obj of ["metrics", "hero", "about", "settings", "headings", "research", "socials", "footer", "consultation"]) {
    if (d[obj] && typeof d[obj] === "object" && !Array.isArray(d[obj])) {
      // In "auto" metrics mode, keep the OpenAlex numbers fetched by fetch-metrics.mjs.
      if (obj === "metrics" && d.metrics.auto === true) continue;
      // Never bake server-side secrets (e.g. the maintenance bypass token) into the public bundle.
      if (obj === "settings") {
        const scrub = (o) => { for (const k of Object.keys(o || {})) if (/token|secret|bypass|password/i.test(k)) delete o[k]; };
        scrub(d.settings);
        if (d.settings.maintenance && typeof d.settings.maintenance === "object") scrub(d.settings.maintenance);
      }
      writeFileSync(dir + obj + ".json", JSON.stringify(d[obj], null, 2) + "\n");
    }
  }
  // Round-2 sections stored as objects: documents/software {items:[...]}, skills {groups:[...]}.
  // An array from the DB is wrapped; a section the DB does not have keeps the committed file.
  for (const [obj, key] of [["documents", "items"], ["software", "items"], ["skills", "groups"]]) {
    const v = d[obj];
    const list = Array.isArray(v) ? v : Array.isArray(v?.[key]) ? v[key] : null;
    if (!list) continue;
    writeFileSync(dir + obj + ".json", JSON.stringify(Array.isArray(v) ? { [key]: list } : v, null, 2) + "\n");
    n++;
  }
  console.log(`  synced ${n} content sections + metrics/hero/about/settings/headings from the live database`);
} catch (e) {
  console.log("  content sync skipped (using committed files):", e.message);
}
