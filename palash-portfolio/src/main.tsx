import { startTransition } from "react";
import { createRoot } from "react-dom/client";
import "./index.css";
import App from "./App.tsx";

const rootElement = document.getElementById("root");
if (!rootElement) {
  throw new Error("Failed to find root element");
}

let started = false;
// As a transition, React builds the page in small slices (yielding to the browser between
// them) instead of one long task; the prerendered HTML stays on screen until it is done.
const start = () => { if (started) return; started = true; const root = createRoot(rootElement); startTransition(() => root.render(<App />)); };
// The homepage and blog are prerendered (scripts/prerender.mjs), so the HTML is already the
// finished page. Let the browser paint it first and start React right after that frame:
// first paint no longer waits for the bundle to run, and React's re-render of the same
// content does not create a later paint. A tab opened in the background runs no animation
// frames, so a timer starts React there. Without prerendered content, render at once.
if (rootElement.firstElementChild) {
  requestAnimationFrame(() => setTimeout(start, 0));
  setTimeout(start, 1000);
} else {
  start();
}
