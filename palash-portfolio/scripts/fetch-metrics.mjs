// Refresh live research-impact metrics from OpenAlex (build-time, no runtime calls).
import { readFileSync, writeFileSync } from "fs";
import { fileURLToPath } from "url";

// identity.json (admin: Identity) when the content sync brought one, else the built-in iD.
const ORCID = (() => {
  try { const o = JSON.parse(readFileSync(fileURLToPath(new URL("../src/content/identity.json", import.meta.url)), "utf8")).orcid; if (/^\d{4}-\d{4}-\d{4}-\d{3}[\dX]$/.test(o)) return o; } catch {}
  return "0000-0001-9470-4233";
})();
const out = fileURLToPath(new URL("../src/content/metrics.json", import.meta.url));

try {
  const res = await fetch(`https://api.openalex.org/authors/https://orcid.org/${ORCID}`);
  const d = await res.json();
  const m = {
    citations: d.cited_by_count ?? 0,
    hIndex: d.summary_stats?.h_index ?? 0,
    works: d.works_count ?? 0,
    source: "OpenAlex",
    openAlexId: (d.id || "").split("/").pop(),
    profileUrl: d.id || "https://openalex.org/",
    updated: new Date().toISOString().slice(0, 10),
  };
  // Keep the previous "updated" date when the numbers did not change, so the date (shown
  // on the site and used as the homepage's sitemap lastmod) only moves on a real change.
  let prev = {};
  try { prev = JSON.parse(readFileSync(out, "utf8")); } catch { /* first run */ }
  if (prev.updated && ["citations", "hIndex", "works"].every((k) => String(prev[k]) === String(m[k]))) m.updated = prev.updated;
  writeFileSync(out, JSON.stringify(m, null, 2) + "\n");
  console.log(`  metrics: ${m.citations} citations, h-index ${m.hIndex}, works ${m.works}`);
} catch (e) {
  console.log("  metrics fetch failed, keeping existing file:", e.message);
}
