// Runs in <head> before first paint so there is no light->dark flash.
// External file (not inline) because the dashboard's CSP forbids inline scripts.
(function () {
    var t = null;
    try { t = localStorage.getItem('pakapay-theme'); } catch (e) {}
    if (t !== 'light' && t !== 'dark') t = (window.matchMedia && matchMedia('(prefers-color-scheme: dark)').matches) ? 'dark' : 'light';
    document.documentElement.setAttribute('data-theme', t);
    document.documentElement.setAttribute('data-bs-theme', t);
})();
