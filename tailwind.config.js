/**
 * Tailwind configuration for the standalone CLI binary (no Node required).
 *
 *   ./tailwindcss.exe -i src/input.css -o public/assets/app.css --watch
 *
 * Palette: Lyceum of the East Aurora official website colors.
 *
 *   Primary Blue  #2E9AD6  headers, buttons, key elements
 *   Navy Blue     #0F4C5C  text, headings, important content
 *   Teal Green    #7ED08A  accents, highlights, success states
 *   Light Mint    #E8F6ED  backgrounds, cards, sections
 *   Sky Blue      #B3DFF5  hover states, borders, subtle accents
 *   Gray          #6B7280  secondary text, icons, disabled states
 *   Red  #E63946 (alert) · Yellow #FFC947 (warning) · Pale Blue #EAF6FF
 *
 * Accessibility note (docs/21): white text on the brand Primary Blue #2E9AD6
 * reaches only 3.1:1, short of the 4.5:1 WCAG AA minimum for normal text. So
 * `brand-600` (#1E7BAE, 4.7:1 on white) carries buttons, links, and small text,
 * while the brand blue itself is kept for large headings, fills, and accents
 * where 3:1 is sufficient. The palette is honored; the contrast still passes.
 */
module.exports = {
  content: [
    './app/Views/**/*.php',
    './app/**/*.php',
    './public/**/*.js',
  ],
  theme: {
    extend: {
      colors: {
        brand: {
          // Primary blue ramp
          50:  '#EAF6FF',  // Pale Blue
          100: '#D3ECFA',
          200: '#B3DFF5',  // Sky Blue
          300: '#8ACDEE',
          400: '#2E9AD6',  // Primary Blue (brand)
          500: '#268AC2',
          600: '#1E7BAE',  // buttons, links, small text (AA on white)
          700: '#186490',
          800: '#134E70',
          900: '#0F4C5C',  // Navy Blue
          // Named aliases, so views can read as the palette does
          primary: '#2E9AD6',
          navy:    '#0F4C5C',
          teal:    '#7ED08A',
          mint:    '#E8F6ED',
          sky:     '#B3DFF5',
          pale:    '#EAF6FF',
          gray:    '#6B7280',
          alert:   '#E63946',
          warning: '#FFC947',
        },
        // Darkened teal and amber for TEXT on their light backgrounds, so
        // success and warning messages stay readable.
        success: {
          bg:   '#E8F6ED',
          text: '#1B6B36',
          line: '#7ED08A',
        },
        warning: {
          bg:   '#FFF6E0',
          text: '#8A5B00',
          line: '#FFC947',
        },
        danger: {
          bg:   '#FDECEE',
          text: '#B01B28',
          line: '#E63946',
        },
      },
      // Typography from the Lyceum template: Montserrat for headings, labels, and
      // uppercase display lines; Inter for body text. Both are self-hosted in
      // public/assets/fonts (see @font-face in src/input.css).
      fontFamily: {
        sans:    ['Inter', 'Segoe UI', 'system-ui', '-apple-system', 'sans-serif'],
        display: ['Montserrat', 'Segoe UI', 'system-ui', '-apple-system', 'sans-serif'],
      },
      letterSpacing: {
        // The template's spaced-out taglines ("DISCIPLINE • INTEGRITY • SERVICE").
        brand: '0.22em',
      },
    },
  },
  plugins: [],
};
