import React, { useState, useEffect, useRef } from "react";
import { createPortal } from "react-dom";

// Single-source content (editable via the /admin CMS)
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
import galleryDataRaw from "./content/gallery.json";
import referencesDataRaw from "./content/references.json";

// Publication record shape (fields beyond the core are optional)
type Publication = {
  title: string;
  authors: string;
  venue: string;
  year: string;
  description: string;
  pages?: string;
  award?: string;
  doi?: string;
  preprint?: string;
  bibtex?: string;
  video?: string;
};
const defaultPublications: Publication[] = publicationsDataRaw.items as Publication[];

type LeadershipRole = { period: string; role: string; organization: string; place: string; link?: string };
const defaultLeadership: LeadershipRole[] = leadershipDataRaw.items as LeadershipRole[];

type ServiceItem = { period: string; role: string; description: string; venue?: string; venues?: string };
const defaultService: ServiceItem[] = serviceDataRaw.items as ServiceItem[];

type GalleryItem = { image: string; title: string; description: string; altText?: string };
const defaultGallery: GalleryItem[] = galleryDataRaw.items as GalleryItem[];

type ReferenceItem = { name: string; title: string; image: string; text: string };
const defaultReferences: ReferenceItem[] = referencesDataRaw.items as ReferenceItem[];


// Define interface for highlight items to include optional image property
interface HighlightItem {
  title: string;
  organization: string;
  description: string;
  link?: string;
  image?: string;
}

// Featured Highlights data - single source in src/content/highlights.json
import highlightsDataRaw from "./content/highlights.json";
const defaultHighlights: HighlightItem[] = highlightsDataRaw.items as HighlightItem[];

// Leadership roles data (renamed from volunteerWorkData)

// Academic service data - now including invited lectures

// Pictures data - add more items here to have more carousel slides

// 2026 GSA Awards & Gala photos - from Palash Roy's tenure as GSA President

// References data - updated with all references and their images

// Experience data for the carousel
const experienceData = [
  {
    role: "Graduate Teaching Assistant",
    organization: "University of Saskatchewan",
    period: "2022 - Present",
    description: "Instructed undergraduate students in data structures, programming, and practical computing labs."
  },
  {
    role: "Research Technician",
    organization: "iSE & SR Lab, University of Saskatchewan",
    period: "May 2022 - Aug 2022",
    description: "Supporting web based systems, Maintaining CFI equipments, Supporting SOAR CREATE Program."
  },
  {
    role: "Visiting Research Student",
    organization: "University of Saskatchewan",
    period: "May 2022 - Aug 2022",
    description: "Developed multiple software engineering tools and published research in code clones and large language models."
  }
];

// Enhanced carousel component that shows multiple items per slide
interface CarouselProps<T> {
  items: T[];
  renderItem: (item: T) => React.ReactNode;
  itemsPerSlide?: number;
  autoRotate?: boolean; // Whether the carousel should auto-rotate
  rotationInterval?: number; // Time in ms between auto rotations
}

const Carousel = <T extends unknown>({ 
  items, 
  renderItem, 
  itemsPerSlide = 3, 
  autoRotate = true,
  rotationInterval = 11000
}: CarouselProps<T>) => {
  const [currentIndex, setCurrentIndex] = useState(0);
  const [width, setWidth] = useState(typeof window !== "undefined" ? window.innerWidth : 1200);
  const [actualItemsPerSlide, setActualItemsPerSlide] = useState(itemsPerSlide);
  const [isLoading, setIsLoading] = useState(true);
  const [loadedImages, setLoadedImages] = useState<Set<number>>(new Set([0]));
  const [isPaused, setIsPaused] = useState(false); // Track hover state to pause rotation
  
  // Loading state for better UX
  useEffect(() => {
    setIsLoading(true);
    const timer = setTimeout(() => {
      setIsLoading(false);
    }, 500);
    
    return () => clearTimeout(timer);
  }, []);

  // Responsive handling - adjust items per slide based on screen width
  useEffect(() => {
    const handleResize = () => {
      setWidth(window.innerWidth);
    };

    window.addEventListener('resize', handleResize);
    return () => window.removeEventListener('resize', handleResize);
  }, []);

  // Calculate items per slide based on screen width
  useEffect(() => {
    if (width < 640) { // Mobile
      setActualItemsPerSlide(1);
    } else if (width < 1024) { // Tablet
      setActualItemsPerSlide(2);
    } else { // Desktop
      setActualItemsPerSlide(itemsPerSlide);
    }
  }, [width, itemsPerSlide]);

  // Calculate total number of slides needed
  const totalSlides = Math.ceil(items.length / actualItemsPerSlide);

  // Only enable navigation if we have more items than can fit in one slide
  const needsNavigation = items.length > actualItemsPerSlide;

  // Auto-rotation effect
  useEffect(() => {
    if (!autoRotate || isPaused || !needsNavigation || totalSlides <= 1) return;
    
    const rotationTimer = setInterval(() => {
      const nextIndex = currentIndex === totalSlides - 1 ? 0 : currentIndex + 1;
      setCurrentIndex(nextIndex);
      
      // Preload next slide's images
      setLoadedImages(prev => {
        const newSet = new Set(prev);
        newSet.add(nextIndex);
        
        // Also add the one after that for smoother experience
        const nextNextIndex = nextIndex === totalSlides - 1 ? 0 : nextIndex + 1;
        newSet.add(nextNextIndex);
        return newSet;
      });
    }, rotationInterval);
    
    return () => clearInterval(rotationTimer);
  }, [autoRotate, currentIndex, isPaused, needsNavigation, rotationInterval, totalSlides]);

  const nextSlide = () => {
    if (!needsNavigation) return;
    const nextIndex = currentIndex === totalSlides - 1 ? 0 : currentIndex + 1;
    setCurrentIndex(nextIndex);
    
    // Preload next slide's images
    setLoadedImages(prev => {
      const newSet = new Set(prev);
      newSet.add(nextIndex);
      
      // Also add the one after that for smoother experience
      const nextNextIndex = nextIndex === totalSlides - 1 ? 0 : nextIndex + 1;
      newSet.add(nextNextIndex);
      return newSet;
    });
  };

  const prevSlide = () => {
    if (!needsNavigation) return;
    const prevIndex = currentIndex === 0 ? totalSlides - 1 : currentIndex - 1;
    setCurrentIndex(prevIndex);
    
    // Preload previous slide's images
    setLoadedImages(prev => {
      const newSet = new Set(prev);
      newSet.add(prevIndex);
      return newSet;
    });
  };

  // Touch / swipe support for mobile — horizontal drag flips slides, while vertical
  // scrolling still works (touchAction: pan-y). A short tap won't trigger a swipe.
  const touchStartX = useRef<number | null>(null);
  const touchStartY = useRef<number | null>(null);
  const onTouchStart = (e: React.TouchEvent) => {
    const t = e.touches[0];
    touchStartX.current = t.clientX;
    touchStartY.current = t.clientY;
    setIsPaused(true);
  };
  const onTouchEnd = (e: React.TouchEvent) => {
    if (touchStartX.current == null) { setIsPaused(false); return; }
    const t = e.changedTouches[0];
    const dx = t.clientX - touchStartX.current;
    const dy = t.clientY - (touchStartY.current ?? 0);
    touchStartX.current = null; touchStartY.current = null;
    setIsPaused(false);
    if (needsNavigation && Math.abs(dx) > 45 && Math.abs(dx) > Math.abs(dy) * 1.2) {
      if (dx < 0) nextSlide(); else prevSlide();
    }
  };

  const goToSlide = (index: number) => {
    if (!needsNavigation) return;
    setCurrentIndex(index);
    
    // Mark this slide as loaded
    setLoadedImages(prev => {
      const newSet = new Set(prev);
      newSet.add(index);
      return newSet;
    });
  };

  // Create array of slides, each containing multiple items
  const slides = [];
  for (let i = 0; i < totalSlides; i++) {
    const slideItems = items.slice(i * actualItemsPerSlide, (i + 1) * actualItemsPerSlide);
    slides.push(slideItems);
  }

  return (
    <div className="relative w-full">
      <div className="overflow-hidden" onTouchStart={onTouchStart} onTouchEnd={onTouchEnd} style={{ touchAction: "pan-y" }}>
        <div
          className="flex transition-transform duration-700 ease-[cubic-bezier(0.22,1,0.36,1)] will-change-transform"
          style={{ transform: `translateX(-${currentIndex * 100}%)` }}
        >
          {slides.map((slide, slideIndex) => (
            <div key={slideIndex} className="w-full flex-shrink-0">
              <div className={`grid gap-4 ${
                actualItemsPerSlide === 1
                  ? "grid-cols-1"
                  : actualItemsPerSlide === 2
                  ? "grid-cols-1 sm:grid-cols-2"
                  : "grid-cols-1 sm:grid-cols-2 lg:grid-cols-3"
              }`}>
                {/* Only render images that are in view or will soon be in view */}
                {(loadedImages.has(slideIndex) || Math.abs(slideIndex - currentIndex) <= 1) && 
                  slide.map((item, itemIndex) => (
                    <div key={`${slideIndex}-${itemIndex}`} className="carousel-item h-full">
                      {renderItem(item)}
                    </div>
                  ))
                }
              </div>
            </div>
          ))}
        </div>
      </div>

      {/* Navigation arrows - only show if needed */}
      {needsNavigation && (
        <>
          <button
            className="absolute left-0 top-1/2 -translate-y-1/2 bg-[var(--c-surface)] p-2 rounded-full z-10 text-[var(--c-accent)] hover:bg-[var(--c-surface2)] transition-colors"
            onClick={prevSlide}
            aria-label="Previous slide"
          >
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
              <path fillRule="evenodd" d="M11.354 1.646a.5.5 0 0 1 0 .708L5.707 8l5.647 5.646a.5.5 0 0 1-.708.708l-6-6a.5.5 0 0 1 0-.708l6-6a.5.5 0 0 1 .708 0z"/>
            </svg>
          </button>
          <button
            className="absolute right-0 top-1/2 -translate-y-1/2 bg-[var(--c-surface)] p-2 rounded-full z-10 text-[var(--c-accent)] hover:bg-[var(--c-surface2)] transition-colors"
            onClick={nextSlide}
            aria-label="Next slide"
          >
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
              <path fillRule="evenodd" d="M4.646 1.646a.5.5 0 0 1 .708 0l6 6a.5.5 0 0 1 0 .708l-6 6a.5.5 0 0 1-.708-.708L10.293 8 4.646 2.354a.5.5 0 0 1 0-.708z"/>
            </svg>
          </button>
        </>
      )}

      {/* Dots navigation with a highlight that "jumps" between dots */}
      {needsNavigation && totalSlides > 1 && (
        <div className="flex justify-center mt-4">
          <div className="relative inline-flex gap-1">
            {Array.from({ length: totalSlides }).map((_, index) => (
              <button
                key={index}
                className="w-6 h-6 flex items-center justify-center rounded-full relative z-10"
                onClick={() => goToSlide(index)}
                aria-label={`Go to slide ${index + 1}`}
              >
                <span className="block w-3 h-3 rounded-full bg-[var(--c-surface2)]" />
              </button>
            ))}
            {/* moving accent dot (28px pitch = 24px button + 4px gap; +6px centers the 12px dot) */}
            <span
              className="pointer-events-none absolute top-1/2 left-0 w-3 h-3 rounded-full bg-[var(--c-accent)]"
              style={{ transform: `translate(${currentIndex * 28 + 6}px, -50%)`, transition: "transform .5s cubic-bezier(0.34, 1.56, 0.64, 1)" }}
            />
          </div>
        </div>
      )}
    </div>
  );
};

// Mobile menu component
interface MobileMenuProps {
  links: Array<{ label: string; href: string }>;
  isOpen: boolean;
  setIsOpen: (isOpen: boolean) => void;
}

const MobileMenu = ({ links, isOpen, setIsOpen }: MobileMenuProps) => {
  if (!isOpen) return null;
  
  return (
    <div className="fixed inset-0 app-page-bg backdrop-blur-sm z-50 flex flex-col justify-center items-center animate-fadeIn">
      <button
        onClick={() => setIsOpen(false)}
        className="absolute top-5 right-5 text-[var(--c-text)] p-2"
        aria-label="Close menu"
      >
        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="currentColor" viewBox="0 0 16 16">
          <path d="M4.646 4.646a.5.5 0 0 1 .708 0L8 7.293l2.646-2.647a.5.5 0 0 1 .708.708L8.707 8l2.647 2.646a.5.5 0 0 1-.708.708L8 8.707l-2.646 2.647a.5.5 0 0 1-.708-.708L7.293 8 4.646 5.354a.5.5 0 0 1 0-.708z"/>
        </svg>
      </button>
      <ul className="flex flex-col items-center gap-6">
        {links.map((link) => (
          <li key={link.href}>
            <a
              href={link.href}
              className="text-[var(--c-text)] hover:text-[var(--c-accent)] text-xl font-semibold transition-colors duration-150"
              onClick={() => setIsOpen(false)}
            >
              {link.label}
            </a>
          </li>
        ))}
      </ul>
    </div>
  );
};

const floatBtn = "w-11 h-11 rounded-full bg-[var(--c-surface)] border border-[var(--c-border)] text-[var(--c-accent)] backdrop-blur-sm shadow-lg flex items-center justify-center hover:bg-[var(--c-accent)] hover:text-black transition-colors";

