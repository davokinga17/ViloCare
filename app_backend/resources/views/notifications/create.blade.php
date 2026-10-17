@extends('layouts.app')

@section('title', 'Compose Discrete Patient SMS')

@section('content')
<div class="page-header">
    <div>
        <h1 class="page-title">Compose Discrete Patient SMS</h1>
        <p class="page-subtitle">Send a confidential, neutral SMS notification to a patient via the {{ \App\Support\Integrations::smsProviderLabel() }} gateway.</p>
    </div>
    <div class="page-actions">
        <a href="{{ route('notifications.index') }}" class="btn btn-secondary">
            &larr; Back to SMS Logs
        </a>
    </div>
</div>

@include('partials.integration-notice', ['kind' => 'sms'])

<form method="POST" action="{{ route('notifications.store') }}">
    @csrf

    <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 24px;">
        <div>
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">1. Recipient & Message Content</h2>
                </div>
                <div class="card-body">
                    <div class="form-group">
                        <label for="patient_id" class="form-label">Link to Patient (Optional)</label>
                        <select id="patient_id" name="patient_id" class="form-select" onchange="autoFillPhone(this)">
                            <option value="">-- Choose Patient or Enter Custom Phone --</option>
                            @foreach($patients as $p)
                                <option value="{{ $p->id }}" data-phone="{{ $p->phone }}" data-name="{{ $p->first_name }}" {{ (old('patient_id', $preselectedPatient?->id) == $p->id) ? 'selected' : '' }}>
                                    {{ $p->art_number }} — {{ $p->full_name }} ({{ $p->phone }})
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="form-grid">
                        <div class="form-group">
                            <label for="recipient_phone" class="form-label">Recipient Mobile Number <span class="required">*</span></label>
                            <input type="tel" inputmode="tel" id="recipient_phone" name="recipient_phone" value="{{ old('recipient_phone', $preselectedPatient?->phone) }}" required class="form-control" placeholder="+211910000000 or 0910000000">
                            <div class="form-help">Supports standard local or international formats (e.g. +211..., 09...).</div>
                        </div>

                        <div class="form-group">
                            <label for="category" class="form-label">Notification Purpose / Category <span class="required">*</span></label>
                            <select id="category" name="category" required class="form-select" onchange="applyTemplate(this.value)">
                                <option value="custom" {{ old('category') === 'custom' ? 'selected' : '' }}>Custom Discrete Notice</option>
                                <option value="result_ready" {{ old('category') === 'result_ready' ? 'selected' : '' }}>Lab Report Ready</option>
                                <option value="due_date_reminder" {{ old('category') === 'due_date_reminder' ? 'selected' : '' }}>Health Check Due</option>
                                <option value="eac_session_reminder" {{ old('category') === 'eac_session_reminder' ? 'selected' : '' }}>EAC Counseling Session</option>
                                <option value="appointment_reminder" {{ old('category') === 'appointment_reminder' ? 'selected' : '' }}>Clinic Visit Reminder</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                            <label for="message_body" class="form-label" style="margin-bottom: 0;">Message Content <span class="required">*</span></label>
                            <span id="char-counter" style="font-size: 0.8rem; color: #64748b;">0 characters (1 SMS part)</span>
                        </div>
                        <textarea id="message_body" name="message_body" rows="4" required class="form-control" maxlength="160" placeholder="Type neutral, discreet message..." oninput="updateCharCount()">{{ old('message_body') }}</textarea>
                    </div>
                </div>
            </div>
        </div>

        <!-- Right Side: Anti-Stigma Rules & Dispatch Button -->
        <div>
            <div class="card" style="border-left: 4px solid #0d9488; margin-bottom: 20px;">
                <div class="card-header">
                    <h2 class="card-title">🛡️ Strict Privacy Policy</h2>
                </div>
                <div class="card-body" style="font-size: 0.825rem; color: #475569; line-height: 1.6;">
                    <p style="margin-bottom: 8px;">
                        To protect patient confidentiality, cellular SMS must <strong>NEVER</strong> contain explicit disease identifiers.
                    </p>
                    <div style="padding: 10px; background: #fff1f2; border-radius: var(--radius-sm); border: 1px solid #fecdd3; color: #9f1239; margin-bottom: 10px;">
                        <strong>Forbidden Words:</strong><br>
                        HIV, Viral Load, ARV, ART, CD4, AIDS, Unsuppressed, Infection, Regimen Switch.
                    </div>
                    <p style="color: #0f766e; font-weight: 600;">
                        Use neutral terms: <em>"Routine wellness report"</em>, <em>"Scheduled health check-up"</em>, <em>"Clinic review"</em>.
                    </p>
                </div>
                <div class="card-footer">
                    <button type="submit" class="btn btn-primary" style="width: 100%; padding: 12px;">
                        📱 Dispatch SMS Notification
                    </button>
                </div>
            </div>
        </div>
    </div>
