/** @type {import('tailwindcss').Config} */

export default {
  darkMode: "class",
  content: ["./index.html", "./src/**/*.{js,ts,vue}"],
  theme: {
    container: {
      center: true,
    },
    extend: {
      fontFamily: {
        display: ['"Bricolage Grotesque"', 'ui-sans-serif', 'system-ui', 'sans-serif'],
        sans: ['Manrope', 'ui-sans-serif', 'system-ui', 'sans-serif'],
        mono: ['"JetBrains Mono"', 'ui-monospace', 'SFMono-Regular', 'monospace'],
      },
      colors: {
        ink: {
          950: '#08090b',
          900: '#0c0e11',
          850: '#101317',
          800: '#14171c',
          750: '#181b21',
          700: '#1d2128',
          600: '#272c35',
          500: '#3a414d',
          400: '#5b6471',
          300: '#828b99',
          200: '#aeb4bf',
          100: '#d6dae2',
          50: '#eef0f4',
        },
        brand: {
          DEFAULT: '#e6b54a',
          soft: '#f0c878',
          deep: '#b9882c',
        },
        state: {
          draft: '#94a3b8',
          pending: '#22d3ee',
          active: '#34d399',
          rejected: '#fb7185',
          frozen: '#818cf8',
          disabled: '#71717a',
        },
      },
      boxShadow: {
        glow: '0 0 0 1px rgba(230,181,74,0.35), 0 8px 40px -12px rgba(230,181,74,0.25)',
        panel: '0 1px 0 0 rgba(255,255,255,0.03) inset, 0 24px 60px -30px rgba(0,0,0,0.8)',
      },
      keyframes: {
        pulseDot: {
          '0%,100%': { opacity: '1' },
          '50%': { opacity: '0.35' },
        },
        fadeUp: {
          from: { opacity: '0', transform: 'translateY(8px)' },
          to: { opacity: '1', transform: 'translateY(0)' },
        },
      },
      animation: {
        pulseDot: 'pulseDot 2.4s ease-in-out infinite',
        fadeUp: 'fadeUp 0.4s ease both',
      },
    },
  },
  plugins: [],
};