// Sun/moon theme toggle used in the floating control cluster.
function ThemeToggle({ theme, setTheme }: { theme: "dark" | "light"; setTheme: (t: "dark" | "light") => void }) {
  return (
    <button
      onClick={() => setTheme(theme === "dark" ? "light" : "dark")}
      aria-label={theme === "dark" ? "Switch to light mode" : "Switch to dark mode"}
      title={theme === "dark" ? "Light mode" : "Dark mode"}
      className={floatBtn}
    >
      {theme === "dark" ? (
        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor" viewBox="0 0 16 16"><path d="M8 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM8 0a.5.5 0 0 1 .5.5v2a.5.5 0 0 1-1 0v-2A.5.5 0 0 1 8 0zm0 13a.5.5 0 0 1 .5.5v2a.5.5 0 0 1-1 0v-2A.5.5 0 0 1 8 13zm8-5a.5.5 0 0 1-.5.5h-2a.5.5 0 0 1 0-1h2a.5.5 0 0 1 .5.5zM3 8a.5.5 0 0 1-.5.5h-2a.5.5 0 0 1 0-1h2A.5.5 0 0 1 3 8zm10.657-5.657a.5.5 0 0 1 0 .707l-1.414 1.415a.5.5 0 1 1-.708-.708l1.415-1.414a.5.5 0 0 1 .707 0zm-9.193 9.193a.5.5 0 0 1 0 .707L3.05 13.657a.5.5 0 0 1-.707-.707l1.414-1.414a.5.5 0 0 1 .707 0zm9.9.707a.5.5 0 0 1-.707 0l-1.414-1.414a.5.5 0 0 1 .707-.708l1.415 1.415a.5.5 0 0 1 0 .707zM4.464 4.464a.5.5 0 0 1-.707 0L2.343 3.05a.5.5 0 1 1 .707-.707l1.414 1.414a.5.5 0 0 1 0 .707z"/></svg>
      ) : (
        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor" viewBox="0 0 16 16"><path d="M6 .278a.768.768 0 0 1 .08.858 7.208 7.208 0 0 0-.878 3.46c0 4.021 3.278 7.277 7.318 7.277.527 0 1.04-.055 1.533-.16a.787.787 0 0 1 .81.316.733.733 0 0 1-.031.893A8.349 8.349 0 0 1 8.344 16C3.734 16 0 12.286 0 7.71 0 4.266 2.114 1.312 5.124.06A.752.752 0 0 1 6 .278z"/></svg>
      )}
    </button>
  );
}

// Floating cluster on the right: theme toggle above, scroll-to-top/bottom below.
function FloatingControls({ theme, setTheme }: { theme: "dark" | "light"; setTheme: (t: "dark" | "light") => void }) {
  const [atTop, setAtTop] = useState(true);
  useEffect(() => {
    const onScroll = () => setAtTop(window.scrollY < 300);
    window.addEventListener("scroll", onScroll, { passive: true });
    onScroll();
    return () => window.removeEventListener("scroll", onScroll);
  }, []);
  const go = () =>
    window.scrollTo({ top: atTop ? document.body.scrollHeight : 0, behavior: "smooth" });
  return (
    <div className="fixed right-4 bottom-6 z-50 flex flex-col items-center gap-3">
      <ThemeToggle theme={theme} setTheme={setTheme} />
      <button
        onClick={go}
        aria-label={atTop ? "Scroll to bottom" : "Scroll to top"}
        title={atTop ? "Scroll to bottom" : "Scroll to top"}
        className={floatBtn}
      >
        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" className={atTop ? "" : "rotate-180"}>
          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 9l-7 7-7-7" />
        </svg>
      </button>
    </div>
  );
}

// Turn an editable URL into a safe href: allow http(s)/mailto/tel/relative/anchor
// as-is, auto-prepend https:// to a bare domain (e.g. srlab.usask.ca), else reject.
function normUrl(u: string): string | null {
  const t = u.trim();
  if (/^(https?:\/\/|mailto:|tel:|\/|#)/i.test(t)) return t;
  if (/^[\w.-]+\.[a-z]{2,}(\/\S*)?$/i.test(t)) return "https://" + t;
  return null;
}

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
      if (url) {
        const ext = /^https?:\/\//i.test(url);
        out.push(
          <a key={k++} href={url} target={ext ? "_blank" : undefined} rel="noopener noreferrer" className="text-[var(--c-accent)] hover:underline">{label}</a>
        );
      } else {
        out.push(label);
      }
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

// ---- Media helpers (YouTube / PDF / image detection + embeds) ----
function ytId(url: string): string | null {
  if (!url) return null;
  const m = String(url).match(/(?:youtube\.com\/(?:watch\?v=|embed\/|shorts\/|live\/)|youtu\.be\/)([\w-]{11})/);
  return m ? m[1] : null;
}
function isPdfUrl(u: string) { return /\.pdf(\?|#|$)/i.test(u); }
function isImageUrl(u: string) { return /\.(png|jpe?g|gif|webp|svg|avif)(\?|#|$)/i.test(u); }

// Responsive 16:9 YouTube embed (privacy-enhanced domain).
function YouTubeEmbed({ id, title }: { id: string; title?: string }) {
  return (
    <div className="relative w-full my-6 rounded-xl overflow-hidden border border-[var(--c-border)] bg-black" style={{ aspectRatio: "16 / 9" }}>
      <iframe className="absolute inset-0 w-full h-full" src={`https://www.youtube-nocookie.com/embed/${id}`} title={title || "YouTube video"}
        loading="lazy" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" allowFullScreen />
    </div>
  );
}
function PdfEmbed({ url }: { url: string }) {
  return (
    <div className="my-6">
      <div className="rounded-xl overflow-hidden border border-[var(--c-border)] bg-[var(--c-surface)]" style={{ height: "80vh", maxHeight: 900 }}>
        <iframe src={url} title="PDF document" className="w-full h-full" loading="lazy" />
      </div>
      <a href={url} target="_blank" rel="noopener noreferrer" className="inline-flex items-center gap-1 mt-2 text-sm text-[var(--c-accent)] hover:underline">Open / download PDF ↗</a>
    </div>
  );
}
// Modal video player used by publication "Watch" buttons.
function VideoModal({ url, onClose }: { url: string; onClose: () => void }) {
  useEffect(() => {
    const onKey = (e: KeyboardEvent) => { if (e.key === "Escape") onClose(); };
    document.addEventListener("keydown", onKey);
    const prev = document.body.style.overflow; document.body.style.overflow = "hidden";
    return () => { document.removeEventListener("keydown", onKey); document.body.style.overflow = prev; };
  }, [onClose]);
  const id = ytId(url);
  return createPortal(
    <div className="fixed inset-0 z-[100] flex items-center justify-center bg-black/80 p-4" onClick={onClose}>
      <div className="w-full max-w-3xl" onClick={(e) => e.stopPropagation()}>
        {id ? <YouTubeEmbed id={id} /> : <video src={url} controls autoPlay className="w-full rounded-xl border border-[var(--c-border)] bg-black" />}
        <button onClick={onClose} className="mt-3 mx-auto block text-white/80 text-sm hover:text-white">Close ✕</button>
      </div>
    </div>, document.body);
}

// ---- Minimal, injection-safe Markdown renderer for blog posts (returns React nodes) ----
// Supports #/##/### headings, **bold**, *italic*, `code`, [links](), ![images](),
// -/1. lists, > quotes, ``` fenced code, --- rules, and auto-embeds a YouTube / PDF /
// image URL that sits alone on its own line.
function mdInline(s: string, keyBase = 0): React.ReactNode[] {
  const RE = /!\[([^\]]*)\]\(([^)\s]+)\)|\[([^\]]+)\]\(([^)\s]+)\)|\*\*([^*]+)\*\*|\*([^*]+)\*|`([^`]+)`/g;
  const out: React.ReactNode[] = [];
  let last = 0, m: RegExpExecArray | null, k = keyBase;
  while ((m = RE.exec(s)) !== null) {
    if (m.index > last) out.push(s.slice(last, m.index));
    if (m[1] !== undefined) { const u = normUrl(m[2]); if (u) out.push(<img key={k++} src={u} alt={m[1]} className="inline-block max-w-full rounded" loading="lazy" />); }
    else if (m[3] !== undefined) { const u = normUrl(m[4]); const ext = u && /^https?:/i.test(u); out.push(u ? <a key={k++} href={u} target={ext ? "_blank" : undefined} rel="noopener noreferrer" className="text-[var(--c-accent)] hover:underline">{m[3]}</a> : m[3]); }
    else if (m[5] !== undefined) out.push(<strong key={k++}>{m[5]}</strong>);
    else if (m[6] !== undefined) out.push(<em key={k++}>{m[6]}</em>);
    else if (m[7] !== undefined) out.push(<code key={k++} className="px-1.5 py-0.5 rounded bg-[var(--c-surface2)] text-[var(--c-accent)] text-[0.9em]">{m[7]}</code>);
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
    blocks.push(React.createElement(ordered ? "ol" : "ul", { key: key++, className: `${ordered ? "list-decimal" : "list-disc"} pl-6 my-4 space-y-1 text-[var(--c-muted)]` },
      items.map((it, j) => <li key={j}>{mdInline(it)}</li>)));
  };
  while (i < lines.length) {
    const line = lines[i];
    if (!line.trim()) { i++; continue; }
    if (/^```/.test(line)) {
      const buf: string[] = []; i++;
      while (i < lines.length && !/^```/.test(lines[i])) { buf.push(lines[i]); i++; }
      i++;
      blocks.push(<pre key={key++} className="my-4 p-4 rounded-xl overflow-x-auto bg-[var(--c-surface2)] border border-[var(--c-border)] text-sm"><code>{buf.join("\n")}</code></pre>);
      continue;
    }
    const hm = line.match(/^(#{1,3})\s+(.*)$/);
    if (hm) {
      const lvl = hm[1].length;
      const cls = lvl === 1 ? "text-3xl font-bold mt-8 mb-3" : lvl === 2 ? "text-2xl font-bold mt-7 mb-3" : "text-xl font-semibold mt-6 mb-2";
      blocks.push(React.createElement(`h${lvl + 1}`, { key: key++, className: cls }, mdInline(hm[2])));
      i++; continue;
    }
    if (/^(-{3,}|\*{3,}|_{3,})$/.test(line.trim())) { blocks.push(<hr key={key++} className="my-8 border-[var(--c-border)]" />); i++; continue; }
    if (/^>\s?/.test(line)) {
      const buf: string[] = [];
      while (i < lines.length && /^>\s?/.test(lines[i])) { buf.push(lines[i].replace(/^>\s?/, "")); i++; }
      blocks.push(<blockquote key={key++} className="my-4 pl-4 border-l-4 border-[var(--c-accent)] text-[var(--c-muted)] italic">{mdInline(buf.join(" "))}</blockquote>);
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
      if (u) { blocks.push(<figure key={key++} className="my-6"><img src={u} alt={imgOnly[1]} className="w-full rounded-xl border border-[var(--c-border)]" loading="lazy" />{imgOnly[1] && <figcaption className="text-center text-xs text-[var(--c-muted)] mt-2">{imgOnly[1]}</figcaption>}</figure>); i++; continue; }
    }
    if (/^https?:\/\/\S+$/i.test(solo)) {
      const yid = ytId(solo);
      if (yid) { blocks.push(<YouTubeEmbed key={key++} id={yid} />); i++; continue; }
      if (isPdfUrl(solo)) { blocks.push(<PdfEmbed key={key++} url={solo} />); i++; continue; }
      if (isImageUrl(solo)) { blocks.push(<figure key={key++} className="my-6"><img src={solo} alt="" className="w-full rounded-xl border border-[var(--c-border)]" loading="lazy" /></figure>); i++; continue; }
      blocks.push(<p key={key++} className="my-4 break-words"><a href={solo} target="_blank" rel="noopener noreferrer" className="text-[var(--c-accent)] hover:underline">{solo}</a></p>); i++; continue;
    }
    const buf: string[] = [];
    while (i < lines.length && lines[i].trim() && !/^(#{1,3}\s|>|[-*]\s|\d+\.\s|```)/.test(lines[i])) { buf.push(lines[i]); i++; }
    blocks.push(<p key={key++} className="my-4 leading-relaxed text-[var(--c-muted)]">{mdInline(buf.join(" "))}</p>);
  }
  return <div className="blog-body">{blocks}</div>;
}

