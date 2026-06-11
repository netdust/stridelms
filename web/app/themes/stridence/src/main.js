/**
 * Stride Frontend Entry Point
 *
 * Initializes Alpine.js and global components.
 */

// Import styles (base.css imports all other CSS files)
import './css/base.css';

// Import Alpine.js and plugins
import Alpine from 'alpinejs';
import collapse from '@alpinejs/collapse';

// Component factories (extracted so Vitest can import them without booting Alpine)
import { createToastStore } from './js/toast-store.js';
import { contentTabs } from './js/content-tabs.js';
import { sidebarRail } from './js/sidebar-rail.js';

// Register Alpine plugins
Alpine.plugin(collapse);

// Make Alpine available globally
window.Alpine = Alpine;

// ══════════════════════════════════════
// ALPINE COMPONENTS
// ══════════════════════════════════════

/**
 * Toast notification store (factory in src/js/toast-store.js)
 * Usage: this.$dispatch('toast', { message: 'Success!', type: 'success' })
 */
Alpine.data('toastStore', createToastStore);

/**
 * Content tabs — Helder Tij detail pages (factory in src/js/content-tabs.js)
 * Usage: x-data="contentTabs(['omschrijving','programma','praktisch','lesgever'])"
 */
Alpine.data('contentTabs', contentTabs);

/**
 * Dashboard sidebar with collapsible rail (factory in src/js/sidebar-rail.js)
 * Usage: x-data="sidebarRail()"
 */
Alpine.data('sidebarRail', sidebarRail);

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
 * Detail page tabs with scroll tracking (factory)
 *
 * Shared by course, edition, and trajectory detail pages.
 * Uses IntersectionObserver to highlight the active section tab.
 */
