import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
export default defineConfig({
  // Resolve lazy CSS/JS from the loaded module, including Apache subfolder URLs.
  base: './',
  plugins: [laravel({input:['resources/js/area3/main.tsx','resources/js/area3/auth.ts'],refresh:true})],
  esbuild: {jsx:'automatic'},
});
