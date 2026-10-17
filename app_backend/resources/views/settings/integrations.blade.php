@extends('layouts.app')

@section('title', 'Integrations')

@section('content')
<div class="page-header">
    <div>
        <h1 class="page-title">Integrations</h1>
        <p class="page-subtitle">Keys for the AI assistant and SMS gateway. They are stored only on this computer and are never shown again. Only administrators can see or change this.</p>
    </div>
    <div class="page-actions">
        <a href="{{ route('settings.sync.edit') }}" class="btn btn-secondary">Sync Connection</a>
    </div>
</div>

<div class="card" style="max-width: 720px; margin-bottom: 20px;">
    <div class="card-header">
        <h2 class="card-title">Gemini (AI Clinical Insights)</h2>
    </div>
    <div class="card-body">
        <form method="POST" action="{{ route('settings.integrations.gemini.update') }}" id="gemini-form" autocomplete="off">
            @csrf
            @method('PUT')
            <div class="form-group">
                <label for="gemini_key" class="form-label">Gemini API key @unless($hasGeminiKey)<span class="required">*</span>@endunless</label>
                <input type="password" id="gemini_key" name="gemini_key" class="form-control" autocomplete="new-password"
                       placeholder="{{ $hasGeminiKey ? $mask . '  (saved - leave blank to keep)' : 'Paste a Google AI Studio key (starts with AIza)' }}"
                       {{ $hasGeminiKey ? '' : 'required' }}>
                @error('gemini_key')<div class="form-help" style="color: #b91c1c;">{{ $message }}</div>@enderror
                <div class="form-help">Only anonymized clinic totals are ever sent to Gemini, never patient details.</div>
            </div>
            <div class="form-group">
                <label for="gemini_model" class="form-label">Model</label>
                <select id="gemini_model" name="gemini_model" class="form-control">
                    <option value="{{ old('gemini_model', $geminiModel) }}" selected>{{ old('gemini_model', $geminiModel) }}</option>
                </select>
                @error('gemini_model')<div class="form-help" style="color: #b91c1c;">{{ $message }}</div>@enderror
                <div class="form-help">Saved on this computer and used for AI summaries and Q&amp;A. Press <strong>Test</strong> to list the models your key can use, then pick one.</div>
            </div>
            <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                <button type="submit" class="btn btn-primary">Save Gemini settings</button>
                <button type="button" class="btn btn-secondary js-test" data-url="{{ route('settings.integrations.gemini.test') }}" data-form="gemini-form" data-out="gemini-result">Test</button>
                <span id="gemini-result" role="status" style="font-weight: 600;"></span>
            </div>
        </form>
    </div>
</div>

