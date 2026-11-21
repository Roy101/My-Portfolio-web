import React, { useState, useEffect, useRef, useCallback } from "react";
import { createPortal } from "react-dom";

// Single-source content (editable via the /admin CMS). At runtime the live
// database copy from /api/content.php wins over these bundled defaults, so the
// UI below treats every content object loosely and tolerates old and new shapes.
import newsData from "./content/news.json";
import mediaData from "./content/media.json";
import metricsData from "./content/metrics.json";
import heroData from "./content/hero.json";
import aboutData from "./content/about.json";
import settingsData from "./content/settings.json";
import headingsData from "./content/headings.json";
import researchData from "./content/research.json";
import socialsData from "./content/socials.json";
import footerData from "./content/footer.json";
import consultationData from "./content/consultation.json";
import blogData from "./content/blog.json";
import publicationsDataRaw from "./content/publications.json";
import leadershipDataRaw from "./content/leadership.json";
import serviceDataRaw from "./content/service.json";
import highlightsDataRaw from "./content/highlights.json";

// Content files added in round 2 (documents, skills, software). Loaded through a glob so
// the build still works before a file exists; the live database copy wins at runtime.
const OPTIONAL_CONTENT = import.meta.glob<any>("./content/{documents,skills,software}.json", { eager: true, import: "default" });
const optionalContent = (name: string): any => OPTIONAL_CONTENT[`./content/${name}.json`];

// ---------------------------------------------------------------------------
// Types
// ---------------------------------------------------------------------------

// Publication record. Core fields are the legacy ones; everything else is the
// extended schema (all optional). `doi` may be bare or a full doi.org URL.
type Publication = {
  title: string;
  authors: string;
  venue: string;
  year: string;
  description?: string;
  pages?: string;
  award?: string;
  doi?: string;
  preprint?: string;
  bibtex?: string;
  video?: string;
  slug?: string;
  type?: "conference" | "journal" | "poster" | "workshop" | "thesis" | string;
  authors_full?: string[];
  venue_short?: string;
  venue_full?: string;
  abstract?: string;
  problem?: string;
  relevant_to?: string[] | string;
  keywords?: string[] | string;
  pdf?: string;
  arxiv?: string;
  code_url?: string;
  tool_url?: string;
  artifact_url?: string;
  dataset_url?: string;
  first_page?: string | number;
  last_page?: string | number;
  volume?: string | number;
  issue?: string | number;
  selected?: boolean | string | number | null;
  // Round 3
  slides_url?: string;
  tags?: string[] | string;
  ml_topics?: string[] | string;
  equal_contribution?: string[] | string;
  handle?: string;
  track?: string;
  pinned?: boolean | string | number | null;
  // SPEC-round3 §22-23
  display_keywords?: string[] | string;
  my_role?: string;
  venue_rank?: string;
};

type LeadershipRole = { period: string; role: string; organization: string; place?: string; link?: string };
type ServiceItem = { period: string; role: string; description?: string; venue?: string; venues?: string };
type HighlightItem = { title: string; organization?: string; description?: string; link?: string; image?: string; year?: string; date?: string; kind?: string; paper_slug?: string; pinned?: any };
type BlogPost = { title: string; slug: string; date?: string; summary?: string; cover?: string; tags?: string; body?: string; draft?: any };
type Theme = "light" | "dark";

const defaultPublications: Publication[] = (publicationsDataRaw as any).items as Publication[];
const defaultLeadership: LeadershipRole[] = (leadershipDataRaw as any).items as LeadershipRole[];
const defaultService: ServiceItem[] = (serviceDataRaw as any).items as ServiceItem[];
const defaultHighlights: HighlightItem[] = (highlightsDataRaw as any).items as HighlightItem[];

const OWNER_NAME = "Palash Ranjan Roy";
const OWNER_ORCID = "0000-0001-9470-4233";
const PUBLIC_EMAIL = "palash.roy@usask.ca";
const DEFAULT_TITLE_LINE = "PhD Researcher in Computer Science · University of Saskatchewan";

// ---------------------------------------------------------------------------
// Small text helpers
// ---------------------------------------------------------------------------

// Strip emoji (pictographs, flags, keycaps, joiners) from CMS strings.
const EMOJI_RE = /[\p{Extended_Pictographic}\u{1F1E6}-\u{1F1FF}\u{1F3FB}-\u{1F3FF}️‍⃣]/gu;
function noEmoji(s: any): string {
  // Keep typographic symbols that Unicode also classes as pictographic (© ® ™ ‼ etc.).
  return String(s ?? "").replace(EMOJI_RE, (c) => (/[\u00A9\u00AE\u2122\u203C\u2049]/.test(c) ? c : "")).replace(/\s{2,}/g, " ").trim();
}

function slugify(s: string) {
  return String(s || "").toLowerCase().trim().replace(/[^a-z0-9]+/g, "-").replace(/^-+|-+$/g, "");
}

// "https://doi.org/10.1145/x" / "doi:10.1145/x" -> "10.1145/x"
function normDoi(d?: string): string {
  const t = String(d || "").trim().replace(/^https?:\/\/(dx\.)?doi\.org\//i, "").replace(/^doi:\s*/i, "");
  return /^10\.\d{4,9}\//.test(t) ? t : "";
}

function asList(v: any): string[] {
  if (Array.isArray(v)) return v.map((x) => String(x).trim()).filter(Boolean);
  if (typeof v === "string") return v.split(/[,;\n]/).map((x) => x.trim()).filter(Boolean);
  return [];
}

// One date style for every period column:
//   "2022 - Present" -> "2022–Present", "2025-2026" -> "2025–2026",
//   "Spring-Summer 2024" -> "Spring–Summer 2024", "May 2022 - Aug 2022" -> "May–Aug 2022",
//   "May 2022 - Aug 2023" -> "May 2022 – Aug 2023" (spaced only when months are involved).
function fmtPeriod(s: any): string {
  const t = noEmoji(s).replace(/\s+/g, " ").trim();
  if (!t || /^\d{4}-\d{2}(-\d{2})?$/.test(t)) return t; // ISO date, not a range
  const parts = t.split(/\s*[-–—]\s*|\s+to\s+/i).map((x) => x.trim()).filter(Boolean);
  if (parts.length !== 2) return t;
  const [a, b] = parts;
  const ym = /^([A-Za-z]+\.?)\s+((?:19|20)\d{2})$/;
  const ma = a.match(ym), mb = b.match(ym);
  if (ma && mb && ma[2] === mb[2]) return `${ma[1]}–${mb[1]} ${mb[2]}`;
  if (mb && /^[A-Za-z]+\.?$/.test(a)) return `${a}–${b}`; // "Spring–Summer 2024"
  // One range style everywhere: a spaced en dash ("2022 – 2024", "May 2025 – Apr 2026").
  return `${a} – ${b}`;
}
// Date column text: "May 2025" never splits, and a range breaks only after its dash.
function dateCell(p: string): string {
  return String(p || "").replace(/([A-Za-z]{3,}\.?) ((?:19|20)\d{2})/g, "$1\u00a0$2").replace(/ – /g, "\u00a0– ");
}

function fmtMonthYear(d?: string): string {
  if (!d) return "";
  const dt = new Date(/^\d{4}-\d{2}(-\d{2})?$/.test(d) ? d.slice(0, 7) + "-01T00:00:00" : d);
  return isNaN(dt.getTime()) ? String(d) : dt.toLocaleDateString("en-US", { month: "short", year: "numeric" });
}

// Turn an editable URL into a safe href: allow http(s)/mailto/tel/relative/anchor
// as-is, auto-prepend https:// to a bare domain (e.g. srlab.usask.ca), else reject.
function normUrl(u: string): string | null {
  const t = String(u || "").trim();
  if (/^(https?:\/\/|mailto:|tel:|\/|#)/i.test(t)) return t;
  if (/^[\w.-]+\.[a-z]{2,}(\/\S*)?$/i.test(t)) return "https://" + t;
  return null;
}
const isExternal = (u: string) => /^https?:\/\//i.test(u);
const extProps = (u: string) => (isExternal(u) ? { target: "_blank", rel: "noopener noreferrer" } : {});

// Lightweight inline formatting for editable text. Links accept both
// [label](url) and [label][url]; plus **bold** and *italic*.
// Builds React nodes (never raw HTML) so it stays safe from injection.
function renderRich(text: any): React.ReactNode {
  const s = text == null ? "" : String(text);
  if (!s) return s;
  const RE = /\[([^\]]+)\]\(([^)\s]+)\)|\[([^\]]+)\]\[([^\]\s]+)\]|\*\*([^*]+)\*\*|\*([^*]+)\*/g;
  const out: React.ReactNode[] = [];
  let last = 0, m: RegExpExecArray | null, k = 0;
  while ((m = RE.exec(s)) !== null) {
    if (m.index > last) out.push(s.slice(last, m.index));
    const label = m[1] !== undefined ? m[1] : m[3];
    const rawUrl = m[1] !== undefined ? m[2] : (m[3] !== undefined ? m[4] : undefined);
    if (label !== undefined && rawUrl !== undefined) {
      const url = normUrl(rawUrl);
      if (url) out.push(<a key={k++} href={url} {...extProps(url)}>{label}</a>);
      else out.push(label);
    } else if (m[5] !== undefined) {
      out.push(<strong key={k++}>{m[5]}</strong>);
    } else if (m[6] !== undefined) {
      out.push(<em key={k++}>{m[6]}</em>);
    }
    last = m.index + m[0].length;
  }
  if (last < s.length) out.push(s.slice(last));
  return out;
}
const rich = (t: any) => renderRich(noEmoji(t));

// ---------------------------------------------------------------------------
// Media helpers (YouTube / PDF / image detection + embeds)
// ---------------------------------------------------------------------------
function ytId(url: string): string | null {
  if (!url) return null;
  const m = String(url).match(/(?:youtube\.com\/(?:watch\?v=|embed\/|shorts\/|live\/)|youtu\.be\/)([\w-]{11})/);
  return m ? m[1] : null;
}
function isPdfUrl(u: string) { return /\.pdf(\?|#|$)/i.test(u); }
function isImageUrl(u: string) { return /\.(png|jpe?g|gif|webp|svg|avif)(\?|#|$)/i.test(u); }

function YouTubeEmbed({ id, title }: { id: string; title?: string }) {
  return (
    <div className="relative w-full my-6 overflow-hidden rounded border border-[var(--c-border)] bg-black" style={{ aspectRatio: "16 / 9" }}>
      <iframe className="absolute inset-0 w-full h-full" src={`https://www.youtube-nocookie.com/embed/${id}`} title={title || "YouTube video"}
        loading="lazy" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" allowFullScreen />
    </div>
  );
}
function PdfEmbed({ url }: { url: string }) {
  return (
    <div className="my-6">
      <div className="overflow-hidden rounded border border-[var(--c-border)] bg-[var(--c-surface)]" style={{ height: "80vh", maxHeight: 900 }}>
        <iframe src={url} title="PDF document" className="w-full h-full" loading="lazy" />
      </div>
      <a href={url} target="_blank" rel="noopener noreferrer" className="inline-block mt-2 small">Open or download the PDF</a>
    </div>
  );
}

// Close-on-Escape + body scroll lock for modals and the drawer. With a container ref it
// also keeps Tab / Shift+Tab inside that dialog and, on close, returns focus to the
// element that opened it (WCAG 2.4.3 for aria-modal dialogs). The Escape it handles is
// marked defaultPrevented so other listeners (the theme prompt) leave it alone.
const FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]), select, textarea, iframe, video[controls], [tabindex]:not([tabindex="-1"])';
function useModalBehaviour(onClose: () => void, trapRef?: React.RefObject<HTMLElement>) {
  const closeRef = useRef(onClose);
  closeRef.current = onClose;
  // The opener is read during the first render, before autoFocus moves focus into the dialog.
  const [opener] = useState<HTMLElement | null>(() => (trapRef && typeof document !== "undefined" ? (document.activeElement as HTMLElement | null) : null));
  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      if (e.key === "Escape") { e.preventDefault(); closeRef.current(); return; }
      const box = trapRef?.current;
      if (e.key !== "Tab" || !box) return;
      const f = Array.from(box.querySelectorAll<HTMLElement>(FOCUSABLE)).filter((el) => el.offsetParent !== null || el === document.activeElement);
      if (!f.length) { e.preventDefault(); return; }
      const first = f[0], last = f[f.length - 1], a = document.activeElement as HTMLElement | null;
      if (!a || !box.contains(a)) { e.preventDefault(); (e.shiftKey ? last : first).focus(); }
      else if (e.shiftKey && a === first) { e.preventDefault(); last.focus(); }
      else if (!e.shiftKey && a === last) { e.preventDefault(); first.focus(); }
    };
    document.addEventListener("keydown", onKey);
    const prev = document.body.style.overflow;
    document.body.style.overflow = "hidden";
    return () => {
      document.removeEventListener("keydown", onKey);
      document.body.style.overflow = prev;
      if (opener && opener !== document.body && document.contains(opener)) setTimeout(() => opener.focus(), 0);
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);
}

function VideoModal({ url, onClose }: { url: string; onClose: () => void }) {
  const boxRef = useRef<HTMLDivElement>(null);
  useModalBehaviour(onClose, boxRef);
  const id = ytId(url);
  return createPortal(
    <div className="modal-backdrop" onClick={onClose}>
      <div ref={boxRef} className="w-full max-w-3xl" role="dialog" aria-modal="true" aria-label="Video" onClick={(e) => e.stopPropagation()}>
        {id ? <YouTubeEmbed id={id} /> : <video src={url} controls autoPlay className="w-full rounded border border-[var(--c-border)] bg-black" />}
        <div className="text-center"><button onClick={onClose} className="btn btn-ghost" style={{ background: "var(--c-surface)" }} autoFocus>Close</button></div>
      </div>
    </div>, document.body);
}

// ---------------------------------------------------------------------------
// Minimal, injection-safe Markdown renderer for blog posts (returns React nodes)
// ---------------------------------------------------------------------------
function mdInline(s: string, keyBase = 0): React.ReactNode[] {
  const RE = /!\[([^\]]*)\]\(([^)\s]+)\)|\[([^\]]+)\]\(([^)\s]+)\)|\*\*([^*]+)\*\*|\*([^*]+)\*|`([^`]+)`/g;
  const out: React.ReactNode[] = [];
  let last = 0, m: RegExpExecArray | null, k = keyBase;
  while ((m = RE.exec(s)) !== null) {
    if (m.index > last) out.push(s.slice(last, m.index));
    if (m[1] !== undefined) { const u = normUrl(m[2]); if (u) out.push(<img key={k++} src={u} alt={m[1]} className="inline-block max-w-full rounded" loading="lazy" />); }
    else if (m[3] !== undefined) { const u = normUrl(m[4]); out.push(u ? <a key={k++} href={u} {...extProps(u)}>{m[3]}</a> : m[3]); }
    else if (m[5] !== undefined) out.push(<strong key={k++}>{m[5]}</strong>);
    else if (m[6] !== undefined) out.push(<em key={k++}>{m[6]}</em>);
    else if (m[7] !== undefined) out.push(<code key={k++} className="px-1 py-0.5 rounded bg-[var(--c-surface2)] text-[0.9em]">{m[7]}</code>);
    last = m.index + m[0].length;
  }
  if (last < s.length) out.push(s.slice(last));
  return out;
}
function renderMarkdown(md: string): React.ReactNode {
  const lines = String(md || "").replace(/\r\n/g, "\n").split("\n");
  const blocks: React.ReactNode[] = [];
  let i = 0, key = 0;
  const flushList = (items: string[], ordered: boolean) => {
    blocks.push(React.createElement(ordered ? "ol" : "ul", { key: key++, className: `${ordered ? "list-decimal" : "list-disc"} pl-6 my-4 space-y-1` },
      items.map((it, j) => <li key={j}>{mdInline(it)}</li>)));
  };
  while (i < lines.length) {
    const line = lines[i];
    if (!line.trim()) { i++; continue; }
    if (/^```/.test(line)) {
      const buf: string[] = []; i++;
      while (i < lines.length && !/^```/.test(lines[i])) { buf.push(lines[i]); i++; }
      i++;
      blocks.push(<pre key={key++} className="my-4 p-4 rounded overflow-x-auto bg-[var(--c-surface2)] border border-[var(--c-border)] text-sm"><code>{buf.join("\n")}</code></pre>);
      continue;
    }
    const hm = line.match(/^(#{1,3})\s+(.*)$/);
    if (hm) {
      const lvl = hm[1].length;
      const cls = lvl === 1 ? "text-[26px] mt-10 mb-3" : lvl === 2 ? "text-[22px] mt-8 mb-3" : "text-[19px] mt-6 mb-2";
      blocks.push(React.createElement(`h${lvl + 1}`, { key: key++, className: cls }, mdInline(hm[2])));
      i++; continue;
    }
    if (/^(-{3,}|\*{3,}|_{3,})$/.test(line.trim())) { blocks.push(<hr key={key++} className="my-8 border-[var(--c-border)]" />); i++; continue; }
    if (/^>\s?/.test(line)) {
      const buf: string[] = [];
      while (i < lines.length && /^>\s?/.test(lines[i])) { buf.push(lines[i].replace(/^>\s?/, "")); i++; }
      blocks.push(<blockquote key={key++} className="my-4 pl-4 border-l-2 border-[var(--c-accent)] muted">{mdInline(buf.join(" "))}</blockquote>);
      continue;
    }
    if (/^[-*]\s+/.test(line)) {
      const items: string[] = [];
      while (i < lines.length && /^[-*]\s+/.test(lines[i])) { items.push(lines[i].replace(/^[-*]\s+/, "")); i++; }
      flushList(items, false); continue;
    }
    if (/^\d+\.\s+/.test(line)) {
      const items: string[] = [];
      while (i < lines.length && /^\d+\.\s+/.test(lines[i])) { items.push(lines[i].replace(/^\d+\.\s+/, "")); i++; }
      flushList(items, true); continue;
    }
    const solo = line.trim();
    const imgOnly = solo.match(/^!\[([^\]]*)\]\(([^)\s]+)\)$/);
    if (imgOnly) {
      const u = normUrl(imgOnly[2]);
      if (u) { blocks.push(<figure key={key++} className="my-6"><img src={u} alt={imgOnly[1]} className="w-full rounded border border-[var(--c-border)]" loading="lazy" />{imgOnly[1] && <figcaption className="text-center tiny muted mt-2">{imgOnly[1]}</figcaption>}</figure>); i++; continue; }
    }
    if (/^https?:\/\/\S+$/i.test(solo)) {
      const yid = ytId(solo);
      if (yid) { blocks.push(<YouTubeEmbed key={key++} id={yid} />); i++; continue; }
      if (isPdfUrl(solo)) { blocks.push(<PdfEmbed key={key++} url={solo} />); i++; continue; }
      if (isImageUrl(solo)) { blocks.push(<figure key={key++} className="my-6"><img src={solo} alt="" className="w-full rounded border border-[var(--c-border)]" loading="lazy" /></figure>); i++; continue; }
      blocks.push(<p key={key++} className="my-4 break-words"><a href={solo} target="_blank" rel="noopener noreferrer">{solo}</a></p>); i++; continue;
    }
    const buf: string[] = [];
    while (i < lines.length && lines[i].trim() && !/^(#{1,3}\s|>|[-*]\s|\d+\.\s|```)/.test(lines[i])) { buf.push(lines[i]); i++; }
    blocks.push(<p key={key++} className="my-4">{mdInline(buf.join(" "))}</p>);
  }
  return <div className="blog-body">{blocks}</div>;
}

// ---------------------------------------------------------------------------
// Theme
// ---------------------------------------------------------------------------
// index.html's inline script sets data-theme on <html> before React loads
// (saved choice in localStorage "theme", else prefers-color-scheme). React
// starts from that value and only writes localStorage when the visitor chooses.
// Stored values: "light" | "dark" | "system" ("system" = follow the OS, and
// also records that the first-visit prompt was answered or closed).
type ThemePref = Theme | "system";
const systemTheme = (): Theme =>
  (typeof window !== "undefined" && window.matchMedia && window.matchMedia("(prefers-color-scheme: dark)").matches) ? "dark" : "light";