// Live network of research topics — any number of nodes. "Main" nodes are the big
// central hubs; the rest orbit around them and auto-connect to the nearest hub. The
// graph lays itself out, gently drifts (edges follow), pauses off-screen, and honours
// prefers-reduced-motion. Supports {nodes:[{label,main}]} and legacy {hubs,leaves}.
function ResearchGraph({ data }: { data: any }) {
  let nodes: { label: string; main: boolean }[] = [];
  if (Array.isArray(data?.nodes)) {
    nodes = data.nodes.map((n: any) => (typeof n === "string" ? { label: n, main: false } : { label: String(n?.label || ""), main: !!n?.main })).filter((n: any) => n.label);
  } else {
    const hubs = Array.isArray(data?.hubs) ? data.hubs : [];
    const leaves = Array.isArray(data?.leaves) ? data.leaves : [];
    nodes = [...hubs.map((l: string) => ({ label: String(l), main: true })), ...leaves.map((l: string) => ({ label: String(l), main: false }))].filter((n) => n.label);
  }

  const cx = 300, cy = 250;
  // deterministic pseudo-random (stable across renders/prerender) for organic scatter
  const rnd = (i: number, salt: number) => { const x = Math.sin((i + 1) * 12.9898 + salt * 78.233) * 43758.5453; return x - Math.floor(x); };
  const mainIdx = nodes.map((n, i) => ({ n, i })).filter((x) => x.n.main);
  const subIdx = nodes.map((n, i) => ({ n, i })).filter((x) => !x.n.main);
  const base: { x: number; y: number }[] = new Array(nodes.length);
  if (!mainIdx.length) {
    subIdx.forEach((s, k) => { const c = Math.max(1, subIdx.length); const ang = -Math.PI / 2 + (k / c) * Math.PI * 2 + (rnd(s.i, 2) - 0.5) * 0.5; const rf = 0.85 + rnd(s.i, 3) * 0.3; base[s.i] = { x: cx + Math.cos(ang) * 215 * rf, y: cy + Math.sin(ang) * 175 * rf }; });
  } else {
    mainIdx.forEach((m, k) => {
      const c = mainIdx.length;
      const t = c === 1 ? 0.5 : k / (c - 1);
      const x = c === 1 ? cx : cx + (t - 0.5) * Math.min(150, 100 + c * 18);
      const y = cy + (c === 1 ? 0 : (k % 2 === 0 ? -30 : 26)) + (rnd(m.i, 1) - 0.5) * 18;
      base[m.i] = { x, y };
    });
    subIdx.forEach((s, k) => {
      const c = Math.max(1, subIdx.length);
      const ang = -Math.PI / 2 + (k / c) * Math.PI * 2 + (rnd(s.i, 2) - 0.5) * 0.55;
      const rf = 0.82 + rnd(s.i, 3) * 0.34;
      base[s.i] = { x: cx + Math.cos(ang) * 232 * rf, y: cy + Math.sin(ang) * 186 * rf };
    });
  }

  const edges: [number, number][] = [];
  for (let a = 0; a < mainIdx.length; a++) for (let b = a + 1; b < mainIdx.length; b++) edges.push([mainIdx[a].i, mainIdx[b].i]);
  if (mainIdx.length) {
    subIdx.forEach((s) => { let best = mainIdx[0].i, bd = Infinity; mainIdx.forEach((m) => { const dx = base[m.i].x - base[s.i].x, dy = base[m.i].y - base[s.i].y; const d = dx * dx + dy * dy; if (d < bd) { bd = d; best = m.i; } }); edges.push([s.i, best]); });
  } else { for (let i = 0; i < nodes.length; i++) edges.push([i, (i + 1) % nodes.length]); }

  const labelFor = (i: number, main: boolean) => {
    const p = base[i];
    if (main) return { x: p.x, y: p.y + 46, anchor: "middle" as const };
    const dx = p.x - cx, dy = p.y - cy, len = Math.hypot(dx, dy) || 1;
    const anchor = dx < -40 ? "end" : dx > 40 ? "start" : "middle";
    return { x: p.x + (dx / len) * 22, y: p.y + (dy / len) * 22 + (dy > 0 ? 12 : 2), anchor };
  };

  const [mounted, setMounted] = useState(false);
  const wrapRef = React.useRef<HTMLDivElement>(null);
  useEffect(() => { const id = setTimeout(() => setMounted(true), 80); return () => clearTimeout(id); }, []);
  useEffect(() => {
    const wrap = wrapRef.current;
    const svg = wrap?.querySelector("svg");
    if (!wrap || !svg) return;
    if (window.matchMedia && window.matchMedia("(prefers-reduced-motion: reduce)").matches) return;
    const movers = Array.from(svg.querySelectorAll("[data-i]")) as unknown as SVGElement[];
    const lines = Array.from(svg.querySelectorAll("line[data-a]")) as unknown as SVGLineElement[];
    const P: { x: number; y: number }[] = [];
    const compute = (t: number) => { for (let i = 0; i < base.length; i++) { const b = base[i]; if (!b) continue; const ph = i * 1.3; P[i] = { x: b.x + 6 * Math.sin(t * 0.6 + ph), y: b.y + 6 * Math.cos(t * 0.5 + ph) }; } };
    let raf = 0; let running = true;
    const loop = (ts: number) => {
      if (!running) return;
      compute(ts / 1000);
      for (const el of movers) {
        const p = P[Number(el.dataset.i)]; if (!p) continue;
        if (el.tagName === "text") { el.setAttribute("x", String(p.x + Number(el.dataset.ox || 0))); el.setAttribute("y", String(p.y + Number(el.dataset.oy || 0))); }
        else { el.setAttribute("cx", String(p.x)); el.setAttribute("cy", String(p.y)); }
      }
      for (const l of lines) {
        const a = P[Number(l.dataset.a)]; const b = P[Number(l.dataset.b)];
        if (a) { l.setAttribute("x1", String(a.x)); l.setAttribute("y1", String(a.y)); }
        if (b) { l.setAttribute("x2", String(b.x)); l.setAttribute("y2", String(b.y)); }
      }
      raf = requestAnimationFrame(loop);
    };
    raf = requestAnimationFrame(loop);
    let io: IntersectionObserver | undefined;
    if ("IntersectionObserver" in window) {
      io = new IntersectionObserver(([e]) => {
        if (e.isIntersecting && !running) { running = true; raf = requestAnimationFrame(loop); }
        else if (!e.isIntersecting && running) { running = false; cancelAnimationFrame(raf); }
      }, { threshold: 0 });
      io.observe(wrap);
    }
    return () => { running = false; cancelAnimationFrame(raf); io && io.disconnect(); };
  }, [nodes.map((n) => n.label + (n.main ? "*" : "")).join("|")]);

  if (!nodes.length) return null;
  return (
    <div ref={wrapRef} className="max-w-2xl mx-auto" style={{ opacity: mounted ? 1 : 0, transition: "opacity .7s ease" }}>
      <svg viewBox="-70 0 740 500" className="w-full h-auto" role="img" aria-label="Network of research topics">
        {edges.map(([a, b], i) => (
          <line key={"e" + i} data-a={a} data-b={b} x1={base[a].x} y1={base[a].y} x2={base[b].x} y2={base[b].y} className="stroke-[var(--c-border)]" strokeWidth={2} />
        ))}
        {nodes.map((n, i) => {
          const p = base[i]; const lab = labelFor(i, n.main); const ox = lab.x - p.x, oy = lab.y - p.y;
          return n.main ? (
            <g key={i}>
              <circle data-i={i} cx={p.x} cy={p.y} r={30} fill="none" className="stroke-[var(--c-accent)]" strokeWidth={2} opacity={0.45} />
              <circle data-i={i} cx={p.x} cy={p.y} r={20} className="fill-[var(--c-accent)]" />
              <text data-i={i} data-ox={ox} data-oy={oy} x={lab.x} y={lab.y} textAnchor={lab.anchor} className="fill-[var(--c-accent)] font-bold" fontSize={15}>{n.label}</text>
            </g>
          ) : (
            <g key={i}>
              <circle data-i={i} cx={p.x} cy={p.y} r={9} className="fill-[var(--c-text)]" />
              <text data-i={i} data-ox={ox} data-oy={oy} x={lab.x} y={lab.y} textAnchor={lab.anchor} className="fill-[var(--c-muted)]" fontSize={12}>{n.label}</text>
            </g>
          );
        })}
      </svg>
    </div>
  );
}

// Contact form — posts to the PHP mailer (api/contact.php). Includes a honeypot.
function ContactForm() {
  const [status, setStatus] = useState<"idle" | "sending" | "ok" | "error">("idle");
  const [err, setErr] = useState("");
  const submit = async (e: React.FormEvent<HTMLFormElement>) => {
    e.preventDefault();
    const f = e.currentTarget;
    const val = (n: string) => (f.elements.namedItem(n) as HTMLInputElement | HTMLTextAreaElement)?.value ?? "";
    const data = { name: val("name"), email: val("email"), subject: val("subject"), message: val("message"), website: val("website") };
    setStatus("sending"); setErr("");
    try {
      const r = await fetch("/api/contact.php", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify(data) });
      const j = await r.json();
      if (j.ok) { setStatus("ok"); f.reset(); }
      else { setStatus("error"); setErr(j.error || "Something went wrong. Please try again."); }
    } catch { setStatus("error"); setErr("Network error. Please try again, or email contact@palashroy.me."); }
  };
  const field = "w-full px-3 py-2 rounded-lg bg-[var(--c-bg)] border border-[var(--c-border)] text-[var(--c-text)] focus:border-[var(--c-accent)] outline-none transition-colors";
  return (
    <form onSubmit={submit} className="max-w-xl mx-auto bg-[var(--c-surface)] border border-[var(--c-border)] rounded-xl p-6 sm:p-8">
      {/* honeypot — hidden from humans, tempting to bots */}
      <input type="text" name="website" tabIndex={-1} autoComplete="off" aria-hidden="true" className="hidden" />
      <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div>
          <label className="block text-xs text-[var(--c-muted)] mb-1">Name</label>
          <input name="name" required maxLength={120} className={field} placeholder="Your name" />
        </div>
        <div>
          <label className="block text-xs text-[var(--c-muted)] mb-1">Email</label>
          <input name="email" type="email" required maxLength={160} className={field} placeholder="you@example.com" />
        </div>
      </div>
      <div className="mt-4">
        <label className="block text-xs text-[var(--c-muted)] mb-1">Subject <span className="opacity-60">(optional)</span></label>
        <input name="subject" maxLength={160} className={field} placeholder="What's this about?" />
      </div>
      <div className="mt-4">
        <label className="block text-xs text-[var(--c-muted)] mb-1">Message</label>
        <textarea name="message" required maxLength={5000} rows={5} className={`${field} resize-y`} placeholder="Write your message…" />
      </div>
      <div className="mt-5 flex items-center gap-4">
        <button type="submit" disabled={status === "sending"}
          className="px-6 py-3 rounded-lg bg-gradient-to-r from-[#35c7ff] to-[#ff4081] text-white text-sm font-semibold hover:opacity-90 transition-opacity disabled:opacity-60">
          {status === "sending" ? "Sending…" : "Send message"}
        </button>
        {status === "ok" && <span className="text-sm text-[#3ddc84]">Thanks! Your message has been sent. ✓</span>}
        {status === "error" && <span className="text-sm text-[#ff6b6b]">{err}</span>}
      </div>
    </form>
  );
}

// Editable section heading (badge + title + subtitle), driven by content/headings.json
function SectionHead({ h }: { h: any }) {
  if (!h) return null;
  return (
    <>
      {h.badge && <div className="inline-block px-3 py-1 bg-[var(--c-surface)] text-[var(--c-accent)] text-sm font-medium mb-2 rounded">{h.badge}</div>}
      {h.title && <h2 className="text-4xl font-bold mb-4">{h.title}</h2>}
      {h.subtitle && <p className="text-[var(--c-muted)] text-lg max-w-3xl mx-auto">{renderRich(h.subtitle)}</p>}
    </>
  );
}

// Collaboration / booking call-to-action. Booking link wins; otherwise emails.
function ConsultationSection({ data, head }: { data: any; head: any }) {
  const href = data.bookingUrl || `mailto:${data.email || "palash.roy@usask.ca"}`;
  const external = /^https?:\/\//i.test(href);
  const points = Array.isArray(data.points) ? data.points.filter(Boolean) : [];
  return (
    <section id="consultation" className="pt-16 pb-16">
      <div className="text-center mb-8"><SectionHead h={head} /></div>
      <div className="max-w-2xl mx-auto bg-[var(--c-surface)] border border-[var(--c-border)] rounded-2xl p-8 sm:p-10 text-center shadow-sm">
        {data.description && <p className="text-[var(--c-muted)] leading-relaxed mb-6">{renderRich(data.description)}</p>}
        {points.length > 0 && (
          <ul className="text-left inline-block mb-8 space-y-2">
            {points.map((pt: string, i: number) => (
              <li key={i} className="flex gap-2 text-[var(--c-muted)]"><span className="text-[var(--c-accent)] font-bold">✓</span><span>{renderRich(pt)}</span></li>
            ))}
          </ul>
        )}
        <div>
          <a href={href} {...(external ? { target: "_blank", rel: "noopener noreferrer" } : {})}
            className="inline-flex items-center gap-2 px-7 py-3.5 rounded-lg bg-gradient-to-r from-[#35c7ff] to-[#ff4081] text-white text-sm font-semibold hover:opacity-90 transition-opacity">
            {data.buttonLabel || "Get in touch"}
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path fillRule="evenodd" d="M1 8a.5.5 0 0 1 .5-.5h11.793l-3.147-3.146a.5.5 0 0 1 .708-.708l4 4a.5.5 0 0 1 0 .708l-4 4a.5.5 0 0 1-.708-.708L13.293 8.5H1.5A.5.5 0 0 1 1 8z"/></svg>
          </a>
        </div>
      </div>
    </section>
  );
}

// Build BibTeX / APA / IEEE citation strings from a publication's fields.
function buildCitations(item: Publication) {
  const authorsList = item.authors.split(/\s*,\s*|\s+and\s+/).map((a) => a.trim()).filter(Boolean);
  const bibAuthors = authorsList.join(" and ");
  const surname = ((authorsList[0] || "").split(/\s+/).pop() || "ref").replace(/[^A-Za-z]/g, "").toLowerCase() || "ref";
  const firstWord = ((item.title.match(/[A-Za-z]+/) || ["ref"])[0]).toLowerCase();
  const key = `${surname}${item.year || ""}${firstWord}`;
  const pageStr = item.pages ? item.pages.replace(/^\s*pp\.\s*/i, "") : "";
  const doiUrl = item.doi ? `https://doi.org/${item.doi}` : "";
  // Use the official publisher BibTeX when provided; otherwise build one from the fields.
  const bibtex = (item.bibtex && item.bibtex.trim())
    ? item.bibtex.trim()
    : [
      `@inproceedings{${key},`,
      `  author    = {${bibAuthors}},`,
      `  title     = {${item.title}},`,
      `  booktitle = {${item.venue}},`,
      item.year ? `  year      = {${item.year}},` : null,
      pageStr ? `  pages     = {${pageStr}},` : null,
      item.doi ? `  doi       = {${item.doi}},` : null,
      `}`,
    ].filter(Boolean).join("\n");
  const apa = `${item.authors} (${item.year}). ${item.title}. ${item.venue}${pageStr ? `, ${pageStr}` : ""}.${doiUrl ? ` ${doiUrl}` : ""}`;
  const ieee = `${item.authors}, "${item.title}," ${item.venue}, ${item.year}${pageStr ? `, pp. ${pageStr}` : ""}.${item.doi ? ` doi: ${item.doi}.` : ""}`;
  return { bibtex, apa, ieee };
}

