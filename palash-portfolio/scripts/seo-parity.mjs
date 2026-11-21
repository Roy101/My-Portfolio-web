// Parity harness: the Node generator (scripts/generate-seo.mjs, build time) and the PHP renderer
// (cpanel-backend/api/seo/, live from the database) must write byte-identical files from the same
// content. Runs both on a set of content fixtures and diffs every output file. No normalization:
// the only dynamic inputs (the copyright year) are pinned.
//
//   node scripts/seo-parity.mjs [--only name,name] [--keep] [--db]
//
// Needs a built dist/ (npm run build) and a PHP 8 CLI: the docker container pr-web (local preview)
// by default, or PARITY_PHP="php" for a local PHP. --db adds the "db-snapshot" fixture (content
// dumped from the preview database with cli.php dump-content). Exit code 1 on any difference.
import { execFileSync } from "child_process";
import { cpSync, mkdirSync, readFileSync, rmSync, writeFileSync, existsSync, readdirSync, statSync, copyFileSync } from "fs";
import path from "path";
import os from "os";
import { FIXTURES, CORPUS } from "./seo-parity-fixtures.mjs";

const ROOT = path.resolve(path.dirname(new URL(import.meta.url).pathname), "..");
const REPO = path.resolve(ROOT, "..");
const DIST = path.join(ROOT, "dist");
const CONTENT = path.join(ROOT, "src/content");
const SEO_PHP = path.join(REPO, "cpanel-backend/api/seo");
const YEAR = "2026";
const args = process.argv.slice(2);
const only = (args[args.indexOf("--only") + 1] || "").split(",").filter((x) => args.includes("--only") && x);
const KEEP = args.includes("--keep");
const WITH_DB = args.includes("--db");
const CONTAINER = process.env.PARITY_CONTAINER || "pr-web";
const LOCAL_PHP = process.env.PARITY_PHP || "";
const TMP = path.join(process.env.PARITY_TMP || os.tmpdir(), "seo-parity");
const CTMP = "/tmp/seo-parity";

if (!existsSync(path.join(DIST, "_seo/manifest.json"))) { console.error("dist/_seo/manifest.json missing: run the build first"); process.exit(2); }
const sh = (cmd, a, opt = {}) => execFileSync(cmd, a, { stdio: ["ignore", "pipe", "pipe"], maxBuffer: 1 << 28, ...opt }).toString();
const php = (a) => (LOCAL_PHP ? sh(LOCAL_PHP, a) : sh("docker", ["exec", CONTAINER, "php", ...a]));
const toC = (src, dst) => { if (LOCAL_PHP) { rmSync(dst, { recursive: true, force: true }); cpSync(src, dst, { recursive: true }); } else { sh("docker", ["exec", CONTAINER, "rm", "-rf", dst]); sh("docker", ["cp", src, `${CONTAINER}:${dst}`]); } };
const fromC = (src, dst) => { rmSync(dst, { recursive: true, force: true }); if (LOCAL_PHP) cpSync(src, dst, { recursive: true }); else sh("docker", ["cp", `${CONTAINER}:${src}`, dst]); };
const P = LOCAL_PHP ? TMP + "/c" : CTMP;

rmSync(TMP, { recursive: true, force: true });
mkdirSync(TMP, { recursive: true });
if (!LOCAL_PHP) sh("docker", ["exec", CONTAINER, "rm", "-rf", CTMP]), sh("docker", ["exec", CONTAINER, "mkdir", "-p", CTMP]);
else mkdirSync(P, { recursive: true });
toC(SEO_PHP, `${P}/seo`);