function readThemePref(): string | null {
  try { return window.localStorage.getItem("theme"); } catch { return null; }
}
function initialTheme(): Theme {
  if (typeof document !== "undefined") {
    const t = document.documentElement.dataset.theme;
    if (t === "light" || t === "dark") return t;
  }
  return systemTheme();
}
function useTheme(): { theme: Theme; pref: ThemePref; toggle: () => void; choose: (p: ThemePref) => void } {
  const [theme, setTheme] = useState<Theme>(initialTheme);
  const [pref, setPref] = useState<ThemePref>(() => {
    const s = typeof window !== "undefined" ? readThemePref() : null;
    return s === "light" || s === "dark" ? s : "system";
  });
  useEffect(() => { document.documentElement.setAttribute("data-theme", theme); }, [theme]);
  // Follow OS changes while the visitor has not picked light or dark.
  useEffect(() => {
    if (!window.matchMedia) return;
    const mq = window.matchMedia("(prefers-color-scheme: dark)");
    const onChange = () => {
      const saved = readThemePref();
      if (saved !== "light" && saved !== "dark") setTheme(mq.matches ? "dark" : "light");
    };
    mq.addEventListener ? mq.addEventListener("change", onChange) : mq.addListener(onChange);
    return () => { mq.removeEventListener ? mq.removeEventListener("change", onChange) : mq.removeListener(onChange); };
  }, []);
  const choose = useCallback((p: ThemePref) => {
    try { window.localStorage.setItem("theme", p); } catch { /* ignore */ }
    setPref(p);
    setTheme(p === "system" ? systemTheme() : p);
  }, []);
  const toggle = useCallback(() => {
    setTheme((t) => {
      const next: Theme = t === "dark" ? "light" : "dark";
      try { window.localStorage.setItem("theme", next); } catch { /* ignore */ }
      setPref(next);
      return next;
    });
  }, []);
  return { theme, pref, toggle, choose };
}

function ThemeToggle({ theme, onToggle }: { theme: Theme; onToggle: () => void }) {
  const label = theme === "dark" ? "Switch to light theme" : "Switch to dark theme";
  return (
    <button type="button" id="theme-toggle" onClick={onToggle} aria-label={label} title={label} className="icon-btn">
      {theme === "dark" ? (
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" aria-hidden="true"><circle cx="12" cy="12" r="4" /><path d="M12 2.5v2M12 19.5v2M4.6 4.6l1.4 1.4M18 18l1.4 1.4M2.5 12h2M19.5 12h2M4.6 19.4 6 18M18 6l1.4-1.4" /></svg>
      ) : (
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.6" strokeLinejoin="round" aria-hidden="true"><path d="M20 14.5A8 8 0 0 1 9.5 4a8 8 0 1 0 10.5 10.5Z" /></svg>
      )}
    </button>
  );
}

// First-visit display chooser: small, non-blocking, dismissible. Never shown when the
// visitor already chose (localStorage "theme" set), when the admin turned it off
// (settings.themePrompt === false), to automated browsers (the build's prerender runs
// headless Chrome, which sets navigator.webdriver), or in print.
function ThemePrompt({ pref, onChoose, enabled }: { pref: ThemePref; onChoose: (p: ThemePref) => void; enabled: boolean }) {
  const [show, setShow] = useState(false);
  const boxRef = useRef<HTMLDivElement>(null);
  // Keyboard users who chose inside the prompt land on the nav theme toggle (the control
  // that changes this setting later) instead of <body>.
  const handOff = () => {
    if (boxRef.current && boxRef.current.contains(document.activeElement)) document.getElementById("theme-toggle")?.focus();
  };
  useEffect(() => {
    if (!enabled || typeof window === "undefined") return;
    if ((navigator as any).webdriver) return;
    if (readThemePref() !== null) return;
    let timer = 0, ready = false, done = false;
    // On narrow screens wait until the hero's links are clear of the bottom strip, so
    // the prompt never covers them.
    const clear = () => {
      if (window.innerWidth >= 640) return true;
      const el = document.getElementById("hero-actions");
      if (!el) return true;
      const r = el.getBoundingClientRect();
      return r.bottom < window.innerHeight - 150 || r.bottom < 0;
    };
    const tryShow = () => { if (!done && ready && clear()) { done = true; setShow(true); cleanup(); } };
    const onScroll = () => tryShow();
    const cleanup = () => { window.removeEventListener("scroll", onScroll); window.removeEventListener("resize", onScroll); };
    timer = window.setTimeout(() => { ready = true; tryShow(); }, 1500);
    window.addEventListener("scroll", onScroll, { passive: true });
    window.addEventListener("resize", onScroll);
    return () => { window.clearTimeout(timer); cleanup(); };
  }, [enabled]);
  // Keep focused elements clear of the prompt (WCAG 2.4.11) while it is open.
  useEffect(() => {
    if (!show) return;
    const h = boxRef.current?.offsetHeight || 0;
    const root = document.documentElement;
    const prev = root.style.scrollPaddingBottom;
    root.style.scrollPaddingBottom = `${h + 16}px`;
    // An Escape meant for an open dialog or the menu drawer must not dismiss this prompt.
    const onKey = (e: KeyboardEvent) => {
      if (e.key !== "Escape" || e.defaultPrevented || document.querySelector('[aria-modal="true"]')) return;
      handOff(); onChoose(pref); setShow(false);
    };
    document.addEventListener("keydown", onKey);
    return () => { root.style.scrollPaddingBottom = prev; document.removeEventListener("keydown", onKey); };
  }, [show, pref, onChoose]);
  if (!show) return null;
  const pick = (p: ThemePref) => { handOff(); onChoose(p); setShow(false); };
  const opts: { p: ThemePref; label: string }[] = [{ p: "light", label: "Light" }, { p: "dark", label: "Dark" }, { p: "system", label: "Match system" }];
  return (
    <div ref={boxRef} className="theme-prompt no-print" role="dialog" aria-modal="false" aria-labelledby="theme-prompt-title">
      <span id="theme-prompt-title" className="theme-prompt-title">Display</span>
      <div className="theme-prompt-opts" role="group" aria-labelledby="theme-prompt-title">
        {opts.map((o) => (
          <button key={o.p} type="button" className="theme-opt" aria-pressed={pref === o.p} onClick={() => pick(o.p)}>{o.label}</button>
        ))}
      </div>
      <button type="button" className="icon-btn theme-prompt-close" aria-label="Close display options" onClick={() => pick(pref)}>
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18" /></svg>
      </button>
    </div>
  );
}

// ---------------------------------------------------------------------------
// Navigation (desktop links + accessible mobile drawer)
// ---------------------------------------------------------------------------
type NavLink = { label: string; href: string; id?: string };

function MobileDrawer({ links, extra, extraLabel, onClose, cvUrl, activeId, currentPage }: {
  links: NavLink[]; extra: NavLink[]; extraLabel: string; onClose: () => void; cvUrl?: string; activeId?: string; currentPage?: string;
}) {
  useModalBehaviour(onClose);
  const panelRef = useRef<HTMLDivElement>(null);
  useEffect(() => {
    const panel = panelRef.current;
    if (!panel) return;
    const focusables = () => Array.from(panel.querySelectorAll<HTMLElement>("a[href], button"));
    focusables()[0]?.focus();
    // Keep Tab inside the drawer while it is open.
    const onKey = (e: KeyboardEvent) => {
      if (e.key !== "Tab") return;
      const f = focusables(); if (!f.length) return;
      const first = f[0], last = f[f.length - 1];
      if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
      else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    };
    panel.addEventListener("keydown", onKey);
    return () => panel.removeEventListener("keydown", onKey);
  }, []);
  const cur = (l: NavLink) => (currentPage && l.id === currentPage ? "page" : activeId && l.id === activeId ? "location" : undefined);
  return createPortal(
    <>
      <div className="drawer-backdrop" onClick={onClose} aria-hidden="true" />
      <div ref={panelRef} id="mobile-menu" className="drawer" role="dialog" aria-modal="true" aria-label="Site menu">
        <div className="flex justify-end">
          <button type="button" className="icon-btn" onClick={onClose} aria-label="Close menu">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18" /></svg>
          </button>
        </div>
        <nav aria-label="Mobile">
          {links.map((l) => (
            <a key={l.href} href={l.href} className="drawer-link" aria-current={cur(l)} onClick={onClose}>{l.label}</a>
          ))}
          {extra.length > 0 && (
            <>
              <div className="sub-title" style={{ margin: "24px 0 4px" }} id="drawer-extra">{extraLabel}</div>
              <ul className="list-none p-0 m-0" aria-labelledby="drawer-extra">
                {extra.map((l) => (
                  <li key={l.href}><a href={l.href} className="drawer-link drawer-link-sm" aria-current={cur(l)} onClick={onClose}>{l.label}</a></li>
                ))}
              </ul>
            </>
          )}
        </nav>
        {cvUrl && <a href={cvUrl} className="btn btn-solid mt-6 w-full" {...extProps(cvUrl)} onClick={onClose}>CV (PDF)</a>}
      </div>
    </>, document.body);
}

function SiteNav({ links, extra, extraLabel, theme, onToggleTheme, cvUrl, homeHref, activeId, currentPage }: {
  links: NavLink[]; extra: NavLink[]; extraLabel: string; theme: Theme; onToggleTheme: () => void; cvUrl?: string; homeHref: string; activeId?: string; currentPage?: string;
}) {
  const [open, setOpen] = useState(false);
  const btnRef = useRef<HTMLButtonElement>(null);
  const close = useCallback(() => { setOpen(false); setTimeout(() => btnRef.current?.focus(), 0); }, []);
  return (
    <header className="site-nav">
      <div className="wrap flex items-center justify-between gap-4" style={{ minHeight: 60 }}>
        <a href={homeHref} className="wordmark font-serif-display inline-flex items-center min-h-[44px] text-[19px] text-[var(--c-text)] hover:no-underline hover:text-[var(--c-accent)] whitespace-nowrap">{OWNER_NAME}</a>
        <nav aria-label="Primary" className="hidden md:flex items-center gap-6">
          {links.map((l) => (
            <a key={l.href} href={l.href} className="nav-link"
              aria-current={currentPage && l.id === currentPage ? "page" : activeId && l.id === activeId ? "location" : undefined}>{l.label}</a>
          ))}
        </nav>
        <div className="flex items-center gap-2">
          {cvUrl && <a href={cvUrl} className="btn btn-solid nav-cv" style={{ padding: "0 14px" }} {...extProps(cvUrl)}>CV</a>}
          <ThemeToggle theme={theme} onToggle={onToggleTheme} />
          <button ref={btnRef} type="button" className="menu-btn md:hidden" aria-expanded={open} aria-controls="mobile-menu" onClick={() => setOpen(true)}>
            <svg className="menu-ic" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16" /></svg>
            <span>Menu</span>
          </button>
        </div>
      </div>
      {open && <MobileDrawer links={links} extra={extra} extraLabel={extraLabel} onClose={close} cvUrl={cvUrl} activeId={activeId} currentPage={currentPage} />}
    </header>
  );
}