// Modal that shows copyable citations. Rendered through a portal so it escapes
// the carousel's overflow + transform (fixed-position would otherwise be clipped).
function CiteModal({ item, onClose }: { item: Publication; onClose: () => void }) {
  const [fmt, setFmt] = useState<"bibtex" | "apa" | "ieee">("bibtex");
  const [copied, setCopied] = useState(false);
  const cites = buildCitations(item);
  const text = cites[fmt];
  const copy = async () => {
    try { await navigator.clipboard.writeText(text); setCopied(true); setTimeout(() => setCopied(false), 1500); } catch { /* ignore */ }
  };
  useEffect(() => {
    const onKey = (e: KeyboardEvent) => { if (e.key === "Escape") onClose(); };
    document.addEventListener("keydown", onKey);
    return () => document.removeEventListener("keydown", onKey);
  }, [onClose]);
  return createPortal(
    <div className="fixed inset-0 z-[100] flex items-center justify-center p-4 bg-black/60 backdrop-blur-sm" onClick={onClose}>
      <div className="bg-[var(--c-surface)] border border-[var(--c-border)] rounded-xl max-w-2xl w-full p-5 shadow-2xl" onClick={(e) => e.stopPropagation()}>
        <div className="flex items-start justify-between gap-3 mb-3">
          <div className="text-sm font-semibold text-[var(--c-text)] line-clamp-2">{item.title}</div>
          <button onClick={onClose} aria-label="Close" className="text-[var(--c-muted)] hover:text-[var(--c-text)] shrink-0 text-lg leading-none">✕</button>
        </div>
        <div className="flex gap-2 mb-3">
          {(["bibtex", "apa", "ieee"] as const).map((f) => (
            <button key={f} onClick={() => setFmt(f)}
              className={`px-3 py-1 rounded text-xs font-medium transition-colors ${fmt === f ? "bg-[var(--c-accent)] text-black" : "bg-[var(--c-surface2)] text-[var(--c-muted)] hover:text-[var(--c-text)]"}`}>
              {f === "bibtex" ? "BibTeX" : f.toUpperCase()}
            </button>
          ))}
        </div>
        <pre className="bg-[var(--c-bg)] border border-[var(--c-border)] rounded-lg p-3 text-xs text-[var(--c-muted)] overflow-x-auto whitespace-pre-wrap break-words max-h-64">{text}</pre>
        <div className="flex justify-end mt-3">
          <button onClick={copy} className="px-4 py-2 rounded-lg bg-gradient-to-r from-[#35c7ff] to-[#ff4081] text-white text-sm font-semibold hover:opacity-90 transition-opacity">
            {copied ? "Copied ✓" : "Copy to clipboard"}
          </button>
        </div>
      </div>
    </div>,
    document.body
  );
}

// "Publications per year" bar chart, derived live from the publication list.
// Bars rise one-by-one (staggered) the first time the chart scrolls into view.
function PubYearChart({ items }: { items: Publication[] }) {
  const counts: Record<string, number> = {};
  items.forEach((p) => { const y = (p.year || "").trim(); if (/^\d{4}$/.test(y)) counts[y] = (counts[y] || 0) + 1; });
  const years = Object.keys(counts).sort();
  const max = Math.max(1, ...Object.values(counts));
  const [grown, setGrown] = useState(false);
  const ref = React.useRef<HTMLDivElement>(null);
  useEffect(() => {
    const el = ref.current; if (!el) return;
    const reduce = window.matchMedia && window.matchMedia("(prefers-reduced-motion: reduce)").matches;
    if (reduce || !("IntersectionObserver" in window)) { setGrown(true); return; }
    const io = new IntersectionObserver((entries) => {
      if (entries[0].isIntersecting) { setGrown(true); io.disconnect(); }
    }, { threshold: 0.35 });
    io.observe(el);
    return () => io.disconnect();
  }, []);
  if (years.length < 2) return null;
  return (
    <div ref={ref} className="max-w-md mx-auto">
      <div className="flex items-end justify-center gap-3 sm:gap-5 h-36 px-2">
        {years.map((y, i) => (
          <div key={y} className="flex flex-col items-center justify-end gap-1 flex-1 max-w-[52px] h-full">
            <div className="text-sm font-bold text-[var(--c-accent)] transition-all duration-500"
              style={{ opacity: grown ? 1 : 0, transform: grown ? "translateY(0)" : "translateY(6px)", transitionDelay: `${300 + i * 140}ms` }}>{counts[y]}</div>
            <div className="w-7 sm:w-9 rounded-t bg-gradient-to-t from-[#35c7ff] to-[#ff4081] transition-[height] duration-700 ease-out"
              style={{ height: grown ? `${Math.max(8, (counts[y] / max) * 100)}%` : "0%", transitionDelay: `${i * 140}ms` }} />
            <div className="text-[11px] text-[var(--c-muted)] mt-1">{y}</div>
          </div>
        ))}
      </div>
    </div>
  );
}

// Publication card that flips to reveal the summary when the "i" button is clicked.
function PublicationCard({ item }: { item: Publication }) {
  const [flipped, setFlipped] = useState(false);
  const [citeOpen, setCiteOpen] = useState(false);
  const [videoOpen, setVideoOpen] = useState(false);
  return (
    <div className={`flip ${flipped ? "flipped" : ""}`}>
      <div className="flip-inner">
        {/* Front */}
        <div className="flip-face bg-[var(--c-surface)] p-6 rounded-lg border border-[var(--c-border)]">
          {item.award && (
            <div className="inline-flex items-center gap-1 mb-2 px-2 py-1 rounded-full text-xs font-semibold bg-gradient-to-r from-[#b8860b] to-[#ffd700] text-black self-start">
              🏆 {item.award}
            </div>
          )}
          <div className="font-bold mb-1 pr-7 line-clamp-3" title={item.title}>{item.title}</div>
          <div className="text-[var(--c-accent)] text-sm mb-1 line-clamp-2">{item.authors}</div>
          <div className="text-[var(--c-muted)] text-xs italic">{item.venue}{item.year ? `, ${item.year}` : ""}{item.pages ? `, ${item.pages}` : ""}</div>
          <div className="flex flex-wrap gap-2 mt-auto pt-3">
            <button onClick={() => setCiteOpen(true)} className="inline-flex items-center px-3 py-1 text-xs rounded bg-[var(--c-surface2)] text-[var(--c-accent)] hover:bg-[var(--c-chip)] transition-colors" title="Copy citation">
              <svg className="w-4 h-4 mr-1" fill="currentColor" viewBox="0 0 16 16" xmlns="http://www.w3.org/2000/svg"><path d="M12 12a1 1 0 0 0 1-1V8.558a1 1 0 0 0-1-1h-1.388q0-.527.062-1.054.093-.558.31-.992.217-.434.559-.683.34-.279.868-.279V3q-.868 0-1.52.372a3.3 3.3 0 0 0-1.085.992 4.9 4.9 0 0 0-.62 1.458A7.7 7.7 0 0 0 8.5 7.558V11a1 1 0 0 0 1 1zm-6 0a1 1 0 0 0 1-1V8.558a1 1 0 0 0-1-1H4.612q0-.527.062-1.054.094-.558.31-.992.217-.434.559-.683.34-.279.868-.279V3q-.868 0-1.52.372a3.3 3.3 0 0 0-1.085.992 4.9 4.9 0 0 0-.62 1.458A7.7 7.7 0 0 0 2.5 7.558V11a1 1 0 0 0 1 1z"/></svg>
              Cite
            </button>
            {item.doi && (
              <a href={`https://doi.org/${item.doi}`} target="_blank" rel="noopener noreferrer" className="inline-flex items-center px-3 py-1 text-xs rounded bg-[var(--c-surface2)] hover:bg-[var(--c-surface2)] transition-colors">
                <svg className="w-4 h-4 mr-1" fill="currentColor" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg"><path d="M11 3a1 1 0 100 2h2.586l-6.293 6.293a1 1 0 101.414 1.414L15 6.414V9a1 1 0 102 0V4a1 1 0 00-1-1h-5z"></path><path d="M5 5a2 2 0 00-2 2v10a2 2 0 002 2h12a2 2 0 002-2V6a2 2 0 00-2-2h-3.5l-1.5-1.5h-5L4 4zm7 5a1 1 0 100-2H9v2h2zm3 0a1 1 0 100-2h-2v2h2zm-9 3a1 1 0 100-2H5v2h2zm3 0a1 1 0 100-2H8v2h2zm3 0a1 1 0 100-2h-2v2h2zm3 0a1 1 0 100-2h-2v2h2z"></path></svg>
                DOI
              </a>
            )}
            {item.preprint && (
              <a href={item.preprint} target="_blank" rel="noopener noreferrer" className="inline-flex items-center px-3 py-1 text-xs rounded bg-[var(--c-chip)] hover:bg-[var(--c-surface2)] text-[var(--c-accent)] transition-colors">
                <svg className="w-4 h-4 mr-1" fill="currentColor" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg"><path d="M4 4a2 2 0 012-2h8a2 2 0 012 2v16a1 1 0 01-1.581.814l-4.419-3.346-4.419 3.346A1 1 0 014 16V4zm5 0a1 1 0 00-1 1v6.5a.5.5 0 001 0V5a1 1 0 00-1-1z"></path></svg>
                Download PDF
              </a>
            )}
            {item.video && (
              ytId(item.video)
                ? <button onClick={() => setVideoOpen(true)} className="inline-flex items-center px-3 py-1 text-xs rounded bg-gradient-to-r from-[#35c7ff] to-[#ff4081] text-white hover:opacity-90 transition-opacity" title="Watch the video">
                    <svg className="w-4 h-4 mr-1" fill="currentColor" viewBox="0 0 16 16" xmlns="http://www.w3.org/2000/svg"><path d="M6.79 5.093A.5.5 0 0 0 6 5.5v5a.5.5 0 0 0 .79.407l3.5-2.5a.5.5 0 0 0 0-.814l-3.5-2.5z"/></svg>
                    Watch
                  </button>
                : <a href={item.video} target="_blank" rel="noopener noreferrer" className="inline-flex items-center px-3 py-1 text-xs rounded bg-gradient-to-r from-[#35c7ff] to-[#ff4081] text-white hover:opacity-90 transition-opacity" title="Watch the video">
                    <svg className="w-4 h-4 mr-1" fill="currentColor" viewBox="0 0 16 16" xmlns="http://www.w3.org/2000/svg"><path d="M6.79 5.093A.5.5 0 0 0 6 5.5v5a.5.5 0 0 0 .79.407l3.5-2.5a.5.5 0 0 0 0-.814l-3.5-2.5z"/></svg>
                    Watch
                  </a>
            )}
          </div>
          {item.description && (
            <button onClick={() => setFlipped(true)} title="More details" aria-label="Show summary"
              className="absolute top-3 right-3 w-6 h-6 rounded-full bg-[var(--c-surface2)] text-[var(--c-accent)] text-sm font-bold italic flex items-center justify-center hover:bg-[var(--c-accent)] hover:text-black transition-colors">
              i
            </button>
          )}
        </div>
        {/* Back */}
        <div className="flip-face flip-back bg-[var(--c-surface)] p-6 rounded-lg border border-[var(--c-accent)]/40">
          <div className="text-[var(--c-accent)] text-xs font-semibold uppercase tracking-wider mb-2">Summary</div>
          <div className="text-[var(--c-muted)] text-sm flex-1">{item.description}</div>
          <button onClick={() => setFlipped(false)} className="mt-3 self-start text-[var(--c-accent)] text-xs font-medium hover:underline">&larr; Back</button>
        </div>
      </div>
      {citeOpen && <CiteModal item={item} onClose={() => setCiteOpen(false)} />}
      {videoOpen && item.video && <VideoModal url={item.video} onClose={() => setVideoOpen(false)} />}
    </div>
  );
}

// Responsive grid of cards for a custom "card list" section.
function CardGrid({ cards }: { cards: any[] }) {
  const list = Array.isArray(cards) ? cards.filter((c) => c && (c.title || c.description || c.image)) : [];
  if (!list.length) return null;
  return (
    <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 max-w-5xl mx-auto">
      {list.map((c, i) => (
        <div key={i} className="bg-[var(--c-surface)] border border-[var(--c-border)] rounded-lg p-5 flex flex-col">
          {c.image && (
            <img src={c.image} alt={c.title || ""} loading="lazy" decoding="async" className="w-full h-40 object-cover rounded mb-3" />
          )}
          {c.title && <h3 className="font-bold mb-1">{c.title}</h3>}
          {c.description && <p className="text-[var(--c-muted)] text-sm flex-1">{renderRich(c.description)}</p>}
          {c.link && (
            <a href={c.link} target="_blank" rel="noopener noreferrer" aria-label={`Learn more about ${c.title || "this item"}`}
              className="text-[var(--c-accent)] text-sm mt-3 hover:underline inline-flex items-center">Learn more &rarr;</a>
          )}
        </div>
      ))}
    </div>
  );
}

// One content block inside a custom section: text, image, or a card grid.
function CustomBlock({ b }: { b: any }) {
  if (!b) return null;
  if (b.type === "image") {
    if (!b.image) return null;
    return (
      <figure className="max-w-3xl mx-auto">
        <img src={b.image} alt={b.caption || ""} loading="lazy" decoding="async" className="w-full rounded-lg border border-[var(--c-border)]" />
        {b.caption && <figcaption className="text-center text-sm text-[var(--c-muted)] mt-2">{b.caption}</figcaption>}
      </figure>
    );
  }
  if (b.type === "cards") return <CardGrid cards={b.cards} />;
  // default: rich text
  return (
    <div className="max-w-3xl mx-auto text-[var(--c-muted)] leading-relaxed space-y-4">
      {String(b.text || "").split(/\n{2,}/).map((p) => p.trim()).filter(Boolean).map((para, i) => (
        <p key={i}>{renderRich(para)}</p>
      ))}
    </div>
  );
}

// A user-defined section (added from admin) — an ordered list of text/image/card blocks.
function CustomSection({ data }: { data: any }) {
  const head = { badge: data.badge, title: data.title, subtitle: data.subtitle };
  const blocks = Array.isArray(data.blocks) ? data.blocks
    : data.type === "cards" ? [{ type: "cards", cards: data.cards }]
    : [{ type: "text", text: data.body }];
  return (
    <section id={data.id} className="pt-16 pb-16">
      <div className="text-center mb-8"><SectionHead h={head} /></div>
      <div className="space-y-8">
        {blocks.map((b: any, i: number) => <CustomBlock key={i} b={b} />)}
      </div>
    </section>
  );
}

