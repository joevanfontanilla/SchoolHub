/**
 * SchoolHub front-end behavior.
 *
 * Vanilla JS, no framework (docs/00). Everything here is progressive
 * enhancement — every page works without it.
 *
 * URLs are never hardcoded here. When a script needs one, PHP passes it in
 * through a data- attribute, because the app runs from a subdirectory.
 */
(function () {
    'use strict';

    /** Toggle a panel by id, keeping aria-expanded in sync. */
    function bindToggle(button, panel, onToggle) {
        if (!button || !panel) {
            return;
        }
        button.addEventListener('click', function () {
            var open = panel.hidden;
            panel.hidden = !open;
            button.setAttribute('aria-expanded', String(open));
            if (typeof onToggle === 'function') {
                onToggle(open);
            }
        });
    }

    // Public site: mobile menu.
    document.querySelectorAll('[data-menu-toggle]').forEach(function (button) {
        bindToggle(button, document.getElementById(button.getAttribute('data-menu-toggle')));
    });

    // Application shell: mobile sidebar with a backdrop.
    var sidebarToggle = document.querySelector('[data-sidebar-toggle]');
    var sidebar = document.getElementById('app-sidebar');
    var backdrop = document.getElementById('sidebar-backdrop');

    if (sidebarToggle && sidebar) {
        var setSidebar = function (open) {
            sidebar.classList.toggle('-translate-x-full', !open);
            sidebarToggle.setAttribute('aria-expanded', String(open));
            if (backdrop) {
                backdrop.hidden = !open;
            }
        };

        sidebarToggle.addEventListener('click', function () {
            setSidebar(sidebar.classList.contains('-translate-x-full'));
        });

        if (backdrop) {
            backdrop.addEventListener('click', function () {
                setSidebar(false);
            });
        }

        // Escape closes the sidebar and returns focus to the button.
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && !sidebar.classList.contains('-translate-x-full')) {
                setSidebar(false);
                sidebarToggle.focus();
            }
        });
    }

    // Confirmation before a destructive action (docs/21).
    document.querySelectorAll('[data-confirm]').forEach(function (element) {
        element.addEventListener('submit', function (event) {
            if (!window.confirm(element.getAttribute('data-confirm'))) {
                event.preventDefault();
            }
        });
    });

    // Prevent a double submit from creating two records.
    document.querySelectorAll('form[method="POST"]').forEach(function (form) {
        form.addEventListener('submit', function () {
            var button = form.querySelector('button[type="submit"]');
            if (button && !form.hasAttribute('data-allow-resubmit')) {
                window.setTimeout(function () {
                    button.disabled = true;
                    button.textContent = 'Working...';
                }, 0);
            }
        });
    });
})();
