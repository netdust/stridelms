import './main.css';
import Alpine from 'alpinejs';

// Make Alpine available globally
window.Alpine = Alpine;

// Register components
Alpine.data('mobileNav', () => ({
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

Alpine.data('courseCard', () => ({
  loading: false,
  async enroll(editionId) {
    this.loading = true;
    // Will integrate with WordPress AJAX
    console.log('Enrolling in edition:', editionId);
    this.loading = false;
  },
}));

// Start Alpine
Alpine.start();