// Icon + brand colour for each social platform (academic ones share a mortarboard).
const ACADEMIC_PATHS = ["M8.211 2.047a.5.5 0 0 0-.422 0l-7.5 3.5a.5.5 0 0 0 .025.917l7.5 3a.5.5 0 0 0 .372 0L14 7.14V13a1 1 0 0 0-1 1v2h3v-2a1 1 0 0 0-1-1V6.739l.686-.275a.5.5 0 0 0 .025-.917zM8 8.46 1.758 5.965 8 3.052l6.242 2.913z", "M4.176 9.032a.5.5 0 0 0-.656.327l-.5 1.7a.5.5 0 0 0 .294.605l4.5 1.8a.5.5 0 0 0 .372 0l4.5-1.8a.5.5 0 0 0 .294-.605l-.5-1.7a.5.5 0 0 0-.656-.327L8 10.466z"];
const SOCIAL_META: Record<string, { color: string; label: string; paths: string[] }> = {
  linkedin: { color: "#0A66C2", label: "LinkedIn", paths: ["M0 1.146C0 .513.526 0 1.175 0h13.65C15.474 0 16 .513 16 1.146v13.708c0 .633-.526 1.146-1.175 1.146H1.175C.526 16 0 15.487 0 14.854V1.146zm4.943 12.248V6.169H2.542v7.225h2.401zm-1.2-8.212c.837 0 1.358-.554 1.358-1.248-.015-.709-.52-1.248-1.342-1.248-.822 0-1.359.54-1.359 1.248 0 .694.521 1.248 1.327 1.248h.016zm4.908 8.212V9.359c0-.216.016-.432.08-.586.173-.431.568-.878 1.232-.878.869 0 1.216.662 1.216 1.634v3.865h2.401V9.25c0-2.22-1.184-3.252-2.764-3.252-1.274 0-1.845.7-2.165 1.193v.025h-.016a5.54 5.54 0 0 1 .016-.025V6.169h-2.4c.03.678 0 7.225 0 7.225h2.4z"] },
  github: { color: "#24292f", label: "GitHub", paths: ["M8 0C3.58 0 0 3.58 0 8c0 3.54 2.29 6.53 5.47 7.59.4.07.55-.17.55-.38 0-.19-.01-.82-.01-1.49-2.01.37-2.53-.49-2.69-.94-.09-.23-.48-.94-.82-1.13-.28-.15-.68-.52-.01-.53.63-.01 1.08.58 1.23.82.72 1.21 1.87.87 2.33.66.07-.52.28-.87.51-1.07-1.78-.2-3.64-.89-3.64-3.95 0-.87.31-1.59.82-2.15-.08-.2-.36-1.02.08-2.12 0 0 .67-.21 2.2.82.64-.18 1.32-.27 2-.27.68 0 1.36.09 2 .27 1.53-1.04 2.2-.82 2.2-.82.44 1.1.16 1.92.08 2.12.51.56.82 1.27.82 2.15 0 3.07-1.87 3.75-3.65 3.95.29.25.54.73.54 1.48 0 1.07-.01 1.93-.01 2.2 0 .21.15.46.55.38A8.012 8.012 0 0 0 16 8c0-4.42-3.58-8-8-8z"] },
  facebook: { color: "#1877F2", label: "Facebook", paths: ["M16 8.049c0-4.446-3.582-8.05-8-8.05C3.58 0-.002 3.603-.002 8.05c0 4.017 2.926 7.347 6.75 7.951v-5.625h-2.03V8.05H6.75V6.275c0-2.017 1.195-3.131 3.022-3.131.876 0 1.791.157 1.791.157v1.98h-1.009c-.993 0-1.303.621-1.303 1.258v1.51h2.218l-.354 2.326H9.25V16c3.824-.604 6.75-3.934 6.75-7.951z"] },
  instagram: { color: "#E4405F", label: "Instagram", paths: ["M8 0C5.829 0 5.556.01 4.703.048 3.85.088 3.269.222 2.76.42a3.917 3.917 0 0 0-1.417.923A3.927 3.927 0 0 0 .42 2.76C.222 3.268.087 3.85.048 4.7.01 5.555 0 5.827 0 8.001c0 2.172.01 2.444.048 3.297.04.852.174 1.433.372 1.942.205.526.478.972.923 1.417.444.445.89.719 1.416.923.51.198 1.09.333 1.942.372C5.555 15.99 5.827 16 8 16s2.444-.01 3.298-.048c.78-.035 1.204-.166 1.486-.275.373-.145.64-.319.92-.599.28-.28.453-.546.598-.92.11-.281.24-.705.276-1.485.038-.843.047-1.096.047-3.232s-.009-2.39-.047-3.233c-.036-.78-.166-1.203-.276-1.485a2.478 2.478 0 0 0-.598-.92 2.48 2.48 0 0 0-.92-.598c-.282-.11-.705-.24-1.486-.276C10.445.01 10.173 0 8 0zm0 1.442c2.136 0 2.389.008 3.232.046.78.036 1.204.166 1.486.276.373.145.64.319.92.599.28.28.453.546.598.92.11.282.24.705.276 1.486.038.843.046 1.096.046 3.232s-.008 2.389-.046 3.232c-.036.78-.166 1.204-.276 1.486a2.5 2.5 0 0 1-.598.92c-.28.28-.546.453-.92.598-.282.11-.705.24-1.486.276-.843.038-1.096.046-3.232.046s-2.389-.008-3.232-.046c-.78-.036-1.204-.166-1.486-.276a2.5 2.5 0 0 1-.92-.598 2.5 2.5 0 0 1-.598-.92c-.11-.282-.24-.705-.276-1.486-.038-.843-.046-1.096-.046-3.232s.008-2.389.046-3.232c.036-.78.166-1.204.276-1.486.145-.373.319-.64.599-.92.28-.28.546-.453.92-.598.282-.11.705-.24 1.486-.276C5.611 1.45 5.864 1.442 8 1.442zm0 2.452a4.108 4.108 0 1 0 0 8.215 4.108 4.108 0 0 0 0-8.215zm0 6.775a2.667 2.667 0 1 1 0-5.334 2.667 2.667 0 0 1 0 5.334zm5.23-6.937a.96.96 0 1 1-1.92 0 .96.96 0 0 1 1.92 0z"] },
  twitter: { color: "#000000", label: "X", paths: ["M12.6.75h2.454l-5.36 6.142L16 15.25h-4.937l-3.867-5.07-4.425 5.07H.316l5.733-6.57L0 .75h5.063l3.495 4.633L12.601.75Zm-.86 13.028h1.36L4.323 2.145H2.865z"] },
  x: { color: "#000000", label: "X", paths: ["M12.6.75h2.454l-5.36 6.142L16 15.25h-4.937l-3.867-5.07-4.425 5.07H.316l5.733-6.57L0 .75h5.063l3.495 4.633L12.601.75Zm-.86 13.028h1.36L4.323 2.145H2.865z"] },
  youtube: { color: "#FF0000", label: "YouTube", paths: ["M8.051 1.999h.089c.822.003 4.987.033 6.11.335a2.01 2.01 0 0 1 1.415 1.42c.101.38.172.883.22 1.402l.01.104.022.26.008.104c.065.914.073 1.77.074 1.957v.075c-.001.194-.01 1.108-.082 2.06l-.008.105-.009.104c-.05.572-.124 1.14-.235 1.558a2.01 2.01 0 0 1-1.415 1.42c-1.16.312-5.569.334-6.18.335h-.142c-.309 0-1.587-.006-2.927-.052l-.17-.006-.087-.004-.171-.007-.171-.007c-1.11-.049-2.167-.128-2.654-.26a2.01 2.01 0 0 1-1.415-1.419c-.111-.417-.185-.986-.235-1.558L.09 9.82l-.008-.104A31 31 0 0 1 0 7.68v-.123c.002-.215.01-.958.064-1.778l.007-.103.003-.052.008-.104.022-.26.01-.104c.048-.519.119-1.023.22-1.402a2.01 2.01 0 0 1 1.415-1.42c.487-.13 1.544-.21 2.654-.26l.17-.007.172-.006.086-.004.171-.006A100 100 0 0 1 7.858 2h.193zM6.4 5.209v4.818l4.157-2.408z"] },
  email: { color: "#6b7280", label: "Email", paths: ["M0 4a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2zm2-1a1 1 0 0 0-1 1v.217l7 4.2 7-4.2V4a1 1 0 0 0-1-1zm13 2.383-4.708 2.825L15 11.105zm-.034 6.876-5.64-3.471L8 9.583l-1.326-.795-5.64 3.47A1 1 0 0 0 2 13h12a1 1 0 0 0 .966-.741M1 11.105l4.708-2.897L1 5.383z"] },
  website: { color: "#6b7280", label: "Website", paths: ["M0 8a8 8 0 1 1 16 0A8 8 0 0 1 0 8m7.5-6.923c-.67.204-1.335.82-1.887 1.855A8 8 0 0 0 5.145 4H7.5zM4.09 4a9.3 9.3 0 0 1 .64-1.539 7 7 0 0 1 .597-.933A7.03 7.03 0 0 0 2.255 4zm-.582 3.5c.03-.877.138-1.718.312-2.5H1.674a7 7 0 0 0-.656 2.5zM4.847 5a12.5 12.5 0 0 0-.338 2.5H7.5V5zM8.5 5v2.5h2.99a12.5 12.5 0 0 0-.337-2.5zM4.51 8.5a12.5 12.5 0 0 0 .337 2.5H7.5V8.5zm3.99 0V11h2.653c.187-.765.306-1.608.338-2.5zM5.145 12q.208.58.468 1.068c.552 1.035 1.218 1.65 1.887 1.855V12zm.182 2.472a7 7 0 0 1-.597-.933A9.3 9.3 0 0 1 4.09 12H2.255a7 7 0 0 0 3.072 2.472M3.82 11a13.7 13.7 0 0 1-.312-2.5h-2.49c.062.89.291 1.733.656 2.5zm6.853 3.472A7.03 7.03 0 0 0 13.745 12H11.91a9.3 9.3 0 0 1-.64 1.539 7 7 0 0 1-.597.933M8.5 12v2.923c.67-.204 1.335-.82 1.887-1.855q.26-.487.468-1.068zm3.68-1h2.146c.365-.767.594-1.61.656-2.5h-2.49a13.7 13.7 0 0 1-.312 2.5m2.802-3.5a7 7 0 0 0-.656-2.5H12.18c.174.782.282 1.623.312 2.5zM11.27 2.461c.247.464.462.98.64 1.539h1.835a7 7 0 0 0-3.072-2.472c.218.284.418.598.597.933M10.855 4a8 8 0 0 0-.468-1.068C9.835 1.897 9.17 1.282 8.5 1.077V4z"] },
  scholar: { color: "#4285F4", label: "Google Scholar", paths: ACADEMIC_PATHS },
  orcid: { color: "#A6CE39", label: "ORCID", paths: ACADEMIC_PATHS },
  dblp: { color: "#1565C0", label: "dblp", paths: ACADEMIC_PATHS },
  researchgate: { color: "#00CCBB", label: "ResearchGate", paths: ACADEMIC_PATHS },
};
function SocialIcon({ platform }: { platform: string }) {
  const m = SOCIAL_META[platform] || SOCIAL_META.website;
  return <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="currentColor" viewBox="0 0 16 16" aria-hidden="true">{m.paths.map((d, i) => <path key={i} d={d} />)}</svg>;
}
// Renders the visible social links (editable in admin) as brand-coloured icon buttons.
function SocialLinks({ links, className }: { links: any[]; className?: string }) {
  const visible = (Array.isArray(links) ? links : []).filter((l) => l && l.url && !l.hidden);
  if (!visible.length) return null;
  return (
    <div className={className || "flex justify-center gap-4 mt-6"}>
      {visible.map((l, i) => {
        const m = SOCIAL_META[l.platform] || SOCIAL_META.website;
        const label = l.label || m.label;
        return (
          <a key={i} href={l.url} target="_blank" rel="noopener noreferrer" aria-label={label} title={label}
            className="w-10 h-10 rounded-full flex items-center justify-center text-white hover:opacity-85 transition-opacity" style={{ backgroundColor: m.color }}>
            <SocialIcon platform={l.platform} />
          </a>
        );
      })}
    </div>
  );
}

// ---- Blog ----
type BlogPost = { title: string; slug: string; date?: string; summary?: string; cover?: string; tags?: string; body?: string; draft?: any };
function fmtDate(d?: string) {
  if (!d) return "";
  const dt = new Date(d + (/^\d{4}-\d{2}-\d{2}$/.test(String(d)) ? "T00:00:00" : ""));
  return isNaN(dt.getTime()) ? String(d) : dt.toLocaleDateString("en-US", { year: "numeric", month: "long", day: "numeric" });
}
function isDraft(p: BlogPost) { const d = String(p?.draft ?? "").toLowerCase(); return p?.draft === true || d === "yes" || d === "true" || d === "1"; }
function slugify(s: string) { return String(s || "").toLowerCase().trim().replace(/[^a-z0-9]+/g, "-").replace(/^-+|-+$/g, ""); }
function eslug(p: BlogPost) { return (p.slug && p.slug.trim()) ? p.slug.trim() : slugify(p.title); }

