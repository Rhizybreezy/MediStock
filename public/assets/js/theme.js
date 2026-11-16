/* Applies the saved light/dark theme before the page paints (loaded in <head>). */
(function () {
    var theme = null;
    try { theme = localStorage.getItem('ms-theme'); } catch (e) { /* storage unavailable */ }
    if (theme !== 'light' && theme !== 'dark') {
        theme = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    }
    document.documentElement.setAttribute('data-bs-theme', theme);
})();