</form>

<script>
// One SMS part holds 160 GSM characters, or 70 if the text has special characters (emoji, curly quotes, ...).
// Same rule as App\Support\SmsSegments.
const GSM_BASIC = "@£$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà";
const GSM_EXTENDED = "^{}\\[~]|€\f";
const SMS_PROVIDER = @json(\App\Support\Integrations::smsProviderLabel());

function smsParts(text) {
    let length = 0;
    let gsm = true;
    for (const ch of text) {
        if (GSM_BASIC.includes(ch)) { length += 1; }
        else if (GSM_EXTENDED.includes(ch)) { length += 2; }
        else { gsm = false; break; }
    }
    if (!gsm) { length = text.length; } // UCS-2: UTF-16 code units
    const limit = gsm ? 160 : 70;
    const perPart = gsm ? 153 : 67;
    return { length, limit, gsm, parts: length <= limit ? 1 : Math.ceil(length / perPart) };
}

function updateCharCount() {
    const textarea = document.getElementById('message_body');
    const counter = document.getElementById('char-counter');
    const info = smsParts(textarea.value);
    let text = `${info.length} / ${info.limit} ${info.gsm ? 'characters' : 'characters (special characters: limit is 70)'} (${info.parts} SMS ${info.parts === 1 ? 'part' : 'parts'})`;
    if (info.parts > 1) {
        text += ` - longer than one part.${SMS_PROVIDER === 'Twilio' ? ' Twilio does not support multi-part SMS to South Sudan.' : ' Each part is billed separately.'}`;
    }
    counter.textContent = text;
    counter.style.color = info.parts > 1 ? '#b45309' : (info.length > info.limit - 10 ? '#e11d48' : '#64748b');
}
function autoFillPhone(select) {
    const selectedOption = select.options[select.selectedIndex];
    const phone = selectedOption.getAttribute('data-phone');
    if (phone) {
        document.getElementById('recipient_phone').value = phone;
    }
}

function applyTemplate(category) {
    const textarea = document.getElementById('message_body');
    const patientSelect = document.getElementById('patient_id');
    const selectedOption = patientSelect.options[patientSelect.selectedIndex];
    const name = selectedOption ? selectedOption.getAttribute('data-name') || 'Client' : 'Client';

    if (category === 'result_ready') {
        textarea.value = `Hello ${name}, your routine wellness lab report is ready for collection at the clinic. Please visit during clinic hours to review.`;
    } else if (category === 'due_date_reminder') {
        textarea.value = `Hello ${name}, a gentle reminder that your scheduled health check-up at the clinic is due soon. See you at your visit.`;
    } else if (category === 'eac_session_reminder') {
        textarea.value = `Hello ${name}, your follow-up wellness counseling session at the clinic is scheduled. Please attend as planned.`;
    } else if (category === 'appointment_reminder') {
        textarea.value = `Hello ${name}, this is a reminder for your upcoming clinic visit. Please remember to bring your clinic card.`;
    }
    updateCharCount();
}

document.addEventListener('DOMContentLoaded', updateCharCount);
</script>
@endsection