function BlogChrome({ children, theme, setTheme, footer, socialLinks }: { children: React.ReactNode; theme: "dark" | "light"; setTheme: (t: "dark" | "light") => void; footer: any; socialLinks: any[] }) {
  return (
    <div className="app-root min-h-screen bg-[var(--c-bg)] font-sans text-[var(--c-text)] relative overflow-hidden">
      <div className="fixed inset-0 app-page-bg z-0 pointer-events-none"></div>
      <nav className="fixed top-0 left-0 right-0 flex justify-between items-center px-4 sm:px-6 lg:px-8 py-4 app-nav-bg backdrop-blur-sm shadow-md z-40">
        <a href="/" className="text-3xl font-bold tracking-wide text-[var(--c-accent)]">PALASH</a>
        <ul className="flex gap-6 text-md">
          <li><a href="/" className="hover:text-[var(--c-accent2)] transition-colors font-semibold">Home</a></li>
          <li><a href="/blog" className="hover:text-[var(--c-accent2)] transition-colors font-semibold">Blog</a></li>
        </ul>
      </nav>
      <div className="relative z-10 pt-24">
        <main id="main" className="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 min-h-[60vh]">{children}</main>
        <div className="bg-[var(--c-footer)] mt-16">
          <footer className="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-10 text-[var(--c-muted)]">
            <div className="flex flex-col md:flex-row justify-between items-center gap-5">
              <div className="text-center md:text-left">
                {footer.name && <div className="text-[var(--c-text)] text-lg font-semibold">{footer.name}</div>}
                {footer.tagline && <div className="text-sm mt-0.5">{footer.tagline}</div>}
              </div>
              {footer.showSocials !== false && <SocialLinks links={socialLinks} className="flex gap-3 flex-wrap justify-center" />}
            </div>
            {footer.copyright && <div className="pt-6 mt-6 border-t border-[var(--c-border)] text-center text-sm">{footer.copyright}</div>}
          </footer>
        </div>
      </div>
      <FloatingControls theme={theme} setTheme={setTheme} />
    </div>
  );
}
function BlogIndex({ posts, head }: { posts: BlogPost[]; head?: any }) {
  const badge = head?.badge ?? "Blog";
  const title = head?.title ?? "Writing & Notes";
  const subtitle = head?.subtitle ?? "Research notes, paper walkthroughs, and things I'm building.";
  useEffect(() => { if (typeof document !== "undefined") document.title = `${title || "Blog"} — Palash Ranjan Roy`; }, [title]);
  return (
    <>
      <header className="text-center mb-10">
        {badge && <div className="inline-block px-3 py-1 bg-[var(--c-surface)] text-[var(--c-accent)] text-sm font-medium mb-2 rounded">{badge}</div>}
        {title && <h1 className="text-4xl font-bold mb-3">{title}</h1>}
        {subtitle && <p className="text-[var(--c-muted)] text-lg">{renderRich(subtitle)}</p>}
      </header>
      {posts.length === 0 ? (
        <p className="text-center text-[var(--c-muted)]">No posts yet — check back soon.</p>
      ) : (
        <div className="space-y-6">
          {posts.map((p) => (
            <a key={eslug(p)} href={`/blog/${eslug(p)}`} className="block bg-[var(--c-surface)] border border-[var(--c-border)] rounded-xl p-6 hover:border-[var(--c-accent)] transition-colors">
              {p.cover && <img src={p.cover} alt="" className="w-full h-48 object-cover rounded-lg mb-4" loading="lazy" />}
              <div className="text-xs text-[var(--c-accent)] mb-1">{fmtDate(p.date)}</div>
              <h2 className="text-xl font-bold mb-1">{p.title}</h2>
              {p.summary && <p className="text-[var(--c-muted)] text-sm">{p.summary}</p>}
              <span className="inline-block mt-3 text-[var(--c-accent)] text-sm">Read more →</span>
            </a>
          ))}
        </div>
      )}
    </>
  );
}
function BlogPostView({ post }: { post: BlogPost }) {
  useEffect(() => { if (typeof document !== "undefined" && post?.title) document.title = `${post.title} — Palash Ranjan Roy`; }, [post]);
  const tags = String(post.tags || "").split(",").map((t) => t.trim()).filter(Boolean);
  return (
    <article>
      <a href="/blog" className="text-[var(--c-accent)] text-sm hover:underline">← All posts</a>
      <header className="mt-4 mb-8">
        <div className="text-sm text-[var(--c-accent)] mb-2">{fmtDate(post.date)}</div>
        <h1 className="text-4xl font-bold leading-tight mb-3">{post.title}</h1>
        {post.summary && <p className="text-[var(--c-muted)] text-lg">{post.summary}</p>}
        {tags.length > 0 && <div className="flex flex-wrap gap-2 mt-4">{tags.map((t) => <span key={t} className="text-xs px-2 py-1 rounded-full bg-[var(--c-chip)] text-[var(--c-accent)]">{t}</span>)}</div>}
      </header>
      {post.cover && <img src={post.cover} alt="" className="w-full rounded-xl border border-[var(--c-border)] mb-8" />}
      {renderMarkdown(post.body || "")}
    </article>
  );
}

