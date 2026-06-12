import path from "path";
import react from "@vitejs/plugin-react";
import { defineConfig } from "vite";

// https://vitejs.dev/config/
export default defineConfig({
  plugins: [react()],
  base: "/", // Base path for assets
  resolve: {
    alias: {
      "@": path.resolve(__dirname, "./src"),
    },
  },
  build: {
    target: "es2020",
    cssMinify: true,
    rollupOptions: {
      output: {
        // Split rarely-changing vendor code into its own long-cached chunk
        manualChunks: {
          "react-vendor": ["react", "react-dom"],
        },
      },
    },
  },
});
