/** @type {import('tailwindcss').Config} */
export default {
  content: [
    './*.php',
    './templates/**/*.php',
    './partials/**/*.php',
    './src/**/*.js',
  ],

  theme: {
    extend: {
      colors: {
        primary: {
          DEFAULT: 'rgb(var(--color-primary) / <alpha-value>)',
          hover: 'rgb(var(--color-primary-hover) / <alpha-value>)',
          subtle: 'rgb(var(--color-primary-subtle) / <alpha-value>)',
          light: 'rgb(var(--color-primary-light) / <alpha-value>)',
          dark: 'rgb(var(--color-primary-dark) / <alpha-value>)',
        },
        accent: {
          DEFAULT: 'rgb(var(--color-accent) / <alpha-value>)',
          light: 'rgb(var(--color-accent-light) / <alpha-value>)',
        },
        tertiary: {
          DEFAULT: 'rgb(var(--color-tertiary) / <alpha-value>)',
          light: 'rgb(var(--color-tertiary-light) / <alpha-value>)',
        },
        'secondary-container': 'rgb(var(--color-secondary-container) / <alpha-value>)',
        surface: {
          DEFAULT: 'rgb(var(--color-surface) / <alpha-value>)',
          alt: 'rgb(var(--color-surface-alt) / <alpha-value>)',
          card: 'rgb(var(--color-surface-card) / <alpha-value>)',
          container: 'rgb(var(--color-surface-container) / <alpha-value>)',
          'container-high': 'rgb(var(--color-surface-container-high) / <alpha-value>)',
          'container-highest': 'rgb(var(--color-surface-container-highest) / <alpha-value>)',
        },
        border: {
          DEFAULT: 'rgb(var(--color-border) / <alpha-value>)',
          strong: 'rgb(var(--color-border-strong) / <alpha-value>)',
        },
        text: {
          DEFAULT: 'rgb(var(--color-text) / <alpha-value>)',
          muted: 'rgb(var(--color-text-muted) / <alpha-value>)',
          inverse: 'rgb(var(--color-text-inverse) / <alpha-value>)',
        },
        success: 'rgb(var(--color-success) / <alpha-value>)',
        warning: 'rgb(var(--color-warning) / <alpha-value>)',
        error: 'rgb(var(--color-error) / <alpha-value>)',
        info: 'rgb(var(--color-info) / <alpha-value>)',
      },

      fontFamily: {
        sans: ['var(--font-sans)'],
        heading: ['var(--font-heading)'],
        serif: ['var(--font-serif)'],
        label: ['var(--font-label)'],
      },

      maxWidth: {
        content: 'var(--content-max)',
        container: 'var(--container-max)',
      },

      boxShadow: {
        card: 'var(--shadow-card)',
        elevated: 'var(--shadow-elevated)',
        overlay: 'var(--shadow-overlay)',
      },

      borderRadius: {
        sm: 'var(--radius-sm)',
        md: 'var(--radius-md)',
        lg: 'var(--radius-lg)',
        xl: 'var(--radius-xl)',
      },

      transitionTimingFunction: {
        out: 'var(--ease-out)',
      },

      transitionDuration: {
        fast: 'var(--duration-fast)',
        normal: 'var(--duration-normal)',
      },

      spacing: {
        section: 'var(--space-section)',
        block: 'var(--space-block)',
        element: 'var(--space-element)',
        sidebar: 'var(--sidebar-width)',
        'sidebar-collapsed': 'var(--sidebar-collapsed)',
      },
    },
  },

  plugins: [],
};