export default function App() {
  const [mobileMenuOpen, setMobileMenuOpen] = useState(false);
  // Light / dark theme — defaults to dark, remembers the visitor's choice.
  const [theme, setTheme] = useState<"dark" | "light">(() => {
    if (typeof window !== "undefined") {
      const saved = window.localStorage.getItem("theme");
      if (saved === "light" || saved === "dark") return saved;
    }
    return "dark";
  });
  useEffect(() => {
    document.documentElement.setAttribute("data-theme", theme);
    try { window.localStorage.setItem("theme", theme); } catch { /* ignore */ }
  }, [theme]);

  // Live content from the cPanel API (falls back to the prerendered/baked content).
  const [remote, setRemote] = useState<Record<string, any>>({});
  useEffect(() => {
    // The page is already correct from the prerendered/baked content, so apply the
    // live update during idle time — keeps the big re-render out of the interactivity
    // (TBT) window.
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
  const publicationsData = (remote.publications ?? defaultPublications) as Publication[];
  const highlightsData = (remote.highlights ?? defaultHighlights) as HighlightItem[];
  const galleryData = (remote.gallery ?? defaultGallery) as GalleryItem[];
  const leadershipRolesData = (remote.leadership ?? defaultLeadership) as LeadershipRole[];
  const academicServiceData = (remote.service ?? defaultService) as ServiceItem[];
  const referencesData = (remote.references ?? defaultReferences) as ReferenceItem[];
  const newsItems = (remote.news ?? newsData.items) as typeof newsData.items;
  const mediaItems = (remote.media ?? mediaData.items) as typeof mediaData.items;
  // In "auto" mode the live row only carries a flag; the real numbers are the
  // OpenAlex values baked at build time (metricsData). Manual mode uses the live row.
  const metrics = ((remote.metrics && (remote.metrics as any).auto !== true && typeof remote.metrics === "object") ? remote.metrics : metricsData) as typeof metricsData;
  const hero = ((remote.hero && typeof remote.hero === "object") ? { ...heroData, ...remote.hero } : heroData) as typeof heroData;
  const about = ((remote.about && typeof remote.about === "object") ? { ...aboutData, ...remote.about } : aboutData) as typeof aboutData;
  const settings = ((remote.settings && typeof remote.settings === "object") ? { ...settingsData, ...remote.settings } : settingsData) as any;
  const headings = ((remote.headings && typeof remote.headings === "object") ? { ...headingsData, ...remote.headings } : headingsData) as any;
  const research = ((remote.research && typeof remote.research === "object") ? { ...researchData, ...remote.research } : researchData) as any;
  const socialLinks = ((remote.socials && Array.isArray((remote.socials as any).links)) ? (remote.socials as any).links : socialsData.links) as any[];
  const footer = ((remote.footer && typeof remote.footer === "object") ? { ...footerData, ...remote.footer } : footerData) as any;
  const consultation = ((remote.consultation && typeof remote.consultation === "object") ? { ...consultationData, ...remote.consultation } : consultationData) as any;
  // Portfolio visuals — shown by default; hide either from admin → Slider & Resume.
  const showYearChart = settings.showYearChart !== false;
  const showResearchGraph = settings.showResearchGraph !== false;
  // Slider speed: per-section override, else the global default.
  const secMs = (id: string) => (Number((settings.sliderPerSection || {})[id]) || Number(settings.sliderSeconds) || 11) * 1000;

  // Prevent scrolling when mobile menu is open
  useEffect(() => {
    if (mobileMenuOpen) {
      document.body.style.overflow = 'hidden';
    } else {
      document.body.style.overflow = 'auto';
    }
    return () => {
      document.body.style.overflow = 'auto';
    };
  }, [mobileMenuOpen]);

  // Add scroll padding to top to prevent content from going under nav bar
  useEffect(() => {
    // Apply scroll-padding to the document to account for the fixed header
    document.documentElement.style.scrollPaddingTop = '80px';
    
    // Smooth scrolling for better UX
    document.documentElement.style.scrollBehavior = 'smooth';
    
    return () => {
      document.documentElement.style.scrollPaddingTop = '';
      document.documentElement.style.scrollBehavior = '';
    };
  }, []);

  // Published blog posts, newest first (drafts hidden). All hooks above have run,
  // so it is safe to branch to the blog routes from here.
  const blogPosts = ((remote.blog ?? blogData.items) as BlogPost[])
    .filter((p) => p && (p.slug || p.title) && !isDraft(p))
    .slice()
    .sort((a, b) => String(b.date || "").localeCompare(String(a.date || "")));
  const pathname = typeof window !== "undefined" ? window.location.pathname : "/";
  const blogMatch = pathname.match(/^\/blog(?:\/([^/?#]+))?\/?$/i);
  if (blogMatch) {
    const chrome = (node: React.ReactNode) => (
      <BlogChrome theme={theme} setTheme={setTheme} footer={footer} socialLinks={socialLinks}>{node}</BlogChrome>
    );
    const slug = blogMatch[1] ? decodeURIComponent(blogMatch[1]) : null;
    if (!slug) return chrome(<BlogIndex posts={blogPosts} head={headings.blog} />);
    // Direct URL finds any post (incl. drafts) so you can preview before publishing;
    // drafts just stay out of the index, nav, and prerender/sitemap.
    const allPosts = ((remote.blog ?? blogData.items) as BlogPost[]).filter((p) => p && (p.slug || p.title));
    const post = allPosts.find((p) => eslug(p) === slug);
    if (post) return chrome(<BlogPostView post={post} />);
    return chrome(
      <div className="text-center py-20">
        <h1 className="text-3xl font-bold mb-3">Post not found</h1>
        <a href="/blog" className="text-[var(--c-accent)] hover:underline">← Back to the blog</a>
      </div>
    );
  }

  // Reorderable page sections — order is editable from /admin (sectionOrder)
  const sectionMap: Record<string, React.ReactNode> = {
    about: (<>
          <section id="about" className="pt-16 pb-16">
            <h2 className="text-3xl font-bold mb-8 border-b border-[var(--c-border)] pb-3">{headings.about?.title || "Biography"}</h2>
            
            <div className="flex flex-col md:flex-row gap-10">
              {/* Left column with profile image and social links */}
              <div className="md:w-1/3 flex flex-col items-center">
                <div className="rounded-full overflow-hidden border-4 border-[var(--c-border)] bg-[var(--c-surface2)] w-64 h-64">
                  <img
                    src={(about as any).photo || "/images/palash_roy.jpg"}
                    alt="Palash Roy - AI Researcher and Computer Science PhD Student at University of Saskatchewan"
                    width={512}
                    height={448}
                    className="w-full h-full object-cover"
                    loading="lazy"
                    decoding="async"
                  />
                </div>
                
                {/* Social links — editable in admin → Social Links */}
                <SocialLinks links={socialLinks} />

                {/* At-a-glance highlights card - surfaces the most important facts */}
                <div className="mt-8 w-full bg-[var(--c-surface)] border border-[var(--c-border)] rounded-xl p-5 text-sm">
                  <h3 className="text-[var(--c-accent)] font-semibold mb-3 uppercase tracking-wider text-xs">At a Glance</h3>
                  <ul className="space-y-2.5 text-[var(--c-muted)]">
                    {about.glance.map((g, i) => (
                      <li key={i} className="flex gap-2"><span>{g.icon}</span><span>{renderRich(g.text)}</span></li>
                    ))}
                  </ul>
                </div>
              </div>

              {/* Right column with biography text */}
              <div className="md:w-2/3">
                {/* Name Variants Badge Component - Added for SEO */}
                <div className="flex flex-wrap gap-2 mb-4">
                  <span className="bg-[var(--c-chip)] text-[var(--c-accent)] px-3 py-1 rounded-full text-sm">Palash Ranjan Roy</span>
                  <span className="bg-[var(--c-chip)] text-[var(--c-accent)] px-3 py-1 rounded-full text-sm">Palash Roy</span>
                  <span className="bg-[var(--c-chip)] text-[var(--c-accent)] px-3 py-1 rounded-full text-sm">P. R. Roy</span>
                  <span className="bg-[var(--c-chip)] text-[var(--c-accent)] px-3 py-1 rounded-full text-sm">Palash R. Roy</span>
                </div>
                
                <div className="text-[var(--c-muted)] space-y-4 leading-relaxed">
                  {about.paragraphs.map((para, i) => (
                    <p key={i}>{renderRich(para)}</p>
                  ))}
                </div>
                
                {/* Education (editable in admin) */}
                <div className="mt-10">
                  <h3 className="text-xl font-semibold mb-4 text-[var(--c-accent)]">Education</h3>
                  <div className="space-y-6">
                    {((about as any).education || []).map((e: any, i: number) => (
                      <div key={i}>
                        <h4 className="font-bold">{e.degree}</h4>
                        <div className="text-[var(--c-muted)] text-sm">{e.place}</div>
                        {e.note && <p className="text-[var(--c-muted)] text-sm mt-1 italic">{e.note}</p>}
                      </div>
                    ))}
                  </div>
                </div>

                {/* Experience (editable in admin) */}
                <div className="mt-10">
                  <h3 className="text-xl font-semibold mb-4 text-[var(--c-accent)]">Experience</h3>
                  {(about as any).expNote && <p className="text-[var(--c-muted)] text-xs italic mb-4">{renderRich((about as any).expNote)}</p>}
                  <div className="space-y-6">
                    {((about as any).experience || []).map((x: any, i: number) => (
                      <div key={i}>
                        <h4 className="font-bold">{x.role}</h4>
                        <div className="text-[var(--c-muted)] text-sm">{x.place}</div>
                        {x.description && <p className="text-[var(--c-muted)] text-sm mt-1">{renderRich(x.description)}</p>}
                      </div>
                    ))}
                  </div>
                </div>
                
                {/* Academic Links at the bottom */}
                <div className="mt-8">
                  <h3 className="text-lg font-semibold mb-2 text-[var(--c-accent)]">Academic Profiles</h3>
                  <div className="flex flex-wrap gap-3">
                    <a href="https://orcid.org/0000-0001-9470-4233" target="_blank" rel="noopener noreferrer" className="inline-flex items-center px-3 py-1 text-xs rounded bg-[var(--c-surface2)] hover:bg-[var(--c-surface2)] transition-colors">
                      <svg className="w-4 h-4 mr-1" fill="currentColor" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg">
                        <path fillRule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM6.5 5.5a1 1 0 11-2 0 1 1 0 012 0zM5.5 8h2v6.5h-2V8zm3.5 0h1.9v.9h.03c.27-.5.92-1.05 1.9-1.05 2.03 0 2.4 1.27 2.4 2.95v3.7h-2v-3.28c0-.78-.01-1.79-1.1-1.79-1.1 0-1.27.86-1.27 1.74v3.33H9V8z" clipRule="evenodd"></path>
                      </svg>
                      ORCID
                    </a>
                    <a href="https://dblp.org/pid/355/4465.html" target="_blank" rel="noopener noreferrer" className="inline-flex items-center px-3 py-1 text-xs rounded bg-[var(--c-surface2)] hover:bg-[var(--c-surface2)] transition-colors">
                      <svg className="w-4 h-4 mr-1" fill="currentColor" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg">
                        <path d="M5 4a2 2 0 00-2 2v8a2 2 0 002 2h10a2 2 0 002-2V6a2 2 0 00-2-2H5zm2 3h6v1.5H7V7zm0 3h6v1.5H7V10zm0 3h4v1.5H7V13z"></path>
                      </svg>
                      dblp
                    </a>
                    <a href="https://scholar.google.com/citations?user=Vy_sw5UAAAAJ" target="_blank" rel="noopener noreferrer" className="inline-flex items-center px-3 py-1 text-xs rounded bg-[var(--c-surface2)] hover:bg-[var(--c-surface2)] transition-colors">
                      <svg className="w-4 h-4 mr-1" fill="currentColor" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg">
                        <path d="M10 12a2 2 0 100-4 2 2 0 000 4z"></path>
                        <path fillRule="evenodd" d="M.458 10C1.732 5.943 5.522 3 10 3s8.268 2.943 9.542 7c-1.274 4.057-5.064 7-9.542 7S1.732 14.057.458 10zM14 10a4 4 0 11-8 0 4 4 0 018 0z" clipRule="evenodd"></path>
                      </svg>
                      Google Scholar
                    </a>
                    <a href="https://srlab.usask.ca/members/" target="_blank" rel="noopener noreferrer" className="inline-flex items-center px-3 py-1 text-xs rounded bg-[var(--c-surface2)] hover:bg-[var(--c-surface2)] transition-colors">
                      <svg className="w-4 h-4 mr-1" fill="currentColor" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg">
                        <path d="M11 3a1 1 0 100 2h2.586l-6.293 6.293a1 1 0 101.414 1.414L15 6.414V9a1 1 0 102 0V4a1 1 0 00-1-1h-5z"></path>
                        <path d="M5 5a2 2 0 00-2 2v10a2 2 0 002 2h12a2 2 0 002-2V6a2 2 0 00-2-2h-3.5l-1.5-1.5h-5L4 4zm7 5a1 1 0 100-2H9v2h2zm3 0a1 1 0 100-2h-2v2h2zm-9 3a1 1 0 100-2H5v2h2zm3 0a1 1 0 100-2H8v2h2zm3 0a1 1 0 100-2h-2v2h2zm3 0a1 1 0 100-2h-2v2h2z"></path>
                      </svg>
                      SRLab Profile
                    </a>
                    <a href="https://ise.usask.ca/team/" target="_blank" rel="noopener noreferrer" className="inline-flex items-center px-3 py-1 text-xs rounded bg-[var(--c-surface2)] hover:bg-[var(--c-surface2)] transition-colors">
                      <svg className="w-4 h-4 mr-1" fill="currentColor" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg">
                        <path d="M4 4a2 2 0 012-2h8a2 2 0 012 2v16a1 1 0 01-1.581.814l-4.419-3.346-4.419 3.346A1 1 0 014 16V4zm5 0a1 1 0 00-1 1v6.5a.5.5 0 001 0V5a1 1 0 00-1-1z"></path>
                      </svg>
                      ISE Lab Profile
                    </a>
                  </div>
                </div>
              </div>
            </div>
          </section>
    </>),
    portfolio: (<>
          {/* Publications Section with Carousel and centered content */}
          <section id="portfolio" className="pt-16 pb-16">
            <div className="text-center mb-8">
              <SectionHead h={headings.portfolio} />
              {/* Live research-impact metrics (refreshed from OpenAlex at build time) */}
              <div className="flex flex-wrap justify-center gap-8 mt-6">
                <div className="text-center">
                  <div className="text-3xl font-bold text-[var(--c-accent)]">{metrics.citations}+</div>
                  <div className="text-xs uppercase tracking-wider">Citations</div>
                </div>
                <div className="text-center">
                  <div className="text-3xl font-bold text-[var(--c-accent)]">{metrics.hIndex}</div>
                  <div className="text-xs uppercase tracking-wider">h-index</div>
                </div>
                <div className="text-center">
                  <div className="text-3xl font-bold text-[var(--c-accent)]">{metrics.works}</div>
                  <div className="text-xs uppercase tracking-wider">Indexed Works</div>
                </div>
              </div>
              <div className="text-xs text-[var(--c-muted)] mt-2">
                via <a href={metrics.profileUrl} target="_blank" rel="noopener noreferrer" className="text-[var(--c-accent)] hover:underline">{metrics.source}</a> &middot; updated {metrics.updated}
              </div>
            </div>
            {/* Two visuals: output over time + what the research is about. Each can be
                hidden from admin → Slider & Resume. */}
            {(showYearChart || showResearchGraph) && (
              <div className={`${showYearChart && showResearchGraph ? "grid grid-cols-1 lg:grid-cols-2 gap-8 lg:gap-6 items-center" : "flex justify-center"} max-w-5xl mx-auto mb-12`}>
                {showYearChart && (
                  <div>
                    <div className="text-xs uppercase tracking-wider text-[var(--c-muted)] mb-4 text-center">Publications per year</div>
                    <PubYearChart items={publicationsData} />
                  </div>
                )}
                {showResearchGraph && (
                  <div>
                    <div className="text-xs uppercase tracking-wider text-[var(--c-muted)] mb-1 text-center">What I work on</div>
                    <ResearchGraph data={research} />
                  </div>
                )}
              </div>
            )}
            <Carousel
              rotationInterval={secMs('portfolio')}
              items={publicationsData}
              renderItem={(item) => (<PublicationCard item={item} />)}
            />
          </section>
    </>),
    news: (<>
          {/* News & Milestones timeline - content from src/content/news.json */}
          <section id="news" className="pt-16 pb-16">
            <div className="text-center mb-8">
              <SectionHead h={headings.news} />
            </div>
            <div className="max-w-3xl mx-auto">
              <ol className="relative border-l border-[var(--c-border)] ml-3">
                {newsItems.map((n, i) => (
                  <li key={i} className="mb-8 ml-6">
                    <span className="absolute -left-3 flex items-center justify-center w-6 h-6 bg-[var(--c-surface)] border border-[var(--c-border)] rounded-full text-sm">{n.icon}</span>
                    <div className="text-xs text-[var(--c-accent)] font-semibold mb-1">{n.date}</div>
                    <h3 className="font-bold">{(n as any).url ? (
                      <a href={(n as any).url} target="_blank" rel="noopener noreferrer" className="hover:text-[var(--c-accent)] transition-colors">{n.title}</a>
                    ) : n.title}</h3>
                    <p className="text-[var(--c-muted)] text-sm mt-1">{renderRich(n.description)}</p>
                  </li>
                ))}
              </ol>
            </div>
          </section>
    </>),
    leadership: (<>
          {/* Leadership Roles Section with Carousel and centered content - updated to match other sections */}
          <section id="leadership" className="pt-16 pb-16">
            <div className="text-center mb-8">
              <SectionHead h={headings.leadership} />
            </div>
            <Carousel
              rotationInterval={secMs('leadership')}
              items={leadershipRolesData}
              renderItem={(item) => (
                <div className="bg-[var(--c-surface)] h-full p-6 rounded-lg">
                  <div className="font-bold mb-1">{item.role}</div>
                  <div className="text-[var(--c-accent)] text-sm mb-1">
                    {item.link ? (
                      <a 
                        href={item.link} 
                        target="_blank" 
                        rel="noopener noreferrer"
                        className="hover:text-[var(--c-accent2)] transition-colors hover:underline"
                      >
                        {item.organization}
                      </a>
                    ) : (
                      item.organization
                    )}
                  </div>
                  <div className="text-[var(--c-muted)] text-xs mb-1 italic">{item.place}</div>
                  <div className="text-[var(--c-muted)] text-sm">{item.period}</div>
                </div>
              )}
            />
          </section>
    </>),
    service: (<>
          {/* Academic Service Section with Carousel and centered content */}
          <section id="service" className="pt-16 pb-16">
            <div className="text-center mb-8">
              <SectionHead h={headings.service} />
            </div>
            <Carousel
              rotationInterval={secMs('service')}
              items={academicServiceData}
              renderItem={(item) => (
                <div className="bg-[var(--c-surface)] h-full p-6 rounded-lg">
                  <div className="font-bold mb-1">{item.role}</div>
                  <div className="text-[var(--c-accent)] text-sm mb-1">{item.venues || item.venue}</div>
                  <div className="text-[var(--c-muted)] text-sm mb-1">{item.period}</div>
                  <div className="text-[var(--c-muted)] text-sm">{item.description}</div>
                </div>
              )}
            />
          </section>
    </>),
    highlights: (<>
          {/* Highlights Section with Carousel and centered content - Redesigned to match reference */}
          <section id="highlights" className="pt-16 pb-16">
            {/* New header format matching the reference image */}
            <div className="text-center mb-8">
              <SectionHead h={headings.highlights} />
            </div>
            
            <Carousel
              rotationInterval={secMs('highlights')}
              items={highlightsData}
              renderItem={(item) => (
                <div className="bg-[var(--c-surface)] h-full p-6 rounded-lg flex flex-col">
                  {item.image && (
                    <div className="mb-4">
                      <img
                        src={item.image}
                        alt={item.title}
                        className="w-full h-48 object-cover rounded"
                        loading="lazy"
                        decoding="async"
                      />
                    </div>
                  )}
                  <h3 className="font-bold text-lg mb-1">{item.title}</h3>
                  <div className="text-[var(--c-accent)] text-sm mb-2">{item.organization}</div>
                  <p className="text-[var(--c-muted)] text-sm flex-grow">{item.description}</p>
                  {item.link && (
                    <div className="mt-4 pt-2 border-t border-[var(--c-border)]">
                      <a
                        href={item.link}
                        target="_blank"
                        rel="noopener noreferrer"
                        aria-label={`Read more about ${item.title}`}
                        className="text-[var(--c-accent)] text-sm hover:text-[var(--c-accent2)] flex items-center"
                      >
                        Read more
                        <svg xmlns="http://www.w3.org/2000/svg" className="h-4 w-4 ml-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M14 5l7 7m0 0l-7 7m7-7H3" />
                        </svg>
                      </a>
                    </div>
                  )}
                </div>
              )}
            />
          </section>
    </>),
    media: (<>
          {/* In the News / media coverage - content from src/content/media.json */}
          <section id="media" className="pt-16 pb-16">
            <div className="text-center mb-8">
              <SectionHead h={headings.media} />
            </div>
            <div className="grid grid-cols-1 md:grid-cols-3 gap-4 max-w-5xl mx-auto">
              {mediaItems.map((m, i) => (
                <a key={i} href={m.url} target="_blank" rel="noopener noreferrer" className="bg-[var(--c-surface)] border border-[var(--c-border)] rounded-lg p-5 hover:border-[var(--c-accent)] transition-colors flex flex-col">
                  <div className="text-xs text-[var(--c-accent)] mb-2">{m.outlet} &middot; {m.date}</div>
                  <div className="font-semibold text-sm mb-3 flex-1">{m.title}</div>
                  <span className="text-[var(--c-accent)] text-xs">Read article &rarr;</span>
                </a>
              ))}
            </div>
          </section>
    </>),
    pictures: (<>
          {/* Combined Gallery Section - GSA presidency highlights and personal moments */}
          <section id="pictures" className="pt-16 pb-16">
            <div className="text-center mb-8">
              <SectionHead h={headings.pictures} />
            </div>
            <Carousel
              rotationInterval={secMs('pictures')}
              items={galleryData}
              renderItem={(item) => (
                <div className="bg-[var(--c-surface)] h-full p-4 rounded-lg flex flex-col items-center text-center">
                  <div className="w-full h-48 sm:h-52 overflow-hidden rounded relative">
                    {/* Loading placeholder */}
                    <div className="absolute inset-0 bg-[var(--c-surface2)] animate-pulse"></div>

                    <img
                      src={item.image}
                      alt={item.altText || `Palash Ranjan Roy (Palash Roy) at ${item.title}`}
                      title={`Palash Roy - ${item.description}`}
                      className="w-full h-full object-cover transition-transform hover:scale-105 duration-300 relative z-10"
                      loading="lazy"
                      decoding="async"
                      fetchPriority="low"
                      onLoad={(e) => {
                        e.currentTarget.style.opacity = '1';
                        if (e.currentTarget.previousSibling) {
                          (e.currentTarget.previousSibling as HTMLElement).style.display = 'none';
                        }
                      }}
                      style={{ opacity: 0, transition: 'opacity 0.3s ease-in' }}
                      onError={(e) => {
                        e.currentTarget.style.display = 'none';
                        console.error(`Failed to load image for ${item.title}`);
                      }}
                    />
                  </div>
                  <div className="font-bold mt-3 mb-1">{item.title}</div>
                  <div className="text-xs text-[var(--c-muted)]">"{item.description}"</div>
                </div>
              )}
            />
          </section>
    </>),
    references: (<>
          {/* References Section - updated with new style matching the image */}
          <section id="references" className="pt-16 pb-16">
            <div className="text-center mb-8">
              <SectionHead h={headings.references} />
              {headings.references?.note && (
                <p className="mt-6 text-[var(--c-muted)] italic max-w-3xl mx-auto">{renderRich(headings.references.note)}</p>
              )}
              {headings.references?.mentors && (
                <div className="mt-8 text-[var(--c-muted)] max-w-3xl mx-auto">
                  <p className="font-medium">{renderRich(headings.references.mentors)}</p>
                </div>
              )}
            </div>

            {/* Reference testimonials in cards - kept from original design */}
            <Carousel
              rotationInterval={secMs('references')}
              items={referencesData}
              renderItem={(item) => (
                <div className="bg-[var(--c-surface)] border border-[var(--c-border)] h-full p-4 sm:p-6 rounded-lg text-[var(--c-muted)] flex flex-col items-start relative overflow-hidden">
                  {/* Quote marks in background */}
                  <div className="absolute right-2 top-2 text-[100px] leading-none text-[var(--c-surface2)] font-serif opacity-70">
                    "
                  </div>
                  
                  <div className="flex items-center mb-4 relative z-10 w-full">
                    {/* Profile image - fixed size for all screen sizes */}
                    <div className="w-14 h-14 sm:w-16 sm:h-16 rounded-full overflow-hidden border-2 border-[var(--c-accent)] mr-3 sm:mr-4 flex-shrink-0">
                      <img
                        src={item.image.replace('.png', '.jpg')}
                        alt={item.name}
                        className="w-full h-full object-cover"
                        loading="lazy"
                        onError={(e) => {
                          e.currentTarget.src = "https://via.placeholder.com/150?text=Profile";
                        }}
                      />
                    </div>
                    
                    {/* Name and title - with proper wrapping */}
                    <div className="min-w-0 flex-1">
                      <div className="font-bold text-base sm:text-lg text-[var(--c-text)] truncate">{item.name}</div>
                      <div className="text-xs sm:text-sm text-[var(--c-accent)] truncate">{item.title}</div>
                    </div>
                  </div>
                  
                  {/* Testimonial text */}
                  <p className="text-xs sm:text-sm leading-relaxed italic relative z-10">{item.text}</p>
                </div>
              )}
              itemsPerSlide={2}
            />
          </section>
    </>),
    consultation: (<>
          <ConsultationSection data={consultation} head={headings.consultation} />
    </>),
    contact: (<>
          <section id="contact" className="pt-16 pb-16">
            <div className="text-center mb-8">
              <SectionHead h={headings.contact} />
            </div>
            <ContactForm />
          </section>
    </>),
  };
  // User-defined sections (added from admin → Custom Sections)
  const customSections = (Array.isArray((remote as any).customSections) ? (remote as any).customSections : []).filter((c: any) => c && c.id);
  customSections.forEach((cs: any) => { sectionMap[cs.id] = <CustomSection data={cs} />; });

  const defaultSectionOrder = ['about','portfolio','news','leadership','service','highlights','media','pictures','references','consultation','contact'];
  const roRaw = Array.isArray((remote as any).sectionOrder) ? ((remote as any).sectionOrder as string[]) : defaultSectionOrder;
  const fullOrder = roRaw.filter((id) => sectionMap[id]);
  defaultSectionOrder.forEach((id) => { if (!fullOrder.includes(id)) fullOrder.push(id); });
  // Show custom sections even if they aren't in the saved order yet (append at end)
  customSections.forEach((cs: any) => { if (!fullOrder.includes(cs.id)) fullOrder.push(cs.id); });
  // Sections the user has hidden from the live site (editable in admin → Layout)
  const hiddenSet = new Set(Array.isArray((remote as any).sectionsHidden) ? ((remote as any).sectionsHidden as string[]) : []);
  const sectionOrder = fullOrder.filter((id) => !hiddenSet.has(id));

  const navLabelOf = (id: string) => {
    if (headings[id] && headings[id].nav) return headings[id].nav;
    const cs = customSections.find((c: any) => c.id === id);
    if (cs) return cs.nav || cs.title || "Section";
    return id;
  };
  // Nav bar follows the same order as the visible sections (editable via layout + headings)
  const navLinks = [
    { label: (hero as any).navHome || "Home", href: "#home" },
    ...sectionOrder.map((id) => ({ label: navLabelOf(id), href: `#${id}` })),
    ...(blogPosts.length ? [{ label: (headings.blog && headings.blog.nav) || "Blog", href: "/blog" }] : []),
  ];

  return (
    <div className="app-root min-h-screen bg-[var(--c-bg)] font-sans text-[var(--c-text)] relative overflow-hidden">
      {/* Background gradient overlay - theme-aware (dark by default, light when toggled) */}
      <div className="fixed inset-0 app-page-bg z-0 pointer-events-none"></div>
      <div className="fixed inset-0 bg-[url('data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHdpZHRoPSI1IiBoZWlnaHQ9IjUiPgo8cmVjdCB3aWR0aD0iNSIgaGVpZ2h0PSI1IiBmaWxsPSIjMDAwMDAwMDYiPjwvcmVjdD4KPHBhdGggZD0iTTAgNUw1IDBaTTYgNEw0IDZaTS0xIDFMMSAtMVoiIHN0cm9rZT0iIzMzMDAzMzA5IiBzdHJva2Utd2lkdGg9IjAuNSI+PC9wYXRoPgo8L3N2Zz4=')] opacity-30 z-0 pointer-events-none"></div>
      
      {/* Mobile Menu */}
      <MobileMenu 
        links={navLinks}
        isOpen={mobileMenuOpen}
        setIsOpen={setMobileMenuOpen}
      />
      
      {/* Navigation - Updated to be fixed at the top with improved background blending */}
      <nav className="fixed top-0 left-0 right-0 flex justify-between items-center px-4 sm:px-6 lg:px-8 py-4 app-nav-bg backdrop-blur-sm shadow-md z-40">
        <div className="text-3xl font-bold tracking-wide text-[var(--c-accent)]">PALASH</div>

        <div className="flex items-center gap-4">
        {/* Mobile hamburger menu button */}
        <button
          className="lg:hidden text-[var(--c-text)]"
          onClick={() => setMobileMenuOpen(true)}
          aria-label="Open menu"
        >
          <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="currentColor" viewBox="0 0 16 16">
            <path fillRule="evenodd" d="M2.5 12a.5.5 0 0 1 .5-.5h10a.5.5 0 0 1 0 1H3a.5.5 0 0 1-.5-.5zm0-4a.5.5 0 0 1 .5-.5h10a.5.5 0 0 1 0 1H3a.5.5 0 0 1-.5-.5zm0-4a.5.5 0 0 1 .5-.5h10a.5.5 0 0 1 0 1H3a.5.5 0 0 1-.5-.5z"/>
          </svg>
        </button>
        
        {/* Desktop navigation */}
        <ul className="hidden lg:flex gap-6 text-md overflow-x-auto pb-1 hide-scrollbar">
          {navLinks.map((link) => (
            <li key={link.href} className="whitespace-nowrap">
              <a
                href={link.href}
                className="hover:text-[var(--c-accent2)] transition-colors duration-150 font-semibold text-base"
              >
                {link.label}
              </a>
            </li>
          ))}
        </ul>
        </div>
      </nav>
      
      {/* Add padding to the top of the header to account for the fixed navbar */}
      <header id="home" className="min-h-screen flex items-center relative pt-16">
        <div className="absolute inset-0 pointer-events-none z-0">
          {/* Additional decorative elements */}
          <div className="absolute right-0 top-1/3 w-1/2 h-1/2 bg-gradient-to-l from-[#5a0029] opacity-10 blur-3xl rounded-full animate-pulse" style={{ animationDuration: '8s' }}></div>
          <div className="absolute left-20 bottom-20 w-96 h-96 bg-[#120338] opacity-10 blur-3xl rounded-full animate-pulse" style={{ animationDuration: '6s' }}></div>
        </div>
        
        <div className="container mx-auto px-4 sm:px-6 lg:px-8 py-20 flex flex-col md:flex-row justify-between items-center relative z-1">
          <div className="flex-1 max-w-2xl">
            <h2 className="text-xl font-light uppercase tracking-wider text-[var(--c-muted)] mb-2">{hero.greeting}</h2>
            <h1 className="text-6xl md:text-7xl font-bold mb-5">
              {hero.name}<br />
              <span className="bg-gradient-to-r from-[#35c7ff] to-[#ff4081] bg-clip-text text-transparent">{hero.line2}</span><br />
              <span className="text-[#ff4081]">{hero.line3}</span>
            </h1>

            {/* Role pills (editable in admin) */}
            <div className="flex flex-wrap gap-2 mb-6">
              {hero.pills.map((p, i) => (
                <span key={i} className="bg-[var(--c-surface)] border border-[var(--c-border)] text-[var(--c-muted)] px-3 py-1 rounded-full text-sm">{p}</span>
              ))}
            </div>

            {hero.awardText && (
              <a href="#portfolio" className="inline-flex items-center gap-2 mb-6 px-4 py-2 rounded-lg bg-gradient-to-r from-[#3a2c05] to-[#5c4708] border border-[#ffd700]/40 text-sm hover:border-[#ffd700] transition-colors">
                <span>🏆</span>
                <span className="text-[#ffd700] font-semibold">{hero.awardText}</span>
                {hero.awardMeta && <span className="text-[var(--c-muted)]">&middot; {hero.awardMeta}</span>}
              </a>
            )}

            <p className="text-lg text-[var(--c-muted)] mb-8 max-w-xl">{renderRich(hero.description)}</p>

            <div className="grid grid-cols-3 gap-3 mb-8 max-w-md">
              {hero.stats.map((s, i) => (
                <div key={i} className="bg-[var(--c-surface)]/70 border border-[var(--c-border)] rounded-xl px-3 py-3 text-center">
                  <div className="text-2xl md:text-3xl font-bold bg-gradient-to-r from-[#35c7ff] to-[#ff4081] bg-clip-text text-transparent">{s.value}</div>
                  <div className="text-[10px] uppercase tracking-wider text-[var(--c-muted)] mt-1">{s.label}</div>
                </div>
              ))}
            </div>

            <div className="flex flex-wrap gap-4">
              <a href="#portfolio" className="inline-flex items-center gap-2 px-5 py-3 rounded-lg bg-gradient-to-r from-[#35c7ff] to-[#ff4081] text-white text-sm font-semibold hover:opacity-90 transition-opacity">
                View Publications
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path fillRule="evenodd" d="M1 8a.5.5 0 0 1 .5-.5h11.793l-3.147-3.146a.5.5 0 0 1 .708-.708l4 4a.5.5 0 0 1 0 .708l-4 4a.5.5 0 0 1-.708-.708L13.293 8.5H1.5A.5.5 0 0 1 1 8z"/></svg>
              </a>
              <a href="https://scholar.google.com/citations?user=Vy_sw5UAAAAJ&hl=en" target="_blank" rel="noopener noreferrer" className="inline-flex items-center gap-2 px-5 py-3 rounded-lg border border-[var(--c-border)] text-[var(--c-muted)] text-sm font-semibold hover:border-[var(--c-accent)] hover:text-[var(--c-text)] transition-colors">
                Google Scholar
              </a>
              {settings.resumeUrl && (
                <a href={settings.resumeUrl} target="_blank" rel="noopener noreferrer" download className="inline-flex items-center gap-2 px-5 py-3 rounded-lg border border-[var(--c-border)] text-[var(--c-muted)] text-sm font-semibold hover:border-[var(--c-accent)] hover:text-[var(--c-text)] transition-colors">
                  <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path d="M.5 9.9a.5.5 0 0 1 .5.5v2.5a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-2.5a.5.5 0 0 1 1 0v2.5a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2v-2.5a.5.5 0 0 1 .5-.5z"/><path d="M7.646 11.854a.5.5 0 0 0 .708 0l3-3a.5.5 0 0 0-.708-.708L8.5 10.293V1.5a.5.5 0 0 0-1 0v8.793L5.354 8.146a.5.5 0 1 0-.708.708l3 3z"/></svg>
                  Download Resume
                </a>
              )}
            </div>
          </div>
          
          <div className="relative mt-12 md:mt-0 md:ml-14 shrink-0">
            {/* Soft animated glow ring behind the portrait */}
            <div className="absolute -inset-3 bg-gradient-to-tr from-[#35c7ff] to-[#ff4081] opacity-20 blur-2xl rounded-[2rem] animate-pulse" style={{ animationDuration: '5s' }}></div>
            <div className="relative w-64 h-64 md:w-80 md:h-80 rounded-2xl overflow-hidden shadow-[0_0_60px_rgba(122,205,235,0.18)] border-4 border-[var(--c-border)] bg-[var(--c-surface2)]">
              {/* Profile photo. Custom upload (admin) wins; otherwise the optimized WebP+JPG default (LCP). */}
              {(hero as any).photo ? (
                <img
                  src={(hero as any).photo}
                  alt="Palash Ranjan Roy (Palash Roy) - Computer Science PhD Student at University of Saskatchewan specializing in AI and Software Engineering"
                  className="w-full h-full object-cover"
                  decoding="async"
                  fetchPriority="high"
                />
              ) : (
                <picture>
                  <source srcSet="/images/palash_roy_headshot.webp" type="image/webp" />
                  <img
                    src="/images/palash_roy_headshot.jpg"
                    alt="Palash Ranjan Roy (Palash Roy) - Computer Science PhD Student at University of Saskatchewan specializing in AI and Software Engineering"
                    width={640}
                    height={552}
                    className="w-full h-full object-cover"
                    decoding="async"
                    fetchPriority="high"
                  />
                </picture>
              )}
            </div>
            {/* Status badge highlighting research identity */}
            <div className="absolute -bottom-4 left-1/2 -translate-x-1/2 whitespace-nowrap bg-[var(--c-surface2)] border border-[var(--c-border)] px-4 py-2 rounded-full text-sm shadow-lg flex items-center gap-2">
              <span className="w-2 h-2 rounded-full bg-[#3ddc84] animate-pulse"></span>
              <span className="font-semibold text-[var(--c-text)]">{(hero as any).badge || "Software Engineering Researcher"}</span>
            </div>
          </div>
        </div>
      </header>
      
      {/* Content Container - Removed gradient background to use the global background */}
      <div className="relative z-10">
        {/* Main content landmark */}
        <main id="main" className="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">
{sectionOrder.map((id) => (
            <React.Fragment key={id}>{sectionMap[id]}</React.Fragment>
          ))}
        </main>

        {/* Footer — editable in admin → Footer + Social Links */}
        <div className="bg-[var(--c-footer)]">
          <footer className="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-10 text-[var(--c-muted)] mt-8">
            <div className="flex flex-col md:flex-row justify-between items-center gap-5">
              <div className="text-center md:text-left">
                {footer.name && <div className="text-[var(--c-text)] text-lg font-semibold">{footer.name}</div>}
                {footer.tagline && <div className="text-sm mt-0.5">{footer.tagline}</div>}
                {footer.email && <a href={`mailto:${footer.email}`} className="text-sm hover:text-[var(--c-accent)]">{footer.email}</a>}
              </div>
              {footer.showSocials !== false && <SocialLinks links={socialLinks} className="flex gap-3 flex-wrap justify-center" />}
            </div>
            {footer.copyright && <div className="pt-6 mt-6 border-t border-[var(--c-border)] text-center text-sm">{footer.copyright}</div>}
          </footer>
        </div>
      </div>
      <FloatingControls theme={theme} setTheme={setTheme} />
    </div>
  );
}
