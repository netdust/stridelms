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
          hover: 'rgb(var(--color-accent-hover) / <alpha-value>)',
          subtle: 'rgb(var(--color-accent-subtle) / <alpha-value>)',
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
          soft: 'rgb(var(--color-border-soft) / <alpha-value>)',
          strong: 'rgb(var(--color-border-strong) / <alpha-value>)',
        },
        text: {
          DEFAULT: 'rgb(var(--color-text) / <alpha-value>)',
          muted: 'rgb(var(--color-text-muted) / <alpha-value>)',
          faint: 'rgb(var(--color-text-faint) / <alpha-value>)',
          inverse: 'rgb(var(--color-text-inverse) / <alpha-value>)',
        },
        success: 'rgb(var(--color-success) / <alpha-value>)',
        warning: 'rgb(var(--color-warning) / <alpha-value>)',
        error: 'rgb(var(--color-error) / <alpha-value>)',
        info: 'rgb(var(--color-info) / <alpha-value>)',
        focus: 'rgb(var(--color-focus) / <alpha-value>)',
        badge: {
          'open-bg': 'rgb(var(--color-badge-open-bg) / <alpha-value>)',
          'open-text': 'rgb(var(--color-badge-open-text) / <alpha-value>)',
          'few-bg': 'rgb(var(--color-badge-few-bg) / <alpha-value>)',
          'few-text': 'rgb(var(--color-badge-few-text) / <alpha-value>)',
          'full-bg': 'rgb(var(--color-badge-full-bg) / <alpha-value>)',
          'full-text': 'rgb(var(--color-badge-full-text) / <alpha-value>)',
          'cancelled-bg': 'rgb(var(--color-badge-cancelled-bg) / <alpha-value>)',
          'cancelled-text': 'rgb(var(--color-badge-cancelled-text) / <alpha-value>)',
          'online-bg': 'rgb(var(--color-badge-online-bg) / <alpha-value>)',
          'online-text': 'rgb(var(--color-badge-online-text) / <alpha-value>)',
          'free-bg': 'rgb(var(--color-badge-free-bg) / <alpha-value>)',
          'free-text': 'rgb(var(--color-badge-free-text) / <alpha-value>)',
        },
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
