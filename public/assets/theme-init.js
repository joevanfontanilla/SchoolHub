/*
 * Colour theme, applied BEFORE first paint so there is no flash.
 *
 * Loaded synchronously in <head>. A remembered choice (localStorage "theme")
 * wins; otherwise the device's prefers-color-scheme decides. The live toggle and
 * the system-change listener live in app.js. CSP-safe: this is a same-origin file,
 * never an inline script.
 */
(function () {
    try {
        var saved = localStorage.getItem('theme');
        var dark = saved
            ? saved === 'dark'
            : (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches);
        document.documentElement.classList.toggle('dark', !!dark);
    } catch (e) { /* private mode / storage blocked: default light */ }
})();
