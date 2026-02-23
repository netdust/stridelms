/**
 * Stridence Navigation
 */
(function() {
    'use strict';

    const toggle = document.querySelector('.str-header__toggle');
    const menu = document.getElementById('mobile-menu');

    if (!toggle || !menu) return;

    toggle.addEventListener('click', function() {
        const expanded = toggle.getAttribute('aria-expanded') === 'true';
        toggle.setAttribute('aria-expanded', !expanded);
        menu.hidden = expanded;
        document.body.classList.toggle('mobile-menu-open', !expanded);
    });

    // Close on escape
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && !menu.hidden) {
            toggle.setAttribute('aria-expanded', 'false');
            menu.hidden = true;
            document.body.classList.remove('mobile-menu-open');
        }
    });
})();
