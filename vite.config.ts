import { defineConfig } from "vite";
import vue from "@vitejs/plugin-vue";
import tailwind from "@tailwindcss/vite";
import { resolve } from "node:path";
export default defineConfig({
  base: "./",
  publicDir: "brand/public",
  plugins: [vue(), tailwind()],
  build: {
    outDir: "packages/cms/public",
    emptyOutDir: true,
    rollupOptions: {
      input: resolve(__dirname, "packages/cms/resources/admin/main.ts"),
      output: {
        manualChunks: (id) =>
          id.includes("/@tiptap/") || id.includes("/prosemirror-")
            ? "editor"
            : undefined,
        entryFileNames: "app.js",
        assetFileNames: "[name][extname]",
      },
    },
  },
});