// ---------------------------------------------------------------- step A: helper corpus
function nodeHelpers() {
  const src = readFileSync(path.join(ROOT, "scripts/generate-seo.mjs"), "utf8");
  const grab = (name) => {
    const i = src.indexOf(`const ${name} =`);
    if (i < 0) throw new Error("helper not found: " + name);
    const rest = src.slice(i);
    const end = rest.search(/\n(?:const |function |let |\/\/ )/);
    return rest.slice(0, end < 0 ? undefined : end);
  };
  const names = ["SITE", "isoDay", "str", "esc", "slugify", "stripEmoji", "ldScript", "clip", "firstSentence", "safeDecode", "OWNER_RE", "OWNER_SET", "ownerKey", "isOwner"];
  // eslint-disable-next-line no-new-func
  return new Function(names.map(grab).join("\n") + "\nreturn { isoDay, str, esc, slugify, stripEmoji, ldScript, clip, firstSentence, safeDecode, isOwner };")();
}
let fails = 0;
{
  const H = nodeHelpers();
  const node = CORPUS.map((s) => {
    let dec; try { dec = decodeURI(s); } catch { dec = "URIError"; }
    return { esc: H.esc(s), slugify: H.slugify(s), stripEmoji: H.stripEmoji(s), clip158: H.clip(s, 158), clip20: H.clip(s, 20), firstSentence: H.firstSentence(s),
      encodeURI: (() => { try { return encodeURI(s); } catch { return "URIError"; } })(), encodeURIComponent: (() => { try { return encodeURIComponent(s); } catch { return "URIError"; } })(), decodeURI: dec,
      ldScript: H.ldScript({ x: s }), isoDay: H.isoDay(s), len: s.length, trim: s.trim(), lower: s.toLowerCase(), isOwner: H.isOwner(s) };
  });
  writeFileSync(path.join(TMP, "corpus.json"), JSON.stringify(CORPUS));
  toC(path.join(TMP, "corpus.json"), `${P}/corpus.json`);
  const phpOut = JSON.parse(php([`${P}/seo/cli.php`, "helpers", "--in", `${P}/corpus.json`]));
  let bad = 0;
  node.forEach((n, i) => {
    for (const k of Object.keys(n)) {
      if (JSON.stringify(n[k]) !== JSON.stringify(phpOut[i][k])) {
        bad++;
        if (bad <= 15) console.log(`  helper ${k}(${JSON.stringify(CORPUS[i]).slice(0, 60)}): node ${JSON.stringify(n[k]).slice(0, 120)} php ${JSON.stringify(phpOut[i][k]).slice(0, 120)}`);
      }
    }
  });
  console.log(`step A: ${CORPUS.length} strings x ${Object.keys(node[0]).length} helpers: ${bad ? bad + " differences" : "identical"}`);
  fails += bad;
}

