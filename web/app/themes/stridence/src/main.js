/**
 * Stride Frontend Entry Point
 *
 * Initializes Alpine.js and global components.
 */

// Import styles (base.css imports all other CSS files)
import './css/base.css';

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
 * Course detail page tabs with scroll tracking
 * Usage: x-data="courseDetailTabs()"
 */
Alpine.data('courseDetailTabs', () => ({
  activeTab: 'overzicht',
  sections: ['overzicht', 'programma', 'sprekers', 'praktisch'],
  observer: null,

  scrollTo(sectionId) {
    const section = document.getElementById(sectionId);
    if (section) {
      this.activeTab = sectionId;
      section.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
  },

  init() {
    // Set up intersection observer to track which section is visible
    const options = {
      root: null,
      rootMargin: '-30% 0px -60% 0px',
      threshold: 0,
    };

    this.observer = new IntersectionObserver((entries) => {
      entries.forEach((entry) => {
        if (entry.isIntersecting) {
          this.activeTab = entry.target.id;
        }
      });
    }, options);

    // Observe all sections
    this.sections.forEach((sectionId) => {
      const section = document.getElementById(sectionId);
      if (section) {
        this.observer.observe(section);
      }
    });
  },

  destroy() {
    if (this.observer) {
      this.observer.disconnect();
    }
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
 * NTDST API wrapper
 * Uses the NTDST REST API with automatic nonce management.
 * Always use this instead of raw fetch() for WP endpoints.
 */
window.ntdstAPI = {
  _nonceCache: {},

  /**
   * Call an NTDST API action (preferred method)
   * Handles nonce fetching and error throwing automatically.
   *
   * @param {string} action - Action name (e.g., 'stride_update_profile')
   * @param {object} params - Action parameters
   * @returns {Promise<object>} Action result data
   * @throws {Error} If action fails
   */
  async call(action, params = {}) {
    // Get or fetch nonce for this action
    let nonce = this._nonceCache[action];
    if (!nonce) {
      const nonceResponse = await fetch('/wp-json/ntdst/v1/get_nonce', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action }),
      });
      const nonceResult = await nonceResponse.json();
      if (!nonceResult.success) {
        throw new Error(nonceResult.data?.message || 'Kon geen beveiligingstoken ophalen');
      }
      nonce = nonceResult.data.nonce;
      this._nonceCache[action] = nonce;
    }

    // Call the action
    const response = await fetch('/wp-json/ntdst/v1/action', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action, nonce, ...params }),
    });

    const result = await response.json();

    // If nonce expired, clear cache and retry once
    if (!result.success && result.data?.code === 'invalid_nonce') {
      delete this._nonceCache[action];
      return this.call(action, params);
    }

    if (!result.success) {
      throw new Error(result.data?.message || 'Actie mislukt');
    }

    return result.data;
  },

  /**
   * POST to WordPress AJAX (legacy, prefer call())
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

  // Built-in helpers matching NTDST reference
  async getRecentPosts(postType = 'post', perPage = 10) {
    return this.call('get_recent_posts', { post_type: postType, per_page: perPage });
  },

  async searchPosts(search, postTypes = ['post', 'page']) {
    return this.call('search_posts', { search, post_types: postTypes });
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
