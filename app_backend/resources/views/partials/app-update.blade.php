{{-- Desktop-app update messages. Rendered only inside the desktop app (sync.update_secret is set by the shell). --}}
@php($updateRequired = app(\App\Services\SyncService::class)->updateRequired())
@php($isUpdateAdmin = auth()->check() && auth()->user()->isAdmin() && config('sync.update_secret'))

@if($updateRequired)
<div class="alert alert-warning" id="app-update-required-banner" role="alert" style="padding: 0.75rem 1rem; margin-bottom: 1rem; border-radius: 12px; border: 1px solid #fde68a; background: #fffbeb; color: #92400e;">
    <div>
        <strong>This version is out of date. Sync is paused until you update.</strong>
        Your records are safe and stay on this computer.
        @if($isUpdateAdmin)
            <span id="app-update-required-admin" hidden>An update has been downloaded. Choose <strong>Restart and update now</strong> in the notice at the bottom right to install it.</span>
        @else
            Please ask an administrator to approve the update.
        @endif
    </div>
</div>
@endif

@if($isUpdateAdmin)
{{-- Everything below exists only in the page of an administrator; the approval itself is also checked on the server. --}}
<div id="app-update-notice" hidden role="status" style="position: fixed; right: 20px; bottom: 20px; z-index: 50; max-width: 340px; padding: 16px 18px; border-radius: 14px; background: #ffffff; color: #0f172a; border: 1px solid #cbd5e1; box-shadow: 0 10px 30px rgba(15,23,42,.18);">
    <strong style="display: block; margin-bottom: 4px;">A ViLoCare update is ready<span id="app-update-version"></span></strong>
    <span style="display: block; font-size: 0.85rem; color: #475569; margin-bottom: 12px;">It installs by itself the next time ViLoCare is closed. Or restart now: syncing pauses for a minute while it installs.</span>
    <span id="app-update-message" style="display: block; font-size: 0.8rem; color: #b91c1c; margin-bottom: 8px;" hidden></span>
    <div style="display: flex; gap: 8px;">
        <button type="button" class="btn btn-primary" id="app-update-now">Restart and update now</button>
        <button type="button" class="btn btn-secondary" id="app-update-later">Later</button>
    </div>
</div>
<script>
(function () {
    const api = window.viloUpdater;
    if (!api) return; // not running inside the desktop shell
    const box = document.getElementById('app-update-notice');
    const adminHint = document.getElementById('app-update-required-admin');
    const msg = document.getElementById('app-update-message');
    const key = 'viloUpdateLater';
    const later = () => { try { return sessionStorage.getItem(key); } catch (e) { return null; } };

    function render(s) {
        const ready = !!s && s.state === 'ready';
        document.getElementById('app-update-version').textContent = ready && s.version ? ' (' + s.version + ')' : '';
        box.hidden = !ready || later() === s.version;
        if (adminHint) adminHint.hidden = !ready;
    }

    api.getState().then(render, () => {});
    api.onChange(render);

    document.getElementById('app-update-later').addEventListener('click', async () => {
        const s = await api.getState().catch(() => null);
        try { sessionStorage.setItem(key, s && s.version ? s.version : '1'); } catch (e) {}
        box.hidden = true;
    });

    document.getElementById('app-update-now').addEventListener('click', async (ev) => {
        ev.target.disabled = true;
        msg.hidden = true;
        try {
            // The server only answers administrators; the shell checks the signature before installing.
            const res = await fetch(@json(route('app-update.approval')), {
                method: 'POST',
                headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
            });
            if (!res.ok) throw new Error('not allowed');
            const { token } = await res.json();
            const out = await api.approve(token);
            if (out && out.error) throw new Error(out.error);
            msg.style.color = '#15803d';
            msg.textContent = 'Installing… ViLoCare will reopen by itself.';
            msg.hidden = false;
        } catch (e) {
            msg.style.color = '#b91c1c';
            msg.textContent = (e && e.message && e.message !== 'not allowed') ? e.message : 'The update could not be started now. It will install the next time ViLoCare is closed.';
            msg.hidden = false;
            ev.target.disabled = false;
        }
    });
})();
</script>
@endif
