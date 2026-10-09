// Admin shell behaviour. Same behaviour as dashboard-and-api's layout, but wired with
// addEventListener/data attributes instead of inline handlers (CSP: script-src 'self').
(function () {
    var sidebar = document.getElementById('sidebar');
    var overlay = document.getElementById('sidebarOverlay');
    function openSidebar()  { if (sidebar) { sidebar.classList.add('open');    overlay.classList.add('open'); } }
    function closeSidebar() { if (sidebar) { sidebar.classList.remove('open'); overlay.classList.remove('open'); } }
    document.querySelectorAll('[data-open-sidebar]').forEach(function (b) { b.addEventListener('click', openSidebar); });
    if (overlay) overlay.addEventListener('click', closeSidebar);
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeSidebar(); });

    // Theme toggle
    var root = document.documentElement, btn = document.getElementById('themeToggle');
    function paint() {
        if (!btn) return;
        var dark = root.getAttribute('data-theme') === 'dark';
        btn.firstElementChild.className = 'ti ' + (dark ? 'ti-sun' : 'ti-moon');
        btn.setAttribute('aria-label', dark ? 'Switch to light mode' : 'Switch to dark mode');
    }
    if (btn) {
        paint();
        btn.addEventListener('click', function () {
            var next = root.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
            root.setAttribute('data-theme', next); root.setAttribute('data-bs-theme', next);
            try { localStorage.setItem('pakapay-theme', next); } catch (e) {}
            paint();
        });
    }

    // Confirmation dialog for <form data-confirm="…" [data-confirm-label] [data-confirm-tone="danger|primary"]>
    var el = document.getElementById('confirmModal'), pending = null;
    if (el && window.bootstrap) {
        var okBtn = document.getElementById('confirmOk');
        document.addEventListener('submit', function (e) {
            var form = e.target;
            if (!form.matches || !form.matches('form[data-confirm]')) return;
            e.preventDefault();
            pending = form;
            var tone = form.dataset.confirmTone === 'primary' ? 'primary' : 'danger';
            document.getElementById('confirmMessage').textContent = form.dataset.confirm;
            okBtn.textContent = form.dataset.confirmLabel || 'Confirm';
            okBtn.className = 'btn ' + (tone === 'primary' ? 'btn-brand' : 'btn-danger');
            var icon = document.getElementById('confirmIcon');
            icon.className = 'confirm-icon is-' + tone;
            icon.firstElementChild.className = 'ti ' + (tone === 'primary' ? 'ti-help-circle' : 'ti-alert-triangle');
            bootstrap.Modal.getOrCreateInstance(el).show();
        }, true);
        okBtn.addEventListener('click', function () {
            if (!pending) return;
            this.disabled = true;
            pending.removeAttribute('data-confirm');
            pending.submit();
        });
        el.addEventListener('shown.bs.modal', function () { okBtn.focus(); });
        el.addEventListener('hidden.bs.modal', function () { pending = null; okBtn.disabled = false; });
    }

    // Toasts: success clears itself, errors stay until dismissed
    document.querySelectorAll('.app-toast[data-autohide="1"]').forEach(function (t) { setTimeout(function () { t.remove(); }, 5000); });
    document.querySelectorAll('.app-toast-close').forEach(function (b) { b.addEventListener('click', function () { b.parentElement.remove(); }); });

    // Copy a one-time secret
    document.querySelectorAll('[data-copy]').forEach(function (b) {
        b.addEventListener('click', function () {
            var target = document.querySelector(b.dataset.copy);
            if (!target || !navigator.clipboard) return;
            navigator.clipboard.writeText(target.textContent.trim()).then(function () {
                var old = b.innerHTML; b.textContent = 'Copied'; setTimeout(function () { b.innerHTML = old; }, 1500);
            });
        });
    });

    // Navigation progress bar
    document.querySelectorAll('a[href]:not([href="#"]):not([target="_blank"])').forEach(function (a) {
        a.addEventListener('click', function (e) {
            if (e.ctrlKey || e.metaKey || e.shiftKey) return;
            var bar = document.getElementById('progress-bar-container'), prog = document.getElementById('progress-bar');
            if (bar && prog) { bar.style.display = 'block'; prog.style.width = '70%'; }
        });
    });
})();