function createDetailTabs(sections) {
  return () => ({
    activeTab: 'overzicht',
    sections,
    observer: null,

    scrollTo(sectionId) {
      const section = document.getElementById(sectionId);
      if (section) {
        this.activeTab = sectionId;
        section.scrollIntoView({ behavior: 'smooth', block: 'start' });
      }
    },

    init() {
      const options = { root: null, rootMargin: '-30% 0px -60% 0px', threshold: 0 };

      this.observer = new IntersectionObserver((entries) => {
        entries.forEach((entry) => {
          if (entry.isIntersecting) {
            this.activeTab = entry.target.id;
          }
        });
      }, options);

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
  });
}

Alpine.data('courseDetailTabs', createDetailTabs(['overzicht', 'programma', 'sprekers', 'praktisch']));
// editionDetailTabs removed (SSA-6): the edition detail page now uses contentTabs.
Alpine.data('trajectoryDetailTabs', createDetailTabs(['overzicht', 'cursussen', 'praktisch', 'faq']));

/**
 * Dashboard home with enrollment side panel
 * Usage: x-data="dashboardHome()"
 */
Alpine.data('dashboardHome', () => ({
  panelOpen: false,
  activeEnrollment: null,
  icalLoading: false,

  init() {
    this.$watch('panelOpen', (open) => {
      document.body.style.overflow = open ? 'hidden' : '';
    });
  },

  openPanel(enrollment) {
    this.activeEnrollment = enrollment;
    this.panelOpen = true;
  },

  closePanel() {
    this.panelOpen = false;
    // Clear data after transition completes
    setTimeout(() => {
      if (!this.panelOpen) this.activeEnrollment = null;
    }, 300);
  },

  async downloadIcal(editionId = null) {
    this.icalLoading = true;
    try {
      const params = {};
      if (editionId) params.edition_id = editionId;
      const blob = await ntdstAPI.download('stride_download_ical', params);
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = 'stride-agenda.ics';
      a.click();
      URL.revokeObjectURL(url);
    } catch (e) {
      console.error('iCal download failed:', e);
    } finally {
      this.icalLoading = false;
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
Alpine.data('expandable', (initialOpen = false) => ({
  open: Boolean(initialOpen),

  toggle() {
    this.open = !this.open;
  },
}));

/**
 * Slide-over panel
 * Usage: x-data="slidePanel()" on a parent, then use openPanel(id)/close()
 *
 * Teleport the panel markup to <body> via x-teleport to avoid z-index issues.
 * Panel content is projected via Alpine slot or simply nested inside.
 */
Alpine.data('slidePanel', () => ({
  isOpen: false,
  activeId: null,

  openPanel(id = null) {
    this.activeId = id;
    this.isOpen = true;
    document.body.classList.add('overflow-hidden');
  },

  close() {
    this.isOpen = false;
    document.body.classList.remove('overflow-hidden');
    // Clear activeId after transition
    setTimeout(() => {
      if (!this.isOpen) this.activeId = null;
    }, 300);
  },

  init() {
    this.$watch('isOpen', (value) => {
      if (!value) {
        document.body.classList.remove('overflow-hidden');
      }
    });
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

/**
 * Inline editable field
 * Usage: x-data="inlineEdit({ value: 'initial', action: 'stride_update_profile', field: 'phone', params: {} })"
 *
 * On click: transforms to input field
 * On blur/enter: saves via ntdstAPI.call()
 * On escape: cancels edit
 */
Alpine.data('inlineEdit', (config) => ({
  value: config.value || '',
  originalValue: config.value || '',
  action: config.action || '',
  field: config.field || '',
  params: config.params || {},
  inputType: config.inputType || 'text',
  placeholder: config.placeholder || '',

  editing: false,
  saving: false,
  error: '',

  startEdit() {
    this.originalValue = this.value;
    this.editing = true;
    this.error = '';
    // Focus input on next tick
    this.$nextTick(() => {
      const input = this.$refs.input;
      if (input) {
        input.focus();
        input.select();
      }
    });
  },

  cancelEdit() {
    this.value = this.originalValue;
    this.editing = false;
    this.error = '';
  },

  async saveEdit() {
    // No change, just close
    if (this.value === this.originalValue) {
      this.editing = false;
      return;
    }

    this.saving = true;
    this.error = '';

    try {
      await ntdstAPI.call(this.action, {
        ...this.params,
        [this.field]: this.value,
      });

      this.originalValue = this.value;
      this.editing = false;
      this.$dispatch('toast', { message: 'Opgeslagen', type: 'success' });
      this.$dispatch('inline-edit-saved', { field: this.field, value: this.value });
    } catch (err) {
      this.error = err.message || 'Opslaan mislukt';
    } finally {
      this.saving = false;
    }
  },

  handleKeydown(event) {
    if (event.key === 'Enter' && this.inputType !== 'textarea') {
      event.preventDefault();
      this.saveEdit();
    } else if (event.key === 'Escape') {
      this.cancelEdit();
    }
  },
}));

/**
 * Inline editable form section
 * Groups multiple fields that save together
 * Usage: x-data="inlineEditSection({ action: 'stride_update_quote', params: { quote_id: 123 } })"
 */
Alpine.data('inlineEditSection', (config) => ({
  action: config.action || '',
  params: config.params || {},
  fields: config.fields || {},

  editing: false,
  saving: false,
  error: '',
  originalFields: {},

  startEdit() {
    this.originalFields = { ...this.fields };
    this.editing = true;
    this.error = '';
  },

  cancelEdit() {
    this.fields = { ...this.originalFields };
    this.editing = false;
    this.error = '';
  },

  async saveEdit() {
    this.saving = true;
    this.error = '';

    try {
      await ntdstAPI.call(this.action, {
        ...this.params,
        ...this.fields,
      });

      this.originalFields = { ...this.fields };
      this.editing = false;
      this.$dispatch('toast', { message: 'Opgeslagen', type: 'success' });
      this.$dispatch('inline-section-saved', { fields: this.fields });
    } catch (err) {
      this.error = err.message || 'Opslaan mislukt';
    } finally {
      this.saving = false;
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
        headers: {
          'Content-Type': 'application/json',
          'X-WP-Nonce': window.strideConfig?.restNonce || '',
        },
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
      headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': window.strideConfig?.restNonce || '',
      },
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
   * Upload files via NTDST API action (multipart/form-data)
   * Same nonce management as call(), but sends FormData for file uploads.
   *
   * @param {string} action - Action name (e.g., 'stride_upload_completion_documents')
   * @param {FormData} formData - FormData with files and params
   * @returns {Promise<object>} Action result data
   * @throws {Error} If action fails
   */
  async upload(action, formData) {
    // Get or fetch nonce for this action
    let nonce = this._nonceCache[action];
    if (!nonce) {
      const nonceResponse = await fetch('/wp-json/ntdst/v1/get_nonce', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/json',
          'X-WP-Nonce': window.strideConfig?.restNonce || '',
        },
        body: JSON.stringify({ action }),
      });
      const nonceResult = await nonceResponse.json();
      if (!nonceResult.success) {
        throw new Error(nonceResult.data?.message || 'Kon geen beveiligingstoken ophalen');
      }
      nonce = nonceResult.data.nonce;
      this._nonceCache[action] = nonce;
    }

    // Append action and nonce to FormData
    formData.set('action', action);
    formData.set('nonce', nonce);

    // Send as multipart/form-data (no Content-Type header — browser sets boundary)
    const response = await fetch('/wp-json/ntdst/v1/action', {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'X-WP-Nonce': window.strideConfig?.restNonce || '',
      },
      body: formData,
    });

    const result = await response.json();

    if (!result.success && result.data?.code === 'invalid_nonce') {
      delete this._nonceCache[action];
      return this.upload(action, formData);
    }

    if (!result.success) {
      throw new Error(result.data?.message || 'Upload mislukt');
    }

    return result.data;
  },

  /**
   * Download a file via NTDST API action.
   * Same nonce management as call(), but returns a Blob instead of JSON.
   *
   * @param {string} action - Action name (e.g., 'stride_download_ical')
   * @param {object} params - Action parameters
   * @returns {Promise<Blob>} File blob
   * @throws {Error} If download fails
   */
  async download(action, params = {}) {
    let nonce = this._nonceCache[action];
    if (!nonce) {
      const nonceResponse = await fetch('/wp-json/ntdst/v1/get_nonce', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/json',
          'X-WP-Nonce': window.strideConfig?.restNonce || '',
        },
        body: JSON.stringify({ action }),
      });
      const nonceResult = await nonceResponse.json();
      if (!nonceResult.success) {
        throw new Error(nonceResult.data?.message || 'Kon geen beveiligingstoken ophalen');
      }
      nonce = nonceResult.data.nonce;
      this._nonceCache[action] = nonce;
    }

    const response = await fetch('/wp-json/ntdst/v1/action', {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': window.strideConfig?.restNonce || '',
      },
      body: JSON.stringify({ action, nonce, ...params }),
    });

    if (!response.ok) {
      throw new Error('Download mislukt');
    }

    return response.blob();
  },

};

// ══════════════════════════════════════
// BFCACHE RELOAD
// ══════════════════════════════════════

// Brave (and some other browsers) serve pages from the back-forward cache
// without making a new HTTP request, causing stale auth/enrollment state.
// When that happens, force a reload to get fresh server-rendered content.
window.addEventListener('pageshow', (event) => {
  if (event.persisted) {
    window.location.reload();
  }
});

// ══════════════════════════════════════
// INITIALIZE ALPINE
// ══════════════════════════════════════

Alpine.start();

// Log initialization in development
if (window.strideConfig?.debug) {
  console.log('Stride frontend initialized');
}