// Which homepage section is under the nav right now (for aria-current).
function useActiveSection(ids: string[]): string | undefined {
  const [active, setActive] = useState<string | undefined>(undefined);
  const key = ids.join("|");
  useEffect(() => {
    const onScroll = () => {
      let cur: string | undefined;
      for (const id of ids) {
        const el = document.getElementById(id);
        if (el && el.getBoundingClientRect().top <= 120) cur = id;
      }
      if (window.innerHeight + window.scrollY >= document.documentElement.scrollHeight - 4 && ids.length) cur = ids[ids.length - 1];
      setActive(cur);
    };
    onScroll();
    window.addEventListener("scroll", onScroll, { passive: true });
    window.addEventListener("resize", onScroll);
    return () => { window.removeEventListener("scroll", onScroll); window.removeEventListener("resize", onScroll); };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [key]);
  return active;
}

// ---------------------------------------------------------------------------
// Publications
// ---------------------------------------------------------------------------

// Same-site PDFs known to exist in this build (from the bundled JSON). A legacy
// `preprint` path from an older database copy that is not in this set is not
// linked, so removed publisher PDFs never turn into dead links.
const KNOWN_PDFS = new Set(
  defaultPublications.flatMap((p) => [p.pdf, p.preprint]).filter((u): u is string => !!u && /^\/[^/]/.test(u) && isPdfUrl(u))
);

function pubSlug(p: Publication): string {
  return (p.slug && String(p.slug).trim()) ? slugify(String(p.slug)) : slugify(p.title);
}
function pubArxiv(p: Publication): string {
  if (p.arxiv) return String(p.arxiv).replace(/^arxiv:\s*/i, "").replace(/^https?:\/\/arxiv\.org\/(abs|pdf)\//i, "").replace(/\.pdf$/i, "").trim();
  const m = String(p.preprint || "").match(/arxiv\.org\/(?:abs|pdf)\/([\w.\-/]+?)(?:\.pdf)?$/i);
  return m ? m[1] : "";
}
function pubPdf(p: Publication): string {
  if (p.pdf && /^\//.test(p.pdf)) return p.pdf;
  if (p.pdf && isExternal(p.pdf)) return p.pdf;
  if (p.preprint && KNOWN_PDFS.has(p.preprint)) return p.preprint;
  return "";
}
function pubType(p: Publication): string {
  if (p.type) return String(p.type).toLowerCase();
  if (/^\s*a poster\b/i.test(p.description || "") || /\bposter\b/i.test(p.title)) return "poster";
  return "";
}
// Labels for the non-standard publication types (shown as a small tag before the venue).
const TYPE_LABEL: Record<string, string> = { doctoral_symposium: "Doctoral Symposium", thesis: "Thesis", poster: "Poster", workshop: "Workshop", demo: "Demo" };
const typeLabel = (t: string) => TYPE_LABEL[t] || t.replace(/_/g, " ");
// Trimmed optional string field ("" when empty or only whitespace).
const fieldStr = (v: any) => (v == null ? "" : String(v).trim());
function pubAuthors(p: Publication): string {
  return Array.isArray(p.authors_full) && p.authors_full.length ? p.authors_full.join(", ") : String(p.authors || "");
}
// Author names in published order (never reordered).
function pubAuthorList(p: Publication): string[] {
  if (Array.isArray(p.authors_full) && p.authors_full.length) return p.authors_full.map((a) => String(a).trim()).filter(Boolean);
  return String(p.authors || "").split(/\s*,\s*(?:and\s+)?|\s+and\s+/).map((a) => a.trim()).filter(Boolean);
}
// "Ajmain Inqiad Alam" matches "Ajmain Inqiad Alam" and "A. I. Alam".
const nameKey = (s: string) => noEmoji(s).toLowerCase().replace(/[.\s]+/g, " ").replace(/[^a-z\s-]/g, "").trim();
function nameKeys(full: string): string[] {
  const parts = String(full).trim().split(/\s+/);
  const last = parts.pop() || "";
  return [nameKey(full), nameKey(`${parts.map((p) => p[0] || "").join(" ")} ${last}`)];
}
function equalContribution(p: Publication): Set<string> {
  const names = asList(p.equal_contribution);
  return new Set(names.length > 1 ? names.flatMap(nameKeys) : []);
}
// Publication tags; a controlled vocabulary (settings.tagVocabulary) supplies long labels.
// Two facets (SPEC-round3 §20-21): `tags` = software engineering areas, `ml_topics` = AI/ML topics.
function pubTags(p: Publication): string[] {
  return Array.from(new Set(asList(p.tags).map(noEmoji).filter(Boolean)));
}
function pubMlTopics(p: Publication): string[] {
  return Array.from(new Set(asList(p.ml_topics).map(noEmoji).filter(Boolean)));
}
const DEFAULT_TAG_VOCAB: Record<string, string> = {
  AI4SE: "AI for Software Engineering", SE4AI: "Software Engineering for AI",
  "Empirical SE": "Empirical Software Engineering", "OOD Generalization": "Out-of-Distribution Generalization",
};
const TagVocab = React.createContext<Record<string, string>>(DEFAULT_TAG_VOCAB);
// Long labels from settings.tagVocabulary and settings.mlTopicVocabulary ({tag, label}).
function readTagVocab(...raws: any[]): Record<string, string> {
  const out: Record<string, string> = { ...DEFAULT_TAG_VOCAB };
  raws.forEach((raw) => listOf(raw).forEach((v: any) => {
    const tag = noEmoji(typeof v === "string" ? v : v?.tag);
    const label = typeof v === "string" ? "" : noEmoji(v?.label);
    if (tag && label) out[tag] = label;
  }));
  return out;
}
const longTag = (vocab: Record<string, string>, t: string) => (vocab[t] || t).toLowerCase();
// One merged keyword row per paper (SPEC-round3 §22). The data keeps `tags` (SE areas) and
// `ml_topics` (AI/ML) apart; only the display merges them, most specific first:
// narrow SE tags and specific ML topics (alternating) → kind tags (Tool, Benchmark) → broad SE
// areas → umbrella tags (AI4SE, SE4AI) → generic model terms, which appear only when fewer than
// 4 others exist. A term is dropped when a more specific synonym is present (Model Merging when
// Task Arithmetic (TIES, DARE) is there); terms with the same long label appear once.
// `display_keywords`, when set, is used verbatim. Same rules in scripts/generate-seo.mjs.
const KW_BROAD = new Set(["software maintenance & evolution", "program comprehension", "empirical se"]);
const KW_UMBRELLA = new Set(["ai4se", "se4ai"]);
const KW_KIND = new Set(["tool", "benchmark"]);
const KW_GENERIC = new Set(["code language models", "large language models"]);
const KW_SPECIFIC: Record<string, string[]> = {
  "model merging": ["task arithmetic (ties, dare)"], "prompting": ["prompting (few-shot)"], benchmark: ["benchmarks & datasets"],
};
function pubKeywords(p: Publication, vocab: Record<string, string>, max = 0): string[] {
  const own = asList(p.display_keywords).map(noEmoji).filter(Boolean);
  if (own.length) return max ? own.slice(0, max) : own;
  const lc = (t: string) => t.toLowerCase();
  const se = pubTags(p), ml = pubMlTopics(p);
  const narrow = se.filter((t) => !KW_BROAD.has(lc(t)) && !KW_UMBRELLA.has(lc(t)) && !KW_KIND.has(lc(t)) && !KW_GENERIC.has(lc(t)));
  const specific = ml.filter((t) => !KW_GENERIC.has(lc(t)));
  const alt: string[] = [];
  for (let i = 0; i < Math.max(narrow.length, specific.length); i++) { if (narrow[i]) alt.push(narrow[i]); if (specific[i]) alt.push(specific[i]); }
  const ordered = [...alt, ...se.filter((t) => KW_KIND.has(lc(t))), ...se.filter((t) => KW_BROAD.has(lc(t))), ...se.filter((t) => KW_UMBRELLA.has(lc(t)))];
  const all = [...ordered, ...[...se, ...ml].filter((t) => KW_GENERIC.has(lc(t)))];
  const present = new Set(all.map(lc));
  const seen = new Set<string>();
  const out: string[] = [];
  all.forEach((t) => {
    const k = longTag(vocab, t);
    if (seen.has(k) || (KW_SPECIFIC[lc(t)] || []).some((s) => present.has(s))) return;
    if (KW_GENERIC.has(lc(t)) && out.length >= 4) return;
    seen.add(k); out.push(t);
  });
  return max ? out.slice(0, max) : out;
}
// Plain, non-interactive keyword chips (filtering lives on /publications/).
function KeywordChips({ tags, label = "Keywords" }: { tags: string[]; label?: string }) {
  const vocab = React.useContext(TagVocab);
  if (!tags.length) return null;
  return (
    <ul className="pub-tags" aria-label={label}>
      {tags.map((t) => {
        const long = vocab[t];
        return <li key={t}>{long && long !== t ? <abbr title={long}>{t}</abbr> : t}</li>;
      })}
    </ul>
  );
}
function PubTags({ item, max = 4 }: { item: Publication; max?: number }) {
  const vocab = React.useContext(TagVocab);
  return <KeywordChips tags={pubKeywords(item, vocab, max)} />;
}
// Short label for a paper: the part of the title before the first colon (or up to a
// question mark), otherwise the whole title. Never cut mid-phrase.
function pubShortTitle(p: Publication): string {
  const title = String(p.title || "").trim();
  const m = title.match(/^(.+?\?)\s/) || title.match(/^(.+?):\s/);
  return (m ? m[1] : title).trim();
}
// "pp. 295-307" -> "pp. 295–307", kept on one line.
function pubPages(p: Publication): string {
  const raw = String(p.pages || "").replace(/^\s*pp?\.\s*/i, "").trim()
    || (p.first_page ? `${p.first_page}${p.last_page ? `–${p.last_page}` : ""}` : "");
  return raw ? `pp. ${raw.replace(/\s*[-–]+\s*/, "–")}` : "";
}
// For places where the venue is itself wrapped in parentheses: "FSE 2026 (PACMSE)" -> "FSE 2026, PACMSE".
function venueInline(v: string): string { return String(v || "").replace(/\s*\(([^()]+)\)/g, ", $1"); }
function pubVenueShort(p: Publication): string {
  if (p.venue_short) return String(p.venue_short);
  const m = String(p.venue || "").match(/\(([A-Z][A-Za-z-]{1,12})\)/);
  return m ? `${m[1]} ${p.year || ""}`.trim() : String(p.year || "");
}

// Bold the owner's name inside an author string, keeping everything else as text.
const OWNER_RE = /(Palash\s+Ranjan\s+Roy|Palash\s+R\.?\s+Roy|Palash\s+Roy|P\.\s*R\.\s*Roy|P\.\s*Roy|Roy,\s*P(?:alash)?\.?(?:\s*R(?:anjan)?\.?)?)/g;
const OWNER_ONE = new RegExp(`^(?:${OWNER_RE.source})$`);
function AuthorLine({ authors, item }: { authors: string; item?: Publication }) {
  const eq = item ? equalContribution(item) : new Set<string>();
  if (item && eq.size) {
    // Equal-contribution authors get an asterisk; the order stays as published.
    const names = pubAuthorList(item);
    return (
      <>
        {names.map((n, i) => {
          const star = nameKeys(n).some((k) => eq.has(k)) || eq.has(nameKey(n));
          const mark = star ? <><sup aria-hidden="true">*</sup><span className="sr-only"> (equal contribution)</span></> : null;
          return (
            <React.Fragment key={i}>
              {i > 0 && ", "}
              {OWNER_ONE.test(n) ? <strong>{n}{mark}</strong> : <>{n}{mark}</>}
            </React.Fragment>
          );
        })}
      </>
    );
  }
  const parts = authors.split(OWNER_RE);
  return <>{parts.map((s, i) => (i % 2 === 1 ? <strong key={i}>{s}</strong> : <React.Fragment key={i}>{s}</React.Fragment>))}</>;
}
function EqualNote({ item }: { item: Publication }) {
  if (!equalContribution(item).size) return null;
  return <p className="pub-note"><span aria-hidden="true">* </span>Equal contribution (co-first authors)</p>;
}

// Build BibTeX / APA / IEEE citation strings from a publication's fields.
function buildCitations(item: Publication) {
  const authorsStr = pubAuthors(item);
  const authorsList = authorsStr.split(/\s*,\s*(?:and\s+)?|\s+and\s+/).map((a) => a.trim()).filter(Boolean);
  const bibAuthors = authorsList.join(" and ");
  const surname = ((authorsList[0] || "").split(/\s+/).pop() || "ref").replace(/[^A-Za-z]/g, "").toLowerCase() || "ref";
  const firstWord = ((item.title.match(/[A-Za-z]+/) || ["ref"])[0]).toLowerCase();
  const key = `${surname}${item.year || ""}${firstWord}`;
  const pageStr = item.pages ? item.pages.replace(/^\s*pp\.\s*/i, "")
    : (item.first_page ? `${item.first_page}${item.last_page ? `--${item.last_page}` : ""}` : "");
  const doi = normDoi(item.doi);
  const doiUrl = doi ? `https://doi.org/${doi}` : "";
  const isJournal = /journal/i.test(String(item.type || ""));
  const bibtex = (item.bibtex && item.bibtex.trim())
    ? item.bibtex.trim()
    : [
      `@${isJournal ? "article" : "inproceedings"}{${key},`,
      `  author    = {${bibAuthors}},`,
      `  title     = {${item.title}},`,
      `  ${isJournal ? "journal  " : "booktitle"} = {${item.venue_full || item.venue}},`,
      item.year ? `  year      = {${item.year}},` : null,
      item.volume ? `  volume    = {${item.volume}},` : null,
      item.issue ? `  number    = {${item.issue}},` : null,
      pageStr ? `  pages     = {${pageStr}},` : null,
      doi ? `  doi       = {${doi}},` : null,
      `}`,
    ].filter(Boolean).join("\n");
  const apa = `${authorsStr} (${item.year}). ${item.title}. ${item.venue}${pageStr ? `, ${pageStr}` : ""}.${doiUrl ? ` ${doiUrl}` : ""}`;
  const ieee = `${authorsStr}, "${item.title}," ${item.venue}, ${item.year}${pageStr ? `, pp. ${pageStr}` : ""}.${doi ? ` doi: ${doi}.` : ""}`;
  return { bibtex, apa, ieee };
}

function CiteModal({ item, onClose }: { item: Publication; onClose: () => void }) {
  const boxRef = useRef<HTMLDivElement>(null);
  useModalBehaviour(onClose, boxRef);
  const [fmt, setFmt] = useState<"bibtex" | "apa" | "ieee">("bibtex");
  const [copied, setCopied] = useState(false);
  const text = buildCitations(item)[fmt];
  const copy = async () => {
    try { await navigator.clipboard.writeText(text); setCopied(true); setTimeout(() => setCopied(false), 1500); } catch { /* ignore */ }
  };
  return createPortal(
    <div className="modal-backdrop" onClick={onClose}>
      <div ref={boxRef} className="modal" role="dialog" aria-modal="true" aria-labelledby="cite-title" onClick={(e) => e.stopPropagation()}>
        <div className="flex items-start justify-between gap-3 mb-3">
          <div id="cite-title" className="font-semibold text-[15px] leading-snug">Cite: {item.title}</div>
          <button onClick={onClose} aria-label="Close" className="icon-btn shrink-0" style={{ width: 32, height: 32 }}>
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18" /></svg>
          </button>
        </div>
        <div className="flex gap-2 mb-3" role="group" aria-label="Citation format">
          {(["bibtex", "apa", "ieee"] as const).map((f) => (
            <button key={f} type="button" aria-pressed={fmt === f} onClick={() => setFmt(f)}
              className={`btn ${fmt === f ? "btn-solid" : "btn-ghost"}`} style={{ minHeight: 32, padding: "0 12px", fontSize: 14 }}>
              {f === "bibtex" ? "BibTeX" : f.toUpperCase()}
            </button>
          ))}
        </div>
        <pre className="bg-[var(--c-bg)] border border-[var(--c-border)] rounded p-3 text-[13px] muted overflow-x-auto whitespace-pre-wrap break-words max-h-64">{text}</pre>
        <div className="flex justify-end items-center gap-3 mt-3">
          <span className="tiny muted" aria-live="polite">{copied ? "Copied" : ""}</span>
          <button onClick={copy} className="btn btn-solid" autoFocus>Copy</button>
        </div>
      </div>
    </div>,
    document.body
  );
}

// Links for one paper. Every repeated label (PDF, DOI ...) gets an accessible name that
// includes the paper's short title, so a screen-reader links list stays meaningful.
function paperLinks(item: Publication, opts: { onCite: () => void; onVideo: () => void; withPage?: boolean; toolName?: string }): React.ReactNode[] {
  const slug = pubSlug(item);
  const page = `/publications/${slug}/`;
  const doi = normDoi(item.doi);
  const arxiv = pubArxiv(item);
  const pdf = pubPdf(item);
  const t = pubShortTitle(item);
  const al = (label: string) => `${label}: ${t}`;
  const otherPreprint = item.preprint && isExternal(item.preprint) && !/arxiv\.org/i.test(item.preprint)
    && !(doi && item.preprint.includes(doi)) && !isPdfUrl(item.preprint) ? item.preprint : "";
  // Artifact links, labelled by what they are. One URL is linked once (Tool wins over
  // Code, Dataset over Replication package).
  const url = (v: any) => { const s = fieldStr(v); return s ? normUrl(s) || "" : ""; };
  const tool = url(item.tool_url);
  const dataset = url(item.dataset_url);
  const code = url(item.code_url) && urlKey(url(item.code_url)) !== urlKey(tool) ? url(item.code_url) : "";
  const artifact = url(item.artifact_url) && urlKey(url(item.artifact_url)) !== urlKey(dataset) ? url(item.artifact_url) : "";
  const handle = url(item.handle);
  const slides = url(item.slides_url);
  const video = fieldStr(item.video) ? (normUrl(fieldStr(item.video)) || "") : "";
  const repo = tool.match(/^https?:\/\/(?:www\.)?github\.com\/[^/]+\/([^/?#]+)/i);
  const toolName = opts.toolName || noEmoji(fieldStr((item as any).tool_name));
  const toolLabel = toolName ? `Tool (${toolName})` : repo ? `Tool (${repo[1].replace(/\.git$/, "")})` : "Tool";
  const links: React.ReactNode[] = [];
  if (opts.withPage !== false) links.push(<a key="page" href={page} aria-label={al("Paper page")}>Paper page</a>);
  if (pdf) links.push(<a key="pdf" href={pdf} {...extProps(pdf)} aria-label={al("PDF")}>PDF</a>);
  if (doi) links.push(<a key="doi" href={`https://doi.org/${doi}`} target="_blank" rel="noopener noreferrer" aria-label={al("DOI")}>DOI</a>);
  if (arxiv) links.push(<a key="arxiv" href={`https://arxiv.org/abs/${arxiv}`} target="_blank" rel="noopener noreferrer" aria-label={al("arXiv")}>arXiv</a>);
  if (handle) {
    // A thesis's full text lives in its university repository (USask HARVEST for handle prefix 10388).
    const repoName = /hdl\.handle\.net\/10388\//i.test(handle) || /harvest\.usask\.ca/i.test(handle) ? "USask HARVEST" : "";
    const what = pubType(item) === "thesis" ? `the ${noEmoji((item as any).dissertation_name) || "thesis"}` : t;
    links.push(<a key="handle" href={handle} {...extProps(handle)} aria-label={`Full text of ${what}${repoName ? ` on ${repoName}` : ""}`}>{repoName ? `Full text (${repoName})` : "Full text"}</a>);
  }
  if (otherPreprint) links.push(<a key="pre" href={otherPreprint} target="_blank" rel="noopener noreferrer" aria-label={al("Preprint")}>Preprint</a>);
  if (code) links.push(<a key="code" href={code} {...extProps(code)} aria-label={al("Code")}>Code</a>);
  if (artifact) links.push(<a key="art" href={artifact} {...extProps(artifact)} aria-label={al("Replication package")}>Replication package</a>);
  if (dataset) links.push(<a key="data" href={dataset} {...extProps(dataset)} aria-label={al("Dataset")}>Dataset</a>);
  if (tool) links.push(<a key="tool" href={tool} {...extProps(tool)} aria-label={al(toolLabel)}>{toolLabel}</a>);
  if (slides) links.push(<a key="slides" href={slides} {...extProps(slides)} aria-label={`Slides for ${t}`}>Slides</a>);
  if (video) links.push(ytId(video)
    ? <button key="vid" type="button" onClick={opts.onVideo} aria-label={`Video for ${t}`}>Video</button>
    : <a key="vid" href={video} {...extProps(video)} aria-label={`Video for ${t}`}>Video</a>);
  links.push(<button key="bib" type="button" onClick={opts.onCite} aria-label={al("BibTeX")}>BibTeX</button>);
  return links;
}
const hasVideo = (p: Publication) => !!fieldStr(p.video) && !!normUrl(fieldStr(p.video));

function VenueLine({ item, showType = true }: { item: Publication; showType?: boolean }) {
  const type = pubType(item);
  let venue = noEmoji(item.venue);
  // A thesis names its degree and university ("M.Sc. thesis, University of Saskatchewan").
  if (type === "thesis" && !/thesis|dissertation/i.test(venue)) {
    venue = [`${noEmoji((item as any).degree) || "M.Sc."} thesis`, noEmoji((item as any).institution) || "University of Saskatchewan"].join(", ");
  }
  const venueHasYear = item.year && venue.includes(String(item.year));
  const pages = pubPages(item);
  const label = type ? typeLabel(type) : "";
  // No tag when the venue already says it ("... Doctoral Symposium", "M.Sc. thesis ...").
  const showTag = showType && type && type !== "conference" && type !== "journal"
    && !venue.toLowerCase().includes(label.toLowerCase()) && !(type === "thesis" && /thesis/i.test(venue));
  return (
    <>
      {showTag && <><span className="pub-tag">{label}</span>{" "}</>}
      <em>{venue}</em>{!venueHasYear && item.year ? `, ${item.year}` : ""}{pages && <>, <span className="nowrap">{pages}</span></>}
      {fieldStr(item.venue_rank) && <span className="pub-rank"> · {noEmoji(item.venue_rank)}</span>}
    </>
  );
}

// A companion entry (e.g. the poster of a full paper) shown as one line under its paper.
function CompanionLine({ item }: { item: Publication }) {
  const [citeOpen, setCiteOpen] = useState(false);
  const [videoOpen, setVideoOpen] = useState(false);
  const type = pubType(item);
  const lead = type === "poster" ? "Also presented as a poster" : "Companion";
  return (
    <div className="pub-companion" id={`pub-${pubSlug(item)}`}>
      <span className="muted">{lead}: </span><a href={`/publications/${pubSlug(item)}/`} aria-label={`${lead}: ${item.title}`}><em>{venueInline(noEmoji(item.venue_short || item.venue).replace(/\s*\((poster|demo)\)\s*$/i, ""))}</em></a>
      <div className="link-row">{paperLinks(item, { onCite: () => setCiteOpen(true), onVideo: () => setVideoOpen(true), withPage: false })}</div>
      {citeOpen && <CiteModal item={item} onClose={() => setCiteOpen(false)} />}
      {videoOpen && hasVideo(item) && <VideoModal url={fieldStr(item.video)} onClose={() => setVideoOpen(false)} />}
    </div>
  );
}

function PublicationEntry({ item, companions = [], compact = false, headingLevel = 4, toolName }: { item: Publication; companions?: Publication[]; compact?: boolean; headingLevel?: 3 | 4 | 5; toolName?: string }) {
  const [citeOpen, setCiteOpen] = useState(false);
  const [videoOpen, setVideoOpen] = useState(false);
  const slug = pubSlug(item);
  const page = `/publications/${slug}/`;
  const award = noEmoji(item.award);
  const abstract = String(item.abstract || "").trim();
  const summary = abstract || String(item.description || "").trim();
  const links = paperLinks(item, { onCite: () => setCiteOpen(true), onVideo: () => setVideoOpen(true), toolName });
  const H = `h${headingLevel}` as "h3" | "h4" | "h5";
  return (
    <article className={`pub${compact ? " pub-compact" : ""}`} id={`pub-${slug}`}>
      <H className="pub-title"><a href={page}>{item.title}</a></H>
      <p className="pub-authors"><AuthorLine authors={pubAuthors(item)} item={item} /></p>
      <EqualNote item={item} />
      <p className="pub-venue"><VenueLine item={item} /></p>
      {award && <p className="pub-award">{award}</p>}
      {!compact && fieldStr(item.my_role) && <p className="pub-role">Role: {noEmoji(item.my_role)}</p>}
      <PubTags item={item} />
      <div className="link-row mt-1">{links}</div>
      {!compact && summary && (
        <details className="abstract">
          <summary aria-label={`${abstract ? "Abstract" : "Summary"}: ${pubShortTitle(item)}`}>{abstract ? "Abstract" : "Summary"}</summary>
          <div>{summary}</div>
        </details>
      )}
      {companions.map((c) => <CompanionLine key={pubSlug(c)} item={c} />)}
      {citeOpen && <CiteModal item={item} onClose={() => setCiteOpen(false)} />}
      {videoOpen && hasVideo(item) && <VideoModal url={fieldStr(item.video)} onClose={() => setVideoOpen(false)} />}
    </article>
  );
}

// Splits the publication list for the homepage: posters/companions that share a short
// title with a full paper become a sub-line of that paper; flagged papers are "Selected"
// (with a seed list when the database copy has no flags yet); the rest are "Other".
const SEED_SELECTED = ["cross-domain-clone-detection-model-merging", "mergese", "carbon-taxed-transformers", "gptclonebench", "classical-clone-detectors-ai-era"];
const isTrue = (v: any) => v === true || v === 1 || /^(true|yes|1)$/i.test(String(v ?? ""));
function planPublications(pubs: Publication[]) {
  const companionsOf = new Map<string, Publication[]>();
  const isCompanion = new Set<Publication>();
  pubs.forEach((p) => {
    const t = pubType(p);
    const of = String((p as any).companion_of || "").trim();
    if (!of && t !== "poster" && !/companion/i.test(String(p.venue_short || ""))) return;
    const parent = (of && pubs.find((q) => q !== p && pubSlug(q) === of)) || pubs.find((q) => q !== p && pubType(q) !== "poster" && pubShortTitle(q).toLowerCase() === pubShortTitle(p).toLowerCase());
    if (!parent) return;
    const k = pubSlug(parent);
    companionsOf.set(k, [...(companionsOf.get(k) || []), p]);
    isCompanion.add(p);
  });
  const main = pubs.filter((p) => !isCompanion.has(p));
  const anyFlag = pubs.some((p) => p.selected !== undefined && p.selected !== null && p.selected !== "");
  let selected = anyFlag ? main.filter((p) => isTrue(p.selected)) : main.filter((p) => SEED_SELECTED.includes(pubSlug(p)));
  if (!selected.length && !anyFlag) selected = [];
  const sel = new Set(selected);
  const byYearDesc = (a: Publication, b: Publication) => (Number(b.year) || 0) - (Number(a.year) || 0);
  selected = selected.slice().sort(byYearDesc);
  const other = main.filter((p) => !sel.has(p));
  return { selected, other, companionsOf };
}

function OtherPublications({ items, companionsOf }: { items: Publication[]; companionsOf: Map<string, Publication[]> }) {
  const byYear = new Map<string, Publication[]>();
  items.forEach((p) => {
    const y = String(p.year || "").trim() || "Other";
    if (!byYear.has(y)) byYear.set(y, []);
    byYear.get(y)!.push(p);
  });
  const years = Array.from(byYear.keys()).sort((a, b) => (b === "Other" ? -1 : a === "Other" ? 1 : b.localeCompare(a)));
  return (
    <div>
      {years.map((y) => (
        <section key={y} aria-label={`Other publications from ${y}`}>
          <h4 className="pub-year">{y}</h4>
          {byYear.get(y)!.map((p, i) => <PublicationEntry key={pubSlug(p) + i} item={p} compact companions={companionsOf.get(pubSlug(p)) || []} headingLevel={5} />)}
        </section>
      ))}
    </div>
  );
}

// ---------------------------------------------------------------------------
// Research themes: each theme links to the papers that match it.
// ---------------------------------------------------------------------------
const STOP = new Set(["the", "and", "for", "with", "from", "into", "via", "using", "based", "towards", "toward"]);
function stems(s: string): string[] {
  return String(s || "").toLowerCase().replace(/[^a-z0-9\s-]/g, " ").split(/[\s-]+/)
    .filter((w) => w.length > 2 && !STOP.has(w)).map((w) => w.slice(0, 5));
}
type Theme_ = { label: string; description?: string; papers?: string[]; also?: boolean; summary?: string; keywords?: string[]; keyResult?: string };
function papersForTheme(theme: Theme_, pubs: Publication[]): Publication[] {
  if (theme.papers && theme.papers.length) {
    const want = theme.papers.map((s) => slugify(s));
    return want.map((w) => pubs.find((p) => pubSlug(p) === w)).filter((p): p is Publication => !!p);
  }
  // Older database copies have only theme labels: match a paper's exact topic tags, or
  // every word of the label in its title. Abstracts are not searched (too loose).
  const need = stems(theme.label).filter((w) => w !== "ai" && w.length > 2);
  if (!need.length) return [];
  const label = theme.label.toLowerCase();
  return pubs.filter((p) => {
    const tagged = [...asList(p.relevant_to), ...asList(p.keywords)].map((t) => t.toLowerCase());
    if (tagged.some((t) => t === label)) return true;
    const set = new Set(stems(p.title));
    return need.every((w) => set.has(w));
  });
}
function shortVenueLabel(p: Publication): string {
  let v = String(pubVenueShort(p) || p.year || "").replace(/\s*\([^()]*\)/g, "").replace(/\bDoctoral Symposium\b/i, "DS")
    .replace(/\bTool Track\b/i, "Tool").replace(/\bResearch Track\b/i, "").replace(/\b(?:19|20)(\d{2})\b/g, "'$1").replace(/\s{2,}/g, " ").trim();
  if (/distinguished paper/i.test(noEmoji(p.award))) v += " · Distinguished Paper";
  return v;
}
function readThemes(research: any): Theme_[] {
  if (Array.isArray(research?.themes) && research.themes.length) {
    return research.themes.map((t: any) => typeof t === "string" ? { label: noEmoji(t) } : {
      label: noEmoji(t?.title || t?.label), description: t?.description ? String(t.description) : undefined,
      papers: asList(t?.paper_slugs).length ? asList(t?.paper_slugs) : asList(t?.papers),
      also: isTrue(t?.also) || isTrue(t?.minor),
      summary: noEmoji(t?.summary) || undefined, keywords: asList(t?.keywords).map(noEmoji).filter(Boolean), keyResult: noEmoji(t?.key_result) || undefined,
    }).filter((t: any) => t.label);
  }
  if (Array.isArray(research?.nodes)) {
    return research.nodes.map((n: any) => ({ label: noEmoji(typeof n === "string" ? n : n?.label) })).filter((t: any) => t.label);
  }
  const hubs = Array.isArray(research?.hubs) ? research.hubs : [];
  const leaves = Array.isArray(research?.leaves) ? research.leaves : [];
  return [...hubs, ...leaves].map((l: any) => ({ label: noEmoji(l) })).filter((t) => t.label);
}

// ---------------------------------------------------------------------------
// Software & data. software.json items {name, description, url, code_url, paper_slug,
// kind}; when no list exists, rows are derived from the papers' tool/code/dataset links.
// ---------------------------------------------------------------------------
type SoftwareRow = { name: string; note?: string; from?: string; fromHref?: string; kind?: string; links: { label: string; url: string }[] };
function listOf(v: any, key = "items"): any[] {
  if (Array.isArray(v)) return v;
  if (v && typeof v === "object" && Array.isArray(v[key])) return v[key];
  return [];
}
// Tools & Benchmarks (owner rule): an item is listed only when it is itself the subject
// of a published paper, so every row needs a paper_slug that exists in publications.
// Doctoral-symposium papers, theses and posters are not tool/benchmark papers. A legacy
// row without publication_kind qualifies only when the paper is named after it
// ("MergeSE: ...", "GPTCloneBench: ..."); the other round-2 rows stay on their papers.
const NOT_TOOL_PAPER = new Set(["doctoral_symposium", "thesis", "poster"]);
function hostLabel(u: string, fallback: string): string {
  if (/github\.com/i.test(u)) return "GitHub";
  if (/zenodo\.org|doi\.org\/10\.5281\//i.test(u)) return "Zenodo";
  return fallback;
}
function readSoftware(items: any[], pubs: Publication[]): SoftwareRow[] {
  const bySlug = (s: any) => (fieldStr(s) ? pubs.find((p) => pubSlug(p) === slugify(String(s))) : undefined);
  const rows: SoftwareRow[] = [];
  items.forEach((s) => {
    if (!s || !s.name || isTrue(s.hidden)) return;
    const p = bySlug(s.paper_slug);
    if (!p || NOT_TOOL_PAPER.has(pubType(p))) return;
    const pk = String(s.publication_kind || "").toLowerCase();
    const kind = pk || String(s.kind || "").toLowerCase();
    if (pk ? !/^(tool|benchmark|dataset)$/.test(pk) : pubShortTitle(p).toLowerCase() !== noEmoji(s.name).toLowerCase()) return;
    const links: { label: string; url: string }[] = [];
    const add = (raw: any, fallback: string) => {
      const u = fieldStr(raw) && normUrl(fieldStr(raw));
      if (u && !links.some((l) => urlKey(l.url) === urlKey(u))) links.push({ label: hostLabel(u, fallback), url: u });
    };
    add(s.url, kind === "tool" ? "Website" : "Data");
    add(s.code_url, "Code");
    add(s.dataset_url, "Data");
    add(s.artifact_url, "Archive");
    // The paper's own archived artifact (e.g. the MergeSE Zenodo record).
    add(p.dataset_url, "Data");
    add(p.artifact_url, "Archive");
    rows.push({ name: noEmoji(s.name), note: s.description ? noEmoji(s.description) : undefined, kind, from: venueInline(pubVenueShort(p)), fromHref: `#pub-${pubSlug(p)}`, links });
  });
  return rows;
}

// ---------------------------------------------------------------------------
// Awards & News
// ---------------------------------------------------------------------------
function maxYear(s: string): number {
  const t = String(s);
  const ys = (t.match(/\b(19|20)\d{2}\b/g) || []).map(Number);
  if (/present|current|now/i.test(t)) ys.push(9999);
  return ys.length ? Math.max(...ys) : 0;
}
function yearSpan(s: string): [number, number] {
  const t = String(s);
  const ys = (t.match(/\b(19|20)\d{2}\b/g) || []).map(Number);
  if (!ys.length) return [0, 0];
  const hi = /present|current|now/i.test(t) ? new Date().getFullYear() : Math.max(...ys);
  return [Math.min(...ys), hi];
}
function inferTag(title: string, fallback: string): string {
  if (/\b(awards?|awarded|prizes?|honou?rs?|scholarships?|nominated|nominee|fellowships?|winner|won)\b/i.test(title)) return "Award";
  if (/\b(paper|papers|published|accepted|poster|publication|preprint)\b/i.test(title)) return "Paper";
  if (/\b(president|serving|elected|appointed|joined|chair|member|role)\b/i.test(title)) return "Role";
  return fallback;
}
function sigStems(s: string): Set<string> {
  const drop = new Set(["won", "recei", "award", "at", "the", "in", "of", "for", "and"]);
  return new Set(stems(s.replace(/\b(19|20)\d{2}\b/g, "")).filter((w) => !drop.has(w)));
}
function overlaps(a: string, b: string, min = 0.75): boolean {
  const x = sigStems(a), y = sigStems(b);
  const [small, big] = x.size <= y.size ? [x, y] : [y, x];
  if (small.size < 2) return false;
  let hit = 0; small.forEach((w) => { if (big.has(w)) hit++; });
  return hit / small.size >= min;
}
const urlKey = (u: any) => String(u || "").trim().replace(/^https?:\/\/(www\.)?/i, "").replace(/[/#?]+$/, "").toLowerCase();

type AwardRow = { date: string; year: number; title: string; org?: string; url?: string; press?: string; pressTitle?: string; source?: string; order: number; iso?: string; pinned?: boolean; paperUrl?: string; scholarship?: boolean };
function splitTitleYear(raw: string): { title: string; date: string; year: number } {
  const ym = raw.match(/\(((?:19|20)\d{2}(?:\s*[-–]\s*(?:19|20)?\d{2,4})?)\)\s*$/);
  const title = ym ? raw.slice(0, ym.index).trim() : raw;
  return { title, date: ym ? fmtPeriod(ym[1]) : "", year: maxYear(ym ? ym[1] : raw) };
}
const isNomination = (h: any) => String(h?.kind || "").toLowerCase() === "nomination" || /\bnomin(at|ee)/i.test(String(h?.title || ""));
// The pre-round-3 combined IWSC entry; ignored once the two split entries exist.
const RE_COMBINED_IWSC = /best paper award and student encouragement award/i;
const ISO_RE = /^\d{4}-\d{2}(-\d{2})?$/;
function buildAwards(highlights: HighlightItem[], media: any[], pubs: Publication[] = []): { rows: AwardRow[]; usedMedia: Set<any> } {
  const usedMedia = new Set<any>();
  const rows: AwardRow[] = [];
  const split = highlights.some((h) => h && /student encouragement award/i.test(String(h.title || "")) && !RE_COMBINED_IWSC.test(String(h.title || "")));
  highlights.forEach((h, order) => {
    if (!h || !h.title || isNomination(h)) return;
    if (split && RE_COMBINED_IWSC.test(String(h.title))) return;
    const { title, date, year } = splitTitleYear(noEmoji(h.title));
    const hd = fieldStr(h.date);
    const hy = fieldStr(h.year);
    const m = media.find((x) => x && x.url && ((h.link && urlKey(x.url) === urlKey(h.link))));
    if (m) usedMedia.add(m);
    // A paper award links to its paper page (explicit paper_slug, a link to the page, or
    // the paper whose `award` field names it); the news/source link stays alongside.
    const ps = fieldStr(h.paper_slug || (h as any).paper);
    const linked = String(h.link || "").match(/^\/publications\/([^/]+)\/?$/);
    const p = (ps && pubs.find((q) => pubSlug(q) === slugify(ps)))
      || (linked && pubs.find((q) => pubSlug(q) === linked[1]))
      || pubs.find((q) => q.award && pubType(q) !== "poster" && noEmoji(q.award).toLowerCase().startsWith(title.toLowerCase()));
    const paperUrl = p ? `/publications/${pubSlug(p)}/` : undefined;
    const ext = linked ? undefined : (h.link || undefined);
    rows.push({
      date: date || (hy ? fmtPeriod(hy) : (ISO_RE.test(hd) ? hd.slice(0, 4) : fmtPeriod(hd))),
      year: year || maxYear(hy || hd), title, org: noEmoji(h.organization) || undefined,
      url: paperUrl || (m ? undefined : ext), press: m ? m.url : undefined,
      // The article's own title and outlet name the Press link (they are not shown as a row).
      pressTitle: m ? [noEmoji(m.title), noEmoji(m.outlet || m.source || m.publisher)].filter(Boolean).join(", ") || undefined : undefined,
      source: paperUrl && !m ? ext : undefined, order, iso: ISO_RE.test(hd) ? hd : undefined, pinned: isTrue(h.pinned), paperUrl,
      scholarship: /^scholarships?$/i.test(String(h.kind || "")),
    });
  });
  rows.sort((a, b) => (b.year - a.year) || (a.order - b.order));
  return { rows, usedMedia };
}
function AwardsList({ rows }: { rows: AwardRow[] }) {
  return (
    <ul className="row-list">
      {rows.map((r, i) => (
        <li key={i} className="row">
          <div className="row-date">{r.date}</div>
          <div className="row-main">
            <span className="row-title">{r.url ? <a href={r.url} className="title-link" {...extProps(r.url)}>{r.title}</a> : r.title}</span>
            {(r.org || r.press || r.source) && (
              <div className="row-meta">
                {r.org}
                {r.press && <>{r.org ? " · " : ""}<a href={r.press} {...extProps(r.press)} aria-label={r.pressTitle ? `Press: ${r.pressTitle}` : `Press coverage: ${r.title}`} title={r.pressTitle}>Press</a></>}
                {r.source && <>{r.org || r.press ? " · " : ""}<a href={r.source} {...extProps(r.source)} aria-label={`Source: ${r.title}`}>Source</a></>}
              </div>
            )}
          </div>
        </li>
      ))}
    </ul>
  );
}

type InLink = { text: string; url: string; label?: string };
// Optional links inside a one-line item ({text, url, label}): each `text` must occur in the
// title; it becomes its own link (one link per named paper, WCAG 2.4.4). Accepts the admin's
// "text | url | label" lines too.
function readInLinks(raw: any, title: string): InLink[] {
  const list = Array.isArray(raw) ? raw : typeof raw === "string" ? raw.split(/\r?\n/) : [];
  return list.map((x: any) => {
    if (typeof x === "string") { const [text, url, label] = x.split("|").map((t) => t.trim()); return { text, url, label }; }
    return { text: noEmoji(x?.text), url: fieldStr(x?.url), label: noEmoji(x?.label) };
  }).filter((l: InLink) => l.text && l.url && normUrl(l.url) && title.includes(l.text)).map((l: InLink) => ({ ...l, url: normUrl(l.url)! }));
}
function LinkedText({ text, links }: { text: string; links?: InLink[] }) {
  if (!links || !links.length) return <>{text}</>;
  const out: React.ReactNode[] = [];
  let rest = text, k = 0;
  while (rest) {
    let best: { i: number; l: InLink } | null = null;
    links.forEach((l) => { const i = rest.indexOf(l.text); if (i >= 0 && (!best || i < best.i)) best = { i, l }; });
    if (!best) { out.push(rest); break; }
    const { i, l } = best as { i: number; l: InLink };
    if (i > 0) out.push(rest.slice(0, i));
    out.push(<a key={k++} href={l.url} {...extProps(l.url)} aria-label={l.label || undefined}>{l.text}</a>);
    rest = rest.slice(i + l.text.length);
  }
  return <>{out}</>;
}
type NewsRow = { date: string; year: number; title: string; meta?: string; url?: string; tag: string; order: number; paper?: string; iso?: string; pinned?: boolean; links?: InLink[] };
function buildNews(news: any[], highlights: HighlightItem[], media: any[], usedMedia: Set<any>, roles: string[]): NewsRow[] {
  const hlTitles = highlights.filter((h) => h && h.title).map((h) => splitTitleYear(noEmoji(h.title)).title);
  const hlUrls = new Set(highlights.map((h) => urlKey(h?.link)).filter(Boolean));
  const roleKeys = roles.map((r) => noEmoji(r).toLowerCase()).filter((r) => r.length > 3);
  const rows: NewsRow[] = [];
  let order = 0;
  news.forEach((n) => {
    if (!n || !n.title) return;
    const title = noEmoji(n.title);
    const tag = noEmoji(n.tag) || inferTag(title, "News");
    if (/^(award|role|honou?r)s?$/i.test(tag)) return;
    if (n.url && hlUrls.has(urlKey(n.url))) return;
    if (hlTitles.some((t) => overlaps(t, title))) return;
    if (roleKeys.some((r) => title.toLowerCase().includes(r) && /\b(president|chair|member|elected|appointed)\b/i.test(title))) return;
    const d = String(n.date || "").trim();
    const links = readInLinks(n.links, title);
    rows.push({ date: /^\d{4}-\d{2}/.test(d) ? fmtMonthYear(d) : fmtPeriod(d), year: maxYear(d), title, url: links.length ? undefined : (n.url || n.link || undefined), tag, order: order++, paper: String(n.paper_slug || n.paper || "").trim() || undefined, iso: ISO_RE.test(d) ? d : undefined, pinned: isTrue(n.pinned), links: links.length ? links : undefined });
  });
  media.forEach((m) => {
    if (!m || !m.title || usedMedia.has(m)) return;
    if (m.url && hlUrls.has(urlKey(m.url))) return;
    rows.push({ date: fmtPeriod(m.date), year: maxYear(m.date), title: noEmoji(m.title), meta: noEmoji(m.outlet), url: m.url || undefined, tag: "Press", order: order++ });
  });
  // Drop duplicates (same year, one title's key words contained in the other's).
  const kept: NewsRow[] = [];
  rows.forEach((r) => {
    // Items that name different papers (paper_slug) are never duplicates of each other.
    const dup = kept.find((k) => k.year === r.year && !(k.paper && r.paper && k.paper !== r.paper) && overlaps(k.title, r.title));
    if (dup) { if (!dup.url && r.url) dup.url = r.url; }
    else kept.push(r);
  });
  return kept.sort((x, y) => (y.year - x.year) || (x.iso && y.iso ? y.iso.localeCompare(x.iso) : 0) || (x.order - y.order));
}

// A news line without its own link points at the paper it is about: an explicit
// paper_slug, else a paper whose short title or short venue ("ICSME 2025") it names.
function linkNewsToPapers(rows: NewsRow[], pubs: Publication[]): NewsRow[] {
  return rows.map((r) => {
    if (r.url || r.links) return r;
    const t = r.title.toLowerCase();
    const p = (r.paper && pubs.find((q) => pubSlug(q) === slugify(r.paper!)))
      || pubs.find((q) => pubType(q) !== "poster" && pubVenueShort(q) && t.includes(venueInline(pubVenueShort(q)).split(",")[0].toLowerCase()))
      || pubs.find((q) => pubType(q) !== "poster" && t.includes(pubShortTitle(q).toLowerCase()));
    return p ? { ...r, url: `#pub-${pubSlug(p)}` } : r;
  });
}

// ---------------------------------------------------------------------------
// "Latest": a short static list near the top. Pinned items (news, awards, leadership,
// service) first, then the newest dated news and awards. The News section below leaves
// out what is shown here; Awards keeps the full list.
// ---------------------------------------------------------------------------
type LatestRow = { key: string; dt: string; date: string; text: string; url?: string; pinned: boolean; sort: number; order: number; links?: InLink[] };
const newsKey = (r: NewsRow) => "news:" + r.title.toLowerCase();
function sortKeyOf(iso: string | undefined, year: number): number {
  if (iso) { const [y, m, d] = iso.split("-").map(Number); return y * 10000 + (m || 0) * 100 + (d || 0); }
  return (year >= 9999 ? new Date().getFullYear() : year) * 10000;
}
function buildLatest(opts: { news: NewsRow[]; awards: AwardRow[]; leadership: any[]; service: any[]; count: number }): LatestRow[] {
  const rows: LatestRow[] = [];
  let order = 0;
  const paperPage = (u?: string) => {
    const m = String(u || "").match(/^#pub-(.+)$/);
    return m ? `/publications/${m[1]}/` : u;
  };
  opts.news.forEach((r) => rows.push({
    key: newsKey(r), dt: r.iso || (r.year && r.year < 9999 ? String(r.year) : ""), date: r.iso ? fmtMonthYear(r.iso) : r.date,
    text: r.title, url: paperPage(r.url), pinned: !!r.pinned, sort: sortKeyOf(r.iso, r.year), order: order++, links: r.links,
  }));
  opts.awards.forEach((r) => rows.push({
    key: "award:" + r.title.toLowerCase(), dt: r.iso || (r.year ? String(r.year) : ""), date: r.iso ? fmtMonthYear(r.iso) : r.date,
    text: r.title, url: r.paperUrl || r.url || r.press, pinned: !!r.pinned, sort: sortKeyOf(r.iso, r.year), order: order++,
  }));
  // Roles and talks only appear here when the owner pins them.
  const roleRow = (it: any, text: string, url?: string) => {
    const d = fieldStr(it.date);
    const iso = ISO_RE.test(d) ? d : undefined;
    const year = maxYear(fieldStr(it.period));
    rows.push({ key: "role:" + text.toLowerCase(), dt: iso || (year && year < 9999 ? String(year) : ""), date: iso ? fmtMonthYear(iso) : fmtPeriod(it.period), text, url, pinned: true, sort: sortKeyOf(iso, year), order: order++ });
  };
  opts.leadership.forEach((it) => { if (it && it.role && isTrue(it.pinned)) roleRow(it, [noEmoji(it.role), noEmoji(it.organization)].filter(Boolean).join(", "), it.link || undefined); });
  opts.service.forEach((it) => {
    if (!it || !it.role || !isTrue(it.pinned)) return;
    const talk = String(it.category || "").toLowerCase() === "talk";
    const venue = noEmoji(it.venues || it.venue);
    roleRow(it, talk && it.description ? `Invited talk: ${noEmoji(it.description)}${venue ? `, ${venue}` : ""}` : [noEmoji(it.role), venue].filter(Boolean).join(", "), it.link || undefined);
  });
  // Curated (SPEC-round3 §19): only items the owner pinned, in list order; no automatic fill.
  const pinned = rows.filter((r) => r.pinned).sort((a, b) => a.order - b.order);
  const out: LatestRow[] = [];
  pinned.forEach((r) => {
    if (out.length < opts.count && !out.some((o) => o.key === r.key || overlaps(o.text, r.text))) out.push(r);
  });
  return out;
}
function LatestList({ rows, title, more }: { rows: LatestRow[]; title: string; more?: { href: string; label: string } }) {
  if (!rows.length) return null;
  return (
    <section className="latest" aria-labelledby="latest-h">
      <h2 id="latest-h" className="latest-title">{title}</h2>
      <ul className="latest-list">
        {rows.map((r) => (
          <li key={r.key}>
            {r.date && <><time dateTime={r.dt || undefined} className="latest-date">{r.date}</time><span className="latest-sep" aria-hidden="true"> · </span></>}
            {r.url ? <a href={r.url} {...extProps(r.url)}>{r.text}</a> : <span><LinkedText text={r.text} links={r.links} /></span>}
          </li>
        ))}
      </ul>
      {more && <a href={more.href} className="latest-more">{more.label}<span aria-hidden="true" className="latest-arrow">→</span></a>}
    </section>
  );
}

function NewsList({ rows }: { rows: NewsRow[] }) {
  // One tag on every row says nothing; show tags only when they tell rows apart.
  const sameTag = rows.length > 1 && rows.every((r) => r.tag === rows[0].tag);
  return (
    <ul className="row-list">
      {rows.map((r, i) => (
        <li key={i} className="row">
          <div className="row-date">{r.date}</div>
          <div className="row-main">
            <span className="row-title">{r.url ? <a href={r.url} className="title-link" {...extProps(r.url)}>{r.title}</a> : <LinkedText text={r.title} links={r.links} />}</span>
            {r.tag && !sameTag && <span className="tag">{r.tag}</span>}
            {r.meta && <div className="row-meta">{r.meta}</div>}
          </div>
        </li>
      ))}
    </ul>
  );
}

// ---------------------------------------------------------------------------
// Experience: roles from about.experience, leadership and service, each shown once.
// A role repeated with the same organisation (e.g. several supervision terms) becomes
// one row with all its periods. Governance committees sit under the leadership role
// they came with (by overlapping years), as sub-lines.
// ---------------------------------------------------------------------------
type RoleItem = { role: string; org: string; period: string; description?: string; link?: string; place?: string; src: "exp" | "lead" | "service"; committees?: any[]; talk?: boolean; category?: string; under?: string; id?: string; bullets?: string[]; order?: number };
type RoleRow = { role: string; org: string; orgNode?: React.ReactNode; periods: string[]; notes: { period: string; text: string }[]; year: number; span: [number, number]; subs: { role: string; period: string }[]; link?: string; src: RoleItem["src"]; id?: string; bullets: string[]; order?: number };
// Optional `order` (1 = first) places a row within its Experience group; rows without it follow, newest first.
const orderOf = (v: any): number | undefined => { const n = Number(String(v ?? "").trim()); return String(v ?? "").trim() !== "" && Number.isFinite(n) ? n : undefined; };
const normKey = (s: any) => noEmoji(s).toLowerCase().replace(/[^a-z0-9]/g, "");
const RE_TEACH = /\b(teach\w*|mentor\w*|supervis\w*|tutor\w*|instructor|lecturer|marker|grader)\b/i;
const RE_REVIEW = /\b(review\w*|referee|program committee|pc member|editor\w*)\b/i;
const RE_TALK = /\b(talks?|keynote|seminar|speaker|presentation|lecture series)\b/i;
const RE_COMMITTEE = /\b(committee|senate|council|board)\b/i;

function splitPlace(place: any): { org: string; dates: string } {
  const parts = String(place || "").split("|").map((s) => s.trim());
  return parts.length > 1 ? { org: noEmoji(parts.slice(0, -1).join(" | ")), dates: fmtPeriod(parts[parts.length - 1]) } : { org: noEmoji(parts[0]), dates: "" };
}
function Row({ date, title, sub, note }: { date?: React.ReactNode; title: React.ReactNode; sub?: React.ReactNode; note?: React.ReactNode }) {
  return (
    <li className="row">
      <div className="row-date">{typeof date === "string" ? dateCell(date) : date}</div>
      <div className="row-main">
        <div className="row-title">{title}</div>
        {sub && <div className="row-meta">{sub}</div>}
        {note && <div className="row-meta mt-0.5" style={{ maxWidth: "68ch" }}>{note}</div>}
      </div>
    </li>
  );
}
function mergeRoles(items: RoleItem[]): RoleRow[] {
  const rows: RoleRow[] = [];
  items.forEach((it) => {
    const k = normKey(it.role) + "|" + normKey(it.org);
    // "Spring-Summer 2023, Spring-Summer 2024" -> two periods.
    const periodsIn = String(it.period || "").split(/\s*[,;]\s*/).map(fmtPeriod).filter(Boolean);
    const period = periodsIn.join(", ");
    const existing = rows.find((r) => normKey(r.role) + "|" + normKey(r.org) === k);
    const note = it.description ? noEmoji(it.description) : "";
    if (existing) {
      periodsIn.forEach((p) => { if (!existing.periods.includes(p)) existing.periods.push(p); });
      if (note && !existing.notes.some((n) => n.text === note)) existing.notes.push({ period, text: note });
      (it.bullets || []).forEach((b) => { if (!existing.bullets.includes(b)) existing.bullets.push(b); });
      if (it.order !== undefined) existing.order = existing.order === undefined ? it.order : Math.min(existing.order, it.order);
      existing.year = Math.max(existing.year, maxYear(it.period));
      const s = yearSpan(it.period); existing.span = [Math.min(existing.span[0] || s[0], s[0] || existing.span[0]), Math.max(existing.span[1], s[1])];
      return;
    }
    rows.push({
      role: noEmoji(it.role), org: it.org, periods: periodsIn, notes: note ? [{ period, text: note }] : [],
      year: maxYear(it.period), span: yearSpan(it.period), subs: (it.committees || []).map((c: any) => typeof c === "string" ? { role: noEmoji(c), period: "" } : { role: noEmoji(c?.role || c?.title), period: fmtPeriod(c?.period) }).filter((c) => c.role),
      link: it.link, src: it.src, id: it.id, bullets: (it.bullets || []).slice(), order: it.order,
      orgNode: it.src === "lead" ? <>{it.link ? <a href={it.link} {...extProps(it.link)}>{it.org}</a> : it.org}{it.place ? `, ${noEmoji(it.place)}` : ""}</>
        : it.link ? (it.org ? <a href={it.link} {...extProps(it.link)}>{it.org}</a> : <a href={it.link} {...extProps(it.link)} aria-label={`Link: ${noEmoji(it.role)}`}>Link</a>) : undefined,
    });
  });
  rows.forEach((r) => {
    r.periods.sort((a, b) => maxYear(b) - maxYear(a));
    // "Spring–Summer 2024", "Spring–Summer 2023" -> one line "Spring–Summer 2023, 2024",
    // so the date rail stays one entry per row.
    const m = r.periods.map((p) => p.match(/^(.*?)\s*((?:19|20)\d{2})$/));
    if (r.periods.length > 1 && m.every((x) => x && x[1] && x[1] === m[0]![1])) {
      r.periods = [`${m[0]![1]} ${m.map((x) => x![2]).sort().join(", ")}`];
    }
  });
  // Newest first; a tie on the latest year (e.g. two "…–Present" roles) goes to the later start.
  // A row dated only "Present" (an ongoing role with no start year) goes first.
  const nowOnly = (r: RoleRow) => (r.year === 9999 && !r.span[0] ? 1 : 0);
  const ord = (r: RoleRow) => (r.order === undefined ? Infinity : r.order);
  return rows.sort((a, b) => (ord(a) - ord(b) || 0) || nowOnly(b) - nowOnly(a) || b.year - a.year || (b.span[0] || 0) - (a.span[0] || 0));
}
function RoleRows({ rows }: { rows: RoleRow[] }) {
  return (
    <ul className="row-list">
      {rows.map((r, i) => {
        const multi = r.notes.length > 1;
        return (
          <li key={i} className="row">
            {/* An undated role gets a quiet dash, so the date column never looks broken. */}
            <div className="row-date">{r.periods.length ? r.periods.map((p, j) => <div key={j}>{dateCell(p)}</div>) : <span aria-hidden="true" className="row-nodate">—</span>}</div>
            <div className="row-main">
              <div className="row-title">{r.role}</div>
              {(r.orgNode || r.org) && <div className="row-meta">{r.orgNode || r.org}</div>}
              {r.notes.length > 0 && (multi
                ? <ul className="row-notes">{r.notes.map((n, j) => <li key={j}>{n.period && <span className="nowrap">{n.period}: </span>}{rich(n.text)}</li>)}</ul>
                : <div className="row-meta mt-0.5" style={{ maxWidth: "68ch" }}>{rich(r.notes[0].text)}</div>)}
              {r.bullets.length > 0 && <ul className="row-bullets">{r.bullets.map((b, j) => <li key={j}>{rich(b)}</li>)}</ul>}
              {r.subs.length > 0 && (
                <ul className="row-bullets">
                  {r.subs.map((s, j) => <li key={j}>{s.role}{s.period && <span className="nowrap">, {s.period}</span>}</li>)}
                </ul>
              )}
            </div>
          </li>
        );
      })}
    </ul>
  );
}

// ---------------------------------------------------------------------------
// Contact
// ---------------------------------------------------------------------------
function ContactForm() {
  const [status, setStatus] = useState<"idle" | "sending" | "ok" | "error">("idle");
  const [err, setErr] = useState("");
  // Per-field errors, shown under the field and tied to it with aria-describedby.
  const [fieldErr, setFieldErr] = useState<Record<string, string>>({});
  const check = (n: string, v: string): string => {
    const t = v.trim();
    if (n === "name") return t ? "" : "Enter your name.";
    if (n === "email") return !t ? "Enter your email address." : /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(t) ? "" : "Enter a valid email address, e.g. name@example.com.";
    if (n === "message") return t ? "" : "Enter a message.";
    return "";
  };
  const submit = async (e: React.FormEvent<HTMLFormElement>) => {
    e.preventDefault();
    const f = e.currentTarget;
    const val = (n: string) => (f.elements.namedItem(n) as HTMLInputElement | HTMLTextAreaElement)?.value ?? "";
    const errs: Record<string, string> = {};
    ["name", "email", "message"].forEach((n) => { const m = check(n, val(n)); if (m) errs[n] = m; });
    setFieldErr(errs);
    const first = ["name", "email", "message"].find((n) => errs[n]);
    if (first) { (f.elements.namedItem(first) as HTMLElement | null)?.focus(); setStatus("idle"); return; }
    const data = { name: val("name"), email: val("email"), subject: val("subject"), message: val("message"), website: val("website") };
    setStatus("sending"); setErr("");
    try {
      const r = await fetch("/api/contact.php", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify(data) });
      const j = await r.json();
      if (j.ok) { setStatus("ok"); f.reset(); }
      else { setStatus("error"); setErr(j.error || `Something went wrong. Please try again, or email ${PUBLIC_EMAIL}.`); }
    } catch { setStatus("error"); setErr(`Network error. Please try again, or email ${PUBLIC_EMAIL}.`); }
  };
  // Clear a field's error once it is fixed (checked when the visitor leaves the field).
  const onBlur = (e: React.FocusEvent<HTMLInputElement | HTMLTextAreaElement>) => {
    const n = e.currentTarget.name;
    if (fieldErr[n] && !check(n, e.currentTarget.value)) setFieldErr((m) => { const c = { ...m }; delete c[n]; return c; });
  };
  const a11y = (n: string) => (fieldErr[n] ? { "aria-invalid": true as const, "aria-describedby": `cf-${n}-err` } : {});
  const Err = ({ n }: { n: string }) => (fieldErr[n] ? <p id={`cf-${n}-err`} className="field-error">{fieldErr[n]}</p> : null);
  return (
    <form onSubmit={submit} aria-label="Contact form" noValidate>
      {/* honeypot: hidden from people, tempting to bots */}
      <input type="text" name="website" tabIndex={-1} autoComplete="off" aria-hidden="true" className="hidden" />
      <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div>
          <label htmlFor="cf-name" className="field-label">Name</label>
          <input id="cf-name" name="name" required maxLength={120} autoComplete="name" className="field" onBlur={onBlur} {...a11y("name")} />
          <Err n="name" />
        </div>
        <div>
          <label htmlFor="cf-email" className="field-label">Email</label>
          <input id="cf-email" name="email" type="email" required maxLength={160} autoComplete="email" className="field" onBlur={onBlur} {...a11y("email")} />
          <Err n="email" />
        </div>
      </div>
      <div className="mt-4">
        <label htmlFor="cf-subject" className="field-label">Subject <span className="muted font-normal">(optional)</span></label>
        <input id="cf-subject" name="subject" maxLength={160} className="field" />
      </div>
      <div className="mt-4">
        <label htmlFor="cf-message" className="field-label">Message</label>
        <textarea id="cf-message" name="message" required maxLength={5000} rows={5} className="field" style={{ resize: "vertical" }} onBlur={onBlur} {...a11y("message")} />
        <Err n="message" />
      </div>
      <div className="mt-4 flex flex-wrap items-center gap-4">
        <button type="submit" disabled={status === "sending"} className="btn btn-solid">
          {status === "sending" ? "Sending…" : "Send message"}
        </button>
        <span role="status" aria-live="polite" className="small">
          {status === "ok" && <span style={{ color: "var(--c-ok)" }}>Thank you. Your message has been sent.</span>}
          {status === "error" && <span style={{ color: "var(--c-error)" }}>{err}</span>}
        </span>
      </div>
    </form>
  );
}

function CopyEmail({ email }: { email: string }) {
  const [copied, setCopied] = useState(false);
  const copy = async () => {
    try { await navigator.clipboard.writeText(email); setCopied(true); setTimeout(() => setCopied(false), 1500); } catch { /* ignore */ }
  };
  return (
    <span className="inline-flex items-center gap-3 flex-wrap">
      <a href={`mailto:${email}`} className="text-[17px] font-medium inline-flex items-center min-h-[44px]">{email}</a>
      <button type="button" onClick={copy} className="btn btn-ghost no-print" style={{ padding: "0 14px", fontSize: 14 }} aria-label={copied ? "Email address copied" : `Copy email address ${email}`}>
        {copied ? "Copied" : "Copy"}
      </button>
    </span>
  );
}

// ---------------------------------------------------------------------------
// Profile links: small monochrome logo + text label. Icons inherit the text colour
// (accent on hover); no brand colours, no external icon files.
// ---------------------------------------------------------------------------
const PLATFORM_LABEL: Record<string, string> = {
  scholar: "Google Scholar", orcid: "ORCID", dblp: "dblp", github: "GitHub", linkedin: "LinkedIn",
  researchgate: "ResearchGate", semanticscholar: "Semantic Scholar", openalex: "OpenAlex", website: "Website",
  twitter: "X", x: "X", youtube: "YouTube", facebook: "Facebook", instagram: "Instagram", email: "Email",
};
const PLATFORM_ORDER = ["scholar", "orcid", "dblp", "github", "linkedin", "researchgate", "semanticscholar", "openalex", "x", "twitter", "facebook", "instagram", "youtube", "website"];
const PATHS: Record<string, string> = {
  linkedin: "M0 1.146C0 .513.526 0 1.175 0h13.65C15.474 0 16 .513 16 1.146v13.708c0 .633-.526 1.146-1.175 1.146H1.175C.526 16 0 15.487 0 14.854V1.146zm4.943 12.248V6.169H2.542v7.225h2.401zm-1.2-8.212c.837 0 1.358-.554 1.358-1.248-.015-.709-.52-1.248-1.342-1.248-.822 0-1.359.54-1.359 1.248 0 .694.521 1.248 1.327 1.248h.016zm4.908 8.212V9.359c0-.216.016-.432.08-.586.173-.431.568-.878 1.232-.878.869 0 1.216.662 1.216 1.634v3.865h2.401V9.25c0-2.22-1.184-3.252-2.764-3.252-1.274 0-1.845.7-2.165 1.193v.025h-.016a5.54 5.54 0 0 1 .016-.025V6.169h-2.4c.03.678 0 7.225 0 7.225h2.4z",
  github: "M8 0C3.58 0 0 3.58 0 8c0 3.54 2.29 6.53 5.47 7.59.4.07.55-.17.55-.38 0-.19-.01-.82-.01-1.49-2.01.37-2.53-.49-2.69-.94-.09-.23-.48-.94-.82-1.130-.28-.15-.68-.52-.01-.53.63-.01 1.08.58 1.23.82.72 1.21 1.87.87 2.33.66.07-.52.28-.87.51-1.07-1.78-.2-3.64-.89-3.64-3.95 0-.87.31-1.59.82-2.15-.08-.2-.36-1.020.08-2.12 0 0 .67-.21 2.2.82.64-.18 1.32-.27 2-.27.68 0 1.36.09 2 .27 1.53-1.04 2.2-.82 2.2-.82.44 1.1.16 1.92.08 2.12.51.56.82 1.27.82 2.15 0 3.07-1.87 3.75-3.65 3.95.29.25.54.73.54 1.48 0 1.07-.01 1.93-.01 2.2 0 .21.15.46.55.38A8.012 8.012 0 0 0 16 8c0-4.42-3.58-8-8-8z",
  facebook: "M16 8.049c0-4.446-3.582-8.05-8-8.05C3.58 0-.002 3.603-.002 8.05c0 4.017 2.926 7.347 6.75 7.951v-5.625h-2.03V8.05H6.75V6.275c0-2.017 1.195-3.131 3.022-3.131.876 0 1.791.157 1.791.157v1.98h-1.009c-.993 0-1.303.621-1.303 1.258v1.51h2.218l-.354 2.326H9.25V16c3.824-.604 6.75-3.934 6.75-7.951z",
  instagram: "M8 0C5.829 0 5.556.01 4.703.048 3.85.088 3.269.222 2.76.42a3.917 3.917 0 0 0-1.417.923A3.927 3.927 0 0 0 .42 2.76C.222 3.268.087 3.85.048 4.7.01 5.555 0 5.827 0 8.001c0 2.172.01 2.444.048 3.297.04.852.174 1.433.372 1.942.205.526.478.972.923 1.417.444.445.89.719 1.416.923.51.198 1.09.333 1.942.372C5.555 15.990 5.827 16 8 16s2.444-.01 3.298-.048c.78-.035 1.204-.166 1.486-.275.373-.145.64-.319.92-.599.28-.28.453-.546.598-.92.11-.281.24-.705.276-1.485.038-.843.047-1.096.047-3.232s-.009-2.39-.047-3.233c-.036-.78-.166-1.203-.276-1.485a2.478 2.478 0 0 0-.598-.92 2.48 2.48 0 0 0-.92-.598c-.282-.11-.705-.24-1.486-.276C10.445.01 10.173 0 8 0zm0 1.442c2.136 0 2.389.008 3.232.046.78.036 1.204.166 1.486.276.373.145.64.319.92.599.28.28.453.546.598.92.11.282.24.705.276 1.486.038.843.046 1.096.046 3.232s-.008 2.389-.046 3.232c-.036.78-.166 1.204-.276 1.486a2.5 2.5 0 0 1-.598.92c-.28.28-.546.453-.92.598-.282.11-.705.24-1.486.276-.843.038-1.096.046-3.232.046s-2.389-.008-3.232-.046c-.78-.036-1.204-.166-1.486-.276a2.5 2.5 0 0 1-.92-.598 2.5 2.5 0 0 1-.598-.92c-.11-.282-.24-.705-.276-1.486-.038-.843-.046-1.096-.046-3.232s.008-2.389.046-3.232c.036-.78.166-1.204.276-1.486.145-.373.319-.64.599-.92.28-.28.546-.453.92-.598.282-.11.705-.24 1.486-.276C5.611 1.450 5.864 1.442 8 1.442zm0 2.452a4.108 4.108 0 1 0 0 8.215 4.108 4.108 0 0 0 0-8.215zm0 6.775a2.667 2.667 0 1 1 0-5.334 2.667 2.667 0 0 1 0 5.334zm5.23-6.937a.96.96 0 1 1-1.92 0 .96.96 0 0 1 1.92 0z",
  x: "M12.6.75h2.454l-5.36 6.142L16 15.25h-4.937l-3.867-5.07-4.425 5.07H.316l5.733-6.57L0 .75h5.063l3.495 4.633L12.601.75Zm-.86 13.028h1.36L4.323 2.145H2.865z",
  youtube: "M8.051 1.999h.089c.822.003 4.987.033 6.110.335a2.01 2.01 0 0 1 1.415 1.42c.101.38.172.883.22 1.402l.01.104.022.26.008.104c.065.914.073 1.77.074 1.957v.075c-.001.194-.01 1.108-.082 2.06l-.008.105-.009.104c-.05.572-.124 1.14-.235 1.558a2.01 2.01 0 0 1-1.415 1.42c-1.16.312-5.569.334-6.18.335h-.142c-.309 0-1.587-.006-2.927-.052l-.17-.006-.087-.004-.171-.007-.171-.007c-1.11-.049-2.167-.128-2.654-.26a2.01 2.01 0 0 1-1.415-1.419c-.111-.417-.185-.986-.235-1.558L.09 9.820l-.008-.104A31 31 0 0 1 0 7.680v-.123c.002-.215.01-.958.064-1.778l.007-.103.003-.052.008-.104.022-.26.01-.104c.048-.519.119-1.023.22-1.402a2.01 2.01 0 0 1 1.415-1.42c.487-.13 1.544-.21 2.654-.26l.17-.007.172-.006.086-.004.171-.006A100 100 0 0 1 7.858 2h.193zM6.4 5.209v4.818l4.157-2.408z",
  email: "M0 4a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2zm2-1a1 1 0 0 0-1 1v.217l7 4.2 7-4.2V4a1 1 0 0 0-1-1zm13 2.383-4.708 2.825L15 11.105zm-.034 6.876-5.64-3.471L8 9.583l-1.326-.795-5.64 3.470A1 1 0 0 0 2 13h12a1 1 0 0 0 .966-.741M1 11.105l4.708-2.897L1 5.383z",
};
PATHS.twitter = PATHS.x;
function ProfileIcon({ platform }: { platform: string }) {
  const p = platform === "twitter" ? "x" : platform;
  const common = { width: 16, height: 16, viewBox: "0 0 16 16", "aria-hidden": true as const, focusable: "false" as const, className: "profile-icon" };
  if (PATHS[p]) return <svg {...common} fill="currentColor"><path d={PATHS[p]} /></svg>;
  if (p === "orcid") return (
    <svg {...common} fill="none" stroke="currentColor">
      <circle cx="8" cy="8" r="7.2" strokeWidth="1.3" />
      <path d="M5.3 6.6v5" strokeWidth="1.4" />
      <circle cx="5.3" cy="4.6" r=".85" fill="currentColor" stroke="none" />
      <path d="M7.6 6.6h1.5a2.5 2.5 0 0 1 0 5H7.6z" strokeWidth="1.3" />
    </svg>
  );
  if (p === "scholar") return (
    <svg {...common} fill="currentColor"><path d="M8 1 0 6.4h16z" /><circle cx="8" cy="10.6" r="4" /></svg>
  );
  if (p === "researchgate") return (
    <svg {...common} fill="none" stroke="currentColor">
      <rect x=".8" y=".8" width="14.4" height="14.4" rx="2" strokeWidth="1.3" />
      <path d="M3.9 11.4V4.6h2a1.75 1.75 0 0 1 0 3.5h-2m2 0 1.8 3.3M12.9 5.4a2.4 2.9 0 1 0 .5 4V8.3H11.9" strokeWidth="1.15" strokeLinecap="round" strokeLinejoin="round" />
    </svg>
  );
  if (p === "dblp") return (
    <svg {...common} fill="none" stroke="currentColor" strokeWidth="1.3">
      <ellipse cx="8" cy="3.4" rx="5.6" ry="2" />
      <path d="M2.4 3.4v9.2c0 1.1 2.5 2 5.6 2s5.6-.9 5.6-2V3.4" />
      <path d="M2.4 8c0 1.1 2.5 2 5.6 2s5.6-.9 5.6-2" />
    </svg>
  );
  // Semantic Scholar, OpenAlex, website and anything else: a globe.
  return (
    <svg {...common} fill="none" stroke="currentColor" strokeWidth="1.3">
      <circle cx="8" cy="8" r="7" /><path d="M1 8h14M8 1c2 2 2.8 4.4 2.8 7S10 13 8 15C6 13 5.2 10.6 5.2 8S6 3 8 1z" />
    </svg>
  );
}
type Profile = { label: string; url: string; platform: string };
function visibleProfiles(links: any[]): Profile[] {
  return (Array.isArray(links) ? links : [])
    .filter((l) => l && l.url && !isTrue(l.hidden) && l.platform !== "email" && normUrl(String(l.url)))
    .map((l) => ({ platform: String(l.platform || "website").toLowerCase(), label: noEmoji(l.label) || PLATFORM_LABEL[l.platform] || "Link", url: normUrl(String(l.url))! }))
    .sort((a, b) => {
      const ia = PLATFORM_ORDER.indexOf(a.platform), ib = PLATFORM_ORDER.indexOf(b.platform);
      return (ia < 0 ? 99 : ia) - (ib < 0 ? 99 : ib);
    });
}
function ProfileLinks({ items, email, className, compact }: { items: Profile[]; email?: string; className?: string; compact?: boolean }) {
  const all: Profile[] = [...items];
  if (email) all.push({ platform: "email", label: "Email", url: `mailto:${email}` });
  if (!all.length) return null;
  return (
    <ul className={`profile-links${compact ? " profile-links-compact" : ""} ${className || ""}`}>
      {all.map((l, i) => (
        <li key={i}>
          <a href={l.url} {...extProps(l.url)} aria-label={l.platform === "email" ? `Email ${email}` : `${l.label} profile`}>
            <ProfileIcon platform={l.platform} /><span>{l.label}</span>
          </a>
        </li>
      ))}
    </ul>
  );
}
function TextLinks({ items, className, label }: { items: { label: string; url: string; aria?: string }[]; className?: string; label?: string }) {
  if (!items.length) return null;
  return (
    <ul className={`flex flex-wrap gap-x-5 gap-y-0 list-none p-0 m-0 ${className || ""}`} aria-label={label}>
      {items.map((l, i) => (
        <li key={i}><a href={l.url} {...extProps(l.url)} aria-label={l.aria} className="inline-flex items-center" style={{ minHeight: 44 }}>{l.label}</a></li>
      ))}
    </ul>
  );
}

// ---------------------------------------------------------------------------
// Documents (CV, research/teaching statements ...). Only entries with a URL render.
// ---------------------------------------------------------------------------
type Doc = { label: string; url: string; kind: string };
const DOC_ORDER = ["cv", "resume", "research_statement", "teaching_statement", "diversity_statement", "other"];
const DOC_LABEL: Record<string, string> = { cv: "CV", resume: "Résumé", research_statement: "Research Statement", teaching_statement: "Teaching Statement", diversity_statement: "Diversity Statement", other: "Document" };
function readDocuments(raw: any, resumeUrl: any): Doc[] {
  const docs: Doc[] = listOf(raw).filter((d) => d && d.url && normUrl(String(d.url)) && !isTrue(d.hidden)).map((d) => {
    const kind = String(d.kind || "other").toLowerCase();
    return { kind, label: noEmoji(d.label) || DOC_LABEL[kind] || "Document", url: normUrl(String(d.url))! };
  });
  const legacy = resumeUrl && normUrl(String(resumeUrl));
  if (legacy && !docs.some((d) => d.kind === "cv")) docs.unshift({ kind: "cv", label: "CV", url: legacy });
  return docs.sort((a, b) => {
    const ia = DOC_ORDER.indexOf(a.kind), ib = DOC_ORDER.indexOf(b.kind);
    return (ia < 0 ? 99 : ia) - (ib < 0 ? 99 : ib);
  });
}
function DocumentButtons({ docs, className }: { docs: Doc[]; className?: string }) {
  if (!docs.length) return null;
  return (
    <ul className={`doc-row ${className || ""}`} aria-label="Documents">
      {docs.map((d, i) => (
        <li key={i}>
          <a href={d.url} {...extProps(d.url)} className={`btn ${i === 0 ? "btn-solid" : "btn-ghost"}`}>
            {d.label}{isPdfUrl(d.url) && <span className="sr-only"> (PDF)</span>}
          </a>
        </li>
      ))}
    </ul>
  );
}

// ---------------------------------------------------------------------------
// Custom sections (from admin) — restyled plainly
// ---------------------------------------------------------------------------
function CardGrid({ cards }: { cards: any[] }) {
  const list = Array.isArray(cards) ? cards.filter((c) => c && (c.title || c.description || c.image)) : [];
  if (!list.length) return null;
  return (
    <ul className="row-list">
      {list.map((c, i) => (
        <li key={i} className="py-4 border-t border-[var(--c-border)] first:border-t-0 flex gap-4">
          {c.image && <img src={c.image} alt="" loading="lazy" decoding="async" className="w-24 h-24 object-cover rounded border border-[var(--c-border)] shrink-0" />}
          <div className="min-w-0">
            {c.title && <h3 className="font-semibold text-[17px] m-0">{c.link ? <a href={c.link} {...extProps(c.link)}>{noEmoji(c.title)}</a> : noEmoji(c.title)}</h3>}
            {c.description && <p className="muted small mt-1 mb-0" style={{ maxWidth: "68ch" }}>{rich(c.description)}</p>}
          </div>
        </li>
      ))}
    </ul>
  );
}
function CustomBlock({ b }: { b: any }) {
  if (!b) return null;
  if (b.type === "image") {
    if (!b.image) return null;
    return (
      <figure className="m-0 prose-w">
        <img src={b.image} alt={b.caption || ""} loading="lazy" decoding="async" className="w-full rounded border border-[var(--c-border)]" />
        {b.caption && <figcaption className="tiny muted mt-2">{b.caption}</figcaption>}
      </figure>
    );
  }
  if (b.type === "cards") return <CardGrid cards={b.cards} />;
  return (
    <div className="prose-w space-y-4">
      {String(b.text || "").split(/\n{2,}/).map((p) => p.trim()).filter(Boolean).map((para, i) => <p key={i} className="m-0">{rich(para)}</p>)}
    </div>
  );
}
function SectionHeader({ title, sub }: { title: string; sub?: any }) {
  return (
    <>
      <h2 className="section-title">{title}</h2>
      {sub ? <p className="section-sub">{rich(sub)}</p> : null}
    </>
  );
}
function CustomSection({ data }: { data: any }) {
  const blocks = Array.isArray(data.blocks) ? data.blocks
    : data.type === "cards" ? [{ type: "cards", cards: data.cards }]
    : [{ type: "text", text: data.body }];
  return (
    <section id={data.id} className="site-section">
      <SectionHeader title={noEmoji(data.title) || "Section"} sub={data.subtitle} />
      <div className="space-y-8 mt-6">
        {blocks.map((b: any, i: number) => <CustomBlock key={i} b={b} />)}
      </div>
    </section>
  );
}

// ---------------------------------------------------------------------------
// Footer
// ---------------------------------------------------------------------------
function SiteFooter({ footer, email, cvUrl, profiles, siteLinks, onPage, onPageLabel }: {
  footer: any; email: string; cvUrl?: string; profiles: Profile[]; siteLinks: { label: string; url: string }[]; onPage: NavLink[]; onPageLabel: string;
}) {
  const year = new Date().getFullYear();
  const copyright = noEmoji(footer?.copyright) || `© ${year} ${OWNER_NAME}`;
  return (
    <footer className="site-footer border-t border-[var(--c-border)] mt-16 md:mt-24">
      <div className="wrap py-10 small muted">
        <div className="flex flex-wrap items-center gap-x-5">
          <span className="font-serif-display text-[17px] text-[var(--c-text)] mr-1">{noEmoji(footer?.name) || OWNER_NAME}</span>
          <a href={`mailto:${email}`} className="inline-flex items-center min-h-[44px]">{email}</a>
          {cvUrl && <a href={cvUrl} {...extProps(cvUrl)} className="inline-flex items-center min-h-[44px]">CV (PDF)</a>}
        </div>
        {footer?.showSocials !== false && profiles.length > 0 && <ProfileLinks items={profiles} compact />}
        <nav aria-label="Site" className="mt-3 pt-3 border-t border-[var(--c-border)]">
          <TextLinks items={siteLinks} />
        </nav>
        {onPage.length > 0 && (
          <nav aria-labelledby="footer-onpage" className="flex flex-wrap items-center gap-x-4">
            <span className="tiny" id="footer-onpage">{onPageLabel}:</span>
            <TextLinks items={onPage.map((l) => ({ label: l.label, url: l.href }))} className="tiny gap-x-4" />
          </nav>
        )}
        <div className="mt-4 tiny">{copyright}</div>
      </div>
    </footer>
  );
}

// ---------------------------------------------------------------------------
// Blog
// ---------------------------------------------------------------------------
function fmtDate(d?: string) {
  if (!d) return "";
  const dt = new Date(d + (/^\d{4}-\d{2}-\d{2}$/.test(String(d)) ? "T00:00:00" : ""));
  return isNaN(dt.getTime()) ? String(d) : dt.toLocaleDateString("en-US", { year: "numeric", month: "long", day: "numeric" });
}
function isDraft(p: BlogPost) { const d = String(p?.draft ?? "").toLowerCase(); return p?.draft === true || d === "yes" || d === "true" || d === "1"; }
function eslug(p: BlogPost) { return (p.slug && p.slug.trim()) ? p.slug.trim() : slugify(p.title); }

// Blog pages can be served from the homepage shell (SPA fallback, before a rebuild
// prerenders them), so set their own canonical URL and social tags here.
const SITE_URL = "https://palashroy.me";
// Meta descriptions: at most 160 characters, cut at a word boundary.
function clipDesc(s: string, n = 158) { const t = s.replace(/\s+/g, " ").trim(); if (t.length <= n) return t; const c = t.slice(0, t.lastIndexOf(" ", n - 1)).replace(/[,;:.]$/, ""); return c + "…"; }
function setPageHead({ title, description, path, noindex }: { title: string; description?: string; path: string; noindex?: boolean }) {
  if (typeof document === "undefined") return;
  document.title = title;
  const url = SITE_URL + path;
  const meta = (attr: "name" | "property", key: string, val: string) => {
    let el = document.head.querySelector<HTMLMetaElement>(`meta[${attr}="${key}"]`);
    if (!el) { el = document.createElement("meta"); el.setAttribute(attr, key); document.head.appendChild(el); }
    el.setAttribute("content", val);
  };
  let link = document.head.querySelector<HTMLLinkElement>('link[rel="canonical"]');
  if (!link) { link = document.createElement("link"); link.rel = "canonical"; document.head.appendChild(link); }
  link.href = url;
  meta("property", "og:url", url);
  meta("property", "og:title", title);
  meta("name", "twitter:title", title);
  if (description) {
    meta("name", "description", description);
    meta("property", "og:description", description);
    meta("name", "twitter:description", description);
  }
  meta("name", "robots", noindex ? "noindex" : "index, follow, max-image-preview:large");
}

function PostMeta({ post }: { post: BlogPost }) {
  const tags = String(post.tags || "").split(",").map((t) => t.trim()).filter(Boolean);
  return (
    <div className="post-meta">
      {post.date && <time dateTime={String(post.date)}>{fmtDate(post.date)}</time>}
      {tags.length > 0 && <ul className="post-tags" aria-label="Tags">{tags.map((t) => <li key={t} className="tag" style={{ marginLeft: 0 }}>{noEmoji(t)}</li>)}</ul>}
    </div>
  );
}
function PostList({ posts, headingLevel = 2 }: { posts: BlogPost[]; headingLevel?: 2 | 3 }) {
  const H = (headingLevel === 3 ? "h3" : "h2") as "h2" | "h3";
  return (
    <ul className="row-list">
      {posts.map((p) => (
        <li key={eslug(p)} className="post-item">
          {p.cover && <img src={p.cover} alt="" loading="lazy" decoding="async" width={160} height={100} className="post-thumb" />}
          <div className="min-w-0">
            <PostMeta post={p} />
            <H className="post-title"><a href={`/blog/${eslug(p)}/`}>{noEmoji(p.title)}</a></H>
            {p.summary && <p className="muted small m-0" style={{ maxWidth: "68ch" }}>{noEmoji(p.summary)}</p>}
          </div>
        </li>
      ))}
    </ul>
  );
}

function BlogIndex({ posts, head }: { posts: BlogPost[]; head?: any }) {
  const title = noEmoji(head?.title) || "Blog";
  const subtitle = head?.subtitle ?? "Research notes and paper walkthroughs.";
  useEffect(() => {
    setPageHead({ title: `${title} | ${OWNER_NAME}`, path: "/blog/", description: clipDesc([noEmoji(String(subtitle || "")).replace(/\[([^\]]+)\]\([^)]*\)/g, "$1").trim(), `Posts by ${OWNER_NAME}, PhD researcher in Computer Science at the University of Saskatchewan.`].filter(Boolean).join(" ")), noindex: !posts.some((p) => !isDraft(p)) });
  }, [title, subtitle, posts]);
  return (
    <>
      <header className="mb-8">
        <h1 className="page-title">{title}</h1>
        {subtitle && <p className="muted mt-3 mb-0">{rich(subtitle)}</p>}
      </header>
      {posts.length === 0 ? (
        <div className="empty-state" role="status">
          <h2 className="text-[19px] font-semibold m-0">Posts coming soon</h2>
          <p className="muted mt-2 mb-0">There are no posts yet. Meanwhile, the <a href="/publications/">publications</a> and the <a href="/faq/">research FAQ</a> cover the work so far.</p>
        </div>
      ) : (
        <PostList posts={posts} />
      )}
    </>
  );
}
function BlogPostView({ post }: { post: BlogPost }) {
  useEffect(() => {
    if (post?.title) setPageHead({ title: `${post.title} | ${OWNER_NAME}`, path: `/blog/${eslug(post)}/`, description: clipDesc(post.summary ? noEmoji(String(post.summary)) : `${noEmoji(post.title)}: a post by ${OWNER_NAME}.`), noindex: isDraft(post) });
  }, [post]);
  return (
    <article>
      <nav aria-label="Breadcrumb" className="small"><a href="/blog/" className="inline-flex items-center min-h-[44px]">Blog</a></nav>
      <header className="mt-2 mb-8">
        {isDraft(post) && <p className="tag" style={{ marginLeft: 0 }}>Draft (not listed)</p>}
        <h1 className="page-title">{noEmoji(post.title)}</h1>
        {post.summary && <p className="muted mt-3 mb-0 text-[19px]">{noEmoji(post.summary)}</p>}
        <div className="mt-4"><PostMeta post={post} /></div>
      </header>
      {post.cover && <img src={post.cover} alt="" decoding="async" className="w-full rounded border border-[var(--c-border)] mb-8" />}
      {renderMarkdown(post.body || "")}
      <p className="mt-12 pt-6 border-t border-[var(--c-border)]"><a href="/blog/">All posts</a></p>
    </article>
  );
}

function PostNotFound({ slug }: { slug: string }) {
  useEffect(() => { setPageHead({ title: `Post not found | ${OWNER_NAME}`, path: `/blog/${encodeURIComponent(slug)}/`, noindex: true }); }, [slug]);
  return (
    <div className="py-12">
      <h1 className="page-title">Post not found</h1>
      <p className="mt-4"><a href="/blog/">Back to the blog</a></p>
    </div>
  );
}

// ---------------------------------------------------------------------------
// Section keys. The admin stores legacy keys in sectionOrder / sectionsHidden; they
// are folded into the homepage sections here. The built-in order is fixed (final IA);
// custom sections from the admin go before Contact, in their saved order.
// ---------------------------------------------------------------------------
const MERGED: Record<string, string> = {
  about: "research", research: "research",
  portfolio: "publications", publications: "publications",
  software: "tools", tools: "tools", skills: "experience",
  leadership: "experience", service: "experience", experience: "experience",
  highlights: "awards", awards: "awards",
  news: "news", media: "news",
  blog: "blog",
  consultation: "contact", contact: "contact",
  pictures: "", references: "", gallery: "",
};
const SOURCES: Record<string, string[]> = {
  research: ["about"],
  publications: ["portfolio"],
  tools: ["software"],
  experience: ["about", "leadership", "service"],
  awards: ["highlights"],
  news: ["news"],
  blog: ["blog"],
  contact: ["consultation", "contact"],
};
const DEFAULT_ORDER = ["research", "publications", "tools", "experience", "awards", "news", "blog", "contact"];
const DEFAULT_HIDDEN = ["pictures", "references"];

// ---------------------------------------------------------------------------
// App
// ---------------------------------------------------------------------------
export default function App() {
  const { theme, pref, toggle: toggleTheme, choose: chooseTheme } = useTheme();

  // Live content from the cPanel API (falls back to the prerendered/baked content).
  const [remote, setRemote] = useState<Record<string, any>>({});
  useEffect(() => {
    // Apply the live update during idle time to keep the re-render out of the
    // interactivity window.
    const apply = (d: Record<string, any>) => {
      const run = () => setRemote(d);
      if ("requestIdleCallback" in window) (window as any).requestIdleCallback(run, { timeout: 2500 });
      else setTimeout(run, 200);
    };
    fetch("/api/content.php")
      .then((r) => (r.ok ? r.json() : null))
      .then((d) => { if (d && typeof d === "object") apply(d); })
      .catch(() => {});
  }, []);

  const obj = (k: string, def: any) => ((remote[k] && typeof remote[k] === "object" && !Array.isArray(remote[k])) ? { ...def, ...remote[k] } : def);
  const arr = <T,>(k: string, def: T[]): T[] => (Array.isArray(remote[k]) ? (remote[k] as T[]) : def);
  // New list sections may be stored as an array or as {items|groups: [...]}.
  const list = (k: string, key = "items"): any[] => {
    const r = listOf(remote[k], key);
    return remote[k] !== undefined && remote[k] !== null ? r : listOf(optionalContent(k), key);
  };

  const publicationsData = arr<Publication>("publications", defaultPublications).filter((p) => p && p.title);
  const highlightsData = arr<HighlightItem>("highlights", defaultHighlights);
  const leadershipRolesData = arr<LeadershipRole>("leadership", defaultLeadership);
  const academicServiceData = arr<ServiceItem>("service", defaultService);
  const newsItems = arr<any>("news", (newsData as any).items);
  const mediaItems = arr<any>("media", (mediaData as any).items);
  // In "auto" mode the live row only carries a flag; the real numbers are the
  // values baked at build time (metricsData). Manual mode uses the live row.
  const metrics: any = (remote.metrics && typeof remote.metrics === "object" && (remote.metrics as any).auto !== true) ? remote.metrics : metricsData;
  const hero: any = obj("hero", heroData);
  const about: any = obj("about", aboutData);
  const settings: any = obj("settings", settingsData);
  const headings: any = obj("headings", headingsData);
  const research: any = obj("research", researchData);
  const footer: any = obj("footer", footerData);
  const consultation: any = obj("consultation", consultationData);
  const socialLinks: any[] = (remote.socials && Array.isArray((remote.socials as any).links)) ? (remote.socials as any).links : (socialsData as any).links;
  const documents = readDocuments(list("documents"), settings.resumeUrl);
  const softwareItems = list("software");
  const tagVocab = readTagVocab(settings.tagVocabulary, settings.mlTopicVocabulary);
  const skillGroups = list("skills", "groups").filter((g) => g && g.group && asList(g.items).length).slice(0, 3);

  const cvDoc = documents.find((d) => d.kind === "cv") || documents.find((d) => d.kind === "resume");
  const cvUrl = cvDoc?.url;
  const email = String(footer.email || consultation.email || PUBLIC_EMAIL).trim() || PUBLIC_EMAIL;
  const profiles = visibleProfiles(socialLinks);
  const scholarUrl = profiles.find((p) => p.platform === "scholar")?.url || "https://scholar.google.com/citations?user=Vy_sw5UAAAAJ";
  // Hero link row: Google Scholar · LinkedIn · GitHub · Email by default; an admin list
  // settings.heroLinks (platform names, "email" included) can change the set and order.
  // Hidden socials never show.
  const heroOrder: string[] = (Array.isArray(settings.heroLinks) && settings.heroLinks.length ? settings.heroLinks : ["scholar", "linkedin", "github", "email"])
    .map((x: any) => String(x).toLowerCase().trim());
  const heroProfiles: Profile[] = heroOrder.map((pl) => (pl === "email"
    ? { platform: "email", label: "Email", url: `mailto:${email}` }
    : profiles.find((p) => p.platform === pl || (pl === "x" && p.platform === "twitter"))))
    .filter((p, i, a): p is Profile => !!p && a.findIndex((q) => q && q.url === p.url) === i);

  // Published blog posts, newest first (drafts hidden).
  const allPosts = arr<BlogPost>("blog", (blogData as any).items).filter((p) => p && (p.slug || p.title));
  const blogPosts = allPosts.filter((p) => !isDraft(p)).slice().sort((a, b) => String(b.date || "").localeCompare(String(a.date || "")));

  // Section order + visibility (computed before any branch so hooks stay stable).
  const customSections: any[] = (Array.isArray(remote.customSections) ? remote.customSections : []).filter((c: any) => c && c.id && !(c.id in MERGED));
  const savedOrder: string[] = Array.isArray(remote.sectionOrder) ? remote.sectionOrder : [];
  const customOrdered = [
    ...savedOrder.map((id) => customSections.find((c) => c.id === id)).filter(Boolean),
    ...customSections.filter((c) => !savedOrder.includes(c.id)),
  ] as any[];
  // A layout saved by the 2026-11 admin starts with the marker "v2" and orders the built-in
  // sections too (admin id "software" = "tools"). Older saved orders only order custom sections,
  // so the recommended built-in order is kept for them. Contact is always last.
  const order = (() => {
    const base = [...DEFAULT_ORDER.slice(0, -1), ...customOrdered.map((c) => c.id)];
    if (savedOrder[0] !== "v2") return [...base, "contact"];
    const wanted = savedOrder.slice(1).map((id) => (id === "software" ? "tools" : id)).filter((id) => base.includes(id));
    return [...new Set([...wanted, ...base])].concat("contact");
  })();
  const hidden = new Set<string>(Array.isArray(remote.sectionsHidden) ? remote.sectionsHidden : DEFAULT_HIDDEN);
  const isHidden = (k: string) => hidden.has(k) || (SOURCES[k] ? SOURCES[k].every((s) => hidden.has(s)) : false);

  const pathname = typeof window !== "undefined" ? window.location.pathname : "/";
  const blogMatch = pathname.match(/^\/blog(?:\/([^/?#]+))?\/?$/i);
  const onHome = !blogMatch;
  const h = (p: string) => (onHome ? `#${p}` : `/#${p}`);

  // Nav labels come from headings.*.nav (admin), with the legacy keys as fallbacks.
  const navText = (keys: string[], fallback: string, ignore?: RegExp) => {
    for (const k of keys) { const v = noEmoji(headings[k]?.nav); if (v && !(ignore && ignore.test(v))) return v; }
    return fallback;
  };
  const titleText = (keys: string[], fallback: string, ignore?: RegExp) => {
    for (const k of keys) { const v = noEmoji(headings[k]?.title); if (v && !(ignore && ignore.test(v))) return v; }
    return fallback;
  };
  // Fixed sub-headings and link texts with an optional admin override (Sections & Headings).
  const hx = (sec: string, key: string, fallback: string) => noEmoji(headings[sec]?.[key]) || fallback;
  const showBlogNav = settings.blogInNav !== false && !isTrue(headings.blog?.hidden);
  const visibleSet = new Set(order.filter((k) => !isHidden(k)));
  const navLinks: NavLink[] = [
    { id: "research", label: navText(["research", "about"], "Research", /^(about|about me|bio|biography|home)$/i), href: h("research") },
    { id: "publications", label: navText(["publications", "portfolio"], "Publications", /^portfolio$/i), href: onHome ? "#publications" : "/publications/" },
    { id: "experience", label: navText(["experience"], "Experience"), href: h("experience") },
    ...customOrdered.filter((c) => c.nav && visibleSet.has(c.id)).map((c) => ({ id: c.id, label: noEmoji(c.nav), href: h(c.id) })),
    ...(showBlogNav ? [{ id: "blog-page", label: navText(["blog"], "Blog"), href: "/blog/" }] : []),
    { id: "contact", label: navText(["contact"], "Contact", /^collaborate$/i), href: h("contact") },
  ].filter((l) => l.id === "blog-page" || !l.href.includes("#") || visibleSet.has(l.id) || customOrdered.some((c) => c.id === l.id))
    // The menu follows the homepage order (Blog sits where the blog section is; Contact stays last).
    .map((l, i) => ({ l, i, p: l.id === "contact" ? 1e9 : order.indexOf(l.id === "blog-page" ? "blog" : l.id) }))
    .sort((a, b) => (a.p - b.p) || (a.i - b.i)).map((x) => x.l).slice(0, 6);

  // Older database copies call this section "Software & Data"; the owner renamed it.
  const softwareTitle = titleText(["tools", "software"], "Tools & Benchmarks", /^software/i);
  const awardsTitle = titleText(["awards", "highlights"], "Awards & Honors");
  const newsTitle = titleText(["news"], "News", /recognition/i);
  const awards = buildAwards(isHidden("awards") ? [] : highlightsData, mediaItems, publicationsData);
  const allNewsRows = linkNewsToPapers(buildNews(
    newsItems,
    highlightsData,
    mediaItems,
    awards.usedMedia,
    leadershipRolesData.map((r) => `${r?.role || ""}`),
  ), publicationsData);
  // Latest (settings.showLatest; settings.latestCount 1-3, default 2): pinned items only.
  const latestCount = Math.min(3, Math.max(1, Math.round(Number(settings.latestCount)) || 2));
  const showLatest = settings.showLatest === undefined || settings.showLatest === null || settings.showLatest === "" ? true : isTrue(settings.showLatest);
  const latestRows = showLatest ? buildLatest({
    news: isHidden("news") ? [] : allNewsRows,
    awards: awards.rows,
    leadership: hidden.has("leadership") ? [] : leadershipRolesData,
    service: hidden.has("service") ? [] : academicServiceData,
    count: latestCount,
  }) : [];
  const inLatest = new Set(latestRows.map((r) => r.key));
  const unpinnedNews = allNewsRows.filter((r) => !inLatest.has(newsKey(r)));
  const newsRowsLinked = unpinnedNews.slice(0, 5);
  const olderNews = unpinnedNews.slice(5);
  const newsRows = newsRowsLinked;
  const software = readSoftware(softwareItems, publicationsData);
  const hasSoftware = software.length > 0;

  const onPageLinks: NavLink[] = [
    ...(visibleSet.has("tools") && hasSoftware ? [{ id: "tools", label: navText(["tools", "software"], "Tools", /^software/i), href: h("tools") }] : []),
    ...(visibleSet.has("awards") && awards.rows.length ? [{ id: "awards", label: navText(["awards", "highlights"], "Awards"), href: h("awards") }] : []),
    ...(visibleSet.has("news") && newsRows.length ? [{ id: "news", label: navText(["news"], "News"), href: h("news") }] : []),
  ];
  const onPageLabel = onHome ? "On this page" : "On the homepage";
  const spyIds = onHome ? ["research", "publications", "tools", "experience", "awards", "news", "blog", "contact"] : [];
  const activeId = useActiveSection(spyIds);

  const siteLinks = [
    { label: "Publications", url: "/publications/", aria: "Publications page (all papers)" },
    { label: navText(["photos"], "Photos"), url: "/photos/" },
    { label: navText(["faq"], "Research FAQ"), url: "/faq/" },
    { label: navText(["aboutPage"], "About"), url: "/about/" },
    ...(showBlogNav || blogPosts.length ? [{ label: navText(["blog"], "Blog"), url: "/blog/" }] : []),
  ];

  const chrome = (node: React.ReactNode, homeHref: string, currentPage?: string) => (
    <TagVocab.Provider value={tagVocab}>
    <div className="min-h-screen flex flex-col">
      <a href="#main" className="skip-link">Skip to content</a>
      {/* Fixed at the bottom visually, but right after the skip link in the DOM / Tab order. */}
      <ThemePrompt pref={pref} onChoose={chooseTheme} enabled={settings.themePrompt !== false && !isTrue(settings.themePromptOff)} />
      <SiteNav links={navLinks} extra={onPageLinks} extraLabel={onPageLabel} theme={theme} onToggleTheme={toggleTheme} cvUrl={cvUrl}
        homeHref={homeHref} activeId={onHome ? activeId : undefined} currentPage={currentPage} />
      {node}
      <SiteFooter footer={footer} email={email} cvUrl={cvUrl} profiles={profiles} siteLinks={siteLinks}
        onPage={[
          { id: "research", label: navLinks[0].label, href: h("research") },
          { id: "publications", label: navText(["publications", "portfolio"], "Publications", /^portfolio$/i), href: h("publications") },
          ...onPageLinks.slice(0, 1),
          { id: "experience", label: navText(["experience"], "Experience"), href: h("experience") },
          ...onPageLinks.slice(1),
          { id: "contact", label: navText(["contact"], "Contact", /^collaborate$/i), href: h("contact") },
        ]} onPageLabel={onPageLabel} />
    </div>
    </TagVocab.Provider>
  );

  if (blogMatch) {
    const slug = blogMatch[1] ? decodeURIComponent(blogMatch[1]) : null;
    const wrapMain = (n: React.ReactNode) => <main id="main" tabIndex={-1} className="wrap pt-12 md:pt-16 flex-1 w-full"><div className="prose-w">{n}</div></main>;
    if (!slug) return chrome(wrapMain(<BlogIndex posts={blogPosts} head={headings.blog} />), "/", "blog-page");
    // A direct URL finds any post (including drafts) so it can be previewed.
    const post = allPosts.find((p) => eslug(p) === slug);
    if (post) return chrome(wrapMain(<BlogPostView post={post} />), "/", "blog-page");
    return chrome(wrapMain(<PostNotFound slug={slug} />), "/", "blog-page");
  }

  // ---- Homepage ----
  const titleLine = noEmoji(hero.title || hero.titleLine || (/university/i.test(String(hero.line2 || "")) ? hero.line2 : "")) || DEFAULT_TITLE_LINE;
  const education: any[] = Array.isArray(about.education) ? about.education.filter((e: any) => e && e.degree) : [];
  const phd = education.find((e) => /ph\.?\s*d/i.test(String(e.degree)));
  const msc = education.find((e) => /\bm\.?\s*sc\b|master/i.test(String(e.degree)));
  const thesisPub = publicationsData.find((p) => pubType(p) === "thesis" && /m\.?\s*sc|master/i.test(`${p.venue || ""} ${(p as any).degree || ""} ${p.venue_short || ""}`))
    || publicationsData.find((p) => pubType(p) === "thesis");
  const expectedRaw = String(phd?.expected || about.expected || hero.expected || "").trim();
  const expected = expectedRaw ? (/^\d{4}-\d{2}/.test(expectedRaw) ? fmtMonthYear(expectedRaw) : noEmoji(expectedRaw)) : "";
  const ctt = publicationsData.find((p) => p.award && /distinguished/i.test(p.award)) || publicationsData.find((p) => p.award);
  const awardText = noEmoji(hero.awardText);
  const awardMeta = noEmoji(hero.awardMeta);
  // One quiet highlights line (SPEC-round3 §24); replaces the award line when set.
  const heroHighlights = listOf(hero.highlights).map((x: any) => typeof x === "string"
    ? (() => { const [text, url] = x.split("|").map((t: string) => t.trim()); return { text: noEmoji(text), url: url ? normUrl(url) || "" : "" }; })()
    : { text: noEmoji(x?.text), url: fieldStr(x?.url) ? normUrl(fieldStr(x.url)) || "" : "" }).filter((x: any) => x.text);
  const heroPhoto: string | undefined = hero.photo || about.photo || undefined;

  // ---- Research ----
  const themes = readThemes(research);
  const flagged = themes.some((t) => t.also);
  const mainThemes = flagged ? themes.filter((t) => !t.also) : themes.slice(0, research.also !== undefined ? themes.length : 4);
  const extraThemes = flagged ? themes.filter((t) => t.also) : themes.slice(mainThemes.length);
  // Each theme lists its papers once (a paper and its poster share a short title).
  const themeRows = mainThemes.map((t) => {
    const all = papersForTheme(t, publicationsData);
    const papers = all.filter((p, j, a) => a.findIndex((q) => pubShortTitle(q) === pubShortTitle(p)) === j).slice(0, 6);
    return { ...t, papers, companions: all.filter((p) => !papers.includes(p)) };
  });
  const alsoPapers: Publication[] = [];
  const pushAlso = (p?: Publication) => { if (p && !alsoPapers.some((q) => pubShortTitle(q) === pubShortTitle(p))) alsoPapers.push(p); };
  if (Array.isArray(research.also)) research.also.forEach((s: any) => pushAlso(publicationsData.find((p) => pubSlug(p) === slugify(String(s)))));
  extraThemes.forEach((t) => papersForTheme(t, publicationsData).forEach(pushAlso));
  const alsoText = typeof research.also === "string" ? research.also : "";
  const statement = research.statement || about.statement || about.researchStatement;

  // ---- Publications ----
  const plan = planPublications(publicationsData);
  const metricBits: string[] = [];
  if (metrics?.citations != null && metrics.citations !== "") metricBits.push(`Citations ${metrics.citations}`);
  if (metrics?.hIndex != null && metrics.hIndex !== "") metricBits.push(`h-index ${metrics.hIndex}`);
  const metricsSource = noEmoji(metrics?.source) || "Google Scholar";
  const metricsUrl = metrics?.profileUrl || (/openalex/i.test(metricsSource) && metrics?.openAlexId ? `https://openalex.org/${metrics.openAlexId}` : scholarUrl);

  // ---- Experience ----
  const raw: RoleItem[] = [];
  if (!hidden.has("about") && Array.isArray(about.experience)) about.experience.forEach((x: any) => {
    if (!x || !x.role) return;
    const { org, dates } = splitPlace(x.place);
    raw.push({ role: x.role, org, period: dates || x.period || "", description: x.description, src: "exp", category: x.category, order: orderOf(x.order), bullets: asList(x.highlights).map(noEmoji).filter(Boolean) });
  });
  if (!hidden.has("leadership")) leadershipRolesData.forEach((r: any) => {
    if (!r || !r.role || isTrue(r.aboutOnly)) return;
    raw.push({ role: r.role, org: noEmoji(r.organization), place: r.place, period: r.period, link: r.link, src: "lead", committees: Array.isArray(r.committees) ? r.committees : undefined, id: r.id ? String(r.id) : undefined, description: fieldStr(r.description) || undefined, bullets: asList(r.highlights).map(noEmoji).filter(Boolean), order: orderOf(r.order) });
  });
  if (!hidden.has("service")) academicServiceData.forEach((s: any) => {
    if (!s || !s.role) return;
    const cat = String(s.category || "").toLowerCase();
    const talk = cat ? cat === "talk" : RE_TALK.test(s.role);
    raw.push({ role: talk && s.description ? s.description : s.role, org: noEmoji(s.venues || s.venue), period: s.period, description: talk ? undefined : s.description, src: "service", talk, category: cat || undefined, under: s.under ? String(s.under) : undefined, link: fieldStr(s.link) || undefined, order: orderOf(s.order) });
  });
  // A role that appears in more than one source is shown once (first source wins).
  const firstSrc = new Map<string, string>();
  const deduped = raw.filter((it) => {
    const k = normKey(it.role);
    const seen = firstSrc.get(k);
    if (it.talk) return true;
    if (seen === undefined) { firstSrc.set(k, it.src); return true; }
    return seen === it.src;
  });
  const isTalkItem = (it: RoleItem) => !!it.talk;
  // The admin's `category` field decides the group; without it, the role name does.
  const catOf = (it: RoleItem) => String(it.category || "").toLowerCase();
  const isTeach = (it: RoleItem) => catOf(it) ? /^(teaching|mentoring|supervision)$/.test(catOf(it)) : RE_TEACH.test(it.role);
  const isReview = (it: RoleItem) => catOf(it) ? catOf(it) === "reviewing" : RE_REVIEW.test(it.role);
  const teaching = deduped.filter((it) => !isTalkItem(it) && isTeach(it));
  const reviewing = deduped.filter((it) => !isTalkItem(it) && !teaching.includes(it) && isReview(it));
  const talks = deduped.filter(isTalkItem);
  const positions = deduped.filter((it) => it.src === "exp" && !teaching.includes(it) && !reviewing.includes(it));
  const leadRows = mergeRoles(deduped.filter((it) => it.src === "lead" && !teaching.includes(it) && !reviewing.includes(it)));
  const committeeItems = deduped.filter((it) => it.src === "service" && !teaching.includes(it) && !reviewing.includes(it) && !talks.includes(it));
  const looseGov: RoleItem[] = [];
  committeeItems.forEach((c) => {
    const s = yearSpan(c.period);
    const host = (c.under && leadRows.find((r) => r.id === c.under))
      || (!c.under && RE_COMMITTEE.test(c.role) && s[0] ? leadRows.find((r) => r.span[0] && r.span[0] <= s[1] && s[0] <= r.span[1]) : undefined);
    if (host) host.subs.push({ role: noEmoji(c.role), period: fmtPeriod(c.period) });
    else looseGov.push(c);
  });
  const govRows = [...leadRows, ...mergeRoles(looseGov)].sort((a, b) => b.year - a.year);
  const teachRows = mergeRoles(teaching);
  const positionRows = mergeRoles(positions);
  const reviewRows = mergeRoles(reviewing);
  const talkRows = mergeRoles(talks);

  // Papers under a theme are named by venue (plus the artifact name when the paper has a
  // short one), not by title: the full titles are in Publications right below.
  const artifactName = (p: Publication) => {
    const n = software.find((x) => x.fromHref === `#pub-${pubSlug(p)}`)?.name || "";
    return n && n.split(/\s+/).length <= 2 && !/[:]/.test(n) ? n : "";
  };
  const paperLabels = (ps: Publication[]) => {
    const base = ps.map((p) => { const v = shortVenueLabel(p) || String(p.year || ""); const a = artifactName(p); return a ? `${a} (${v})` : v; });
    return base.map((b, i) => (base.indexOf(b) !== base.lastIndexOf(b) ? `${b}: ${pubShortTitle(ps[i]).split(/\s+/).slice(0, 4).join(" ")}…` : b));
  };
  const PaperRef = ({ p, j, labels }: { p: Publication; j: number; labels: string[] }) => (
    <>{j > 0 && " · "}<a href={`#pub-${pubSlug(p)}`} aria-label={`${labels[j]}: ${p.title}`}>{labels[j]}</a></>
  );

  // ---- Contact ----
  const contactLine = [consultation.description, headings.contact?.subtitle]
    .map((s) => noEmoji(s)).find((s) => s && !/\bopen to\b/i.test(s)) || "Email is the quickest way to reach me. The form below sends a message to the same inbox.";

  const Sub = ({ children, id }: { children: React.ReactNode; id?: string }) => <h3 className="sub-title" id={id}>{children}</h3>;

  const sections: Record<string, React.ReactNode> = {
    research: (
      <section id="research" className="site-section" aria-labelledby="research-h">
        <span id="about" aria-hidden="true" />
        <h2 id="research-h" className="section-title">{titleText(["research", "about"], "Research", /^(about|about me|bio|biography|home)$/i)}</h2>
        {statement && <div className="prose-w mt-6"><p className="m-0 text-[19px] leading-relaxed">{rich(statement)}</p></div>}
        {themeRows.length > 0 && (
          <>
            <Sub id="themes-h">{hx("about", "themesTitle", "Research themes")}</Sub>
            <ul className="theme-cards" aria-labelledby="themes-h">
              {themeRows.map((t, i) => {
                const labels = paperLabels(t.papers);
                const chips = (t.keywords && t.keywords.length ? t.keywords : (t.papers[0] ? pubKeywords(t.papers[0], tagVocab, 3) : [])).slice(0, 4);
                // A paper's own tool that has no Tools & Benchmarks row (e.g. CLEAR) is linked here by name.
                const toolOf = (p: Publication) => {
                  const u = fieldStr(p.tool_url) && normUrl(fieldStr(p.tool_url));
                  const n = noEmoji((p as any).tool_name);
                  return u && n && !software.some((x) => x.fromHref === `#pub-${pubSlug(p)}`) ? { u, n } : null;
                };
                return (
                  <li key={i} className="theme-card">
                    <h4 className="theme-card-title">{t.label}</h4>
                    {(t.summary || t.description) && <p className="theme-card-sum">{rich(t.summary || t.description)}</p>}
                    {t.keyResult && <p className="theme-card-result">{rich(t.keyResult)}</p>}
                    <KeywordChips tags={chips} />
                    {t.papers.length > 0 && (
                      <p className="theme-card-papers theme-papers">
                        {t.papers.map((p, j) => {
                          const tool = toolOf(p);
                          const comps = t.companions.filter((c) => pubShortTitle(c) === pubShortTitle(p));
                          return (
                            <React.Fragment key={j}>
                              <PaperRef p={p} j={j} labels={labels} />
                              {comps.map((c) => <React.Fragment key={pubSlug(c)}>{" · "}<a href={`#pub-${pubSlug(c)}`} aria-label={`${pubType(c) === "poster" ? "Poster" : "Companion"}: ${c.title}`}>{pubType(c) === "poster" ? "Poster" : "Companion"}</a></React.Fragment>)}
                              {tool && <>{" · "}<a href={tool.u} {...extProps(tool.u)} aria-label={`${tool.n}, the tool from ${p.title}`}>{tool.n}</a></>}
                            </React.Fragment>
                          );
                        })}
                      </p>
                    )}
                  </li>
                );
              })}
            </ul>
            {(alsoPapers.length > 0 || alsoText) && (
              <p className="small muted mt-4 mb-0 prose-w theme-papers">
                <span className="font-medium text-[var(--c-text)]">{hx("about", "alsoLabel", "Also").replace(/\s*:\s*$/, "")}: </span>
                {alsoText ? rich(alsoText) : alsoPapers.map((p, j) => <PaperRef key={j} p={p} j={j} labels={paperLabels(alsoPapers)} />)}
              </p>
            )}
          </>
        )}
        {/* Same labels as the footer (headings.*.nav); "About" alone says less here, so the
            default stays "Biography and leadership" until the owner sets another label. */}
        <ul className="inline-links sep-list small mt-6" aria-label="More about the research">
          <li><a href="/about/">{navText(["aboutPage"], "Biography and leadership", /^about$/i)}</a></li>
          <li><a href="/faq/">{navText(["faq"], "Research FAQ")}</a></li>
          <li><a href="/photos/">{navText(["photos"], "Photos")}</a></li>
        </ul>
      </section>
    ),
    publications: (
      <section id="publications" className="site-section" aria-labelledby="publications-h">
        <span id="portfolio" aria-hidden="true" />
        <h2 id="publications-h" className="section-title">{titleText(["publications", "portfolio"], "Publications", /^portfolio$/i)}</h2>
        {plan.selected.length > 0 && (
          <>
            <Sub>{noEmoji(headings.portfolio?.selectedTitle) || "Featured publications"}</Sub>
            {plan.selected.map((p) => <PublicationEntry key={pubSlug(p)} item={p} companions={plan.companionsOf.get(pubSlug(p)) || []} />)}
          </>
        )}
        {plan.other.length > 0 && (
          <>
            <Sub>{plan.selected.length ? (noEmoji(headings.portfolio?.otherTitle) || "Other publications") : "All publications"}</Sub>
            <OtherPublications items={plan.other} companionsOf={plan.companionsOf} />
          </>
        )}
        <p className="small muted mt-8 mb-0">
          {metricBits.length > 0 && <>{metricBits.join(" · ")} · </>}
          Source: <a href={metricsUrl} target="_blank" rel="noopener noreferrer">{metricsSource}</a>
          {metrics?.updated && <>, {fmtMonthYear(String(metrics.updated))}</>}
        </p>
        <p className="mt-2 mb-0"><a href="/publications/" className="font-medium inline-flex items-center min-h-[44px]">{hx("portfolio", "allLinkText", "All publications with abstracts and BibTeX")}</a></p>
      </section>
    ),
    tools: hasSoftware ? (
      <section id="tools" className="site-section" aria-labelledby="tools-h">
        <span id="software" aria-hidden="true" />
        <h2 id="tools-h" className="section-title">{softwareTitle}</h2>
        <ul className="row-list mt-4">
          {software.map((s, i) => (
            <li key={i} className="py-3 border-t border-[var(--c-border)] first:border-t-0">
              {/* Name + kind, then "paper: …"; the separator dot is hidden when "paper: …" wraps to a new line. */}
              <div className="sep-list sw-head">
                <span><span className="font-medium">{s.name}</span>{s.kind && <span className="tag">{s.kind}</span>}</span>
                {s.from && <span className="muted small sw-from">paper: {s.fromHref ? <a href={s.fromHref} aria-label={`${s.from}, the paper on ${s.name}`}>{s.from}</a> : s.from}</span>}
              </div>
              {s.note && <div className="small muted mt-0.5" style={{ maxWidth: "68ch" }}>{rich(s.note)}</div>}
              {s.links.length > 0 && <div className="link-row">{s.links.map((l, j) => <a key={j} href={l.url} {...extProps(l.url)} aria-label={`${l.label}: ${s.name}`}>{l.label}</a>)}</div>}
            </li>
          ))}
        </ul>
      </section>
    ) : <span id="software" aria-hidden="true" />,
    experience: (
      <section id="experience" className="site-section" aria-labelledby="experience-h">
        <span id="leadership" aria-hidden="true" /><span id="service" aria-hidden="true" />
        <h2 id="experience-h" className="section-title">{titleText(["experience"], "Experience")}</h2>
        {!hidden.has("about") && education.length > 0 && (
          <>
            <Sub>{hx("experience", "educationTitle", "Education")}</Sub>
            <ul className="row-list">
              {education.map((e: any, i: number) => {
                const { org, dates } = splitPlace(e.place);
                const isPhd = e === phd;
                // "2024 – Aug 2028 (expected)" once an expected date is set.
                const start = (String(dates || e.period || "").match(/\b(?:19|20)\d{2}\b/) || [""])[0];
                const date = isPhd && expected ? `${start ? `${start} – ` : ""}${expected} (expected)` : dates;
                const rawNote = String(e.note || "").trim();
                const note = /^(completed|done)$/i.test(rawNote) || (isPhd && expected && /^in progress$/i.test(rawNote)) ? "" : noEmoji(rawNote);
                // The admin's "Thesis" field (thesis_slug) names the paper; otherwise the M.Sc. row gets the thesis entry.
                const thesisSlug = fieldStr(e.thesis_slug);
                const thesis = thesisSlug ? publicationsData.find((p) => pubSlug(p) === slugify(thesisSlug)) : (e === msc ? thesisPub : undefined);
                const thesisTitle = noEmoji(e.thesis_title);
                const sup = noEmoji(e.supervisor || e.supervisors);
                const supLabel = /\band\b|,|;/.test(sup) ? "Supervisors" : "Supervisor";
                const noteBits: React.ReactNode[] = [];
                if (thesis) noteBits.push(<React.Fragment key="t">Thesis: <a href={`/publications/${pubSlug(thesis)}/`}>{thesis.title}</a></React.Fragment>);
                else if (thesisTitle) noteBits.push(<React.Fragment key="t">Thesis: {thesisTitle}</React.Fragment>);
                if (sup) noteBits.push(<React.Fragment key="s">{noteBits.length ? " · " : ""}{supLabel}: {sup}</React.Fragment>);
                return <Row key={i} date={date} title={noEmoji(e.degree)} sub={[org, note].filter(Boolean).join(" · ")}
                  note={noteBits.length ? <>{noteBits}</> : undefined} />;
              })}
            </ul>
          </>
        )}
        {/* SPEC-round3 §24 order: Education → Teaching & Mentoring → Research & Professional Positions →
            Leadership & Service → Reviewing (one line) → Invited Talks → Skills & Methods. */}
        {teachRows.length > 0 && <><Sub>{hx("experience", "teachingTitle", "Teaching & Mentoring")}</Sub><RoleRows rows={teachRows} /></>}
        {positionRows.length > 0 && <><Sub>{hx("experience", "positionsTitle", "Research & Professional Positions")}</Sub><RoleRows rows={positionRows} /></>}
        {govRows.length > 0 && <><Sub>{titleText(["leadership"], "Leadership & Service", /^university governance\s*(&|and)\s*leadership$/i)}</Sub><RoleRows rows={govRows} /></>}
        {reviewRows.length > 0 && (
          <>
            <Sub>{titleText(["service"], "Reviewing", /^reviewing\s*(&|and)\s*service$/i)}</Sub>
            <ul className="row-list">
              {reviewRows.map((r, i) => (
                <Row key={i} date={r.periods.join(", ")} title={<>{r.role}{(r.orgNode || r.org) && <span className="font-normal"> — {r.orgNode || r.org}</span>}</>} />
              ))}
            </ul>
          </>
        )}
        {talkRows.length > 0 && <><Sub>{hx("experience", "talksTitle", "Invited Talks")}</Sub><RoleRows rows={talkRows} /></>}
        {skillGroups.length > 0 && (
          <div id="skills">
            <Sub>{titleText(["skills"], "Skills & Methods", /^methods\s*(&|and)\s*tools$/i)}</Sub>
            <ul className="row-list">
              {skillGroups.map((g, i) => (
                <li key={i} className="row">
                  <div className="row-date">{noEmoji(g.group)}</div>
                  <div className="row-main small">{asList(g.items).map(noEmoji).join(" · ")}</div>
                </li>
              ))}
            </ul>
          </div>
        )}
      </section>
    ),
    awards: awards.rows.length ? (
      <section id="awards" className="site-section" aria-labelledby="awards-h">
        <span id="highlights" aria-hidden="true" />
        <h2 id="awards-h" className="section-title">{awardsTitle}</h2>
        {awards.rows.some((r) => !r.scholarship) && <div className="mt-2"><AwardsList rows={awards.rows.filter((r) => !r.scholarship)} /></div>}
        {awards.rows.some((r) => r.scholarship) && <><Sub>{hx("highlights", "scholarshipsTitle", "Scholarships")}</Sub><AwardsList rows={awards.rows.filter((r) => r.scholarship)} /></>}
      </section>
    ) : null,
    news: newsRows.length ? (
      <section id="news" className="site-section" aria-labelledby="news-h">
        <span id="media" aria-hidden="true" />
        <h2 id="news-h" className="section-title">{newsTitle}</h2>
        <div className="mt-2"><NewsList rows={newsRowsLinked} /></div>
        {olderNews.length > 0 && (
          // Older items stay reachable without making the section long (closed by default; no JS needed).
          <details className="news-older">
            <summary>{hx("news", "olderTitle", "Older news")} <span className="muted font-normal">({olderNews.length})</span></summary>
            <NewsList rows={olderNews} />
          </details>
        )}
      </section>
    ) : <span id="media" aria-hidden="true" />,
    blog: blogPosts.length ? (
      <section id="blog" className="site-section" aria-labelledby="blog-h">
        <h2 id="blog-h" className="section-title">{noEmoji(headings.blog?.teaserTitle) || "From the blog"}</h2>
        <div className="mt-2"><PostList posts={blogPosts.slice(0, 2)} headingLevel={3} /></div>
        <p className="mt-2 mb-0"><a href="/blog/" className="font-medium inline-flex items-center min-h-[44px]">{hx("blog", "allPostsText", "All posts")}</a></p>
      </section>
    ) : null,
    contact: (
      <section id="contact" className="site-section" aria-labelledby="contact-h">
        <span id="consultation" aria-hidden="true" />
        <h2 id="contact-h" className="section-title">{titleText(["contact"], "Contact")}</h2>
        <div className="grid grid-cols-1 md:grid-cols-[minmax(0,5fr)_minmax(0,7fr)] gap-10 mt-6">
          <div>
            <CopyEmail email={email} />
            <p className="muted mt-3 mb-0">{rich(contactLine)}</p>
            {documents.length > 0 && <><Sub>{titleText(["documents"], "Documents")}</Sub><DocumentButtons docs={documents} /></>}
            {profiles.length > 0 && <><Sub>{hx("contact", "profilesTitle", "Profiles")}</Sub><ProfileLinks items={profiles} /></>}
          </div>
          {!hidden.has("contact") && (
            <div>
              <h3 className="sub-title" style={{ marginTop: 0 }}>{hx("contact", "formTitle", "Send a message")}</h3>
              <ContactForm />
            </div>
          )}
        </div>
      </section>
    ),
  };
  customOrdered.forEach((cs) => { sections[cs.id] = <CustomSection data={cs} />; });

  const heroSection = (
    <section id="top" className="pt-10 md:pt-16" aria-labelledby="hero-name">
      <span id="home" aria-hidden="true" />
      <div className="hero-grid">
        <div className="hero-main min-w-0 prose-w">
          <h1 className="hero-name" id="hero-name">{OWNER_NAME}</h1>
          <p className="mt-2 mb-0 text-[17px] font-medium text-[var(--c-text)]">
            {titleLine}{expected && <span className="muted font-normal"> · expected {expected}</span>}
          </p>
          <p className="mt-1 mb-0 small muted">
            Published as Palash Roy / Palash R. Roy · ORCID{" "}
            <a href={`https://orcid.org/${OWNER_ORCID}`} target="_blank" rel="noopener noreferrer">{OWNER_ORCID}</a>
          </p>
          {hero.description && <p className="mt-5 mb-0">{rich(hero.description)}</p>}
          {heroHighlights.length > 0 ? (
            // Each fact stays on one line; lines break only between facts, and no line starts with a dot.
            <ul className="hero-highlights sep-list" aria-label="Highlights">
              {heroHighlights.map((x: any, i: number) => (
                <li key={i}>{x.url ? <a href={x.url} {...extProps(x.url)}>{x.text}</a> : <span>{x.text}</span>}</li>
              ))}
            </ul>
          ) : awardText && (
            <p className="mt-4 mb-0 award font-medium">
              {ctt ? <a href={`/publications/${pubSlug(ctt)}/`} className="award hover:text-[var(--c-award)]">{awardText}</a> : awardText}
              {awardMeta && <span className="muted font-normal">, {awardMeta}</span>}
            </p>
          )}
          <div id="hero-actions" className="mt-6">
            <DocumentButtons docs={documents} className="mb-3" />
            {heroProfiles.length > 0 && (
              <ul className="profile-links hero-links" aria-label="Profiles and email">
                {heroProfiles.map((l) => (
                  <li key={l.platform}>
                    <a href={l.url} {...extProps(l.url)} aria-label={l.platform === "email" ? `Email ${email}` : `${l.label} profile`}>
                      <ProfileIcon platform={l.platform} /><span>{l.label}</span>
                    </a>
                  </li>
                ))}
              </ul>
            )}
          </div>
        </div>
        <div className="hero-photo-wrap">
          {heroPhoto ? (
            <img src={heroPhoto} alt={OWNER_NAME} className="hero-photo" width={200} height={200} decoding="async" fetchPriority="high" />
          ) : (
            <picture>
              <source srcSet="/images/palash_roy_headshot.webp" type="image/webp" />
              <img src="/images/palash_roy_headshot.jpg" alt={OWNER_NAME} width={200} height={200} className="hero-photo" decoding="async" fetchPriority="high" />
            </picture>
          )}
        </div>
        {latestRows.length > 0 && (
          <div className="hero-latest">
            <LatestList rows={latestRows} title={noEmoji(headings.latest?.title) || "Latest"}
              more={newsRows.length && visibleSet.has("news") ? { href: "#news", label: noEmoji(headings.latest?.allLabel) || "All news" }
                : awards.rows.length && visibleSet.has("awards") ? { href: "#awards", label: "All awards" } : undefined} />
          </div>
        )}
      </div>
    </section>
  );

  return chrome(
    <main id="main" tabIndex={-1} className="wrap flex-1 w-full">
      {heroSection}
      {order.filter((k) => !isHidden(k)).map((k) => <React.Fragment key={k}>{sections[k]}</React.Fragment>)}
    </main>,
    "#top"
  );
}
