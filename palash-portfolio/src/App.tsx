import React, { useState, useEffect } from "react";

// Add console log to debug rendering
console.log("App component is being rendered");

const navLinks = [
  { label: "Home", href: "#home" },
  { label: "About", href: "#about" },
  // Education and Experience removed since they're integrated in the Biography section
  { label: "Skills", href: "#skills" },
  { label: "Awards", href: "#highlights" },
  { label: "Publications", href: "#portfolio" },
  { label: "Leadership", href: "#leadership" },
  { label: "Service", href: "#service" },
  { label: "Pictures", href: "#pictures" },
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

// Featured Highlights data - reorganized to show wins first, then nominations
const highlightsData: HighlightItem[] = [
  // Wins - 2025
  {
    title: "Research Excellence in Science, Technology, Engineering, or Math (2025)",
    organization: "Graduate Students Association, University of Saskatchewan",
    description: "Recognized for outstanding contributions to research in STEM fields at the university level.",
    link: "https://www.cs.usask.ca/news/2025/celebrating-excellence-computer-science-professor-and-graduate-students-receive-gsa-awards.php"
  },
  {
    title: "Best Thesis Award (MSc) (2025)",
    organization: "Department of Computer Science, University of Saskatchewan",
    description: "Awarded for exceptional research work during Master's studies in Computer Science."
  },
  
  // Wins - 2024
  {
    title: "SOAR Distinguished Research Award (2024)",
    organization: "NSERC Collaborative Research and Training (CREATE), Canada",
    description: "Recognized for distinguished research contributions within the NSERC collaborative framework."
  },
  {
    title: "75th Anniversary Scholarship (2024)",
    organization: "College of Graduate & Postdoctoral Studies (CGPS), University of Saskatchewan",
    description: "Received scholarship commemorating the 75th anniversary of the College of Graduate Studies."
  },
  {
    title: "CS Citizenship Award (M.Sc.) (2024)",
    organization: "Department of Computer Science, University of Saskatchewan",
    description: "Recognized for exemplary citizenship and community contributions within the Computer Science department.",
    link: "https://www.cs.usask.ca/news/2024/graduate-student-award-recipients.php"
  },
  {
    title: "Research Award (2024)",
    organization: "8th Symposium on Innovations in Computer Science and Applied Computing (ICSAC), University of Saskatchewan",
    description: "Awarded for innovative research presented at the ICSAC symposium."
  },
  
  // Wins - 2023
  {
    title: "People's Choice Best Paper Award (2023)",
    organization: "IWSC-2023 at Bogota, Colombia",
    description: "Paper on large language models and code clones voted as the best paper by attendees.",
    link: "https://news.usask.ca/articles/colleges/2023/usask-clones-researcher-wins-international-award.php"
  },
  {
    title: "Student Encouragement Award (2023)",
    organization: "IWSC-2023 at Bogota, Colombia",
    description: "Received encouragement award at the International Workshop on Software Clones in Bogota.",
    link: "https://news.usask.ca/articles/colleges/2023/usask-clones-researcher-wins-international-award.php"
  },
  {
    title: "People's Choice Best Presentation Award (2023)",
    organization: "SOAR Symposium at Saskatoon, Saskatchewan",
    description: "Presentation voted as the best by symposium attendees at the SOAR event in Saskatoon."
  },
  
  // Nominations - 2025
  {
    title: "Nominated for University of Saskatchewan Best Thesis Award (2025)",
    organization: "College of Graduate and Postdoctoral Studies, University of Saskatchewan",
    description: "Thesis nominated for the prestigious university-wide best thesis recognition."
  },
  {
    title: "Nominated for WAGS Distinguished MSc Thesis STEM (2025)",
    organization: "Western Association of Graduate School",
    description: "Thesis nominated for distinguished recognition among STEM disciplines across Western institutions."
  },
  
  // Nominations - 2024
  {
    title: "Nominee, CS Geddes Awards (M.Sc.) (2024)",
    organization: "Department of Computer Science, University of Saskatchewan",
    description: "Nominated for the prestigious Geddes Award for excellence in Computer Science."
  },
  {
    title: "Nominee, Carl McCrosky Innovation Scholarship (2024)",
    organization: "University of Saskatchewan",
    description: "Nominated for scholarship recognizing innovative approaches in Computer Science research."
  },
  {
    title: "Nominee, Mark Kroeker Exceptional Student Leadership Award (2024)",
    organization: "University of Saskatchewan",
    description: "Nominated for exceptional leadership within the student community."
  }
];

// Leadership roles data (renamed from volunteerWorkData)
const leadershipRolesData = [
  {
    period: "2024-2025",
    role: "President",
    organization: "Bangladeshi Students' Association",
    place: "University of Saskatchewan"
  },
  {
    period: "2023-2024",
    role: "Vice President Finance",
    organization: "Computer Science Graduate Council",
    place: "University of Saskatchewan"
  },
  {
    period: "2022-2023",
    role: "Vice President Social",
    organization: "Computer Science Graduate Council",
    place: "University of Saskatchewan"
  },
  {
    period: "2018-2021",
    role: "Assistant General Secretary",
    organization: "Robotics Club Of Brac University [ROBU]",
    place: "BRAC University"
  }
];

// Academic service data - now including invited lectures
const academicServiceData = [
  {
    period: "2022-Present",
    role: "Peer Reviewer",
    description: "Reviewed multiple Software Engineering conferences and Journal submissions",
    venues: "ICSME, ASE, SCAM, SANER, MSR, JSS, TSE, and TOSEM"
  },
  {
    period: "Spring-Summer 2024",
    role: "Research Supervision",
    description: "Supervised one undergraduate research student who won two undergraduate research excellence awards at ICSAC-24 and SOAR Symposium 2024",
    venue: "Software Research lab & Interactive Software Engineering Lab, University of Saskatchewan"
  },
  {
    period: "Spring-Summer 2023",
    role: "Research Supervision",
    description: "Supervised six undergraduate research students",
    venue: "Software Research lab & Interactive Software Engineering Lab, University of Saskatchewan"
  },
  {
    period: "2025",
    role: "Invited Speaker",
    description: "Large language models and Code Similarity",
    venue: "Thompson Rivers University"
  },
  {
    period: "2024",
    role: "Invited Speaker",
    description: "AI for Software Engineering",
    venue: "University of Saskatchewan"
  },
  {
    period: "2024",
    role: "Invited Speaker",
    description: "The Effects of Visual Embellishment on Comprehension and Memorability of Charts",
    venue: "University of Saskatchewan"
  },
  {
    period: "2023",
    role: "Invited Speaker",
    description: "Software Engineering in Practice",
    venue: "BRAC University"
  },
  {
    period: "2022",
    role: "Invited Speaker",
    description: "How to start building your first Robot?",
    venue: "BRAC University"
  }
];

// Publications data - Palash Ranjan Roy's publications
const publicationsData = [
  {
    title: "Are large language models a threat to programming platforms? an exploratory study",
    authors: "M. M. Billah, P. R. Roy, Z. Codabux, and B. Roy",
    venue: "Proceedings of the 18th ACM/IEEE International Symposium on Empirical Software Engineering and Measurement",
    year: "2024",
    pages: "pp. 292–301",
    doi: "10.1145/3674805.3686689",
    description: "This study aims to assess LLMs' capability to solve diverse programming challenges across programming platforms with varying difficulty levels, comparing them to human programmers and identifying potential threats to established norms in programming platforms.",
    preprint: "/papers/Are_large_language.pdf"
  },
  {
    title: "Gptclonebench: A comprehensive benchmark of semantic clones and cross-language clones using gpt-3 model and semanticclonebench",
    authors: "A. I. Alam, P. R. Roy, F. Al-Omari, C. K. Roy, B. Roy, and K. A. Schneider",
    venue: "2023 IEEE International Conference on Software Maintenance and Evolution (ICSME)",
    year: "2023",
    pages: "pp. 1–13",
    doi: "10.1109/ICSME58846.2023.00013",
    description: "Presents a benchmark for evaluating semantic code clones and cross-language clones generated using GPT-3.",
    preprint: "/papers/gptclonebench.pdf"
  },
  {
    title: "Unveiling the potential of large language models in generating semantic and cross-language clones",
    authors: "P. R. Roy, A. I. Alam, F. Al-omari, B. Roy, C. K. Roy, and K. A. Schneider",
    venue: "2023 IEEE 17th International Workshop on Software Clones (IWSC)",
    year: "2023",
    pages: "pp. 22–28",
    doi: "10.1109/IWSC60764.2023.00011",
    description: "Explores how large language models can generate semantic code clones and cross-language clones, analyzing their potential applications and implications.",
    preprint: "/papers/unveling_potential.pdf"
  },
  {
    title: "A study on paper and author ranking",
    authors: "P. R. Roy, M. N. Islam, L. T. Jeba, et al.",
    venue: "2022 International Conference on Innovations in Science, Engineering and Technology (ICISET)",
    year: "2022",
    pages: "pp. 545–549",
    doi: "10.1109/ICISET54810.2022.9775821",
    description: "Examines methodologies for ranking academic papers and authors, analyzing citation patterns and impact metrics in scientific literature.",
    preprint: "/papers/A_Study_on_Paper_and_Author_Ranking.pdf"
  }
];

// Pictures data - add more items here to have more carousel slides
const picturesData = [
  {
    image: "/images/Pic_section/Palash-Roy_Plaza de Bolívar.jpg",
    title: "Plaza de Bolívar, Colombia",
    description: "Exploring the historic heart of Bogotá at Plaza de Bolívar during my research visit for ICSME-2023.",
    altText: "Palash Ranjan Roy (Palash Roy) at Plaza de Bolívar in Bogotá during ICSME-2023 research conference"
  },
  {
    image: "/images/Pic_section/Palash-Justin_Santa Fe, Bogotá, Chocó.jpg",
    title: "Santa Fe, Bogotá, Chocó",
    description: "Making connections with fellow researchers in beautiful Colombia during our conference travel.",
    altText: "Palash Roy with Justin in Santa Fe, Bogotá, Chocó - Computer Science researchers at international conference"
  },
  {
    image: "/images/Pic_section/Palash_Group_Chico_Norte.jpg",
    title: "Group Photo at Chico Norte",
    description: "Exploring Chico Norte fellow friends of mine from all over the world.",
    altText: "Palash Ranjan Roy with international research colleagues at Chico Norte in Colombia"
  },
  {
    image: "/images/Pic_section/Palash-Group_Parque_Nacional_Oriental.jpg",
    title: "Parque Nacional Oriental",
    description: "Taking a break from academic discussions to enjoy Colombia's natural beauty at Parque Nacional Oriental.",
    altText: "Palash Roy with research colleagues at Parque Nacional Oriental in Colombia during academic conference"
  },
  {
    image: "/images/Pic_section/Palash-Cousins_Banff_Gondola.jpg",
    title: "Banff Gondola with Cousins",
    description: "Enjoying the breathtaking views of the Canadian Rockies with family at the Banff Gondola.",
    altText: "PhD student Palash Ranjan Roy with cousins at Banff Gondola in the Canadian Rockies"
  },
  {
    image: "/images/Pic_section/Palash-Cousins2_banff.jpg",
    title: "Banff National Park",
    description: "Another memorable moment with cousins exploring the natural wonders of Banff National Park.",
    altText: "Palash Roy and cousins visiting Banff National Park in Alberta, Canada"
  },
  {
    image: "/images/Pic_section/Palash-Family_Sulphur_Mountain.jpg",
    title: "Family at Sulphur Mountain",
    description: "A family adventure scaling the heights of Sulphur Mountain with its spectacular panoramic views.",
    altText: "Computer Science researcher Palash Ranjan Roy with family at Sulphur Mountain in Banff, Canada"
  },
  {
    image: "/images/Pic_section/Palash-Mugdho(Cousin)_Montauk_Point_State_Park.jpg",
    title: "Montauk Point State Park",
    description: "An enjoyable day with my cousin Mugdho at Montauk Point State Park, taking in the coastal scenery.",
    altText: "Palash Roy and cousin Mugdho at Montauk Point State Park in New York"
  },
  {
    image: "/images/Pic_section/Palash-Roy_West_Village_NY(Friends_Building).jpg",
    title: "West Village, New York",
    description: "Couldn't resist visiting the iconic 'Friends' building during my trip to West Village, New York!",
    altText: "Palash Ranjan Roy visiting the Friends TV show building in West Village, New York City"
  },
];

// References data - updated with all references and their images
const referencesData = [
  {
    name: "Rezwana Mahfuza",
    title: "PhD Student, Dalhousie University",
    image: "/images/rezwana.png",
    text: "Palash was an integral part of my study environment when I first started university at Brac University. Palash spent time with me not just to assist me with day-to-day tasks, but also to encourage me with many academic endeavors over the years. He inspires his team, provides important input, and works for everyone to achieve better as a groupmate."
  },
  {
    name: "Farishta Kabir",
    title: "Graduate Research and Teaching Assistant, University of Alberta",
    image: "/images/farishta.png",
    text: "I've had the pleasure of working with Palash during a few undergraduate courses and in the Robotics Club at BRAC University as a colleague. He always stood-out for his focused problem-solving approach and leadership in teamwork. Palash combines his expertise with an organizational mindset, making him a productive addition to any research project or team. Wishing him all the best for his future endeavours."
  },
  {
    name: "Fahmida Nipa",
    title: "PS & CS Core Network, Cyber Security, Datacom",
    image: "/images/Nipa.png",
    text: "Throughout my Robotics Club journey, I have seen Palash to be very enthusiastic about robotics. Not to mention the leadership and managerial quality I got to experience while working with him was quite mentionable. I believe he will do his best wherever he set his foot, as his dignity towards his responsibilities resembles how much effort he can demonstrate. He is very adaptive and can communicate fluently with anyone when working in teams. I personally teamed up with him during several events organized by the club. those works were success and naturally outstanding as he pulled out the best from the team."
  },
  {
    name: "Syeda Umme Salma",
    title: "Graduate Research Assistant, PhD Student",
    image: "/images/omi.png",
    text: "Palash is one of the greatest guys I've ever had as a collaborator, and we've worked together on multiple projects. I would strongly suggest his services to anyone in need of an software engineer. He is one of the wisest persons I have ever encountered, and his ability to solve any situation with a pleasant gesture is astonishing. Palash would be a valuable addition to any team."
  },
  {
    name: "Md. Noushin Islam",
    title: "Web Developer, Frontend Developer, MSc in Software Engineering",
    image: "/images/saad.png",
    text: "I was impressed by Palash's ability to handle any situation calmly and patiently, even in the toughest situation. His natural skill has helped us succeed in many events throughout my journey at ROBU."
  }
];

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
}

