// Tema chiaro/scuro: segue il sistema finché l'utente non sceglie, poi ricorda la scelta.
// Va caricato nell'<head> (senza defer) così il tema è applicato prima del primo disegno della pagina.
(function () {
    var KEY = 'postezone-theme';
    var root = document.documentElement;

    function stored() {
        try { return localStorage.getItem(KEY); } catch (e) { return null; }
    }

    function systemTheme() {
        return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    }

    function apply(theme) {
        root.setAttribute('data-theme', theme);
        var meta = document.querySelector('meta[name="theme-color"]');
        if (meta) meta.setAttribute('content', theme === 'dark' ? '#0e1626' : '#ffc20e');
        var btn = document.getElementById('theme-toggle');
        if (btn) {
            btn.textContent = theme === 'dark' ? '☀️' : '🌙';
            btn.title = theme === 'dark' ? 'Passa al tema chiaro' : 'Passa al tema scuro';
            btn.setAttribute('aria-label', btn.title);
        }
    }

    apply(stored() || systemTheme());

    // Se non c'è una scelta salvata, segue i cambi del sistema (es. modalità scura automatica la sera)
    if (window.matchMedia) {
        var mq = window.matchMedia('(prefers-color-scheme: dark)');
        var onChange = function () { if (!stored()) apply(systemTheme()); };
        if (mq.addEventListener) mq.addEventListener('change', onChange);
        else if (mq.addListener) mq.addListener(onChange);
    }

    document.addEventListener('DOMContentLoaded', function () {
        var nav = document.querySelector('header nav') || document.querySelector('header');
        if (!nav) return;
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.id = 'theme-toggle';
        btn.className = 'theme-toggle';
        btn.addEventListener('click', function () {
            var next = root.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
            try { localStorage.setItem(KEY, next); } catch (e) { /* storage non disponibile: vale solo per questa visita */ }
            apply(next);
        });
        nav.appendChild(btn);
        apply(root.getAttribute('data-theme'));
    });
})();
