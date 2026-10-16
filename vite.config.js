import tailwindcss from '@tailwindcss/vite'
import laravel from 'laravel-vite-plugin'
import { defineConfig } from 'vite'

// Vite ne sert qu'à compiler la feuille de style et le script de l'interface :
// en production, seuls les fichiers de `public/build` sont servis, par PHP.
export default defineConfig({
  plugins: [
    laravel({
      input: ['resources/css/app.css', 'resources/js/app.js'],
      refresh: ['resources/views/**', 'app/Livewire/**'],
    }),
    tailwindcss(),
  ],
})
