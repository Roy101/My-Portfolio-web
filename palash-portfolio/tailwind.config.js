/** @type {import('tailwindcss').Config} */
// Colours come from CSS variables in src/index.css (--c-*), so light/dark is
// handled there. Tailwind is used for layout utilities only.
module.exports = {
  content: ["./index.html", "./src/**/*.{ts,tsx,js,jsx}"],
  theme: {
    extend: {
      fontFamily: {
        sans: ["var(--font-sans)"],
        serif: ["var(--font-serif)"],
      },
      colors: {
        bg: "var(--c-bg)",
        surface: "var(--c-surface)",
        ink: "var(--c-text)",
        muted: "var(--c-muted)",
        line: "var(--c-border)",
        accent: "var(--c-accent)",
        award: "var(--c-award)",
      },
      borderRadius: {
        DEFAULT: "4px",
        md: "4px",
        lg: "6px",
        xl: "6px",
        "2xl": "6px",
      },
      maxWidth: {
        site: "960px",
        prose: "720px",
      },
    },
  },
  plugins: [],
  // Scope Tailwind's --tw-* default variables to the elements whose utilities use them, instead
  // of setting about 30 custom properties on every element (*, ::before, ::after). Same look,
  // much less style work when the page (re)renders.
  experimental: { optimizeUniversalDefaults: true },
};
