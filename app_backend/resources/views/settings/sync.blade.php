@extends('layouts.app')

@section('title', 'Sync Connection')

@section('content')
<div class="page-header">
    <div>
        <h1 class="page-title">Sync Connection</h1>
        <p class="page-subtitle">The central server this clinic computer syncs with. Only administrators can see or change this.</p>
    </div>
    <div class="page-actions">
        <a href="{{ route('sync.index') }}" class="btn btn-secondary">&larr; Back to Sync Center</a>
    </div>
</div>

@if($central ?? false)
<div class="card" style="max-width: 720px;">
    <div class="card-header">
        <h2 class="card-title">Central server</h2>
    </div>
    <div class="card-body">
        <p>This is the central server. Clinic computers connect to it using the sync token set in this server's environment settings.</p>
        <dl style="margin-top: 16px;">
            <dt class="form-label">Sync endpoint address</dt>
            <dd style="margin: 0 0 14px;"><code>{{ $endpoint }}</code></dd>
            <dt class="form-label">Last clinic sync</dt>
            <dd style="margin: 0;">
                @if($lastClinicSyncAt)
                    {{ $lastClinicSyncAt->format('Y-m-d H:i') }} ({{ $lastClinicSyncAt->diffForHumans() }})@if($lastClinicDevice) &middot; {{ $lastClinicDevice }}@endif
                @else
                    No clinic has synced yet.
                @endif
            </dd>
        </dl>
    </div>
</div>
@else
@unless($configured)
<div class="card" style="max-width: 720px; margin-bottom: 20px; border-left: 4px solid #f59e0b; background: #fffbeb;">
    <div class="card-body">
        <strong>Sync is not set up.</strong> This computer is working offline. Records are kept safely and will upload once a connection is saved here.
    </div>
</div>
@endunless

<div class="card" style="max-width: 720px;">
    <div class="card-header">
        <h2 class="card-title">Central server details</h2>
    </div>
    <div class="card-body">
        <form method="POST" action="{{ route('settings.sync.update') }}" id="sync-settings-form" autocomplete="off">
            @csrf
            @method('PUT')

            <div class="form-group">
                <label for="url" class="form-label">Central sync URL <span class="required">*</span></label>
                <input type="url" id="url" name="url" value="{{ old('url', $url) }}" required class="form-control" placeholder="https://example.com/api/v1/sync">
                @error('url')<div class="form-help" style="color: #b91c1c;">{{ $message }}</div>@enderror
            </div>

            <div class="form-group">
                <label for="sync_token" class="form-label">Sync token @unless($hasToken)<span class="required">*</span>@endunless</label>
                <input type="password" id="sync_token" name="sync_token" class="form-control" autocomplete="new-password"
                       placeholder="{{ $hasToken ? $maskedToken . '  (saved - leave blank to keep)' : 'Paste the token from your administrator' }}"
                       {{ $hasToken ? '' : 'required' }}>
                @error('sync_token')<div class="form-help" style="color: #b91c1c;">{{ $message }}</div>@enderror
                <div class="form-help">The token is stored only on this computer and is never displayed again.</div>
            </div>

            <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                <button type="submit" class="btn btn-primary">Save connection</button>
                <button type="button" class="btn btn-secondary" id="btn-test-sync">Test connection</button>
                <span id="test-result" role="status" style="font-weight: 600;"></span>
            </div>
        </form>
    </div>
</div>
@endif
@endsection

@if(! ($central ?? false))
@push('scripts')
<script>
document.getElementById('btn-test-sync').addEventListener('click', async function () {
    const out = document.getElementById('test-result');
    const form = document.getElementById('sync-settings-form');
    out.style.color = '#475569';
    out.textContent = 'Testing…';
    this.disabled = true;
    try {
        const res = await fetch(@json(route('settings.sync.test')), {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
            },
            body: JSON.stringify({ url: form.url.value, sync_token: form.sync_token.value }),
        });
        const data = await res.json();
        const ok = res.ok && data.ok;
        out.style.color = ok ? '#15803d' : '#b91c1c';
        out.textContent = ok ? data.message
            : (data.message || (data.errors ? Object.values(data.errors)[0][0] : 'The test could not be completed.'));
    } catch (e) {
        out.style.color = '#b91c1c';
        out.textContent = 'The test could not be completed. Check this computer\'s connection and try again.';
    } finally {
        this.disabled = false;
    }
});
</script>
@endpush
@endif
