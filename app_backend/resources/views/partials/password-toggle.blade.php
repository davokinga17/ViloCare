{{-- Adds a show/hide (eye) button to every password field on the page. --}}
<style>
    .pw-wrap { position: relative; display: block; }
    .pw-wrap > input { padding-right: 44px !important; }
    .pw-toggle {
        position: absolute; top: 50%; right: 6px; transform: translateY(-50%);
        width: 34px; height: 34px; padding: 0; border: 0; border-radius: 6px;
        background: transparent; color: #64748b; cursor: pointer;
        display: inline-flex; align-items: center; justify-content: center;
    }
    .pw-toggle:hover, .pw-toggle:focus-visible { color: #0f172a; background: rgba(100, 116, 139, 0.12); outline: none; }
</style>
<script>
(function () {
    var EYE = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>';
    var EYE_OFF = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>';

    function attach(input) {
        if (input.dataset.pwToggle) { return; }
        input.dataset.pwToggle = '1';

        var wrap = document.createElement('span');
        wrap.className = 'pw-wrap';
        input.parentNode.insertBefore(wrap, input);
        wrap.appendChild(input);

        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'pw-toggle';
        btn.setAttribute('aria-label', 'Show password');
        btn.setAttribute('aria-pressed', 'false');
        btn.innerHTML = EYE;
        btn.addEventListener('click', function () {
            var show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            btn.innerHTML = show ? EYE_OFF : EYE;
            btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
            btn.setAttribute('aria-pressed', show ? 'true' : 'false');
            input.focus();
        });
        wrap.appendChild(btn);
    }

    function run() { document.querySelectorAll('input[type="password"]').forEach(attach); }
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', run); } else { run(); }
})();
</script>
