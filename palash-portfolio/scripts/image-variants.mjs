// Smaller WebP copies of the /photos/ gallery images, so phones and the 3-column desktop
// gallery download an image near the size it is shown at (Lighthouse "image delivery").
//
//   npm run images        (needs ImageMagick: `magick`, or `convert` from ImageMagick 6)
//
// For every gallery.json image under /images/ it writes
//   public/images/<dir>/_v/<name>-<width>w.webp     widths 300, 360, 540, 660, 720, 900 (never wider than the original)
// and skips variants that are newer than their original. The files are committed with the
// site; generate-seo.mjs uses them on /photos/ when they exist (<picture> with srcset) and
// falls back to the original file for photos without variants (for example uploads/).
// Run it again after adding or replacing a gallery photo in public/images/.
import { existsSync, mkdirSync, readFileSync, statSync } from "fs";
import { execFileSync } from "child_process";
import path from "path";
import { fileURLToPath } from "url";
import { VARIANT_WIDTHS, variantPath } from "./image-variants-lib.mjs";

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const PUB = path.join(ROOT, "public");
const gallery = JSON.parse(readFileSync(path.join(ROOT, "src/content/gallery.json"), "utf8"));
const srcs = [...new Set((gallery.items || []).map((g) => String(g?.image || "").trim()).filter((u) => u.startsWith("/images/")))];

const tool = (() => {
  for (const t of ["magick", "convert"]) { try { execFileSync(t, ["-version"], { stdio: "ignore" }); return t; } catch { /* next */ } }
  return null;
})();
if (!tool) { console.error("ImageMagick (magick or convert) is not installed; no variants written."); process.exit(1); }

let made = 0, kept = 0;
for (const u of srcs) {
  const rel = decodeURI(u);
  const file = path.join(PUB, rel);
  if (!existsSync(file)) { console.warn(`  missing ${rel}`); continue; }
  let size = null;
  try { size = { w: parseInt(execFileSync(tool === "magick" ? "magick" : "identify", [...(tool === "magick" ? ["identify"] : []), "-format", "%w", `${file}[0]`]).toString(), 10) }; } catch { size = null; }
  if (!size || !size.w) { console.warn(`  unreadable ${rel}`); continue; }
  const widths = [...new Set([...VARIANT_WIDTHS.filter((w) => w < size.w), Math.min(size.w, VARIANT_WIDTHS.at(-1))])];
  for (const w of widths) {
    const out = path.join(PUB, variantPath(rel, w));
    if (existsSync(out) && statSync(out).mtimeMs >= statSync(file).mtimeMs) { kept++; continue; }
    mkdirSync(path.dirname(out), { recursive: true });
    execFileSync(tool, [file, "-auto-orient", "-resize", `${w}x`, "-strip", "-quality", "78", "-define", "webp:method=6", out]);
    made++;
  }
}
console.log(`image variants: ${made} written, ${kept} up to date (${srcs.length} gallery images)`);