// ---------------------------------------------------------------- step B: full output
const readContent = () => Object.fromEntries(readdirSync(CONTENT).filter((f) => f.endsWith(".json")).map((f) => [f.slice(0, -5), JSON.parse(readFileSync(path.join(CONTENT, f), "utf8"))]));
const fixtures = [...FIXTURES];
if (WITH_DB) {
  // The staged backend (with its config.php) reads the database.
  const dump = `${P}/dbdump`;
  php([process.env.PARITY_DB_CLI || "/var/www/html/api/seo/cli.php", "dump-content", "--out", dump]);
  fromC(dump, path.join(TMP, "dbdump"));
  const db = Object.fromEntries(readdirSync(path.join(TMP, "dbdump")).map((f) => [f.slice(0, -5), JSON.parse(readFileSync(path.join(TMP, "dbdump", f), "utf8"))]));
  fixtures.unshift({ name: "db-snapshot", note: "content dumped from the preview database", apply: (c) => { for (const k of Object.keys(c)) delete c[k]; Object.assign(c, db); } });
}
const walk = (d, base = d, out = []) => { if (!existsSync(d)) return out; for (const f of readdirSync(d)) { const p = path.join(d, f); if (statSync(p).isDirectory()) walk(p, base, out); else out.push(path.relative(base, p)); } return out; };
const results = [];
for (const fx of fixtures) {
  if (only.length && !only.includes(fx.name)) continue;
  const T = path.join(TMP, fx.name);
  mkdirSync(path.join(T, "content"), { recursive: true });
  const content = readContent();
  const extra = {};
  fx.apply(content, { addFile: (rel, from) => { extra[rel] = from; } });
  for (const [k, v] of Object.entries(content)) writeFileSync(path.join(T, "content", k + ".json"), JSON.stringify(v, null, 2) + "\n");
  // dist for both: the build output plus the fixture's extra files (uploads)
  const base = path.join(T, "dist");
  cpSync(DIST, base, { recursive: true });
  for (const [rel, from] of Object.entries(extra)) { mkdirSync(path.dirname(path.join(base, rel)), { recursive: true }); copyFileSync(path.join(DIST, from), path.join(base, rel)); }
  const A = path.join(T, "node");
  cpSync(base, A, { recursive: true });
  let nodeErr = "";
  try { sh(process.execPath, [path.join(ROOT, "scripts/generate-seo.mjs")], { env: { ...process.env, DIST: A, CONTENT_DIR: path.join(T, "content"), SEO_YEAR: YEAR } }); }
  catch (e) { nodeErr = String(e.stderr || e.message); }
  toC(path.join(T, "content"), `${P}/${fx.name}-content`);
  toC(base, `${P}/${fx.name}-dist`);
  let phpErr = "";
  try { php([`${P}/seo/cli.php`, "export", "--content", `${P}/${fx.name}-content`, "--docroot", `${P}/${fx.name}-dist`, "--out", `${P}/${fx.name}-php`, "--year", YEAR]); }
  catch (e) { phpErr = String(e.stderr || e.message); }
  if (nodeErr || phpErr) {
    // Invalid content: both sides must refuse it.
    const ok = !!nodeErr && !!phpErr;
    results.push({ name: fx.name, files: 0, diffs: ok ? 0 : 1, note: ok ? "both refused (validation)" : `node ${nodeErr ? "refused" : "ok"}, php ${phpErr ? "refused" : "ok"}: ${(nodeErr || phpErr).split("\n").slice(0, 3).join(" | ")}` });
    if (!ok) fails++;
    continue;
  }
  const B = path.join(T, "php");
  fromC(`${P}/${fx.name}-php`, B);
  const man = JSON.parse(readFileSync(path.join(A, "_seo/manifest.json"), "utf8"));
  const nodeFiles = [...new Set([...man.staticFiles, "index.html", ...walk(path.join(A, "blog")).map((f) => "blog/" + f)])].sort();
  const phpFiles = walk(B).sort();
  const diffs = [];
  for (const f of nodeFiles) {
    if (!existsSync(path.join(B, f))) { diffs.push([f, "missing in PHP output"]); continue; }
    const a = readFileSync(path.join(A, f)), b = readFileSync(path.join(B, f));
    if (!a.equals(b)) diffs.push([f, firstDiff(a.toString(), b.toString())]);
  }
  for (const f of phpFiles) if (!nodeFiles.includes(f)) diffs.push([f, "only in PHP output"]);
  results.push({ name: fx.name, files: nodeFiles.length, diffs: diffs.length, list: diffs });
  if (diffs.length) fails++;
  if (!KEEP) rmSync(T, { recursive: true, force: true });
}
function firstDiff(a, b) {
  let i = 0;
  while (i < a.length && i < b.length && a[i] === b[i]) i++;
  const ctx = (s) => JSON.stringify(s.slice(Math.max(0, i - 60), i + 80));
  return `at char ${i}\n      node: ${ctx(a)}\n      php : ${ctx(b)}`;
}
console.log("\nstep B: full output, byte for byte");
for (const r of results) {
  console.log(`  ${r.diffs ? "FAIL" : "ok  "} ${r.name.padEnd(22)} ${String(r.files).padStart(3)} files${r.note ? "  (" + r.note + ")" : ""}${r.diffs && r.list ? `  ${r.diffs} differing` : ""}`);
  for (const [f, d] of (r.list || []).slice(0, 6)) console.log(`       ${f}: ${d}`);
}
const total = results.reduce((n, r) => n + r.files, 0);
console.log(`\n${results.length} fixtures, ${total} files compared: ${fails ? fails + " failing" : "zero differences"}`);
if (!LOCAL_PHP && !KEEP) sh("docker", ["exec", CONTAINER, "rm", "-rf", CTMP]);
if (!KEEP) rmSync(TMP, { recursive: true, force: true });
process.exit(fails ? 1 : 0);
