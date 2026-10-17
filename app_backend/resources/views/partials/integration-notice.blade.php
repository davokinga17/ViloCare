{{-- Shows a notice when an integration key is missing. Usage: @include('partials.integration-notice', ['kind' => 'gemini'|'sms']) --}}
@php
    $missing = $kind === 'gemini' ? ! \App\Support\Integrations::geminiConfigured() : ! \App\Support\Integrations::smsConfigured();
@endphp
@if($missing)
<div class="card" role="alert" data-integration-notice="{{ $kind }}" style="border-left: 4px solid #f59e0b; background: #fffbeb; margin-bottom: 16px;">
    <div class="card-body" style="padding: 12px 16px; font-size: 0.85rem; color: #78350f;">
        @if($kind === 'gemini')
            <strong>AI Insights is not set up.</strong> No Gemini API key is saved on this computer, so summaries and the Q&amp;A assistant are unavailable.
        @else
            <strong>SMS is not set up.</strong> The {{ \App\Support\Integrations::smsProviderLabel() }} credentials are not saved on this computer. Messages are only recorded here and are <u>not delivered</u> to patients.
        @endif
        @if(auth()->user()->isAdmin())
            <a href="{{ route('settings.integrations.edit') }}" style="font-weight: 600; margin-left: 6px;">Open Integrations settings &rarr;</a>
        @else
            An administrator can add {{ $kind === 'gemini' ? 'the key' : 'them' }} under Settings &rarr; Integrations.
        @endif
    </div>
</div>
@endif
