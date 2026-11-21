// Shared by image-variants.mjs (writes the files) and generate-seo.mjs (uses them on /photos/).
import path from "path";

export const VARIANT_WIDTHS = [300, 360, 540, 660, 720, 900];
// "/images/Pic_section/Palash-Roy_Plaza de Bolívar.jpg", 540 -> "/images/Pic_section/_v/Palash-Roy_Plaza-de-Bol-var-540w.webp"
// (ASCII-only names, so the URLs need no escaping and never contain the commas srcset splits on).
export function variantPath(rel, w) {
  const dir = path.posix.dirname(rel);
  const base = path.posix.basename(rel).replace(/\.[^.]+$/, "").replace(/[^A-Za-z0-9_-]+/g, "-");
  return `${dir}/_v/${base}-${w}w.webp`;
}