const Carousel = <T extends unknown>({ items, renderItem, itemsPerSlide = 3 }: CarouselProps<T>) => {
  const [currentIndex, setCurrentIndex] = useState(0);
  const [width, setWidth] = useState(typeof window !== "undefined" ? window.innerWidth : 1200);
  const [actualItemsPerSlide, setActualItemsPerSlide] = useState(itemsPerSlide);
  const [isLoading, setIsLoading] = useState(true);
  const [loadedImages, setLoadedImages] = useState<Set<number>>(new Set([0])); // Track loaded slide indexes

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
          <div className="absolute right-0 top-1/3 w-1/2 h-1/2 bg-gradient-to-l from-[#5a0029] opacity-10 blur-3xl rounded-full"></div>
          <div className="absolute left-20 bottom-20 w-96 h-96 bg-[#120338] opacity-10 blur-3xl rounded-full"></div>
        </div>
        
        <div className="container mx-auto px-4 sm:px-6 lg:px-8 py-20 flex flex-col md:flex-row justify-between items-center relative z-1">
          <div className="flex-1 max-w-2xl">
            <h2 className="text-xl font-light uppercase tracking-wider text-gray-400 mb-2">INTRODUCTION</h2>
            <h1 className="text-6xl md:text-7xl font-bold mb-6">
              I'm Palash, a<br />
              <span className="bg-gradient-to-r from-[#35c7ff] to-[#ff4081] bg-clip-text text-transparent">Computer Science</span><br />
              <span className="text-[#ff4081]">PhD Student</span>
            </h1>
            <p className="text-lg text-[#a9c0d4] mb-8 max-w-xl">
              I am a Software Engineering Researcher at <a href="https://srlab.usask.ca/members/" target="_blank" rel="noopener noreferrer" className="text-[#7ec8e3] hover:underline">Software Analytics Lab (SRLab)</a> & <a href="https://ise.usask.ca/team/" target="_blank" rel="noopener noreferrer" className="text-[#7ec8e3] hover:underline">Interactive Software Engineering Lab (ISELab)</a> specializing in <strong>code clone detection</strong>, <strong>refactoring</strong>, and <strong>large language models</strong>. Currently I am working under the supervision of Dr. Kevin Schneider.
            </p>
            
            <div className="flex gap-8 mb-8">
              <div className="text-center">
                <div className="text-3xl font-bold text-[#7ec8e3]">3+</div>
                <div className="text-xs uppercase tracking-wider">Years Experience</div>
              </div>
              <div className="text-center">
                <div className="text-3xl font-bold text-[#7ec8e3]">10+</div>
                <div className="text-xs uppercase tracking-wider">Projects Completed</div>
              </div>
              <div className="text-center">
                <div className="text-3xl font-bold text-[#7ec8e3]">5+</div>
                <div className="text-xs uppercase tracking-wider">Awards/Accolades</div>
              </div>
            </div>
            
            <a href="#about" className="inline-flex items-center gap-2 text-sm font-medium uppercase tracking-wider text-[#35c7ff] hover:text-[#ff4081] transition-colors">
              Browse Experience
              <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
                <path fillRule="evenodd" d="M8 1a.5.5 0 0 1 .5.5v11.793l3.146-3.147a.5.5 0 0 1 .708.708l-4 4a.5.5 0 0 1-.708 0l-4-4a.5.5 0 0 1 .708-.708L7.5 13.293V1.5A.5.5 0 0 1 8 1z"/>
              </svg>
            </a>
          </div>
          
          <div className="w-64 h-64 md:w-96 md:h-96 rounded-xl overflow-hidden shadow-[0_0_50px_rgba(122,205,235,0.15)] mt-10 md:mt-0 md:ml-14 border-4 border-[#2d324b] bg-[#171830] flex items-center justify-center relative">
            {/* Profile photo with improved alt text for SEO */}
            <img 
              src="/images/AnotherDP.png" 
              alt="Palash Ranjan Roy (Palash Roy) - Computer Science PhD Student at University of Saskatchewan specializing in AI and Software Engineering" 
              className="w-full h-full object-cover"
            />
            <div className="absolute bottom-0 inset-x-0 bg-black bg-opacity-70 text-xs p-2 text-center">
              This isn't serious, but I think it is hilarious. If this was serious, I would be ready to change the world.
            </div>
          </div>
        </div>
      </header>
      
      {/* Content Container - Removed gradient background to use the global background */}
      <div className="relative z-10">
        {/* About Section with centered content - Updated to match reference style */}
        <div className="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">
          <section id="about" className="pt-20 pb-16">
            <h2 className="text-3xl font-bold mb-8 border-b border-[#2d324b] pb-3">Biography</h2>
            
            <div className="flex flex-col md:flex-row gap-10">
              {/* Left column with profile image and social links */}
              <div className="md:w-1/3 flex flex-col items-center">
                <div className="rounded-full overflow-hidden border-4 border-[#2d324b] bg-[#233343] w-64 h-64">
                  <img 
                    src="/images/MyDP.png" 
                    alt="Palash Roy - AI Researcher and Computer Science PhD Student at University of Saskatchewan" 
                    className="w-full h-full object-cover"
                  />
                </div>
                
                {/* Social Media Links - your actual links */}
                <div className="flex justify-center gap-4 mt-6">
                  <a href="https://www.facebook.com/pial.roy.3705/" target="_blank" rel="noopener noreferrer" 
                    className="w-10 h-10 rounded-full bg-[#1877F2] flex items-center justify-center text-white hover:bg-opacity-80 transition-all">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="currentColor" viewBox="0 0 16 16">
                      <path d="M16 8.049c0-4.446-3.582-8.05-8-8.05C3.58 0-.002 3.603-.002 8.05c0 4.017 2.926 7.347 6.75 7.951v-5.625h-2.03V8.05H6.75V6.275c0-2.017 1.195-3.131 3.022-3.131.876 0 1.791.157 1.791.157v1.98h-1.009c-.993 0-1.303.621-1.303 1.258v1.51h2.218l-.354 2.326H9.25V16c3.824-.604 6.75-3.934 6.75-7.951z"/>
                    </svg>
                  </a>
                  <a href="https://www.instagram.com/checkoutpial/?hl=en" target="_blank" rel="noopener noreferrer" 
                    className="w-10 h-10 rounded-full bg-[#E4405F] flex items-center justify-center text-white hover:bg-opacity-80 transition-all">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="currentColor" viewBox="0 0 16 16">
                      <path d="M8 0C5.829 0 5.556.01 4.703.048 3.85.088 3.269.222 2.76.42a3.917 3.917 0 0 0-1.417.923A3.927 3.927 0 0 0 .42 2.76C.222 3.268.087 3.85.048 4.7.01 5.555 0 5.827 0 8.001c0 2.172.01 2.444.048 3.297.04.852.174 1.433.372 1.942.205.526.478.972.923 1.417.444.445.89.719 1.416.923.51.198 1.09.333 1.942.372C5.555 15.99 5.827 16 8 16s2.444-.01 3.298-.048c.78-.035 1.204-.166 1.486-.275.373-.145.64-.319.92-.599.28-.28.453-.546.598-.92.11-.281.24-.705.275-1.485.039.843.047 1.096.047 3.231s-.008 2.389-.047 3.232c-.035.78-.166 1.203-.275 1.485a2.47 2.47 0 0 1-.599.919c-.28.28-.546.453-.92.598-.28.11-.704.24-1.485.276-.843.038-1.096.047-3.232.047s-2.39-.009-3.233-.047c-.78-.036-1.203-.166-1.485-.276a2.478 2.478 0 0 1-.92-.598 2.48 2.48 0 0 1-.6-.92c-.109-.281-.24-.705-.275-1.485-.038-.843-.046-1.096-.046-3.233 0-2.136.008-2.388.046-3.231.036-.78.166-1.204.276-1.486.145-.373.319-.64.599-.92.28-.28.546-.453.92-.598.282-.11.705-.24 1.485-.276.738-.034 1.024-.044 2.515-.045v.002zm4.988 1.328a.96.96 0 1 0 0 1.92.96.96 0 0 0 0-1.92zm-4.27 1.122a4.109 4.109 0 1 0 0 8.217 4.109 4.109 0 0 0 0-8.217zm0 1.441a2.667 2.667 0 1 1 0 5.334 2.667 2.667 0 0 1 0-5.334z"/>
                    </svg>
                  </a>
                  <a href="https://www.linkedin.com/in/palash-ranjan-roy-b3578918b" target="_blank" rel="noopener noreferrer" 
                    className="w-10 h-10 rounded-full bg-[#0A66C2] flex items-center justify-center text-white hover:bg-opacity-80 transition-all">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="currentColor" viewBox="0 0 16 16">
                      <path d="M0 1.146C0 .513.526 0 1.175 0h13.65C15.474 0 16 .513 16 1.146v13.708c0 .633-.526 1.146-1.175 1.146H1.175C.526 16 0 15.487 0 14.854V1.146zm4.943 12.248V6.169H2.542v7.225h2.401zm-1.2-8.212c.837 0 1.358-.554 1.358-1.248-.015-.709-.52-1.248-1.342-1.248-.822 0-1.359.54-1.359 1.248 0 .694.521 1.248 1.327 1.248h.016zm4.908 8.212V9.359c0-.216.016-.432.08-.586.173-.431.568-.878 1.232-.878.869 0 1.216.662 1.216 1.634v3.865h2.401V9.25c0-2.22-1.184-3.252-2.764-3.252-1.274 0-1.845.7-2.165 1.193v.025h-.016a5.54 5.54 0 0 1 .016-.025V6.169h-2.4c.03.678 0 7.225 0 7.225h2.4z"/>
                    </svg>
                  </a>
                  <a href="https://github.com/Roy101" target="_blank" rel="noopener noreferrer" 
                    className="w-10 h-10 rounded-full bg-[#333] flex items-center justify-center text-white hover:bg-opacity-80 transition-all">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="currentColor" viewBox="0 0 16 16">
                      <path d="M8 0C3.58 0 0 3.58 0 8c0 3.54 2.29 6.53 5.47 7.59.4.07.55-.17.55-.38 0-.19-.01-.82-.01-1.49-2.01.37-2.53-.49-2.69-.94-.09-.23-.48-.94-.82-1.13-.28-.15-.68-.52-.01-.53.63-.01 1.08.58 1.23.82.72 1.21 1.87.87 2.33.66.07-.52.28-.87.51-1.07-1.78-.2-3.64-.89-3.64-3.95 0-.87.31-1.59.82-2.15-.08-.2-.36-1.02.08-2.12 0 0 .67-.21 2.2.82.64-.18 1.32-.27 2-.27.68 0 1.36.09 2 .27 1.53-1.04 2.2-.82 2.2-.82.44 1.1.16 1.92.08 2.12.51.56.82 1.27.82 2.15 0 3.07-1.87 3.75-3.65 3.95.29.25.54.73.54 1.48 0 1.07-.01 1.93-.01 2.2 0 .21.15.46.55.38A8.012 8.012 0 0 0 16 8c0-4.42-3.58-8-8-8z"/>
                    </svg>
                  </a>
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
                    <strong>Palash Ranjan Roy</strong> (also known as <strong>Palash Roy</strong> in academic publications) is a PhD Student in Computer Science at the University of Saskatchewan, Canada. His research focuses on <strong>code clone detection</strong>, <strong>refactoring methodologies</strong>, and <strong>large language model applications in software engineering</strong>.
                  </p>
                  
                  <p>
                    With publications in renowned venues like ICSME, ESEM, IWSC etc, Palash has received multiple awards including the Research Excellence Award. Originally from Bangladesh, <strong>Palash</strong> completed his undergraduate studies at BRAC University before moving to Canada to pursue graduate studies.
                  </p>
                  
                  <p>
                    He is affiliated with both the <a href="https://srlab.usask.ca/members/" className="text-[#7ec8e3] hover:underline">Software Research Lab</a> and <a href="https://ise.usask.ca/team/" className="text-[#7ec8e3] hover:underline">Interactive Software Engineering Lab</a>, where he explores the intersection of AI and software development to create more efficient and reliable coding tools.
                  </p>
                  
                  <p>
                    Throughout his academic journey, Palash has demonstrated exceptional aptitude in software engineering research, particularly in applying AI techniques to solve complex coding problems. His work on identifying code clones using large language models has been recognized internationally, reflecting his commitment to advancing the field.
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
                  <div className="space-y-6">
                    <div>
                      <h4 className="font-bold">Graduate Teaching Assistant</h4>
                      <div className="text-[#a2a5b9] text-sm">University of Saskatchewan | 2022 - Present</div>
                      <p className="text-[#d0cccc] text-sm mt-1">Instructed undergraduate students in data structures, programming, and practical computing labs.</p>
                    </div>
                    
                    <div>
                      <h4 className="font-bold">Research Technician</h4>
                      <div className="text-[#a2a5b9] text-sm">iSE & SR Lab, University of Saskatchewan | May 2022 - Aug 2022</div>
                      <p className="text-[#d0cccc] text-sm mt-1">Supporting web based systems, Maintaining CFI equipments, Supporting SOAR CREATE Program.</p>
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

          {/* Skills Section with centered content - updated to match the reference design */}
          <section id="skills" className="pt-10 pb-20">
            <div className="text-center mb-12">
              <div className="inline-block px-4 py-2 bg-[#1c1c24] rounded-lg text-[#7ec8e3] text-sm font-semibold mb-3">
                Competencies
              </div>
              <h2 className="text-4xl font-bold mb-6">Technical Competencies</h2>
              
              <div className="max-w-3xl mx-auto text-[#d0cccc] space-y-4">
                <p>
                  These are technical skills that I have acquired thus far in my computer science career.
                </p>
                <p>
                  I am not claiming to be a master in all of these skills, that would be amazing, but it is simply not true.
                </p>
                <p>
                  With that said, I am confident that I can hold a position that utilizes any of the following competencies and increase my mastery in any of these skills if needed.
                </p>
              </div>
            </div>
            
            <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8 mt-12 max-w-6xl mx-auto">
              <div className="bg-[#181a22] p-8 rounded-lg border border-[#2d324b] hover:border-[#7ec8e3] transition-colors">
                <div className="flex items-center mb-4">
                  <div className="w-12 h-12 bg-[#2a3b4d] rounded-full flex items-center justify-center mr-4">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="#7ec8e3" viewBox="0 0 16 16">
                      <path d="M2.5 3.5a.5.5 0 0 1 0-1h11a.5.5 0 0 1 0 1h-11zm2-2a.5.5 0 0 1 0-1h7a.5.5 0 0 1 0 1h-7zM0 13a1.5 1.5 0 0 0 1.5 1.5h13A1.5 1.5 0 0 0 16 13V6a1.5 1.5 0 0 0-1.5-1.5h-13A1.5 1.5 0 0 0 0 6v7zm1.5.5A.5.5 0 0 1 1 13V6a.5.5 0 0 1 .5-.5h13a.5.5 0 0 1 .5.5v7a.5.5 0 0 1-.5.5h-13z"/>
                    </svg>
                  </div>
                  <h4 className="text-xl font-semibold">Research Domains</h4>
                </div>
                <ul className="text-[#d0cccc] space-y-1 pl-4">
                  <li>Code Clone Detection, Program Analysis</li>
                  <li>Code Intelligence, Code Summarization</li>
                  <li>Code Translation, Semantic Similarity</li>
                  <li>Source Code Embedding, Software Quality</li>
                  <li>LLMs for Code, Software Mining</li>
                </ul>
              </div>
              
              <div className="bg-[#181a22] p-8 rounded-lg border border-[#2d324b] hover:border-[#7ec8e3] transition-colors">
                <div className="flex items-center mb-4">
                  <div className="w-12 h-12 bg-[#2a3b4d] rounded-full flex items-center justify-center mr-4">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="#7ec8e3" viewBox="0 0 16 16">
                      <path d="M14 1a1 1 0 0 1 1 1v12a1 1 0 0 1-1 1H2a1 1 0 0 1-1-1V2a1 1 0 0 1 1-1h12zM2 0a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V2a2 2 0 0 0-2-2H2z"/>
                      <path d="M6.854 4.646a.5.5 0 0 1 0 .708L4.207 8l2.647 2.646a.5.5 0 0 1-.708.708l-3-3a.5.5 0 0 1 0-.708l3-3a.5.5 0 0 1 .708 0zm2.292 0a.5.5 0 0 0 0 .708L11.793 8l-2.647 2.646a.5.5 0 0 0 .708.708l3-3a.5.5 0 0 0 0-.708l-3-3a.5.5 0 0 0-.708 0z"/>
                    </svg>
                  </div>
                  <h4 className="text-xl font-semibold">Machine Learning & NLP</h4>
                </div>
                <ul className="text-[#d0cccc] space-y-1 pl-4">
                  <li>Transformer Models, CodeBERT</li>
                  <li>GraphCodeBERT, Codex, StarCoder, GPT</li>
                  <li>BERT, Embeddings, Fine-Tuning</li>
                  <li>Few-Shot Learning, Attention Mechanisms</li>
                  <li>Prompt Engineering, AI AGENT, Tokenization</li>
                  <li>BLEU, ROUGE, CodeBLEU</li>
                </ul>
              </div>
              
              <div className="bg-[#181a22] p-8 rounded-lg border border-[#2d324b] hover:border-[#7ec8e3] transition-colors">
                <div className="flex items-center mb-4">
                  <div className="w-12 h-12 bg-[#2a3b4d] rounded-full flex items-center justify-center mr-4">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="#7ec8e3" viewBox="0 0 16 16">
                      <path d="M8 15A7 7 0 1 1 8 1a7 7 0 0 1 0 14zm0 1A8 8 0 1 0 8 0a8 8 0 0 0 0 16z"/>
                      <path d="M7.5 7.793l2.646-2.647a.5.5 0 0 1 .708.708L8.207 8.5l2.647 2.646a.5.5 0 0 1-.708.708L7.5 9.207l-2.646 2.647a.5.5 0 0 1-.708-.708L6.793 8.5 4.146 5.854a.5.5 0 0 1 .708-.708L7.5 7.793z"/>
                    </svg>
                  </div>
                  <h4 className="text-xl font-semibold">Tools & Frameworks</h4>
                </div>
                <ul className="text-[#d0cccc] space-y-1 pl-4">
                  <li>PyTorch, TensorFlow</li>
                  <li>Hugging Face Transformers</li>
                  <li>Scikit-learn, NumPy, Pandas</li>
                  <li>Matplotlib, Tree-sitter, ANTLR</li>
                  <li>Javalang, Gensim, OpenAI API</li>
                </ul>
              </div>
              
              <div className="bg-[#181a22] p-8 rounded-lg border border-[#2d324b] hover:border-[#7ec8e3] transition-colors">
                <div className="flex items-center mb-4">
                  <div className="w-12 h-12 bg-[#2a3b4d] rounded-full flex items-center justify-center mr-4">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="#7ec8e3" viewBox="0 0 16 16">
                      <path d="M8 4.754a3.246 3.246 0 1 0 0 6.492 3.246 3.246 0 0 0 0-6.492zM5.754 8a2.246 2.246 0 1 1 4.492 0 2.246 2.246 0 0 1-4.492 0z"/>
                      <path d="M9.796 1.343c-.527-1.79-3.065-1.79-3.592 0l-.094.319a.873.873 0 0 1-1.255.52l-.292-.16c-1.64-.892-3.433.902-2.54 2.541l.159.292a.873.873 0 0 1-.52 1.255l-.319.094c-1.79.527-1.79 3.065 0 3.592l.319.094a.873.873 0 0 1 .52 1.255l-.16.292c-.892 1.64.901 3.434 2.541 2.54l.292-.159a.873.873 0 0 1 1.255.52l.094.319c.527 1.79 3.065 1.79 3.592 0l.094-.319a.873.873 0 0 1 1.255-.52l.292.16c1.64.893 3.434-.902 2.54-2.541l-.159-.292a.873.873 0 0 1 .52-1.255l.34-.1a.873.873 0 0 1 .52-1.255l.16-.292c.893-1.64-.902-3.433-2.541-2.54l-.292.159a.873.873 0 0 1-1.255-.52l-.1-.34zm-2.633.283c.246-.835 1.428-.835 1.674 0l.094.319a1.873 1.873 0 0 0 2.693 1.115l.291-.16c.764-.415 1.6.42 1.184 1.185l-.159.292a1.873 1.873 0 0 0 1.116 2.692l.318.094c.835.246.835 1.428 0 1.674l-.319.094a1.873 1.873 0 0 0-1.115 2.693l.16.291c.415.764-.42 1.6-1.185 1.184l-.291-.159a1.873 1.873 0 0 0-2.693 1.116l-.094.318c-.246.835-1.428.835-1.674 0l-.094-.319a1.873 1.873 0 0 0-2.692-1.115l-.292.16c-.764.415-1.6-.42-1.184-1.185l.159-.291A1.873 1.873 0 0 0 1.945 8.93l-.319-.094c-.835-.246-.835-1.428 0-1.674l.319-.094A1.873 1.873 0 0 0 3.06 4.377l-.16-.292c-.415-.764.42-1.6 1.185-1.184l.292.159a1.873 1.873 0 0 0 2.692-1.115l.094-.319z"/>
                    </svg>
                  </div>
                  <h4 className="text-xl font-semibold">Clone Detection & Code Analysis</h4>
                </div>
                <ul className="text-[#d0cccc] space-y-1 pl-4">
                  <li>SourcererCC, NiCad, Deckard</li>
                  <li>CloneWorks, ASTs</li>
                  <li>Control Flow Graphs (CFG)</li>
                  <li>Data Flow Analysis</li>
                  <li>Static & Dynamic Analysis, Code Metrics</li>
                </ul>
              </div>
              
              <div className="bg-[#181a22] p-8 rounded-lg border border-[#2d324b] hover:border-[#7ec8e3] transition-colors">
                <div className="flex items-center mb-4">
                  <div className="w-12 h-12 bg-[#2a3b4d] rounded-full flex items-center justify-center mr-4">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="#7ec8e3" viewBox="0 0 16 16">
                      <path d="M0 8a8 8 0 1 1 16 0A8 8 0 0 1 0 8zm7.5-6.923c-.67.204-1.335.82-1.887 1.855-.143.268-.276.56-.395.872.705.157 1.472.257 2.282.287V1.077zM4.249 3.539c.142-.384.304-.744.481-1.078a6.7 6.7 0 0 1 .597-.933A7.01 7.01 0 0 0 3.051 3.05c.362.184.763.349 1.198.49zM3.509 7.5c.036-1.07.188-2.087.436-3.008a9.124 9.124 0 0 1-1.565-.667A6.964 6.964 0 0 0 1.018 7.5h2.49zm1.4-2.741a12.344 12.344 0 0 0-.4 2.741H7.5V5.091c-.91-.03-1.783-.145-2.591-.332zM8.5 5.09V7.5h2.99a12.342 12.342 0 0 0-.399-2.741c-.808.187-1.681.301-2.591.332zM4.51 8.5c.035.987.176 1.914.399 2.741A13.612 13.612 0 0 1 7.5 10.91V8.5H4.51zm3.99 0v2.409c.91.03 1.783.145 2.591.332.223-.827.364-1.754.4-2.741H8.5zm-3.282 3.696c.12.312.252.604.395.872.552 1.035 1.218 1.65 1.887 1.855V11.91c-.81.03-1.577.13-2.282.287zm.11 2.276a6.696 6.696 0 0 1-.598-.933 8.853 8.853 0 0 1-.481-1.079 8.38 8.38 0 0 0-1.198.49 7.01 7.01 0 0 0 2.276 1.522zm-1.383-2.964A13.36 13.36 0 0 1 3.508 8.5h-2.49a6.963 6.963 0 0 0 1.362 3.675c.47-.258.995-.482 1.565-.667zm6.728 2.964a7.009 7.009 0 0 0 2.275-1.521 8.376 8.376 0 0 0-1.197-.49 8.853 8.853 0 0 1-.481 1.078 6.688 6.688 0 0 1-.597.933zM8.5 11.909v3.014c.67-.204 1.335-.82 1.887-1.855.143-.268.276-.56.395-.872A12.63 12.63 0 0 0 8.5 11.91zm3.555-.401c.57.185 1.095.409 1.565.667A6.963 6.963 0 0 0 14.982 8.5h-2.49a13.36 13.36 0 0 1-.437 3.008zM14.982 7.5a6.963 6.963 0 0 0-1.362-3.675c-.47.258-.995.482-1.565.667.248.92.4 1.938.437 3.008h2.49zM11.27 2.461c.177.334.339.694.482 1.078a8.368 8.368 0 0 0 1.196-.49 7.01 7.01 0 0 0-2.275-1.52c.218.283.418.597.597.932zm-.488 1.343a7.765 7.765 0 0 0-.395-.872C9.835 1.897 9.17 1.282 8.5 1.077V4.09c.81-.03 1.577-.13 2.282-.287z"/>
                    </svg>
                  </div>
                  <h4 className="text-xl font-semibold">Data & Experimentation</h4>
                </div>
                <ul className="text-[#d0cccc] space-y-1 pl-4">
                  <li>BigCloneBench, CodeSearchNet</li>
                  <li>POJ-104, HumanEval</li>
                  <li>Jupyter, Git, Docker, Bash</li>
                  <li>LaTeX, Statistical Testing</li>
                  <li>Reproducibility, Experimental Design</li>
                </ul>
              </div>
              
              <div className="bg-[#181a22] p-8 rounded-lg border border-[#2d324b] hover:border-[#7ec8e3] transition-colors">
                <div className="flex items-center mb-4">
                  <div className="w-12 h-12 bg-[#2a3b4d] rounded-full flex items-center justify-center mr-4">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="#7ec8e3" viewBox="0 0 16 16">
                      <path d="M9.405 1.05c-.413-1.4-2.397-1.4-2.81 0l-.1.34a1.464 1.464 0 0 1-2.105.872l-.31-.17c-1.283-.698-2.686.705-1.987 1.987l.169.311c.446.82.023 1.841-.872 2.105l-.34.1c-1.4.413-1.4 2.397 0 2.81l.34.1a1.464 1.464 0 0 1 .872 2.105l-.17.31c-.698 1.283.705 2.686 1.987 1.987l.311-.169a1.464 1.464 0 0 1 2.105.872l.1.34c.413 1.4 2.397 1.4 2.81 0l.1-.34a1.464 1.464 0 0 1 2.105-.872l.31.17c1.283.698 2.686-.705 1.987-1.987l-.169-.311a1.464 1.464 0 0 1 .872-2.105l.34-.1c1.4-.413 1.4-2.397 0-2.81l-.34-.1a1.464 1.464 0 0 1-.872-2.105l.17-.31c.698-1.283-.705-2.686-1.987-1.987l-.311.169a1.464 1.464 0 0 1-2.105-.872l-.1-.34zM8 10.93a2.929 2.929 0 1 1 0-5.86 2.929 2.929 0 0 1 0 5.858z"/>
                    </svg>
                  </div>
                  <h4 className="text-xl font-semibold">Programming Languages</h4>
                </div>
                <ul className="text-[#d0cccc] space-y-1 pl-4">
                  <li>Python, Java, JavaScript</li>
                  <li>C++, Bash, HTML, CSS</li>
                  <li>SQL, JSON, YAML</li>
                </ul>
              </div>
            </div>
          </section>

          {/* Highlights Section with Carousel and centered content - Redesigned to match reference */}
          <section id="highlights" className="pt-20 pb-16">
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
            
            {/* Carousel with highlights items */}
            <Carousel
              items={highlightsData}
              renderItem={(item) => (
                <div className="bg-[#181a22] h-full p-6 rounded-lg flex flex-col">
                  {/* Display image at the top if available */}
                  {item.image && (
                    <div className="mb-4">
                      <img
                        src={item.image}
                        alt={item.title}
                        className="w-full h-48 object-cover rounded"
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
              )}
            />
          </section>

          {/* Publications Section with Carousel and centered content */}
          <section id="portfolio" className="pt-2 pb-12">
            <div className="text-center mb-8">
              <div className="inline-block px-3 py-1 bg-[#181a22] text-[#7ec8e3] text-sm font-medium mb-2 rounded">
                Publications
              </div>
              <h2 className="text-4xl font-bold mb-4">My Latest Publications</h2>
              <p className="text-[#d0cccc] text-lg max-w-3xl mx-auto">
                See my <a href="https://scholar.google.com/citations?user=Vy_sw5UAAAAJ&hl=en" target="_blank" rel="noopener noreferrer" className="text-[#7ec8e3] hover:underline">Google Scholar</a> for actual details on the following projects. This is also most likely not up to date.
              </p>
            </div>
            <Carousel
              items={publicationsData}
              renderItem={(item) => (
                <div className="bg-[#181a22] h-full p-6 rounded-lg">
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
              )}
            />
          </section>

          {/* Pictures Section with Carousel and centered content - updated to match other sections */}
          <section id="pictures" className="pt-2 pb-12">
            <div className="text-center mb-8">
              <div className="inline-block px-3 py-1 bg-[#181a22] text-[#7ec8e3] text-sm font-medium mb-2 rounded">
                Pictures
              </div>
              <h2 className="text-4xl font-bold mb-4">Pictures to Verify I am a Weirdo</h2>
              <p className="text-[#d0cccc] text-lg max-w-3xl mx-auto">
                Here is a collage of photos that help eludidate my eceletic personality.
              </p>
            </div>
            <Carousel
              items={picturesData}
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
                        // Once the image loads, make it visible
                        e.currentTarget.style.opacity = '1';
                        // Hide the loading placeholder
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

          {/* Leadership Roles Section with Carousel and centered content - updated to match other sections */}
          <section id="leadership" className="pt-2 pb-12">
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
                  <div className="text-[#7ec8e3] text-sm mb-1">{item.organization}</div>
                  <div className="text-[#a2a5b9] text-xs mb-1 italic">{item.place}</div>
                  <div className="text-[#a9c0d4] text-sm">{item.period}</div>
                </div>
              )}
            />
          </section>

          {/* Academic Service Section with Carousel and centered content */}
          <section id="service" className="pt-2 pb-12">
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

          {/* References Section - updated with new style matching the image */}
          <section id="references" className="pt-2 pb-16">
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
                  For my future reference: 
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
                <div className="bg-gradient-to-br from-[#1c1c24] to-[#13131a] h-full p-6 rounded-lg text-[#d0cccc] flex flex-col items-start relative overflow-hidden">
                  {/* Quote marks in background */}
                  <div className="absolute right-2 top-2 text-[100px] leading-none text-[#1d1d27] font-serif opacity-70">
                    "
                  </div>
                  
                  <div className="flex items-center mb-4 relative z-10">
                    {/* Profile image */}
                    <div className="w-16 h-16 rounded-full overflow-hidden border-2 border-[#7ec8e3] mr-4 flex-shrink-0">
                      <img
                        src={item.image}
                        alt={item.name}
                        className="w-full h-full object-cover"
                        onError={(e) => {
                          e.currentTarget.src = "https://via.placeholder.com/150?text=Profile";
                        }}
                      />
                    </div>
                    
                    {/* Name and title */}
                    <div>
                      <div className="font-bold text-lg text-white">{item.name}</div>
                      <div className="text-sm text-[#7ec8e3]">{item.title}</div>
                    </div>
                  </div>
                  
                  {/* Testimonial text */}
                  <p className="text-sm leading-relaxed italic relative z-10">{item.text}</p>
                </div>
              )}
              itemsPerSlide={2}
            />
          </section>
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
              <div>© 2025 Palash Ranjan Roy | All rights reserved</div>
              <div className="flex gap-4 mt-2 md:mt-0">
                <a href="https://github.com/YourGithubUsername" target="_blank" rel="noopener noreferrer" className="hover:text-[#7ec8e3]">GitHub</a>
                <a href="https://www.linkedin.com/in/palash-ranjan-roy-b3578918b" target="_blank" rel="noopener noreferrer" className="hover:text-[#7ec8e3]">LinkedIn</a>
                <a href="https://www.researchgate.net/profile/Palash_Roy" target="_blank" rel="noopener noreferrer" className="hover:text-[#7ec8e3]">ResearchGate</a>
              </div>
            </div>
          </footer>
        </div>
      </div>
    </div>
  );
}