<div class="card" style="max-width: 720px;">
    <div class="card-header">
        <h2 class="card-title">SMS (patient reminders)</h2>
    </div>
    <div class="card-body">
        @php $currentProvider = old('sms_provider', $smsProvider); @endphp
        <form method="POST" action="{{ route('settings.integrations.sms.update') }}" id="sms-form" autocomplete="off">
            @csrf
            @method('PUT')
            <div class="form-group">
                <label for="sms_provider" class="form-label">SMS provider</label>
                <select id="sms_provider" name="sms_provider" class="form-control">
                    @foreach($smsProviders as $key => $label)
                        <option value="{{ $key }}" @selected($currentProvider === $key)>{{ $label }}</option>
                    @endforeach
                </select>
                @error('sms_provider')<div class="form-help" style="color: #b91c1c;">{{ $message }}</div>@enderror
                <div class="form-help">Messages sent from this computer go through the chosen provider. Only its settings are shown. Keep Render on the same provider (see the deployment notes).</div>
            </div>

            {{-- Africa's Talking --}}
            <div data-sms-provider="africastalking" @if($currentProvider !== 'africastalking') hidden @endif>
                <div class="form-group">
                    <label for="sms_username" class="form-label">Username <span class="required">*</span></label>
                    <input type="text" id="sms_username" name="sms_username" value="{{ old('sms_username', $smsUsername) }}" required class="form-control" @disabled($currentProvider !== 'africastalking')>
                    @error('sms_username')<div class="form-help" style="color: #b91c1c;">{{ $message }}</div>@enderror
                    <div class="form-help">Use <strong>sandbox</strong> while testing.</div>
                </div>
                <div class="form-group">
                    <label for="sms_key" class="form-label">API key @unless($hasSmsKey)<span class="required">*</span>@endunless</label>
                    <input type="password" id="sms_key" name="sms_key" class="form-control" autocomplete="new-password"
                           placeholder="{{ $hasSmsKey ? $mask . '  (saved - leave blank to keep)' : 'Paste the API key' }}"
                           {{ $hasSmsKey ? '' : 'required' }} @disabled($currentProvider !== 'africastalking')>
                    @error('sms_key')<div class="form-help" style="color: #b91c1c;">{{ $message }}</div>@enderror
                </div>
                <div class="form-group">
                    <label for="sms_sender_id" class="form-label">Sender ID</label>
                    <input type="text" id="sms_sender_id" name="sms_sender_id" value="{{ old('sms_sender_id', $smsSenderId) }}" maxlength="11" class="form-control" placeholder="Optional, up to 11 characters" @disabled($currentProvider !== 'africastalking')>
                    @error('sms_sender_id')<div class="form-help" style="color: #b91c1c;">{{ $message }}</div>@enderror
                </div>
                <div class="form-group">
                    <label style="display: flex; align-items: center; gap: 8px; font-weight: 600;">
                        <input type="checkbox" name="sandbox" value="1" id="sandbox" {{ (session()->hasOldInput('sms_username') ? old('sandbox') : $smsSandbox) ? 'checked' : '' }} @disabled($currentProvider !== 'africastalking')>
                        Sandbox mode (test environment, no real messages)
                    </label>
                    <div class="form-help">Turn this off only when using a live Africa's Talking account.</div>
                </div>
            </div>

            {{-- Twilio --}}
            <div data-sms-provider="twilio" @if($currentProvider !== 'twilio') hidden @endif>
                <div class="form-group">
                    <label for="twilio_sid" class="form-label">Account SID @unless($hasTwilioSid)<span class="required">*</span>@endunless</label>
                    <input type="password" id="twilio_sid" name="twilio_sid" class="form-control" autocomplete="new-password"
                           placeholder="{{ $hasTwilioSid ? $mask . '  (saved - leave blank to keep)' : 'Starts with AC' }}" @disabled($currentProvider !== 'twilio')>
                    @error('twilio_sid')<div class="form-help" style="color: #b91c1c;">{{ $message }}</div>@enderror
                </div>
                <div class="form-group">
                    <label for="twilio_token" class="form-label">Auth Token @unless($hasTwilioToken)<span class="required">*</span>@endunless</label>
                    <input type="password" id="twilio_token" name="twilio_token" class="form-control" autocomplete="new-password"
                           placeholder="{{ $hasTwilioToken ? $mask . '  (saved - leave blank to keep)' : 'Paste the Auth Token' }}" @disabled($currentProvider !== 'twilio')>
                    @error('twilio_token')<div class="form-help" style="color: #b91c1c;">{{ $message }}</div>@enderror
                </div>
                <div class="form-group">
                    <label for="twilio_sender" class="form-label">Sender <span class="required">*</span></label>
                    <input type="text" id="twilio_sender" name="twilio_sender" value="{{ old('twilio_sender', $twilioSender) }}" maxlength="40" class="form-control"
                           placeholder="Sender ID (up to 11 characters) or +phone number" @disabled($currentProvider !== 'twilio')>
                    @error('twilio_sender')<div class="form-help" style="color: #b91c1c;">{{ $message }}</div>@enderror
                    <div class="form-help">An alphanumeric sender ID, a phone number starting with +, or a Messaging Service SID (starts with MG).</div>
                </div>
                <div class="form-help" style="margin-bottom: 12px;">Twilio does not support multi-part SMS to South Sudan. Keep each message to one part: 160 characters, or 70 if it contains special characters.</div>
            </div>

            <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                <button type="submit" class="btn btn-primary">Save SMS settings</button>
                <button type="button" class="btn btn-secondary js-test" data-url="{{ route('settings.integrations.sms.test') }}" data-form="sms-form" data-out="sms-result">Test</button>
                <span id="sms-result" role="status" style="font-weight: 600;"></span>
            </div>
            <div class="form-help" style="margin-top: 8px;">Test only checks the credentials with the provider. No SMS is sent.</div>

            <div class="form-group" style="margin-top: 18px; padding-top: 14px; border-top: 1px solid #e2e8f0;">
                <label for="test_phone" class="form-label">Send a test SMS to</label>
                <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                    <input type="tel" inputmode="tel" id="test_phone" name="test_phone" class="form-control" style="max-width: 240px;" placeholder="0911000000 or +211911000000">
                    <button type="button" class="btn btn-secondary js-test" data-url="{{ route('settings.integrations.sms.send-test') }}" data-form="sms-form" data-out="sms-send-result">Send test SMS</button>
                </div>
                <div id="sms-send-result" role="status" style="font-weight: 600; margin-top: 8px;"></div>
                <div class="form-help">Sends one real message through the chosen provider using the settings above (saved or not) and shows the provider's own answer. South Sudan numbers are written as +211.</div>
            </div>
        </form>
    </div>
</div>@endsection

@push('scripts')
<script>
// Replace the Model dropdown with the models Google says this key can use (textContent only: no HTML from the network).
function fillModels(select, data) {
    const wanted = select.value;
    select.innerHTML = '';
    if (!data.model_available) {
        const stale = document.createElement('option');
        stale.value = wanted;
        stale.textContent = wanted + ' (not available to this key)';
        select.appendChild(stale);
    }
    data.models.forEach(function (m) {
        const opt = document.createElement('option');
        opt.value = m.name;
        opt.textContent = m.label && m.label !== m.name ? m.name + ' (' + m.label + ')' : m.name;
        select.appendChild(opt);
    });
    select.value = wanted;
}

// Show only the fields of the chosen SMS provider; disabled fields are neither submitted nor tested.
(function () {
    const select = document.getElementById('sms_provider');
    function showProvider() {
        document.querySelectorAll('[data-sms-provider]').forEach(function (group) {
            const on = group.dataset.smsProvider === select.value;
            group.hidden = !on;
            group.querySelectorAll('input, select').forEach(function (el) { el.disabled = !on; });
        });
    }
    select.addEventListener('change', showProvider);
    showProvider();
})();

document.querySelectorAll('.js-test').forEach(function (btn) {
    btn.addEventListener('click', async function () {
        const form = document.getElementById(btn.dataset.form);
        const out = document.getElementById(btn.dataset.out);
        const body = {};
        new FormData(form).forEach(function (v, k) { if (k !== '_token' && k !== '_method') body[k] = v; });
        out.style.color = '#475569';
        out.textContent = 'Testing…';
        btn.disabled = true;
        try {
            const res = await fetch(btn.dataset.url, {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                },
                body: JSON.stringify(body),
            });
            const data = await res.json();
            const ok = res.ok && data.ok;
            out.style.color = ok ? (data.warning ? '#b45309' : '#15803d') : '#b91c1c';
            if (ok && Array.isArray(data.models) && btn.dataset.form === 'gemini-form') {
                fillModels(document.getElementById('gemini_model'), data);
            }
            out.textContent = ok ? data.message
                : (data.message || (data.errors ? Object.values(data.errors)[0][0] : 'The test could not be completed.'));
            // A text warning (for example a message longer than one SMS part) is added after the result.
            if (typeof data.warning === 'string') {
                out.textContent += ' ' + data.warning;
            }
        } catch (e) {
            out.style.color = '#b91c1c';
            out.textContent = 'The test could not be completed. Check this computer\'s connection and try again.';
        } finally {
            btn.disabled = false;
        }
    });
});
</script>
@endpush
