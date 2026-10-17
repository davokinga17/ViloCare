/**
 * ViLoCare sync UI
 *
 * The scheduler (php artisan schedule:work, every minute) does the syncing; this page never starts a sync on
 * its own: no load, timer, online or form-submit triggers. The header badge is rendered by the server from the
 * scheduler's last run. This script only
 *   1. starts a background sync when someone clicks "Sync now" and shows its progress (polling only while it runs),
 *   2. keeps the "last sync / heartbeat age" labels on the Sync Center ticking (client-side only, no requests).
 */
(function () {
    'use strict';

    const CONFIG = {
        statusUrl: '/sync/status-check?light=1',
        pollMs: 2000,
        maxWaitMs: 10 * 60 * 1000,
    };

    const health = { receivedAt: 0, heartbeatAge: null, lastSyncAge: null, standalone: false };
    const csrf = () => document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    const esc = (s) => String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

    const PHASES = { push: 'Sending local changes', pull: 'Receiving updates from the cloud', messages: 'Sending queued messages' };

    const ViLoCareSync = {
        init() {
            this.initHealth();
            this.setupManualSyncButton();
        },

        /** Updates the header badge from a server-provided summary {state,label,title}. */
        setBadge(summary) {
            const el = document.getElementById('global-sync-status');
            const text = document.getElementById('sync-status-text');
            if (!el || !text || !summary) return;
            el.className = 'sync-indicator sync-' + summary.state;
            el.title = summary.title || '';
            text.textContent = summary.label;
        },

        showBanner(type, html) {
            const container = document.querySelector('.content-body');
            if (!container) return;
            const old = document.getElementById('dynamic-sync-alert');
            if (old) old.remove();
            const box = document.createElement('div');
            box.id = 'dynamic-sync-alert';
            box.className = `alert alert-${type === 'success' ? 'success' : 'warning'}`;
            box.style.marginBottom = '20px';
            box.innerHTML = `<span>${type === 'success' ? '✅' : '⚠️'}</span><div>${html}</div>`;
            container.insertBefore(box, container.firstChild);
        },

        progressText(run) {
            if (run.starting) return 'Starting…';
            const label = PHASES[run.phase] || 'Working…';
            return run.phase && run.step > 1 ? `${label} (step ${run.step})…` : `${label}…`;
        },

        resultHtml(result) {
            if (!result) return 'The sync finished.';
            if (result.mode === 'not_configured') return 'Sync is not set up. An administrator can add the connection in Settings.';
            if (!result.success) {
                return '<strong>The sync could not finish.</strong> Your records are safe on this computer and the automatic sync will keep trying. Details are shown on this page.';
            }
            let msg = `Sync completed: sent ${result.pushed || 0} local changes, received ${result.pulled || 0} cloud updates.`;
            if (result.sms_processed > 0) msg += ` Sent ${result.sms_processed} queued messages.`;
            if (result.failed > 0) msg += ` ${result.failed} record(s) need review.`;
            return esc(msg);
        },

        /** Polls only while a manual sync is running. */
        async watch(btn, originalHtml) {
            const startedAt = Date.now();
            let last = null;
            while (Date.now() - startedAt < CONFIG.maxWaitMs) {
                try {
                    const res = await fetch(CONFIG.statusUrl, { headers: { Accept: 'application/json' } });
                    if (res.status === 401 || res.status === 419) return;
                    const data = await res.json();
                    last = data;
                    this.setBadge(data.summary);
                    if (!data.run.running) break;
                    btn.innerHTML = `<span class="spin-icon">🔄</span> ${esc(this.progressText(data.run))}`;
                } catch (e) {
                    /* the next poll retries */
                }
                await new Promise((r) => setTimeout(r, CONFIG.pollMs));
            }
            btn.innerHTML = originalHtml;
            btn.disabled = false;
            if (last && !last.run.running) {
                const ok = !last.run.last_result || last.run.last_result.success;
                this.showBanner(ok ? 'success' : 'warning', this.resultHtml(last.run.last_result));
                // Refresh the Sync Center numbers and any "last problem" card.
                setTimeout(() => window.location.reload(), 2500);
            }
        },

        setupManualSyncButton() {
            const btn = document.getElementById('btn-sync-now');
            const form = btn && btn.closest('form');
            if (!btn || !form) return;
            const originalHtml = btn.innerHTML;

            // A sync that was already running when the page opened (scheduler or earlier click).
            if (btn.dataset.running === '1') {
                btn.disabled = true;
                this.watch(btn, originalHtml);
            }

            form.addEventListener('submit', async (e) => {
                e.preventDefault();
                if (btn.disabled) return;
                btn.disabled = true;
                btn.innerHTML = '<span class="spin-icon">🔄</span> Starting…';
                try {
                    const res = await fetch(form.action, {
                        method: 'POST',
                        headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf(), 'X-Requested-With': 'XMLHttpRequest' },
                        body: '{}',
                    });
                    const data = await res.json();
                    if (!data.success) {
                        btn.innerHTML = originalHtml;
                        btn.disabled = false;
                        this.showBanner('warning', esc(data.message || 'The sync could not be started.'));
                        return;
                    }
                    this.showBanner('success', esc(data.message));
                    this.watch(btn, originalHtml);
                } catch (err) {
                    btn.innerHTML = originalHtml;
                    btn.disabled = false;
                    this.showBanner('warning', 'The sync could not be started. Please try again.');
                }
            });
        },

        humanAge(seconds) {
            if (seconds < 45) return 'just now';
            if (seconds < 3600) {
                const m = Math.max(1, Math.round(seconds / 60));
                return `${m} minute${m === 1 ? '' : 's'}`;
            }
            if (seconds < 86400) {
                const h = Math.round(seconds / 3600);
                return `${h} hour${h === 1 ? '' : 's'}`;
            }
            const d = Math.round(seconds / 86400);
            return `${d} day${d === 1 ? '' : 's'}`;
        },

        /**
         * Truthful auto-sync badge on the Sync Center: green only while the scheduler really ran within 3 minutes;
         * amber "stalled" otherwise. "Last successful sync" turns amber after 10 minutes.
         */
        renderHealth() {
            const badge = document.getElementById('auto-sync-badge');
            const lastVal = document.getElementById('stat-last-synced-val');
            const elapsed = health.receivedAt ? Math.floor((Date.now() - health.receivedAt) / 1000) : 0;

            if (badge && !health.standalone) {
                const stalledAfter = parseInt(badge.dataset.stalledAfter || '180', 10);
                const age = health.heartbeatAge === null ? null : health.heartbeatAge + elapsed;
                const stalled = age === null || age > stalledAfter;

                badge.classList.toggle('badge-success', !stalled);
                badge.style.background = stalled ? '#fef3c7' : '';
                badge.style.color = stalled ? '#b45309' : '';
                badge.textContent = !stalled
                    ? '⚡ Auto-Sync Active (Every 60s)'
                    : (age === null
                        ? '⚠️ Auto-sync stalled, no run recorded yet'
                        : `⚠️ Auto-sync stalled, last run ${this.humanAge(age)} ago`);
            }

            if (lastVal && health.lastSyncAge !== null) {
                const staleAfter = parseInt(lastVal.dataset.staleAfter || '600', 10);
                const age = health.lastSyncAge + elapsed;
                lastVal.style.color = age > staleAfter ? '#b45309' : '#0f172a';
                lastVal.innerHTML = `${age < 45 ? 'Just now' : this.humanAge(age) + ' ago'}`;
            }
        },

        initHealth() {
            const badge = document.getElementById('auto-sync-badge');
            const lastVal = document.getElementById('stat-last-synced-val');
            if (!badge && !lastVal) return;

            const toInt = (v) => (v === undefined || v === '' ? null : parseInt(v, 10));
            health.receivedAt = Date.now();
            health.heartbeatAge = badge ? toInt(badge.dataset.heartbeatAge) : null;
            health.lastSyncAge = lastVal ? toInt(lastVal.dataset.lastSyncAge) : null;
            health.standalone = false;

            // Only relabels the ages already on the page; makes no request.
            setInterval(() => this.renderHealth(), 15000);
        },
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => ViLoCareSync.init());
    } else {
        ViLoCareSync.init();
    }
    window.ViLoCareSync = ViLoCareSync;
})();
