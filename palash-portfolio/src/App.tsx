import React, { useState, useEffect } from "react";

// Single-source content (editable via the /admin CMS)
import newsData from "./content/news.json";
import mediaData from "./content/media.json";
import metricsData from "./content/metrics.json";
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

const navLinks = [
  { label: "Home", href: "#home" },
  { label: "About", href: "#about" },
  { label: "Publications", href: "#portfolio" },
  { label: "News", href: "#news" },
  { label: "Leadership", href: "#leadership" },
  { label: "Service", href: "#service" },
  { label: "Awards", href: "#highlights" },
  { label: "Gallery", href: "#pictures" },
  { label: "References", href: "#references" }
];

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
  rotationInterval = 8000
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
      <div className="overflow-hidden">
        <div
          className="flex transition-transform duration-500 ease-in-out"
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
            className="absolute left-0 top-1/2 -translate-y-1/2 bg-[#181a22] p-2 rounded-full z-10 text-[#7ec8e3] hover:bg-[#232333] transition-colors"
            onClick={prevSlide}
            aria-label="Previous slide"
          >
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
              <path fillRule="evenodd" d="M11.354 1.646a.5.5 0 0 1 0 .708L5.707 8l5.647 5.646a.5.5 0 0 1-.708.708l-6-6a.5.5 0 0 1 0-.708l6-6a.5.5 0 0 1 .708 0z"/>
            </svg>
          </button>
          <button
            className="absolute right-0 top-1/2 -translate-y-1/2 bg-[#181a22] p-2 rounded-full z-10 text-[#7ec8e3] hover:bg-[#232333] transition-colors"
            onClick={nextSlide}
            aria-label="Next slide"
          >
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
              <path fillRule="evenodd" d="M4.646 1.646a.5.5 0 0 1 .708 0l6 6a.5.5 0 0 1 0 .708l-6 6a.5.5 0 0 1-.708-.708L10.293 8 4.646 2.354a.5.5 0 0 1 0-.708z"/>
            </svg>
          </button>
        </>
      )}

      {/* Dots navigation - only show if needed */}
      {needsNavigation && totalSlides > 1 && (
        <div className="flex justify-center gap-3 mt-4">
          {Array.from({ length: totalSlides }).map((_, index) => (
            <button
              key={index}
              className={`w-3 h-3 rounded-full ${
                index === currentIndex ? "bg-[#7ec8e3]" : "bg-[#2d324b]"
              } transition-colors`}
              onClick={() => goToSlide(index)}
              aria-label={`Go to slide ${index + 1}`}
            />
          ))}
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
    <div className="fixed inset-0 bg-gradient-to-tr from-black via-[#0e071b] to-[#200216] bg-opacity-95 backdrop-blur-sm z-50 flex flex-col justify-center items-center animate-fadeIn">
      <button 
        onClick={() => setIsOpen(false)}
        className="absolute top-5 right-5 text-white p-2"
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
              className="text-white hover:text-[#7ec8e3] text-xl font-semibold transition-colors duration-150"
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

export default function App() {
  const [mobileMenuOpen, setMobileMenuOpen] = useState(false);

  // Live content from the cPanel API (falls back to the prerendered/baked content).
  const [remote, setRemote] = useState<Record<string, any>>({});
  useEffect(() => {
    fetch("/api/content.php")
      .then((r) => (r.ok ? r.json() : null))
      .then((d) => { if (d && typeof d === "object") setRemote(d); })
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

  // Reorderable page sections — order is editable from /admin (sectionOrder)
  const sectionMap: Record<string, React.ReactNode> = {
    about: (<>
          <section id="about" className="pt-16 pb-16">
            <h2 className="text-3xl font-bold mb-8 border-b border-[#2d324b] pb-3">Biography</h2>
            
            <div className="flex flex-col md:flex-row gap-10">
              {/* Left column with profile image and social links */}
              <div className="md:w-1/3 flex flex-col items-center">
                <div className="rounded-full overflow-hidden border-4 border-[#2d324b] bg-[#233343] w-64 h-64">
                  <img
                    src="/images/palash_roy.jpg"
                    alt="Palash Roy - AI Researcher and Computer Science PhD Student at University of Saskatchewan"
                    className="w-full h-full object-cover"
                    loading="lazy"
                    decoding="async"
                  />
                </div>
                
                {/* Social Media Links - with accessible labels for screen readers */}
                <div className="flex justify-center gap-4 mt-6">
                  <a href="https://www.facebook.com/pial.roy.3705/" target="_blank" rel="noopener noreferrer" 
                    className="w-10 h-10 rounded-full bg-[#1877F2] flex items-center justify-center text-white hover:bg-opacity-80 transition-all"
                    aria-label="Visit Palash Roy's Facebook profile">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="currentColor" viewBox="0 0 16 16" aria-hidden="true">
                      <path d="M16 8.049c0-4.446-3.582-8.05-8-8.05C3.58 0-.002 3.603-.002 8.05c0 4.017 2.926 7.347 6.75 7.951v-5.625h-2.03V8.05H6.75V6.275c0-2.017 1.195-3.131 3.022-3.131.876 0 1.791.157 1.791.157v1.98h-1.009c-.993 0-1.303.621-1.303 1.258v1.51h2.218l-.354 2.326H9.25V16c3.824-.604 6.75-3.934 6.75-7.951z"/>
                    </svg>
                    <span className="sr-only">Facebook</span>
                  </a>
                  <a href="https://www.instagram.com/checkoutroy/?hl=en" target="_blank" rel="noopener noreferrer" 
                    className="w-10 h-10 rounded-full bg-[#E4405F] flex items-center justify-center text-white hover:bg-opacity-80 transition-all"
                    aria-label="Visit Palash Roy's Instagram profile">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="currentColor" viewBox="0 0 16 16" aria-hidden="true">
                      <path d="M8 0C5.829 0 5.556.01 4.703.048 3.85.088 3.269.222 2.76.42a3.917 3.917 0 0 0-1.417.923A3.927 3.927 0 0 0 .42 2.76C.222 3.268.087 3.85.048 4.7.01 5.555 0 5.827 0 8.001c0 2.172.01 2.444.048 3.297.04.852.174 1.433.372 1.942.205.526.478.972.923 1.417.444.445.89.719 1.416.923.51.198 1.09.333 1.942.372C5.555 15.99 5.827 16 8 16s2.444-.01 3.298-.048c.78-.035 1.204-.166 1.486-.275.373-.145.64-.319.92-.599.28-.28.453-.546.598-.92.11-.281.24-.705.275-1.485.039.843.047 1.096.047 3.231s-.008 2.389-.047 3.232c-.035.78-.166 1.203-.275 1.485a2.47 2.47 0 0 1-.599.919c-.28.28-.546.453-.92.598-.28.11-.704.24-1.485.276-.843.038-1.096.047-3.232.047s-2.39-.009-3.233-.047c-.78-.036-1.203-.166-1.485-.276a2.478 2.478 0 0 1-.92-.598 2.48 2.48 0 0 1-.6-.92c-.109-.281-.24-.705-.275-1.485-.038-.843-.046-1.096-.046-3.233 0-2.136.008-2.388.046-3.231.036-.78.166-1.204.276-1.486.145-.373.319-.64.599-.92.28-.28.546-.453.92-.598.282-.11.705-.24 1.485-.276.738-.034 1.024-.044 2.515-.045v.002zm4.988 1.328a.96.96 0 1 0 0 1.92.96.96 0 0 0 0-1.92zm-4.27 1.122a4.109 4.109 0 1 0 0 8.217 4.109 4.109 0 0 0 0-8.217zm0 1.441a2.667 2.667 0 1 1 0 5.334 2.667 2.667 0 0 1 0-5.334z"/>
                    </svg>
                    <span className="sr-only">Instagram</span>
                  </a>
                  <a href="https://www.linkedin.com/in/palashranjanroy/" target="_blank" rel="noopener noreferrer" 
                    className="w-10 h-10 rounded-full bg-[#0A66C2] flex items-center justify-center text-white hover:bg-opacity-80 transition-all"
                    aria-label="Visit Palash Roy's LinkedIn profile">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="currentColor" viewBox="0 0 16 16" aria-hidden="true">
                      <path d="M0 1.146C0 .513.526 0 1.175 0h13.65C15.474 0 16 .513 16 1.146v13.708c0 .633-.526 1.146-1.175 1.146H1.175C.526 16 0 15.487 0 14.854V1.146zm4.943 12.248V6.169H2.542v7.225h2.401zm-1.2-8.212c.837 0 1.358-.554 1.358-1.248-.015-.709-.52-1.248-1.342-1.248-.822 0-1.359.54-1.359 1.248 0 .694.521 1.248 1.327 1.248h.016zm4.908 8.212V9.359c0-.216.016-.432.08-.586.173-.431.568-.878 1.232-.878.869 0 1.216.662 1.216 1.634v3.865h2.401V9.25c0-2.22-1.184-3.252-2.764-3.252-1.274 0-1.845.7-2.165 1.193v.025h-.016a5.54 5.54 0 0 1 .016-.025V6.169h-2.4c.03.678 0 7.225 0 7.225h2.4z"/>
                    </svg>
                    <span className="sr-only">LinkedIn</span>
                  </a>
                  <a href="https://github.com/Roy101" target="_blank" rel="noopener noreferrer" 
                    className="w-10 h-10 rounded-full bg-[#333] flex items-center justify-center text-white hover:bg-opacity-80 transition-all"
                    aria-label="Visit Palash Roy's GitHub profile">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="currentColor" viewBox="0 0 16 16" aria-hidden="true">
                      <path d="M8 0C3.58 0 0 3.58 0 8c0 3.54 2.29 6.53 5.47 7.59.4.07.55-.17.55-.38 0-.19-.01-.82-.01-1.49-2.01.37-2.53-.49-2.69-.94-.09-.23-.48-.94-.82-1.13-.28-.15-.68-.52-.01-.53.63-.01 1.08.58 1.23.82.72 1.21 1.87.87 2.33.66.07-.52.28-.87.51-1.07-1.78-.2-3.64-.89-3.64-3.95 0-.87.31-1.59.82-2.15-.08-.2-.36-1.02.08-2.12 0 0 .67-.21 2.2.82.64-.18 1.32-.27 2-.27.68 0 1.36.09 2 .27 1.53-1.04 2.2-.82 2.2-.82.44 1.1.16 1.92.08 2.12.51.56.82 1.27.82 2.15 0 3.07-1.87 3.75-3.65 3.95.29.25.54.73.54 1.48 0 1.07-.01 1.93-.01 2.2 0 .21.15.46.55.38A8.012 8.012 0 0 0 16 8c0-4.42-3.58-8-8-8z"/>
                    </svg>
                    <span className="sr-only">GitHub</span>
                  </a>
                </div>

                {/* At-a-glance highlights card - surfaces the most important facts */}
                <div className="mt-8 w-full bg-[#181a22] border border-[#2d324b] rounded-xl p-5 text-sm">
                  <h3 className="text-[#7ec8e3] font-semibold mb-3 uppercase tracking-wider text-xs">At a Glance</h3>
                  <ul className="space-y-2.5 text-[#d0cccc]">
                    <li className="flex gap-2"><span>🔬</span><span>Code clone researcher at SRLab and ISELab</span></li>
                    <li className="flex gap-2"><span>🎓</span><span>PhD Researcher in Computer Science, University of Saskatchewan</span></li>
                    <li className="flex gap-2"><span>🤖</span><span>Works on clone detection, refactoring, and large language models</span></li>
                    <li className="flex gap-2"><span>📄</span><span>Newest paper "Carbon-Taxed Transformers" (FSE 2026)</span></li>
                    <li className="flex gap-2"><span>🏛️</span><span>President, Graduate Students' Association (2025-26)</span></li>
                    <li className="flex gap-2"><span>🏅</span><span>USask Senator and Provost Search Committee member</span></li>
                    <li className="flex gap-2"><span>📍</span><span>Saskatoon, Canada. Originally from Bangladesh 🇧🇩</span></li>
                  </ul>
                </div>
              </div>

              {/* Right column with biography text */}
              <div className="md:w-2/3">
                {/* Name Variants Badge Component - Added for SEO */}
                <div className="flex flex-wrap gap-2 mb-4">
                  <span className="bg-[#2a3b4d] text-[#7ec8e3] px-3 py-1 rounded-full text-sm">Palash Ranjan Roy</span>
                  <span className="bg-[#2a3b4d] text-[#7ec8e3] px-3 py-1 rounded-full text-sm">Palash Roy</span>
                  <span className="bg-[#2a3b4d] text-[#7ec8e3] px-3 py-1 rounded-full text-sm">P. R. Roy</span>
                  <span className="bg-[#2a3b4d] text-[#7ec8e3] px-3 py-1 rounded-full text-sm">Palash R. Roy</span>
                </div>
                
                <div className="text-[#d0cccc] space-y-4 leading-relaxed">
                  <p>
                    <strong>Palash Ranjan Roy</strong> (also published as <strong>Palash Roy</strong>) is a PhD researcher in Computer Science at the University of Saskatchewan, Canada. He is a <strong>code clone researcher</strong> whose work spans clone detection, refactoring, and the application of <strong>large language models</strong> to software engineering. He conducts his research in the <a href="https://srlab.usask.ca/members/" className="text-[#7ec8e3] hover:underline">Software Research Lab (SRLab)</a> and the <a href="https://ise.usask.ca/team/" className="text-[#7ec8e3] hover:underline">Interactive Software Engineering Lab (ISELab)</a> under the supervision of Dr. Kevin Schneider.
                  </p>

                  <p>
                    His research has been published in flagship software engineering conferences such as ASE, FSE, ICSME, and ESEM. His most recent paper, <em>Carbon-Taxed Transformers</em>, appeared at FSE 2026. He has received several honors for his work, including a Research Excellence Award and a Best Thesis Award.
                  </p>

                  <p>
                    Beyond his research, Palash served as President of the University of Saskatchewan Graduate Students' Association (2025-26), where he represented more than 4,500 graduate students. He has contributed extensively to university governance as a member of the University Senate, the Provost Search Committee, and several College of Graduate and Postdoctoral Studies and award committees.
                  </p>

                  <p>
                    Originally from Bangladesh, Palash completed his undergraduate studies at BRAC University before moving to Canada for his graduate studies.
                  </p>
                </div>
                
                {/* Education Section integrated within the Biography section */}
                <div className="mt-10">
                  <h3 className="text-xl font-semibold mb-4 text-[#7ec8e3]">Education</h3>
                  <div className="space-y-6">
                    <div>
                      <h4 className="font-bold">Ph.D. in Computer Science</h4>
                      <div className="text-[#a2a5b9] text-sm">University of Saskatchewan | 2024 - Present</div>
                      <p className="text-[#d0cccc] text-sm mt-1 italic">In Progress</p>
                    </div>
                    
                    <div>
                      <h4 className="font-bold">M.Sc. in Computer Science</h4>
                      <div className="text-[#a2a5b9] text-sm">University of Saskatchewan | 2022 - 2024</div>
                      <p className="text-[#d0cccc] text-sm mt-1 italic">Completed</p>
                    </div>
                    
                    <div>
                      <h4 className="font-bold">B.Sc. in Computer Science</h4>
                      <div className="text-[#a2a5b9] text-sm">BRAC University | 2018 - 2021</div>
                      <p className="text-[#d0cccc] text-sm mt-1 italic">Completed</p>
                    </div>
                  </div>
                </div>
                
                {/* Experience Section integrated within the Biography section */}
                <div className="mt-10">
                  <h3 className="text-xl font-semibold mb-4 text-[#7ec8e3]">Experience</h3>
                  <p className="text-[#a2a5b9] text-xs italic mb-4">Currently serving concurrently as Graduate Teaching Assistant, Research Technician, and Graduate Peer Mentor at the University of Saskatchewan.</p>
                  <div className="space-y-6">
                    <div>
                      <h4 className="font-bold">Graduate Teaching Assistant</h4>
                      <div className="text-[#a2a5b9] text-sm">University of Saskatchewan | 2022 - Present</div>
                      <p className="text-[#d0cccc] text-sm mt-1">Instructed undergraduate students in data structures, programming, and practical computing labs.</p>
                    </div>

                    <div>
                      <h4 className="font-bold">Research Technician</h4>
                      <div className="text-[#a2a5b9] text-sm">iSE & SR Lab, University of Saskatchewan | 2022 - Present</div>
                      <p className="text-[#d0cccc] text-sm mt-1">Supporting web-based systems, maintaining CFI equipment, and supporting the SOAR CREATE Program.</p>
                    </div>

                    <div>
                      <h4 className="font-bold">Graduate Peer Mentor</h4>
                      <div className="text-[#a2a5b9] text-sm">University of Saskatchewan | 2026 - Present</div>
                      <p className="text-[#d0cccc] text-sm mt-1">Helping new graduate students navigate grad life through the Peer Assisted Learning (PAL) program. Sharing my own grad school experience, delivering small-group workshops, and meeting students one-to-one to support their academic skills and transition into graduate studies.</p>
                    </div>

                    <div>
                      <h4 className="font-bold">Visiting Research Student</h4>
                      <div className="text-[#a2a5b9] text-sm">University of Saskatchewan | May 2022 - Aug 2022</div>
                      <p className="text-[#d0cccc] text-sm mt-1">Developed multiple software engineering tools and published research in code clones and large language models.</p>
                    </div>
                  </div>
                </div>
                
                {/* Academic Links at the bottom */}
                <div className="mt-8">
                  <h3 className="text-lg font-semibold mb-2 text-[#7ec8e3]">Academic Profiles</h3>
                  <div className="flex flex-wrap gap-3">
                    <a href="https://orcid.org/0000-0001-9470-4233" target="_blank" rel="noopener noreferrer" className="inline-flex items-center px-3 py-1 text-xs rounded bg-[#2d324b] hover:bg-[#363c5a] transition-colors">
                      <svg className="w-4 h-4 mr-1" fill="currentColor" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg">
                        <path fillRule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM6.5 5.5a1 1 0 11-2 0 1 1 0 012 0zM5.5 8h2v6.5h-2V8zm3.5 0h1.9v.9h.03c.27-.5.92-1.05 1.9-1.05 2.03 0 2.4 1.27 2.4 2.95v3.7h-2v-3.28c0-.78-.01-1.79-1.1-1.79-1.1 0-1.27.86-1.27 1.74v3.33H9V8z" clipRule="evenodd"></path>
                      </svg>
                      ORCID
                    </a>
                    <a href="https://dblp.org/pid/355/4465.html" target="_blank" rel="noopener noreferrer" className="inline-flex items-center px-3 py-1 text-xs rounded bg-[#2d324b] hover:bg-[#363c5a] transition-colors">
                      <svg className="w-4 h-4 mr-1" fill="currentColor" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg">
                        <path d="M5 4a2 2 0 00-2 2v8a2 2 0 002 2h10a2 2 0 002-2V6a2 2 0 00-2-2H5zm2 3h6v1.5H7V7zm0 3h6v1.5H7V10zm0 3h4v1.5H7V13z"></path>
                      </svg>
                      dblp
                    </a>
                    <a href="https://scholar.google.com/citations?user=Vy_sw5UAAAAJ" target="_blank" rel="noopener noreferrer" className="inline-flex items-center px-3 py-1 text-xs rounded bg-[#2d324b] hover:bg-[#363c5a] transition-colors">
                      <svg className="w-4 h-4 mr-1" fill="currentColor" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg">
                        <path d="M10 12a2 2 0 100-4 2 2 0 000 4z"></path>
                        <path fillRule="evenodd" d="M.458 10C1.732 5.943 5.522 3 10 3s8.268 2.943 9.542 7c-1.274 4.057-5.064 7-9.542 7S1.732 14.057.458 10zM14 10a4 4 0 11-8 0 4 4 0 018 0z" clipRule="evenodd"></path>
                      </svg>
                      Google Scholar
                    </a>
                    <a href="https://srlab.usask.ca/members/" target="_blank" rel="noopener noreferrer" className="inline-flex items-center px-3 py-1 text-xs rounded bg-[#2d324b] hover:bg-[#363c5a] transition-colors">
                      <svg className="w-4 h-4 mr-1" fill="currentColor" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg">
                        <path d="M11 3a1 1 0 100 2h2.586l-6.293 6.293a1 1 0 101.414 1.414L15 6.414V9a1 1 0 102 0V4a1 1 0 00-1-1h-5z"></path>
                        <path d="M5 5a2 2 0 00-2 2v10a2 2 0 002 2h12a2 2 0 002-2V6a2 2 0 00-2-2h-3.5l-1.5-1.5h-5L4 4zm7 5a1 1 0 100-2H9v2h2zm3 0a1 1 0 100-2h-2v2h2zm-9 3a1 1 0 100-2H5v2h2zm3 0a1 1 0 100-2H8v2h2zm3 0a1 1 0 100-2h-2v2h2zm3 0a1 1 0 100-2h-2v2h2z"></path>
                      </svg>
                      SRLab Profile
                    </a>
                    <a href="https://ise.usask.ca/team/" target="_blank" rel="noopener noreferrer" className="inline-flex items-center px-3 py-1 text-xs rounded bg-[#2d324b] hover:bg-[#363c5a] transition-colors">
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
              <div className="inline-block px-3 py-1 bg-[#181a22] text-[#7ec8e3] text-sm font-medium mb-2 rounded">
                Publications
              </div>
              <h2 className="text-4xl font-bold mb-4">My Latest Publications</h2>
              <p className="text-[#d0cccc] text-lg max-w-3xl mx-auto">
                See my <a href="https://scholar.google.com/citations?user=Vy_sw5UAAAAJ&hl=en" target="_blank" rel="noopener noreferrer" className="text-[#7ec8e3] hover:underline">Google Scholar</a> for the latest details on the following work.
              </p>
              {/* Live research-impact metrics (refreshed from OpenAlex at build time) */}
              <div className="flex flex-wrap justify-center gap-8 mt-6">
                <div className="text-center">
                  <div className="text-3xl font-bold text-[#7ec8e3]">{metricsData.citations}+</div>
                  <div className="text-xs uppercase tracking-wider">Citations</div>
                </div>
                <div className="text-center">
                  <div className="text-3xl font-bold text-[#7ec8e3]">{metricsData.hIndex}</div>
                  <div className="text-xs uppercase tracking-wider">h-index</div>
                </div>
                <div className="text-center">
                  <div className="text-3xl font-bold text-[#7ec8e3]">{metricsData.works}</div>
                  <div className="text-xs uppercase tracking-wider">Indexed Works</div>
                </div>
              </div>
              <div className="text-xs text-[#a2a5b9] mt-2">
                Live via <a href={metricsData.profileUrl} target="_blank" rel="noopener noreferrer" className="text-[#7ec8e3] hover:underline">{metricsData.source}</a> &middot; updated {metricsData.updated}
              </div>
            </div>
            <div className="grid grid-cols-1 md:grid-cols-2 gap-5 max-w-6xl mx-auto">
              {publicationsData.map((item, idx) => (
                <div key={idx} className="bg-[#181a22] h-full p-6 rounded-lg border border-[#2d324b] hover:border-[#7ec8e3]/50 transition-colors">
                  {item.award && (
                    <div className="inline-flex items-center gap-1 mb-2 px-2 py-1 rounded-full text-xs font-semibold bg-gradient-to-r from-[#b8860b] to-[#ffd700] text-black">
                      🏆 {item.award}
                    </div>
                  )}
                  <div className="font-bold mb-1">{item.title}</div>
                  <div className="text-[#7ec8e3] text-sm mb-1">{item.authors}</div>
                  <div className="text-[#a2a5b9] text-xs mb-1 italic">{item.venue}{item.year ? `, ${item.year}` : ""}{item.pages ? `, ${item.pages}` : ""}</div>
                  <div className="flex flex-wrap gap-2 mt-2 mb-3">
                    {item.doi && (
                      <a 
                        href={`https://doi.org/${item.doi}`} 
                        target="_blank" 
                        rel="noopener noreferrer"
                        className="inline-flex items-center px-3 py-1 text-xs rounded bg-[#2d324b] hover:bg-[#363c5a] transition-colors"
                      >
                        <svg className="w-4 h-4 mr-1" fill="currentColor" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg">
                          <path d="M11 3a1 1 0 100 2h2.586l-6.293 6.293a1 1 0 101.414 1.414L15 6.414V9a1 1 0 102 0V4a1 1 0 00-1-1h-5z"></path>
                          <path d="M5 5a2 2 0 00-2 2v10a2 2 0 002 2h12a2 2 0 002-2V6a2 2 0 00-2-2h-3.5l-1.5-1.5h-5L4 4zm7 5a1 1 0 100-2H9v2h2zm3 0a1 1 0 100-2h-2v2h2zm-9 3a1 1 0 100-2H5v2h2zm3 0a1 1 0 100-2H8v2h2zm3 0a1 1 0 100-2h-2v2h2zm3 0a1 1 0 100-2h-2v2h2z"></path>
                        </svg>
                        DOI
                      </a>
                    )}
                    {item.preprint && (
                      <a 
                        href={item.preprint} 
                        target="_blank" 
                        rel="noopener noreferrer"
                        className="inline-flex items-center px-3 py-1 text-xs rounded bg-[#2a3b4d] hover:bg-[#344a61] text-[#7ec8e3] transition-colors"
                      >
                        <svg className="w-4 h-4 mr-1" fill="currentColor" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg">
                          <path d="M4 4a2 2 0 012-2h8a2 2 0 012 2v16a1 1 0 01-1.581.814l-4.419-3.346-4.419 3.346A1 1 0 014 16V4zm5 0a1 1 0 00-1 1v6.5a.5.5 0 001 0V5a1 1 0 00-1-1z"></path>
                        </svg>
                        Download PDF
                      </a>
                    )}
                  </div>
                  <div className="text-[#a9c0d4] text-sm">{item.description}</div>
                </div>
              ))}
            </div>
          </section>
    </>),
    news: (<>
          {/* News & Milestones timeline - content from src/content/news.json */}
          <section id="news" className="pt-16 pb-16">
            <div className="text-center mb-8">
              <div className="inline-block px-3 py-1 bg-[#181a22] text-[#7ec8e3] text-sm font-medium mb-2 rounded">
                News &amp; Milestones
              </div>
              <h2 className="text-4xl font-bold mb-4">What's Happening</h2>
              <p className="text-[#d0cccc] text-lg max-w-3xl mx-auto">
                Recent milestones, awards, and research highlights.
              </p>
            </div>
            <div className="max-w-3xl mx-auto">
              <ol className="relative border-l border-[#2d324b] ml-3">
                {newsItems.map((n, i) => (
                  <li key={i} className="mb-8 ml-6">
                    <span className="absolute -left-3 flex items-center justify-center w-6 h-6 bg-[#181a22] border border-[#2d324b] rounded-full text-sm">{n.icon}</span>
                    <div className="text-xs text-[#7ec8e3] font-semibold mb-1">{n.date}</div>
                    <h3 className="font-bold">{n.title}</h3>
                    <p className="text-[#a9c0d4] text-sm mt-1">{n.description}</p>
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
              <div className="inline-block px-3 py-1 bg-[#181a22] text-[#7ec8e3] text-sm font-medium mb-2 rounded">
                Leadership
              </div>
              <h2 className="text-4xl font-bold mb-4">Titles I Didn't Ask For but Took Anyway</h2>
              <p className="text-[#d0cccc] text-lg max-w-3xl mx-auto">
                A curated list of leadership roles where I herded humans, orchestrated controlled chaos, and occasionally made important decisions while pretending to know what I was doing.
              </p>
            </div>
            <Carousel
              items={leadershipRolesData}
              renderItem={(item) => (
                <div className="bg-[#181a22] h-full p-6 rounded-lg">
                  <div className="font-bold mb-1">{item.role}</div>
                  <div className="text-[#7ec8e3] text-sm mb-1">
                    {item.link ? (
                      <a 
                        href={item.link} 
                        target="_blank" 
                        rel="noopener noreferrer"
                        className="hover:text-[#35c7ff] transition-colors hover:underline"
                      >
                        {item.organization}
                      </a>
                    ) : (
                      item.organization
                    )}
                  </div>
                  <div className="text-[#a2a5b9] text-xs mb-1 italic">{item.place}</div>
                  <div className="text-[#a9c0d4] text-sm">{item.period}</div>
                </div>
              )}
            />
          </section>
    </>),
    service: (<>
          {/* Academic Service Section with Carousel and centered content */}
          <section id="service" className="pt-16 pb-16">
            <h2 className="text-2xl font-semibold mb-8">Academic Service</h2>
            <Carousel
              items={academicServiceData}
              renderItem={(item) => (
                <div className="bg-[#181a22] h-full p-6 rounded-lg">
                  <div className="font-bold mb-1">{item.role}</div>
                  <div className="text-[#7ec8e3] text-sm mb-1">{item.venues || item.venue}</div>
                  <div className="text-[#d0cccc] text-sm mb-1">{item.period}</div>
                  <div className="text-[#a9c0d4] text-sm">{item.description}</div>
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
              <div className="inline-block px-3 py-1 bg-[#181a22] text-[#7ec8e3] text-sm font-medium mb-2 rounded">
                Highlights
              </div>
              <h2 className="text-4xl font-bold mb-4">Featured Highlights</h2>
              <p className="text-[#d0cccc] text-lg max-w-3xl mx-auto">
                Here are some awards, articles, documents, certificates, and whatever else I am proud of.
              </p>
            </div>
            
            <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 max-w-6xl mx-auto">
              {highlightsData.map((item, idx) => (
                <div key={idx} className="bg-[#181a22] h-full p-6 rounded-lg flex flex-col border border-[#2d324b]">
                  {/* Display image at the top if available */}
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
                  <div className="text-[#7ec8e3] text-sm mb-2">{item.organization}</div>
                  <p className="text-[#a9c0d4] text-sm flex-grow">{item.description}</p>
                  {item.link && (
                    <div className="mt-4 pt-2 border-t border-[#2d324b]">
                      <a
                        href={item.link}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="text-[#7ec8e3] text-sm hover:text-[#35c7ff] flex items-center"
                      >
                        Read more
                        <svg xmlns="http://www.w3.org/2000/svg" className="h-4 w-4 ml-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M14 5l7 7m0 0l-7 7m7-7H3" />
                        </svg>
                      </a>
                    </div>
                  )}
                </div>
              ))}
            </div>
          </section>
    </>),
    media: (<>
          {/* In the News / media coverage - content from src/content/media.json */}
          <section id="media" className="pt-16 pb-16">
            <div className="text-center mb-8">
              <div className="inline-block px-3 py-1 bg-[#181a22] text-[#7ec8e3] text-sm font-medium mb-2 rounded">
                In the News
              </div>
              <h2 className="text-4xl font-bold mb-4">Featured Coverage</h2>
              <p className="text-[#d0cccc] text-lg max-w-3xl mx-auto">
                Selected media coverage and university announcements.
              </p>
            </div>
            <div className="grid grid-cols-1 md:grid-cols-3 gap-4 max-w-5xl mx-auto">
              {mediaItems.map((m, i) => (
                <a key={i} href={m.url} target="_blank" rel="noopener noreferrer" className="bg-[#181a22] border border-[#2d324b] rounded-lg p-5 hover:border-[#7ec8e3] transition-colors flex flex-col">
                  <div className="text-xs text-[#7ec8e3] mb-2">{m.outlet} &middot; {m.date}</div>
                  <div className="font-semibold text-sm mb-3 flex-1">{m.title}</div>
                  <span className="text-[#7ec8e3] text-xs">Read article &rarr;</span>
                </a>
              ))}
            </div>
          </section>
    </>),
    pictures: (<>
          {/* Combined Gallery Section - GSA presidency highlights and personal moments */}
          <section id="pictures" className="pt-16 pb-16">
            <div className="text-center mb-8">
              <div className="inline-block px-3 py-1 bg-[#181a22] text-[#7ec8e3] text-sm font-medium mb-2 rounded">
                Gallery
              </div>
              <h2 className="text-4xl font-bold mb-4">Beyond the Research</h2>
              <p className="text-[#d0cccc] text-lg max-w-3xl mx-auto">
                From representing 4,500+ graduate students as GSA President to exploring the world with family and friends.
              </p>
            </div>
            <Carousel
              items={galleryData}
              renderItem={(item) => (
                <div className="bg-[#181a22] h-full p-4 rounded-lg flex flex-col items-center text-center">
                  <div className="w-full h-48 sm:h-52 overflow-hidden rounded relative">
                    {/* Loading placeholder */}
                    <div className="absolute inset-0 bg-[#232333] animate-pulse"></div>

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
                  <div className="text-xs text-[#d0cccc]">"{item.description}"</div>
                </div>
              )}
            />
          </section>
    </>),
    references: (<>
          {/* References Section - updated with new style matching the image */}
          <section id="references" className="pt-16 pb-16">
            <div className="text-center mb-8">
              <div className="inline-block px-4 py-2 bg-[#1c1c24] rounded-lg text-[#7ec8e3] text-sm font-semibold mb-3">
                References
              </div>
              <h2 className="text-4xl font-bold mb-3">References</h2>
              <p className="max-w-3xl mx-auto text-[#d0cccc]">
                Here are some of the amazing people who I have worked with in the past that I 
                could reach out to for a reference if needed.
              </p>
              
              <p className="mt-6 text-[#d0cccc] italic">
                I really need to update this to add all my amazing computer science references! 
                But that will be for another day.
              </p>
              
              <div className="mt-8 text-[#a2a5b9] max-w-3xl mx-auto">
                <p className="font-medium">
                  I have had the privilege to work with
                  <a href="https://artsandscience.usask.ca/profile/KSchneider" className="text-[#7ec8e3] hover:underline ml-1">Dr. Kevin Schneider</a>,
                  <a href="https://clones.usask.ca/" className="text-[#7ec8e3] hover:underline ml-1">Dr. Chanchal Roy</a>,
                  <a href="https://www.tru.ca/science/departments/engineering/Faculty.html" className="text-[#7ec8e3] hover:underline ml-1">Dr. Farouq Al-Omari</a>,
                  <a href="https://www.cs.usask.ca/people/faculty%20profiles/banani-roy.php" className="text-[#7ec8e3] hover:underline ml-1">Dr. Banani Roy</a>,
                  <a href="https://www.qut.edu.au/about/our-people/academic-profiles/cody.phillips" className="text-[#7ec8e3] hover:underline ml-1">Dr. Cody Phillips</a>
                </p>
              </div>
            </div>

            {/* Reference testimonials in cards - kept from original design */}
            <Carousel
              items={referencesData}
              renderItem={(item) => (
                <div className="bg-gradient-to-br from-[#1c1c24] to-[#13131a] h-full p-4 sm:p-6 rounded-lg text-[#d0cccc] flex flex-col items-start relative overflow-hidden">
                  {/* Quote marks in background */}
                  <div className="absolute right-2 top-2 text-[100px] leading-none text-[#1d1d27] font-serif opacity-70">
                    "
                  </div>
                  
                  <div className="flex items-center mb-4 relative z-10 w-full">
                    {/* Profile image - fixed size for all screen sizes */}
                    <div className="w-14 h-14 sm:w-16 sm:h-16 rounded-full overflow-hidden border-2 border-[#7ec8e3] mr-3 sm:mr-4 flex-shrink-0">
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
                      <div className="font-bold text-base sm:text-lg text-white truncate">{item.name}</div>
                      <div className="text-xs sm:text-sm text-[#7ec8e3] truncate">{item.title}</div>
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
  };
  const defaultSectionOrder = ['about','portfolio','news','leadership','service','highlights','media','pictures','references'];
  const roRaw = Array.isArray((remote as any).sectionOrder) ? ((remote as any).sectionOrder as string[]) : defaultSectionOrder;
  const sectionOrder = roRaw.filter((id) => sectionMap[id]);
  defaultSectionOrder.forEach((id) => { if (!sectionOrder.includes(id)) sectionOrder.push(id); });

  return (
    <div className="min-h-screen bg-black font-sans text-white relative overflow-hidden">
      {/* Background gradient overlay with red/purple accents - applied to the entire site */}
      <div className="fixed inset-0 bg-gradient-to-tr from-black via-[#0e071b] to-[#200216] z-0 pointer-events-none"></div>
      <div className="fixed inset-0 bg-[url('data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHdpZHRoPSI1IiBoZWlnaHQ9IjUiPgo8cmVjdCB3aWR0aD0iNSIgaGVpZ2h0PSI1IiBmaWxsPSIjMDAwMDAwMDYiPjwvcmVjdD4KPHBhdGggZD0iTTAgNUw1IDBaTTYgNEw0IDZaTS0xIDFMMSAtMVoiIHN0cm9rZT0iIzMzMDAzMzA5IiBzdHJva2Utd2lkdGg9IjAuNSI+PC9wYXRoPgo8L3N2Zz4=')] opacity-30 z-0 pointer-events-none"></div>
      
      {/* Mobile Menu */}
      <MobileMenu 
        links={navLinks}
        isOpen={mobileMenuOpen}
        setIsOpen={setMobileMenuOpen}
      />
      
      {/* Navigation - Updated to be fixed at the top with improved background blending */}
      <nav className="fixed top-0 left-0 right-0 flex justify-between items-center px-4 sm:px-6 lg:px-8 py-4 bg-gradient-to-tr from-black via-[#0e071b] to-[#200216] bg-opacity-95 backdrop-blur-sm shadow-md z-40">
        <div className="text-3xl font-bold tracking-wide text-[#7ec8e3]">PALASH</div>
        
        {/* Mobile hamburger menu button */}
        <button 
          className="lg:hidden text-white"
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
                className="hover:text-[#69b0e0] transition-colors duration-150 font-semibold text-base"
              >
                {link.label}
              </a>
            </li>
          ))}
        </ul>
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
            <h2 className="text-xl font-light uppercase tracking-wider text-gray-400 mb-2">👋 Hello, I'm</h2>
            <h1 className="text-6xl md:text-7xl font-bold mb-5">
              Palash Roy,<br />
              <span className="bg-gradient-to-r from-[#35c7ff] to-[#ff4081] bg-clip-text text-transparent">Computer Science</span><br />
              <span className="text-[#ff4081]">PhD Researcher</span>
            </h1>

            {/* Role pills - lead with research identity, then leadership and personality */}
            <div className="flex flex-wrap gap-2 mb-6">
              <span className="bg-[#181a22] border border-[#2d324b] text-[#d0cccc] px-3 py-1 rounded-full text-sm">🔬 Code Clone Researcher</span>
              <span className="bg-[#181a22] border border-[#2d324b] text-[#d0cccc] px-3 py-1 rounded-full text-sm">🤖 LLMs for Software Engineering</span>
              <span className="bg-[#181a22] border border-[#2d324b] text-[#d0cccc] px-3 py-1 rounded-full text-sm">🏛️ GSA President</span>
            </div>

            <a href="#portfolio" className="inline-flex items-center gap-2 mb-6 px-4 py-2 rounded-lg bg-gradient-to-r from-[#3a2c05] to-[#5c4708] border border-[#ffd700]/40 text-sm hover:border-[#ffd700] transition-colors">
              <span>🏆</span>
              <span className="text-[#ffd700] font-semibold">ACM SIGSOFT Distinguished Paper Award</span>
              <span className="text-[#d0cccc]">&middot; FSE 2026</span>
            </a>

            <p className="text-lg text-[#a9c0d4] mb-8 max-w-xl">
              I am a <strong>code clone researcher</strong> at the <a href="https://srlab.usask.ca/members/" target="_blank" rel="noopener noreferrer" className="text-[#7ec8e3] hover:underline">Software Research Lab (SRLab)</a> and <a href="https://ise.usask.ca/team/" target="_blank" rel="noopener noreferrer" className="text-[#7ec8e3] hover:underline">ISELab</a>, working on <strong>clone detection</strong>, <strong>refactoring</strong>, and <strong>large language models</strong> under Dr. Kevin Schneider. My goal is to turn research into tools that make software better.
            </p>

            <div className="grid grid-cols-3 gap-3 mb-8 max-w-md">
              <div className="bg-[#181a22]/70 border border-[#2d324b] rounded-xl px-3 py-3 text-center">
                <div className="text-2xl md:text-3xl font-bold bg-gradient-to-r from-[#35c7ff] to-[#ff4081] bg-clip-text text-transparent">8+</div>
                <div className="text-[10px] uppercase tracking-wider text-[#a2a5b9] mt-1">Publications</div>
              </div>
              <div className="bg-[#181a22]/70 border border-[#2d324b] rounded-xl px-3 py-3 text-center">
                <div className="text-2xl md:text-3xl font-bold bg-gradient-to-r from-[#35c7ff] to-[#ff4081] bg-clip-text text-transparent">31+</div>
                <div className="text-[10px] uppercase tracking-wider text-[#a2a5b9] mt-1">Citations</div>
              </div>
              <div className="bg-[#181a22]/70 border border-[#2d324b] rounded-xl px-3 py-3 text-center">
                <div className="text-2xl md:text-3xl font-bold bg-gradient-to-r from-[#35c7ff] to-[#ff4081] bg-clip-text text-transparent">4.5K+</div>
                <div className="text-[10px] uppercase tracking-wider text-[#a2a5b9] mt-1">Students Led</div>
              </div>
            </div>

            <div className="flex flex-wrap gap-4">
              <a href="#portfolio" className="inline-flex items-center gap-2 px-5 py-3 rounded-lg bg-gradient-to-r from-[#35c7ff] to-[#ff4081] text-white text-sm font-semibold hover:opacity-90 transition-opacity">
                View Publications
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path fillRule="evenodd" d="M1 8a.5.5 0 0 1 .5-.5h11.793l-3.147-3.146a.5.5 0 0 1 .708-.708l4 4a.5.5 0 0 1 0 .708l-4 4a.5.5 0 0 1-.708-.708L13.293 8.5H1.5A.5.5 0 0 1 1 8z"/></svg>
              </a>
              <a href="https://scholar.google.com/citations?user=Vy_sw5UAAAAJ&hl=en" target="_blank" rel="noopener noreferrer" className="inline-flex items-center gap-2 px-5 py-3 rounded-lg border border-[#2d324b] text-[#d0cccc] text-sm font-semibold hover:border-[#7ec8e3] hover:text-white transition-colors">
                Google Scholar
              </a>
            </div>
          </div>
          
          <div className="relative mt-12 md:mt-0 md:ml-14 shrink-0">
            {/* Soft animated glow ring behind the portrait */}
            <div className="absolute -inset-3 bg-gradient-to-tr from-[#35c7ff] to-[#ff4081] opacity-20 blur-2xl rounded-[2rem] animate-pulse" style={{ animationDuration: '5s' }}></div>
            <div className="relative w-64 h-64 md:w-80 md:h-80 rounded-2xl overflow-hidden shadow-[0_0_60px_rgba(122,205,235,0.18)] border-4 border-[#2d324b] bg-[#171830]">
              {/* Profile photo with improved alt text for SEO */}
              <img
                src="/images/palash_roy_headshot.jpg"
                alt="Palash Ranjan Roy (Palash Roy) - Computer Science PhD Student at University of Saskatchewan specializing in AI and Software Engineering"
                className="w-full h-full object-cover"
                decoding="async"
                fetchPriority="high"
              />
            </div>
            {/* Status badge highlighting research identity */}
            <div className="absolute -bottom-4 left-1/2 -translate-x-1/2 whitespace-nowrap bg-[#12131c] border border-[#2d324b] px-4 py-2 rounded-full text-sm shadow-lg flex items-center gap-2">
              <span className="w-2 h-2 rounded-full bg-[#3ddc84] animate-pulse"></span>
              <span className="font-semibold text-white">Code Clone Researcher</span>
            </div>
          </div>
        </div>
      </header>
      
      {/* Content Container - Removed gradient background to use the global background */}
      <div className="relative z-10">
        {/* About Section with centered content - Updated to match reference style */}
        <div className="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">
{sectionOrder.map((id) => (
            <React.Fragment key={id}>{sectionMap[id]}</React.Fragment>
          ))}
        </div>

        {/* Footer with centered content and academic credentials */}
        <div className="bg-[#0d0d0d]">
          <footer className="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-8 text-gray-400 mt-8">
            <div className="grid grid-cols-1 md:grid-cols-3 gap-8 mb-6">
              <div>
                <h3 className="text-white text-lg font-semibold mb-3">Contact</h3>
                <ul className="space-y-2">
                  <li className="flex items-center">
                    <svg className="w-4 h-4 mr-2" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                    </svg>
                    <a href="mailto:palash.roy@usask.ca" className="hover:text-[#7ec8e3]">palash.roy@usask.ca</a>
                  </li>
                  <li className="flex items-center">
                    <svg className="w-4 h-4 mr-2" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" />
                      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" />
                    </svg>
                    <span>Dept. of Computer Science, University of Saskatchewan</span>
                  </li>
                </ul>
              </div>
              <div>
                <h3 className="text-white text-lg font-semibold mb-3">Academic Profiles</h3>
                <ul className="space-y-2">
                  <li>
                    <a href="https://srlab.usask.ca/members/" target="_blank" rel="noopener noreferrer" className="hover:text-[#7ec8e3] flex items-center">
                      <svg className="w-4 h-4 mr-2" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                      </svg>
                      Software Research Lab
                    </a>
                  </li>
                  <li>
                    <a href="https://ise.usask.ca/team/" target="_blank" rel="noopener noreferrer" className="hover:text-[#7ec8e3] flex items-center">
                      <svg className="w-4 h-4 mr-2" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                      </svg>
                      Interactive Software Engineering Lab
                    </a>
                  </li>
                  <li>
                    <a href="https://scholar.google.com/citations?user=Vy_sw5UAAAAJ&hl=en" target="_blank" rel="noopener noreferrer" className="hover:text-[#7ec8e3] flex items-center">
                      <svg className="w-4 h-4 mr-2" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253" />
                      </svg>
                      Google Scholar
                    </a>
                  </li>
                </ul>
              </div>
              <div>
                <h3 className="text-white text-lg font-semibold mb-3">Recent Awards</h3>
                <ul className="space-y-2">
                  <li>
                    <a href="https://www.cs.usask.ca/news/2025/celebrating-excellence-computer-science-professor-and-graduate-students-receive-gsa-awards.php" target="_blank" rel="noopener noreferrer" className="hover:text-[#7ec8e3] flex items-center">
                      <svg className="w-4 h-4 mr-2" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.857L21 12l-5.714 2.143L13 21l-2.286-6.857L5 12l5.714-2.143L13 3z" />
                      </svg>
                      GSA Research Excellence Award
                    </a>
                  </li>
                  <li>
                    <a href="#highlights" className="hover:text-[#7ec8e3] flex items-center">
                      <svg className="w-4 h-4 mr-2" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.857L21 12l-5.714 2.143L13 21l-2.286-6.857L5 12l5.714-2.143L13 3z" />
                      </svg>
                      Best Thesis Award (MSc)
                    </a>
                  </li>
                  <li>
                    <a href="https://www.cs.usask.ca/news/2024/graduate-student-award-recipients.php" target="_blank" rel="noopener noreferrer" className="hover:text-[#7ec8e3] flex items-center">
                      <svg className="w-4 h-4 mr-2" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.857L21 12l-5.714 2.143L13 21l-2.286-6.857L5 12l5.714-2.143L13 3z" />
                      </svg>
                      CS Citizenship Award
                    </a>
                  </li>
                </ul>
              </div>
            </div>
            <div className="pt-6 border-t border-gray-800 flex flex-col md:flex-row justify-between items-center">
              <div>© 2026 Palash Ranjan Roy | All rights reserved</div>
              <div className="flex gap-4 mt-2 md:mt-0">
                <a href="https://github.com/Roy101" target="_blank" rel="noopener noreferrer" className="hover:text-[#7ec8e3]">GitHub</a>
                <a href="https://www.linkedin.com/in/palashranjanroy/" target="_blank" rel="noopener noreferrer" className="hover:text-[#7ec8e3]">LinkedIn</a>
                <a href="https://www.researchgate.net/profile/Palash_Roy" target="_blank" rel="noopener noreferrer" className="hover:text-[#7ec8e3]">ResearchGate</a>
              </div>
            </div>
          </footer>
        </div>
      </div>
    </div>
  );
}
