/**
 * Stride Frontend Entry Point
 *
 * Initializes Alpine.js and global components.
 */

// Import styles
import './css/tokens.css';
import './css/base.css';
import './css/components.css';
import './css/learndash.css';

// Import Alpine.js
import Alpine from 'alpinejs';

// Make Alpine available globally
window.Alpine = Alpine;

// ══════════════════════════════════════
// ALPINE COMPONENTS
// ══════════════════════════════════════

/**
 * Toast notification store
 * Usage: this.$dispatch('toast', { message: 'Success!', type: 'success' })
 */
Alpine.data('toastStore', () => ({
  visible: false,
  message: '',
  type: 'success',
  timeout: null,

  show({ message, type = 'success' }) {
    clearTimeout(this.timeout);
    this.message = message;
    this.type = type;
    this.visible = true;
    this.timeout = setTimeout(() => (this.visible = false), 4000);
  },

  init() {
    this.$watch('visible', (value) => {
      if (!value) {
        this.message = '';
      }
    });
  },
}));

/**
 * Dashboard tabs with URL state
 * Usage: x-data="dashboardTabs()"
 */
Alpine.data('dashboardTabs', () => ({
  activeTab: new URLSearchParams(window.location.search).get('tab') || 'inschrijvingen',

  setTab(tab) {
    this.activeTab = tab;
    const url = new URL(window.location);
    url.searchParams.set('tab', tab);
    history.pushState({}, '', url);
  },

  init() {
    window.addEventListener('popstate', () => {
      this.activeTab =
        new URLSearchParams(window.location.search).get('tab') || 'inschrijvingen';
    });
  },
}));

/**
 * Inline confirmation for destructive actions
 * Usage: x-data="confirmAction()"
 */
Alpine.data('confirmAction', () => ({
  confirming: false,

  startConfirm() {
    this.confirming = true;
  },

  cancel() {
    this.confirming = false;
  },

  async confirm(callback) {
    if (typeof callback === 'function') {
      await callback();
    }
    this.confirming = false;
  },
}));

/**
 * Mobile menu toggle
 * Usage: x-data="mobileMenu()"
 */
Alpine.data('mobileMenu', () => ({
  open: false,

  toggle() {
    this.open = !this.open;
    document.body.classList.toggle('overflow-hidden', this.open);
  },

  close() {
    this.open = false;
    document.body.classList.remove('overflow-hidden');
  },
}));

/**
 * Dropdown menu
 * Usage: x-data="dropdown()"
 */
Alpine.data('dropdown', () => ({
  open: false,

  toggle() {
    this.open = !this.open;
  },

  close() {
    this.open = false;
  },

  init() {
    // Close on click outside
    this.$watch('open', (value) => {
      if (value) {
        setTimeout(() => {
          document.addEventListener('click', this.handleClickOutside.bind(this), { once: true });
        }, 0);
      }
    });
  },

  handleClickOutside(e) {
    if (!this.$el.contains(e.target)) {
      this.open = false;
    }
  },
}));

/**
 * Expandable card/accordion
 * Usage: x-data="expandable()"
 */
Alpine.data('expandable', () => ({
  open: false,

  toggle() {
    this.open = !this.open;
  },
}));

/**
 * Loading state wrapper
 * Usage: x-data="loadingState()"
 */
Alpine.data('loadingState', () => ({
  loading: false,

  async withLoading(callback) {
    this.loading = true;
    try {
      await callback();
    } finally {
      this.loading = false;
    }
  },
}));

// ══════════════════════════════════════
// NTDST API WRAPPER
// ══════════════════════════════════════

/**
 * WordPress AJAX wrapper
 * Always use this instead of raw fetch() for WP endpoints
 */
window.ntdstAPI = {
  /**
   * POST to WordPress AJAX
   * @param {string} action - AJAX action name
   * @param {object} data - Request data
   * @returns {Promise<object>}
   */
  async post(action, data = {}) {
    const formData = new FormData();
    formData.append('action', action);
    formData.append('nonce', window.strideConfig?.nonce || '');

    Object.entries(data).forEach(([key, value]) => {
      if (value !== undefined && value !== null) {
        formData.append(key, typeof value === 'object' ? JSON.stringify(value) : value);
      }
    });

    try {
      const response = await fetch(window.strideConfig?.ajaxUrl || '/wp-admin/admin-ajax.php', {
        method: 'POST',
        credentials: 'same-origin',
        body: formData,
      });

      const result = await response.json();
      return result;
    } catch (error) {
      console.error('ntdstAPI error:', error);
      return {
        success: false,
        data: { message: 'Verbinding mislukt. Probeer opnieuw.' },
      };
    }
  },

  /**
   * GET from WordPress REST API
   * @param {string} endpoint - REST endpoint path
   * @returns {Promise<object>}
   */
  async get(endpoint) {
    try {
      const response = await fetch(`/wp-json/${endpoint}`, {
        method: 'GET',
        credentials: 'same-origin',
        headers: {
          'X-WP-Nonce': window.strideConfig?.restNonce || '',
        },
      });

      return await response.json();
    } catch (error) {
      console.error('ntdstAPI error:', error);
      return null;
    }
  },
};

// ══════════════════════════════════════
// INITIALIZE ALPINE
// ══════════════════════════════════════

Alpine.start();

// Log initialization in development
if (window.strideConfig?.debug) {
  console.log('Stride frontend initialized');
}
