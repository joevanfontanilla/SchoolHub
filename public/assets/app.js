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

    // Subject selection: keep the unit total in step with the ticked subjects.
    // The server re-checks the limit; this only helps the student see it.
    document.querySelectorAll('form[data-units-form]').forEach(function (form) {
        var total = document.querySelector('[data-units-total]');
        if (!total) {
            return;
        }
        form.addEventListener('change', function () {
            var units = 0;
            form.querySelectorAll('input[data-units]:checked').forEach(function (box) {
                units += parseFloat(box.getAttribute('data-units')) || 0;
            });
            total.textContent = String(Math.round(units * 10) / 10);
        });
    });

    // Printable documents: the Print button opens the browser's print dialog.
    document.querySelectorAll('[data-print]').forEach(function (button) {
        button.addEventListener('click', function () {
            window.print();
        });
    });

    // Dropdown menus built on <details>: close on an outside click or Escape.
    var dropdowns = document.querySelectorAll('details[data-dropdown]');
    if (dropdowns.length > 0) {
        document.addEventListener('click', function (event) {
            dropdowns.forEach(function (menu) {
                if (menu.open && !menu.contains(event.target)) {
                    menu.open = false;
                }
            });
        });
        document.addEventListener('keydown', function (event) {
            if (event.key !== 'Escape') {
                return;
            }
            dropdowns.forEach(function (menu) {
                if (menu.open) {
                    menu.open = false;
                    menu.querySelector('summary').focus();
                }
            });
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

    // A confirmation on one specific submit button, for forms whose buttons do
    // different things (return, reject, cancel share one reason box).
    document.querySelectorAll('button[data-confirm-click]').forEach(function (button) {
        button.addEventListener('click', function (event) {
            if (!window.confirm(button.getAttribute('data-confirm-click'))) {
                event.preventDefault();
            }
        });
    });

    // Filter forms apply as soon as a dropdown changes. The search box still
    // waits for Enter or its button, so typing does not reload the page.
    document.querySelectorAll('form[data-auto-submit]').forEach(function (form) {
        form.querySelectorAll('select, input[type="date"]').forEach(function (select) {
            select.addEventListener('change', function () {
                var page = form.querySelector('input[name="page"]');
                if (page) {
                    page.value = '1';
                }
                form.submit();
            });
        });
    });

    // Warn about an oversized file before it is uploaded, so the student is not
    // left waiting for a multi-megabyte upload the server will refuse anyway.
    // The server still enforces the limit; this is only an early warning.
    function formatMegabytes(bytes) {
        return (Math.round(bytes / 104857.6) / 10) + ' MB';
    }
    function fileTooBig(input) {
        var max = parseInt(input.getAttribute('data-max-bytes'), 10);
        var file = input.files && input.files[0];
        return file && max && file.size > max ? file : null;
    }
    document.querySelectorAll('input[type="file"][data-max-bytes]').forEach(function (input) {
        var help = document.getElementById(input.id + '-help');
        var original = help ? help.textContent : '';

        input.addEventListener('change', function () {
            var big = fileTooBig(input);
            input.setCustomValidity(big ? 'This file is ' + formatMegabytes(big.size) + '. Please choose a smaller one.' : '');
            if (help) {
                help.textContent = big ? input.validationMessage : original;
                help.classList.toggle('text-danger-text', !!big);
            }
        });

        if (input.form) {
            input.form.addEventListener('submit', function (event) {
                if (fileTooBig(input)) {
                    event.preventDefault();
                    input.reportValidity();
                }
            });
        }
    });

    // Guardian rows on the profile form: add and remove.
    document.querySelectorAll('[data-repeater]').forEach(function (repeater) {
        var list = repeater.querySelector('[data-repeater-list]');
        var template = repeater.querySelector('template[data-repeater-template]');
        var add = repeater.querySelector('[data-repeater-add]');
        if (!list || !template || !add) {
            return;
        }

        var next = list.querySelectorAll('[data-repeater-item]').length;

        add.addEventListener('click', function () {
            var html = template.innerHTML.replace(/__INDEX__/g, String(next++));
            list.insertAdjacentHTML('beforeend', html);
            var added = list.lastElementChild;
            var firstInput = added && added.querySelector('input');
            if (firstInput) {
                firstInput.focus();
            }
        });

        list.addEventListener('click', function (event) {
            var remove = event.target.closest('[data-repeater-remove]');
            if (!remove) {
                return;
            }
            var item = remove.closest('[data-repeater-item]');
            // Keep at least one row, so there is always somewhere to type.
            if (item && list.querySelectorAll('[data-repeater-item]').length > 1) {
                item.remove();
                add.focus();
            }
        });
    });

    // Show the transferee fields only when "Transferee" is chosen. Without
    // JavaScript they simply stay visible, and the server still validates them.
    document.querySelectorAll('[data-student-type-form]').forEach(function (form) {
        var type = form.querySelector('[name="student_type"]');
        var fields = form.querySelector('[data-transferee-fields]');
        if (!type || !fields) {
            return;
        }
        var sync = function () {
            fields.hidden = type.value !== 'Transferee';
        };
        type.addEventListener('change', sync);
        sync();
    });

    // Attendance sheet: "Mark all present" checks that status for every student.
    // Only a convenience; the teacher still reviews and saves the sheet.
    document.querySelectorAll('button[data-mark-all]').forEach(function (button) {
        button.addEventListener('click', function () {
            var form = button.closest('form');
            var status = button.getAttribute('data-mark-all');
            form.querySelectorAll('input[type="radio"][value="' + status + '"]').forEach(function (radio) {
                radio.checked = true;
            });
        });
    });

    // Prevent a double submit from creating two records. Checked after the
    // other submit handlers have run, so a cancelled confirmation or a refused
    // file does not leave the button disabled.
    document.querySelectorAll('form[method="POST"]').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            var button = form.querySelector('button[type="submit"]');
            if (!button || form.hasAttribute('data-allow-resubmit')) {
                return;
            }
            window.setTimeout(function () {
                if (event.defaultPrevented) {
                    return;
                }
                button.disabled = true;
                button.textContent = 'Working...';
            }, 0);
        });
    });
})();
