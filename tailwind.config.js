/**
 * Tailwind configuration for the standalone CLI binary (no Node required).
 *
 *   ./tailwindcss.exe -i src/input.css -o public/assets/app.css --watch
 *
 * Palette: refined blue, from the Lyceum of the East Aurora website blue (docs/21).
 *
 *   Primary Blue  #1F6FA8  buttons, links, key elements (5.4:1 with white text)
 *   Navy Blue     #173F63  headings, sidebar, important content
 *   Gold          #E3A72F  the accent (token name brand-teal, kept for the existing markup)
 *   Quiet tint    #EBF2F8  soft sections and backgrounds (brand-mint)
 *   Sky Blue      #C4D9EC  borders and subtle accents
 *   Gray          #5B6B7A  secondary text, icons, disabled states
 *   Red  #D64550 (alert) · Gold  #E3A72F (warning) · Pale Blue #F1F6FB
 *
 * Type: Plus Jakarta Sans for headings, Inter for text. Sentence case, no all-caps headings.
 *
 * THEMING (light + dark). Every colour below resolves to a CSS variable holding
 * "R G B" channels, wrapped as rgb(var(--x) / <alpha-value>) so Tailwind opacity
 * modifiers (bg-white/95, border-brand-200/70) keep working. The channel values
 * live in src/input.css: :root for the (lightened) light theme, and .dark for the
 * navy-tinted dark theme. Switching the .dark class on <html> recolours the whole
 * app from that one place — no per-template edits. The theme is chosen by
 * public/assets/theme-init.js (device setting by default, remembered choice wins).
 *
 * Accessibility (docs/21): white text on the brand Primary Blue #2E9AD6 reaches
 * only 3.1:1, so brand-600 carries buttons, links, and small text on light; the
 * dark theme brightens the same tokens to keep AA on dark surfaces.
 */
const channel = (name) => `rgb(var(${name}) / <alpha-value>)`;

module.exports = {
  darkMode: 'class',
  content: [
    './app/Views/**/*.php',
    './app/**/*.php',
    './public/**/*.js',
  ],
  theme: {
    extend: {
      colors: {
        brand: {
          50:  channel('--brand-50'),
          100: channel('--brand-100'),
          200: channel('--brand-200'),
          300: channel('--brand-300'),
          400: channel('--brand-400'),
          500: channel('--brand-500'),
          600: channel('--brand-600'),
          700: channel('--brand-700'),
          800: channel('--brand-800'),
          900: channel('--brand-900'),
          950: channel('--brand-950'),
          primary: channel('--brand-primary'),
          navy:    channel('--brand-navy'),
          teal:    channel('--brand-teal'),
          mint:    channel('--brand-mint'),
          sky:     channel('--brand-sky'),
          pale:    channel('--brand-pale'),
          gray:    channel('--brand-gray'),
          alert:   channel('--brand-alert'),
          warning: channel('--brand-warning'),
          canvas:  channel('--brand-canvas'),
        },
        // Neutral scale (Tailwind's slate names, kept so existing markup works) is
        // remapped to variables so text/borders/surfaces recolour in dark mode.
        slate: {
          50:  channel('--slate-50'),
          100: channel('--slate-100'),
          200: channel('--slate-200'),
          300: channel('--slate-300'),
          400: channel('--slate-400'),
          500: channel('--slate-500'),
          600: channel('--slate-600'),
          700: channel('--slate-700'),
          800: channel('--slate-800'),
          900: channel('--slate-900'),
        },
        success: {
          bg:   channel('--success-bg'),
          text: channel('--success-text'),
          line: channel('--success-line'),
        },
        warning: {
          bg:   channel('--warning-bg'),
          text: channel('--warning-text'),
          line: channel('--warning-line'),
        },
        danger: {
          bg:   channel('--danger-bg'),
          text: channel('--danger-text'),
          line: channel('--danger-line'),
        },
      },
      fontFamily: {
        sans:    ['Inter', 'Segoe UI', 'system-ui', '-apple-system', 'sans-serif'],
        display: ['Plus Jakarta Sans', 'Segoe UI', 'system-ui', '-apple-system', 'sans-serif'],
      },
      letterSpacing: {
        brand: '0.22em',
      },
    },
  },
  plugins: [],
};
