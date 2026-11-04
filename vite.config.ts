import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
export default defineConfig({plugins:[laravel({input:['resources/js/area3/main.tsx','resources/js/area3/auth.ts'],refresh:true})],esbuild:{jsx:'automatic'}});
