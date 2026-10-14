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

    /* Colour theme toggle. theme-init.js already set the class before paint;
       here we wire the buttons and follow the device when nothing is remembered. */
    (function () {
        var root = document.documentElement;

        function isDark() { return root.classList.contains('dark'); }

        function reflect() {
            var dark = isDark();
            document.querySelectorAll('[data-theme-toggle]').forEach(function (button) {
                button.setAttribute('aria-pressed', String(dark));
                var label = dark ? 'Switch to light mode' : 'Switch to dark mode';
                button.setAttribute('aria-label', label);
                button.setAttribute('title', label);
            });
        }

        document.querySelectorAll('[data-theme-toggle]').forEach(function (button) {
            button.addEventListener('click', function () {
                var next = !isDark();
                root.classList.toggle('dark', next);
                try { localStorage.setItem('theme', next ? 'dark' : 'light'); } catch (e) {}
                reflect();
            });
        });

        // Follow the OS setting live, but only while the user hasn't chosen one.
        if (window.matchMedia) {
            var mq = window.matchMedia('(prefers-color-scheme: dark)');
            var onChange = function (e) {
                var saved;
                try { saved = localStorage.getItem('theme'); } catch (err) { saved = null; }
                if (!saved) { root.classList.toggle('dark', e.matches); reflect(); }
            };
            if (mq.addEventListener) { mq.addEventListener('change', onChange); }
            else if (mq.addListener) { mq.addListener(onChange); }
        }

        reflect();
    })();

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

    // The e-signature drawing pad on My Account (docs/08): draw with a mouse, pen, or finger. What is drawn
    // is sent as a PNG with the form; without scripts only the upload field is shown.
    document.querySelectorAll('[data-signature-form]').forEach(function (form) {
        var wrap = form.querySelector('[data-signature-pad-wrap]');
        var canvas = form.querySelector('[data-signature-pad]');
        var drawn = form.querySelector('[data-signature-drawn]');
        if (!wrap || !canvas || !drawn || !canvas.getContext) {
            return;
        }
        wrap.hidden = false;
        var or = form.querySelector('[data-signature-or]');
        var only = form.querySelector('[data-signature-upload-only]');
        if (or) { or.hidden = false; }
        if (only) { only.hidden = true; }

        var ctx = canvas.getContext('2d');
        ctx.lineWidth = 2.5;
        ctx.lineCap = 'round';
        ctx.lineJoin = 'round';
        ctx.strokeStyle = '#111827';
        var drawing = false;
        var marked = false;

        function point(event) {
            var box = canvas.getBoundingClientRect();
            return { x: (event.clientX - box.left) * canvas.width / box.width, y: (event.clientY - box.top) * canvas.height / box.height };
        }
        canvas.addEventListener('pointerdown', function (event) {
            drawing = true;
            canvas.setPointerCapture(event.pointerId);
            var at = point(event);
            ctx.beginPath();
            ctx.moveTo(at.x, at.y);
            ctx.lineTo(at.x + 0.1, at.y + 0.1);
            ctx.stroke();
            marked = true;
            event.preventDefault();
        });
        canvas.addEventListener('pointermove', function (event) {
            if (!drawing) { return; }
            var at = point(event);
            ctx.lineTo(at.x, at.y);
            ctx.stroke();
        });
        ['pointerup', 'pointercancel'].forEach(function (name) {
            canvas.addEventListener(name, function () { drawing = false; });
        });

        var clear = form.querySelector('[data-signature-clear]');
        if (clear) {
            clear.addEventListener('click', function () {
                ctx.clearRect(0, 0, canvas.width, canvas.height);
                marked = false;
                drawn.value = '';
            });
        }

        // A drawing is sent only when something was drawn and no file was chosen.
        form.addEventListener('submit', function () {
            var file = form.querySelector('input[type="file"]');
            drawn.value = marked && !(file && file.files && file.files.length) ? canvas.toDataURL('image/png') : '';
        });
    });

    // Quick amounts: a button fills the amount field and leaves everything else to the cashier.
    document.querySelectorAll('[data-fill-amount]').forEach(function (button) {
        button.addEventListener('click', function () {
            var field = document.querySelector(button.getAttribute('data-fill-target') || '');
            if (field) {
                field.value = button.getAttribute('data-fill-amount');
                field.dispatchEvent(new Event('input', { bubbles: true }));
                field.focus();
            }
        });
    });

    // A receipt opened with ?print=1 offers the print dialog straight away.
    if (document.body.hasAttribute('data-auto-print')) {
        window.print();
    }

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

    // Confirmation before an action (docs/21), in the portal's own window
    // (partials/confirm-dialog.php) rather than the browser's confirm(). A form asks with
    // data-confirm; a submit button asks for itself with data-confirm-click, for forms whose
    // buttons do different things. After "Confirm" the same button submits again, with its
    // name, value, and formaction. Where a reason is required (data-confirm-requires), an
    // empty reason is pointed out before anything is sent.
    (function () {
        var dialog = document.querySelector('[data-confirm-dialog]');
        var modal = dialog && typeof dialog.showModal === 'function';
        var heading = modal ? dialog.querySelector('[data-confirm-heading]') : null;
        var text = modal ? dialog.querySelector('[data-confirm-text]') : null;
        var accept = modal ? dialog.querySelector('[data-confirm-accept]') : null;
        var cancel = modal ? dialog.querySelector('[data-confirm-cancel]') : null;
        var tiles = modal ? dialog.querySelectorAll('[data-confirm-tile]') : [];
        var buttonClass = {
            danger: 'btn-danger',
            warning: 'btn-warning',
            success: 'btn-success',
            primary: 'btn-primary'
        };
        var onAccept = null;
        var returnFocus = null;

        function words(element) {
            return element ? element.textContent.replace(/\s+/g, ' ').trim() : '';
        }

        function guessTone(source, button, message) {
            var explicit = source.getAttribute('data-confirm-tone');
            if (explicit && buttonClass[explicit]) {
                return explicit;
            }
            var classes = button ? button.className : '';
            if (/danger/.test(classes)) {
                return 'danger';
            }
            var said = (words(button) + ' ' + message).toLowerCase();
            if (/\b(delete|reject|reverse|disable|drop|remove|cancel)\b/.test(said)) {
                return 'danger';
            }
            if (/\b(return|correction|replace|close|archive|reopen|unlock|weights)\b/.test(said) || /warning/.test(classes)) {
                return 'warning';
            }
            if (/\b(approve|verify|confirm|enroll|publish|post|enable|record|issue|send|submit)\b/.test(said) || /success/.test(classes)) {
                return 'success';
            }
            return 'primary';
        }

        function open(options) {
            heading.textContent = options.title;
            text.textContent = options.message;
            text.hidden = options.message === '';
            Array.prototype.forEach.call(tiles, function (tile) {
                tile.hidden = tile.getAttribute('data-confirm-tile') !== options.tone;
            });
            accept.className = buttonClass[options.tone] || 'btn-primary';
            accept.textContent = options.label;
            cancel.hidden = options.blocked === true;
            onAccept = options.onAccept;
            returnFocus = options.returnFocus || null;
            dialog.showModal();
            // A risky action starts on "Go back"; anything else starts on the confirming button.
            (options.tone === 'danger' && !options.blocked ? cancel : accept).focus();
        }

        function close() {
            if (dialog.open) {
                dialog.close();
            }
        }

        if (modal) {
            accept.addEventListener('click', function () {
                var run = onAccept;
                onAccept = null;
                close();
                if (typeof run === 'function') {
                    run();
                }
            });
            cancel.addEventListener('click', close);
            dialog.addEventListener('click', function (event) {
                if (event.target === dialog) {
                    close();
                }
            });
            dialog.addEventListener('close', function () {
                if (returnFocus && document.contains(returnFocus)) {
                    returnFocus.focus();
                }
                returnFocus = null;
            });
        }

        function resubmit(form, button) {
            form.__confirmed = true;
            if (typeof form.requestSubmit === 'function') {
                form.requestSubmit(button || undefined);
            } else {
                if (button && button.name) {
                    var carry = document.createElement('input');
                    carry.type = 'hidden';
                    carry.name = button.name;
                    carry.value = button.value;
                    form.appendChild(carry);
                }
                if (button && button.hasAttribute('formaction')) {
                    form.action = button.getAttribute('formaction');
                }
                form.submit();
            }
            // requestSubmit fires the submit event at once; if validation stopped it, ask again next time.
            window.setTimeout(function () {
                form.__confirmed = false;
            }, 0);
        }

        document.addEventListener('submit', function (event) {
            var form = event.target;
            if (!(form instanceof HTMLFormElement)) {
                return;
            }
            var button = event.submitter || null;
            var source = button && button.hasAttribute('data-confirm-click') ? button : (form.hasAttribute('data-confirm') ? form : null);
            if (!source) {
                return;
            }
            if (form.__confirmed) {
                form.__confirmed = false;
                return;
            }
            event.preventDefault();

            var message = source.getAttribute(source === button ? 'data-confirm-click' : 'data-confirm') || 'Continue?';
            if (!modal) {
                if (window.confirm(message)) {
                    resubmit(form, button);
                }
                return;
            }

            // A required reason, checked before asking.
            var requires = source.getAttribute('data-confirm-requires') || form.getAttribute('data-confirm-requires');
            var field = requires ? (form.querySelector(requires) || document.querySelector(requires)) : null;
            if (field && field.value.trim() === '') {
                open({
                    title: 'Add a reason first',
                    message: source.getAttribute('data-confirm-requires-message') || form.getAttribute('data-confirm-requires-message') || 'Write the reason in the box before continuing.',
                    tone: 'warning',
                    label: 'Write the reason',
                    blocked: true,
                    returnFocus: field,
                    onAccept: null
                });
                return;
            }

            var tone = guessTone(source, button, message);
            var buttonWords = words(button);
            var title = source.getAttribute('data-confirm-title');
            // "Reject this payment? The student will see your note.": the question is the title.
            var split = !title && message.match(/^([^?]{3,160}\?)\s*([\s\S]*)$/);
            if (split) {
                title = split[1];
                message = split[2];
            }
            open({
                title: title || (buttonWords !== '' ? buttonWords.replace(/[.?!]+$/, '') + '?' : 'Are you sure?'),
                message: message,
                tone: tone,
                label: source.getAttribute('data-confirm-label') || buttonWords || 'Confirm',
                returnFocus: button,
                onAccept: function () {
                    resubmit(form, button);
                }
            });
        }, true);
    })();

    // Flash messages: a Dismiss button removes one.
    document.querySelectorAll('[data-alert-dismiss]').forEach(function (button) {
        button.hidden = false;
        button.addEventListener('click', function () {
            var alert = button.closest('[data-alert]');
            if (alert) {
                alert.remove();
            }
        });
    });

    // Announcement form: show the group choices only for "Chosen groups", and the
    // program and year-level narrowing only when Students is ticked. Without
    // JavaScript every field stays visible and the server validates the combination.
    document.querySelectorAll('form[data-announcement-form]').forEach(function (form) {
        var targets = form.querySelector('[data-targets]');
        var narrowing = form.querySelector('[data-student-narrowing]');
        var studentRole = form.querySelector('[data-student-role]');
        function sync() {
            var chosen = form.querySelector('input[data-audience]:checked');
            if (targets) {
                targets.hidden = !chosen || chosen.value !== 'TARGETED';
            }
            if (narrowing) {
                narrowing.hidden = !studentRole || !studentRole.checked;
            }
        }
        form.addEventListener('change', sync);
        sync();
    });

    // Copy a one-time link to the clipboard. The field stays selectable, so copying
    // by hand still works where the clipboard API is unavailable (plain http).
    document.querySelectorAll('[data-copy]').forEach(function (button) {
        button.addEventListener('click', function () {
            var field = document.querySelector(button.getAttribute('data-copy'));
            if (!field) {
                return;
            }
            field.focus();
            field.select();
            var done = function () {
                button.textContent = 'Copied';
            };
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(field.value).then(done);
            } else if (document.execCommand && document.execCommand('copy')) {
                done();
            }
        });
    });
    document.querySelectorAll('[data-select-on-focus]').forEach(function (field) {
        field.addEventListener('focus', function () {
            field.select();
        });
    });

    // Filter forms (data-auto-submit) apply by themselves. A dropdown, checkbox, or date changes the
    // results at once; a search box applies about half a second after typing stops, and the cursor is put
    // back in it after the page reloads so typing can continue. The Show / Search buttons are hidden while
    // scripts work (they stay for visitors without scripts). A date range waits until it is valid: the
    // start date may not be after the end date.
    (function () {
        var FOCUS_KEY = 'schoolhub.autoSubmitFocus';
        var DELAY = 500;

        function isTextField(field) {
            var type = (field.getAttribute('type') || 'text').toLowerCase();
            return field.tagName === 'INPUT' && (type === 'search' || type === 'text' || type === 'number');
        }

        function rangeProblem(form) {
            var from = form.querySelector('input[type="date"][name="from"]');
            var to = form.querySelector('input[type="date"][name="to"]');
            if (from && to && from.value && to.value && from.value > to.value) {
                return 'The start date must be on or before the end date.';
            }
            return null;
        }

        function showProblem(form, message) {
            var note = form.querySelector('[data-range-note]');
            if (!message) {
                if (note) {
                    note.remove();
                }
                return;
            }
            if (!note) {
                note = document.createElement('p');
                note.setAttribute('data-range-note', '');
                note.setAttribute('role', 'alert');
                note.className = 'field-error w-full';
                form.appendChild(note);
            }
            note.textContent = message;
        }

        function apply(form, field) {
            var problem = rangeProblem(form);
            showProblem(form, problem);
            if (problem) {
                return;
            }
            var page = form.querySelector('input[name="page"]');
            if (page) {
                page.value = '1';
            }
            if (field && isTextField(field)) {
                try {
                    sessionStorage.setItem(FOCUS_KEY, JSON.stringify({ action: form.getAttribute('action'), name: field.name }));
                } catch (e) { /* storage blocked: the cursor simply is not restored */ }
            }
            form.submit();
        }

        document.querySelectorAll('form[data-auto-submit]').forEach(function (form) {
            var timers = [];

            form.querySelectorAll('button[type="submit"]:not([data-keep]), input[type="submit"]:not([data-keep])').forEach(function (button) {
                button.hidden = true;
            });

            form.querySelectorAll('select, input[type="date"], input[type="month"], input[type="checkbox"], input[type="radio"]').forEach(function (field) {
                field.addEventListener('change', function () {
                    apply(form, field);
                });
            });

            form.querySelectorAll('input[type="search"], input[type="text"], input[type="number"]').forEach(function (field) {
                var last = field.value;
                field.addEventListener('input', function (event) {
                    if (event.isComposing) {
                        return;
                    }
                    clearTimeout(timers[field.name]);
                    timers[field.name] = setTimeout(function () {
                        if (field.value !== last) {
                            last = field.value;
                            apply(form, field);
                        }
                    }, DELAY);
                });
            });

            // Enter submits the form itself, so a waiting timer must not submit it a second time.
            form.addEventListener('submit', function () {
                Object.keys(timers).forEach(function (name) {
                    clearTimeout(timers[name]);
                });
            });
        });

        // After a search reloads the page, put the cursor back at the end of the box.
        try {
            var saved = sessionStorage.getItem(FOCUS_KEY);
            if (saved) {
                sessionStorage.removeItem(FOCUS_KEY);
                var where = JSON.parse(saved);
                document.querySelectorAll('form[data-auto-submit]').forEach(function (form) {
                    if (form.getAttribute('action') !== where.action) {
                        return;
                    }
                    var field = form.querySelector('input[name="' + where.name + '"]');
                    if (field) {
                        field.focus({ preventScroll: true });
                        try {
                            var end = field.value.length;
                            field.setSelectionRange(end, end);
                        } catch (e) { /* a number box has no caret to move */ }
                    }
                });
            }
        } catch (e) { /* nothing to restore */ }
    }());
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

    // Live preview for image uploads: show the chosen picture before it is submitted, so the user
    // confirms it first. A field wraps its file input, a preview <img>, and an optional placeholder.
    document.querySelectorAll('[data-image-field]').forEach(function (field) {
        var input = field.querySelector('input[type="file"]');
        var img = field.querySelector('[data-image-preview-target]');
        if (!input || !img) {
            return;
        }
        var placeholder = field.querySelector('[data-image-preview-placeholder]');
        var originalSrc = img.getAttribute('src') || '';
        var objectUrl = null;

        input.addEventListener('change', function () {
            var file = input.files && input.files[0];
            if (objectUrl) {
                URL.revokeObjectURL(objectUrl);
                objectUrl = null;
            }
            if (file && /^image\//.test(file.type)) {
                objectUrl = URL.createObjectURL(file);
                img.src = objectUrl;
                img.hidden = false;
                if (placeholder) { placeholder.hidden = true; }
            } else {
                // Nothing chosen, or not an image: fall back to the saved picture or the placeholder.
                img.src = originalSrc;
                img.hidden = originalSrc === '';
                if (placeholder) { placeholder.hidden = originalSrc !== ''; }
            }
        });
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

    // Password fields (components/field.php): a button shows or hides what was typed, and a
    // notice appears while Caps Lock is on. The field is hidden again before the form is
    // sent, so the browser never stores it as plain text.
    document.querySelectorAll('input[data-password]').forEach(function (input) {
        var wrap = input.parentElement;
        var toggle = wrap.querySelector('[data-password-toggle]');
        var caps = document.getElementById(input.id + '-caps');

        if (toggle) {
            var show = toggle.querySelector('[data-password-show]');
            var hide = toggle.querySelector('[data-password-hide]');
            var label = toggle.querySelector('[data-password-label]');
            var set = function (visible) {
                input.type = visible ? 'text' : 'password';
                toggle.setAttribute('aria-pressed', String(visible));
                show.hidden = visible;
                hide.hidden = !visible;
                label.textContent = visible ? 'Hide password' : 'Show password';
                toggle.title = label.textContent;
            };
            toggle.hidden = false;
            toggle.title = 'Show password';
            toggle.addEventListener('click', function () {
                var start = input.selectionStart;
                var end = input.selectionEnd;
                set(input.type === 'password');
                input.focus();
                try {
                    input.setSelectionRange(start, end);
                } catch (error) {
                    // Some browsers do not allow a selection on every input type.
                }
            });
            if (input.form) {
                input.form.addEventListener('submit', function () {
                    set(false);
                });
            }
        }

        if (caps) {
            var check = function (event) {
                if (typeof event.getModifierState === 'function') {
                    caps.hidden = !event.getModifierState('CapsLock');
                }
            };
            input.addEventListener('keydown', check);
            input.addEventListener('keyup', check);
            input.addEventListener('blur', function () {
                caps.hidden = true;
            });
        }
    });

    // Top-bar quick search (partials/app-topbar.php): a combobox listing the pages of this
    // portal that match what is typed, plus "Search students for …" where the role has a
    // search. Arrow keys move, Enter opens, Escape closes, "/" focuses the box.
    document.querySelectorAll('form[data-quick-search]').forEach(function (form) {
        var input = form.querySelector('input[role="combobox"]');
        var list = form.querySelector('[role="listbox"]');
        if (!input || !list) {
            return;
        }
        var pages = Array.prototype.slice.call(list.querySelectorAll('[role="option"][data-href]'));
        var submitOption = list.querySelector('[data-search-submit]');
        var echo = list.querySelector('[data-search-echo]');
        var empty = list.querySelector('[data-search-empty]');
        var hasTarget = form.hasAttribute('data-search-target');
        var active = null;

        function shown() {
            return Array.prototype.filter.call(list.querySelectorAll('[role="option"]'), function (option) {
                return !option.hidden;
            });
        }

        function setActive(option) {
            if (active) {
                active.setAttribute('aria-selected', 'false');
            }
            active = option;
            if (option) {
                option.setAttribute('aria-selected', 'true');
                input.setAttribute('aria-activedescendant', option.id);
                option.scrollIntoView({ block: 'nearest' });
            } else {
                input.removeAttribute('aria-activedescendant');
            }
        }

        function close() {
            list.hidden = true;
            input.setAttribute('aria-expanded', 'false');
            setActive(null);
        }

        function update() {
            var query = input.value.trim().toLowerCase();
            if (query === '') {
                close();
                return;
            }
            var words = query.split(/\s+/);
            var count = 0;
            pages.forEach(function (option) {
                var terms = option.getAttribute('data-terms');
                var match = count < 8 && words.every(function (word) { return terms.indexOf(word) !== -1; });
                option.hidden = !match;
                if (match) {
                    count++;
                }
            });
            if (submitOption) {
                submitOption.hidden = false;
                echo.textContent = input.value.trim();
            }
            if (empty) {
                empty.hidden = count > 0 || hasTarget;
            }
            list.hidden = false;
            input.setAttribute('aria-expanded', 'true');
            setActive(null);
        }

        function choose(option) {
            if (!option) {
                return;
            }
            if (option.hasAttribute('data-search-submit')) {
                form.submit();
            } else {
                window.location.href = option.getAttribute('data-href');
            }
        }

        input.addEventListener('input', update);
        input.addEventListener('focus', function () {
            if (input.value.trim() !== '') {
                update();
            }
        });
        input.addEventListener('keydown', function (event) {
            var options = shown();
            var index = options.indexOf(active);
            if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                event.preventDefault();
                if (list.hidden) {
                    update();
                    options = shown();
                }
                if (options.length > 0) {
                    var step = event.key === 'ArrowDown' ? 1 : -1;
                    setActive(options[(index + step + options.length) % options.length]);
                }
            } else if (event.key === 'Enter') {
                if (input.value.trim() === '') {
                    event.preventDefault();
                    return;
                }
                event.preventDefault();
                if (active) {
                    choose(active);
                } else if (hasTarget) {
                    form.submit();
                } else {
                    // No search behind this box: open the best matching page, if any.
                    choose(pages.filter(function (option) { return !option.hidden; })[0]);
                }
            } else if (event.key === 'Escape') {
                if (!list.hidden) {
                    event.preventDefault();
                    close();
                }
            }
        });
        list.addEventListener('mousedown', function (event) {
            // Keep focus in the box so the list does not close before the click lands.
            event.preventDefault();
        });
        list.addEventListener('click', function (event) {
            choose(event.target.closest('[role="option"]'));
        });
        input.addEventListener('blur', close);

        document.addEventListener('keydown', function (event) {
            var target = event.target;
            var typing = target && (target.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(target.tagName));
            if (event.key === '/' && !typing && !event.ctrlKey && !event.metaKey && !event.altKey && input.offsetParent !== null) {
                event.preventDefault();
                input.focus();
            }
        });
    });

    // Document preview window (partials/preview-dialog.php). A link made with
    // partials/preview-link opens its document here instead of leaving the page:
    // an image directly, a PDF or a printable page in a frame this site is allowed to
    // fill. Links sharing data-preview-group get Previous and Next. Ctrl/Cmd/Shift-click
    // and browsers without <dialog> just follow the link.
    (function () {
        var dialog = document.querySelector('[data-preview-dialog]');
        if (!dialog || typeof dialog.showModal !== 'function') {
            return;
        }
        var heading = dialog.querySelector('[data-preview-heading]');
        var position = dialog.querySelector('[data-preview-position]');
        var body = dialog.querySelector('[data-preview-body]');
        var prev = dialog.querySelector('[data-preview-prev]');
        var next = dialog.querySelector('[data-preview-next]');
        var printButton = dialog.querySelector('[data-preview-print]');
        var download = dialog.querySelector('[data-preview-download]');
        var openLink = dialog.querySelector('[data-preview-open]');
        var hint = dialog.querySelector('[data-preview-hint]');
        var items = [];
        var index = 0;
        var trigger = null;

        function groupOf(link) {
            var group = link.getAttribute('data-preview-group');
            if (!group) {
                return [link];
            }
            // One entry per document, even when two links on the page open the same one.
            var seen = {};
            return Array.prototype.filter.call(document.querySelectorAll('a[data-preview-group]'), function (other) {
                var key = other.getAttribute('data-preview');
                if (other.getAttribute('data-preview-group') !== group || seen[key]) {
                    return false;
                }
                seen[key] = true;
                return true;
            });
        }

        function show() {
            var link = items[index];
            var kind = link.getAttribute('data-preview-kind');
            var source = link.getAttribute('data-preview');
            var title = link.getAttribute('data-preview-title') || link.textContent.trim();

            heading.textContent = title;
            position.hidden = items.length < 2;
            position.textContent = (index + 1) + ' of ' + items.length;
            prev.hidden = next.hidden = items.length < 2;
            prev.disabled = index === 0;
            next.disabled = index === items.length - 1;

            while (body.firstChild) {
                body.removeChild(body.firstChild);
            }
            if (kind === 'image') {
                var wrap = document.createElement('div');
                wrap.className = 'flex min-h-full items-center justify-center p-4';
                var image = document.createElement('img');
                image.src = source;
                image.alt = title;
                image.className = 'max-h-full max-w-full rounded-lg bg-white shadow';
                wrap.appendChild(image);
                body.appendChild(wrap);
            } else {
                var frame = document.createElement('iframe');
                frame.src = source;
                frame.title = title;
                frame.className = 'preview-frame';
                body.appendChild(frame);
            }

            printButton.hidden = kind !== 'page';
            if (hint) {
                hint.hidden = kind !== 'pdf';
            }
            var file = link.getAttribute('data-preview-download');
            download.hidden = !file;
            if (file) {
                download.href = file;
            }
            openLink.href = link.href;
        }

        function move(step) {
            var target = index + step;
            if (target >= 0 && target < items.length) {
                index = target;
                show();
            }
        }

        document.addEventListener('click', function (event) {
            var link = event.target.closest ? event.target.closest('a[data-preview]') : null;
            if (!link || event.defaultPrevented || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) {
                return;
            }
            event.preventDefault();
            trigger = link;
            items = groupOf(link);
            index = Math.max(0, items.indexOf(link));
            show();
            dialog.showModal();
        });

        prev.addEventListener('click', function () { move(-1); });
        next.addEventListener('click', function () { move(1); });
        dialog.querySelector('[data-preview-close]').addEventListener('click', function () { dialog.close(); });
        printButton.addEventListener('click', function () {
            var frame = body.querySelector('iframe');
            if (frame && frame.contentWindow) {
                frame.contentWindow.focus();
                frame.contentWindow.print();
            }
        });
        // A click on the dimmed backdrop (the dialog element itself) closes it.
        dialog.addEventListener('click', function (event) {
            if (event.target === dialog) {
                dialog.close();
            }
        });
        dialog.addEventListener('keydown', function (event) {
            if (event.key === 'ArrowLeft' && items.length > 1) {
                move(-1);
            } else if (event.key === 'ArrowRight' && items.length > 1) {
                move(1);
            }
        });
        dialog.addEventListener('close', function () {
            // The close event arrives a moment later; if another document was opened in the
            // meantime, leave it alone.
            if (dialog.open) {
                return;
            }
            // Stop loading the document and give focus back to the link that opened it.
            while (body.firstChild) {
                body.removeChild(body.firstChild);
            }
            if (trigger) {
                trigger.focus();
            }
        });
    })();

    // Prevent a double submit from creating two records. Checked after the
    // other submit handlers have run, so a cancelled confirmation or a refused
    // file does not leave the button disabled.
    document.querySelectorAll('form[method="POST"]').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            var button = event.submitter || form.querySelector('button[type="submit"]');
            if (!button || form.hasAttribute('data-allow-resubmit')) {
                return;
            }
            window.setTimeout(function () {
                if (event.defaultPrevented) {
                    return;
                }
                button.disabled = true;
                button.textContent = button.getAttribute('data-busy-label') || 'Working...';
            }, 0);
        });
    });
})();
