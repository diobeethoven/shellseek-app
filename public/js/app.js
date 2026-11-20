// ShellSeek UI helpers (no dependencies, no network calls).
// Progressive enhancement only: every form and link works without JS.
(function () {
    'use strict';

    // ------------------------------------------------------------ sidebar
    var toggles = document.querySelectorAll('[data-menu-toggle]');
    function setNav(open) {
        document.body.classList.toggle('nav-open', open);
        toggles.forEach(function (t) { t.setAttribute('aria-expanded', open ? 'true' : 'false'); });
    }
    toggles.forEach(function (t) {
        t.addEventListener('click', function () { setNav(!document.body.classList.contains('nav-open')); });
    });
    document.querySelectorAll('[data-menu-close]').forEach(function (el) {
        el.addEventListener('click', function () { setNav(false); });
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            setNav(false);
        }
    });

    // ------------------------------------------------------------ copy buttons
    document.querySelectorAll('[data-copy]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            if (!navigator.clipboard) { return; }
            navigator.clipboard.writeText(btn.getAttribute('data-copy')).then(function () {
                btn.textContent = 'Copied';
                btn.classList.add('copied');
                setTimeout(function () { btn.textContent = 'Copy'; btn.classList.remove('copied'); }, 1500);
            });
        });
    });

    // ------------------------------------------------------------ auto-submit filters
    document.querySelectorAll('select[data-autosubmit]').forEach(function (sel) {
        sel.addEventListener('change', function () { sel.form.submit(); });
    });

    // ------------------------------------------------------------ confirm destructive forms
    document.querySelectorAll('form[data-confirm]').forEach(function (f) {
        f.addEventListener('submit', function (e) {
            if (!window.confirm(f.getAttribute('data-confirm'))) { e.preventDefault(); }
        });
    });

    // ------------------------------------------------------------ composer
    var form = document.querySelector('[data-recon-form]');
    if (!form) { return; }

    var input = form.querySelector('[data-composer-input]');
    var count = form.querySelector('[data-count]');
    var button = form.querySelector('button[type="submit"]');
    var max = parseInt(input.getAttribute('maxlength'), 10) || 1000;
    var submitted = false;

    function grow() {
        input.style.height = 'auto';
        input.style.height = Math.min(input.scrollHeight, 220) + 'px';
        if (count) {
            var n = input.value.length;
            count.textContent = n > 0 ? n + '/' + max : '';
        }
    }
    input.addEventListener('input', grow);
    grow();

    // Enter sends, Shift+Enter inserts a newline (IME composition is respected).
    input.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) {
            e.preventDefault();
            if (typeof form.requestSubmit === 'function') {
                form.requestSubmit();
            } else if (form.reportValidity()) {
                form.submit();
            }
        }
    });

    // Example prompts fill the composer and select the <placeholder> to replace.
    document.querySelectorAll('[data-example]').forEach(function (ex) {
        ex.addEventListener('click', function () {
            var text = ex.getAttribute('data-example');
            input.value = text;
            grow();
            input.focus();
            var start = text.indexOf('<');
            var end = text.indexOf('>', start);
            if (start >= 0 && end > start) {
                input.setSelectionRange(start, end + 1);
            }
        });
    });

    function el(tag, cls, text) {
        var node = document.createElement(tag);
        if (cls) { node.className = cls; }
        if (text !== undefined) { node.textContent = text; } // never innerHTML with user text
        return node;
    }

    // Pending state: show the new request immediately while the local LLM
    // and the Scope Enforcer work (~20 s). The real result arrives via the
    // normal POST -> redirect -> GET /recon/{id}.
    form.addEventListener('submit', function (e) {
        if (submitted) { e.preventDefault(); return; }
        submitted = true;
        input.readOnly = true; // readOnly (not disabled) so the value is still submitted
        if (button) { button.disabled = true; }
        form.classList.add('is-loading');

        var thread = document.querySelector('[data-thread]');
        if (!thread) { return; }

        var user = el('article', 'msg msg-user is-pending');
        var meta = el('div', 'msg-meta');
        meta.appendChild(el('span', 'msg-who', 'You'));
        user.appendChild(meta);
        user.appendChild(el('div', 'msg-body', input.value));

        var bot = el('article', 'msg msg-assistant');
        var botMeta = el('div', 'msg-meta');
        botMeta.appendChild(el('span', 'msg-avatar', 'S'));
        botMeta.appendChild(el('span', 'msg-who', 'ShellSeek'));
        bot.appendChild(botMeta);
        var thinking = el('div', 'thinking');
        thinking.setAttribute('role', 'status');
        var dots = el('span', 'thinking-dots');
        dots.appendChild(el('i'));
        dots.appendChild(el('i'));
        dots.appendChild(el('i'));
        thinking.appendChild(dots);
        var label = el('span', '', 'Generating the command with the local LLM, then checking it with the Scope Enforcer… (~20 s)');
        thinking.appendChild(label);
        bot.appendChild(thinking);

        thread.replaceChildren(user, bot); // a submit is always a new request

        var home = document.querySelector('[data-home]');
        if (home) { home.classList.remove('is-home'); } // leave the start screen

        var heading = document.querySelector('.chat-head h1');
        if (heading) { heading.textContent = 'Recon Workspace'; }
    });
})();
