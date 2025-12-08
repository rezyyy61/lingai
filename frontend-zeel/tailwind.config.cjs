/** @type {import('tailwindcss').Config} */
module.exports = {
  darkMode: 'class',
  content: [
    './index.html',
    './src/**/*.{vue,js,ts,jsx,tsx}',
  ],
  theme: {
    extend: {
      colors: {
        zee: {
          500: '#0ea5e9',
          600: '#0284c7',
        },
        zeel: {
          bg: '#010814',
          surface: '#071327',
          primary: '#1dd8c1',
          accent: '#f4b86f',
          muted: '#9fb1c7',
        },
      },
      borderRadius: {
        xl2: '1.25rem',
      },
      boxShadow: {
        soft: '0 18px 45px rgba(15,23,42,0.35)',
      },
      fontFamily: {
        sans: ['system-ui', 'ui-sans-serif', 'Inter', 'sans-serif'],
      },
    },
  },
  plugins: [],
};
