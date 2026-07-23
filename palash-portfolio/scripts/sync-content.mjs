// Pull the latest content from the live database so the prerendered HTML + structured
// data reflect what's in /admin. Fail-safe: if the site/API is unreachable, keep the
// committed files. Runs first in the build.
import { writeFileSync } from "fs";
import { fileURLToPath } from "url";

const API = "https://palashroy.me/api/content.php";
const dir = fileURLToPath(new URL("../src/content/", import.meta.url));
const LIST = ["publications", "highlights", "news", "media", "gallery", "leadership", "service", "references"];

try {
  const res = await fetch(API, { headers: { "Cache-Control": "no-cache" } });
  if (!res.ok) throw new Error("HTTP " + res.status);
  const d = await res.json();
  if (!d || d.error) throw new Error("bad payload");
  let n = 0;
  for (const s of LIST) {
    if (Array.isArray(d[s])) {
      writeFileSync(dir + s + ".json", JSON.stringify({ items: d[s] }, null, 2) + "\n");
      n++;
    }
  }
  if (d.metrics && typeof d.metrics === "object") {
    writeFileSync(dir + "metrics.json", JSON.stringify(d.metrics, null, 2) + "\n");
  }
  console.log(`  synced ${n} content sections + metrics from the live database`);
} catch (e) {
  console.log("  content sync skipped (using committed files):", e.message);
}
