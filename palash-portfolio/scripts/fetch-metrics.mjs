// Refresh live research-impact metrics from OpenAlex (build-time, no runtime calls).
import { writeFileSync } from "fs";
import { fileURLToPath } from "url";

const ORCID = "0000-0001-9470-4233";
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
  writeFileSync(out, JSON.stringify(m, null, 2) + "\n");
  console.log(`  metrics: ${m.citations} citations, h-index ${m.hIndex}, works ${m.works}`);
} catch (e) {
  console.log("  metrics fetch failed, keeping existing file:", e.message);
}
