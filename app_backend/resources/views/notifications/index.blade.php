@extends('layouts.app')

@section('title', 'SMS Notification Logs')

@section('content')
<div class="page-header">
    <div>
        <h1 class="page-title">SMS Notifications & Patient Messaging</h1>
        <p class="page-subtitle">SMS via {{ \App\Support\Integrations::smsProviderLabel() }} for appointment reminders, lab notices, and EAC adherence check-ins.</p>
    </div>
    <div class="page-actions">
        <form method="POST" action="{{ route('notifications.bulk-due') }}" style="display: inline;">
            @csrf
            <button type="submit" class="btn btn-secondary" title="Dispatch discrete reminders to patients due in the next 7 days">
                📢 Send Due Reminders (Next 7 Days)
            </button>
        </form>
        <a href="{{ route('notifications.create') }}" class="btn btn-primary">
            <span>✉️</span> Compose Discrete SMS
        </a>
    </div>
</div>

@include('partials.integration-notice', ['kind' => 'sms'])

<!-- Privacy Guarantee Alert -->
<div class="card" style="border-left: 4px solid #0d9488; background: #f0fdfa; margin-bottom: 20px;">
    <div class="card-body" style="padding: 14px 18px; font-size: 0.85rem; color: #134e4a; display: flex; align-items: center; gap: 12px;">
        <span style="font-size: 1.5rem;">🛡️</span>
        <div>
            <strong>Confidentiality & Anti-Stigma Guarantee:</strong>
            All patient SMS messages strictly enforce neutral, discreet wording. The system automatically blocks explicit medical terms (e.g. "HIV", "viral load", "ARV") from being transmitted over cellular networks.
        </div>
    </div>
</div>

<!-- KPI Cards -->
<div class="stats-grid stats-grid-5">
    <div class="stat-card" style="padding: 14px; min-width: 0;">
        <div class="stat-header" style="margin-bottom: 8px;">
            <span class="stat-label" style="font-size: 0.75rem; letter-spacing: 0.2px;">Total Messages Dispatched</span>
            <div class="stat-icon-wrapper" style="background-color: #f0fdfa; color: #0d9488; width: 32px; height: 32px; font-size: 0.9rem; flex-shrink: 0;">📱</div>
        </div>
        <div class="stat-val" style="font-size: 1.5rem;">{{ $stats['total'] }}</div>
    </div>

    <div class="stat-card" style="padding: 14px; border-left: 4px solid #10b981; min-width: 0;">
        <div class="stat-header" style="margin-bottom: 8px;">
            <span class="stat-label" style="font-size: 0.75rem; letter-spacing: 0.2px;">Delivered / Sent</span>
            <div class="stat-icon-wrapper" style="background-color: #ecfdf5; color: #059669; width: 32px; height: 32px; font-size: 0.9rem; flex-shrink: 0;">✅</div>
        </div>
        <div class="stat-val" style="font-size: 1.5rem; color: #059669;">{{ $stats['sent'] }}</div>
        <div class="stat-desc" style="font-size: 0.72rem; margin-top: 4px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">Delivery Rate: {{ $stats['delivery_rate'] }}%</div>
    </div>

    <div class="stat-card" style="padding: 14px; border-left: 4px solid #3b82f6; min-width: 0;">
        <div class="stat-header" style="margin-bottom: 8px;">
            <span class="stat-label" style="font-size: 0.75rem; letter-spacing: 0.2px;">Locally Queued (Offline)</span>
            <div class="stat-icon-wrapper" style="background-color: #eff6ff; color: #2563eb; width: 32px; height: 32px; font-size: 0.9rem; flex-shrink: 0;">⏳</div>
        </div>
        <div class="stat-val" style="font-size: 1.5rem; color: #2563eb;">{{ $stats['queued'] }}</div>
        <div class="stat-desc" style="font-size: 0.72rem; margin-top: 4px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">Awaiting network connectivity</div>
    </div>

    <div class="stat-card" style="padding: 14px; border-left: 4px solid #ef4444; min-width: 0;">
        <div class="stat-header" style="margin-bottom: 8px;">
            <span class="stat-label" style="font-size: 0.75rem; letter-spacing: 0.2px;">Gateway Failures</span>
            <div class="stat-icon-wrapper" style="background-color: #fef2f2; color: #dc2626; width: 32px; height: 32px; font-size: 0.9rem; flex-shrink: 0;">⚠️</div>
        </div>
        <div class="stat-val" style="font-size: 1.5rem; color: #dc2626;">{{ $stats['gateway_failed'] }}</div>
        <div class="stat-desc" style="font-size: 0.72rem; margin-top: 4px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">Telecom / API errors (Retryable)</div>
    </div>

    <div class="stat-card" style="padding: 14px; border-left: 4px solid #f59e0b; min-width: 0;">
        <div class="stat-header" style="margin-bottom: 8px;">
            <span class="stat-label" style="font-size: 0.75rem; letter-spacing: 0.2px;">Privacy / Policy Blocked</span>
            <div class="stat-icon-wrapper" style="background-color: #fffbeb; color: #d97706; width: 32px; height: 32px; font-size: 0.9rem; flex-shrink: 0;">🛡️</div>
        </div>
        <div class="stat-val" style="font-size: 1.5rem; color: #d97706;">{{ $stats['privacy_violations'] }}</div>
        <div class="stat-desc" style="font-size: 0.72rem; margin-top: 4px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">Anti-stigma protected terms</div>
    </div>
</div>

<!-- Filters Bar -->
<div class="card" style="margin-bottom: 20px;">
    <div class="card-body" style="padding: 16px 20px;">
        <form method="GET" action="{{ route('notifications.index') }}" style="display: flex; gap: 12px; flex-wrap: wrap; align-items: center;">
            <div style="flex: 2; min-width: 220px;">
                <input type="text" name="search" value="{{ request('search') }}" class="form-control" placeholder="Search by recipient phone, patient ART, message text...">
            </div>

            <div style="width: 200px;">
                <select name="status" class="form-select">
                    <option value="">All Delivery Statuses</option>
                    <option value="sent" {{ request('status') === 'sent' ? 'selected' : '' }}>Sent / Delivered</option>
                    <option value="queued" {{ request('status') === 'queued' ? 'selected' : '' }}>Queued Offline</option>
                    <option value="gateway_failed" {{ request('status') === 'gateway_failed' ? 'selected' : '' }}>Gateway Failures</option>
                    <option value="privacy_violation" {{ request('status') === 'privacy_violation' ? 'selected' : '' }}>Privacy / Policy Blocked</option>
                    <option value="invalid_recipient" {{ request('status') === 'invalid_recipient' ? 'selected' : '' }}>Invalid Phone Number</option>
                </select>
            </div>

            <div style="width: 190px;">
                <select name="category" class="form-select">
                    <option value="">All Notification Types</option>
                    <option value="result_ready" {{ request('category') === 'result_ready' ? 'selected' : '' }}>Lab Report Ready</option>
                    <option value="due_date_reminder" {{ request('category') === 'due_date_reminder' ? 'selected' : '' }}>Health Check Due</option>
                    <option value="eac_session_reminder" {{ request('category') === 'eac_session_reminder' ? 'selected' : '' }}>EAC Counseling</option>
                    <option value="appointment_reminder" {{ request('category') === 'appointment_reminder' ? 'selected' : '' }}>Appointment Visit</option>
                    <option value="custom" {{ request('category') === 'custom' ? 'selected' : '' }}>Custom Notice</option>
                </select>
            </div>

            <div style="width: 170px;">
                <select name="provider" class="form-select">
                    <option value="">All Providers</option>
                    @foreach(\App\Services\Sms\SmsProviderFactory::PROVIDERS as $key => $label)
                        <option value="{{ $key }}" {{ request('provider') === $key ? 'selected' : '' }}>{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            <button type="submit" class="btn btn-secondary">Filter</button>
            @if(request()->hasAny(['search', 'status', 'category', 'provider']))
                <a href="{{ route('notifications.index') }}" class="btn btn-secondary" style="color: #64748b;">Reset</a>
            @endif
        </form>
    </div>
</div>

<!-- Logs Table -->
<div class="card">
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>Timestamp</th>
                    <th>Recipient & Patient</th>
                    <th>Category</th>
                    <th>Message Content (Discreet)</th>
                    <th>Delivery Status</th>
                    <th>Provider ID / Error</th>
                    <th style="text-align: right;">Action</th>
                </tr>
            </thead>
            <tbody>
                @forelse($notifications as $item)
                    <tr>
                        <td>
                            <strong style="color: #0f172a;">{{ $item->created_at->format('Y-m-d') }}</strong>
                            <div style="font-size: 0.75rem; color: #64748b;">{{ $item->created_at->format('H:i:s') }}</div>
                        </td>
                        <td>
                            <strong style="font-family: monospace; color: #0f172a;">{{ $item->recipient_phone }}</strong>
                            @if($item->patient)
                                <div style="font-size: 0.8rem;">
                                    <a href="{{ route('patients.show', $item->patient) }}" style="color: var(--primary); font-weight: 600;">
                                        {{ $item->patient->full_name }} ({{ $item->patient->art_number }})
                                    </a>
                                </div>
                            @endif
                        </td>
                        <td>
                            <span class="badge badge-secondary" style="font-size: 0.75rem;">
                                {{ $item->categoryLabel() }}
                            </span>
                        </td>
                        <td style="max-width: 320px; font-size: 0.85rem; color: #334155;">
                            {{ $item->message_body }}
                        </td>
                        <td>
                            <span class="badge {{ $item->statusBadgeClass() }}">
                                {{ $item->statusDisplayLabel() }}
                            </span>
                            @if($item->sent_at)
                                <div style="font-size: 0.7rem; color: #059669;">Sent: {{ $item->sent_at->format('H:i') }}</div>
                            @endif
                        </td>
                        <td style="font-size: 0.75rem; color: #64748b; max-width: 200px;">
                            <div style="font-weight: 700; color: #475569; margin-bottom: 2px;">{{ \App\Services\Sms\SmsProviderFactory::PROVIDERS[$item->provider] ?? $item->provider }}</div>
                            @if($item->provider_message_id)
                                <span style="font-family: monospace; color: #059669;" title="Provider Message ID">{{ $item->provider_message_id }}</span>
                            @elseif($item->isPrivacyViolation())
                                <div style="color: #b45309; font-weight: 500;" title="{{ $item->error_message }}">
                                    🛡️ {{ Str::limit($item->error_message, 50) }}
                                </div>
                            @elseif($item->isInvalidRecipient())
                                <div style="color: #64748b; font-weight: 500;" title="{{ $item->error_message }}">
                                    📵 {{ Str::limit($item->error_message, 50) }}
                                </div>
                            @elseif($item->error_message)
                                <div style="color: #dc2626; font-weight: 500;" title="{{ $item->error_message }}">
                                    ⚠️ {{ Str::limit($item->error_message, 50) }}
                                </div>
                            @else
                                <span>Pending gateway dispatch</span>
                            @endif
                        </td>
                        <td style="text-align: right;">
                            @if($item->isRetryable())
                                <form method="POST" action="{{ route('notifications.retry', $item) }}" style="display: inline;">
                                    @csrf
                                    <button type="submit" class="btn btn-sm btn-secondary" title="Re-dispatch via {{ \App\Support\Integrations::smsProviderLabel() }}">
                                        🔄 Retry
                                    </button>
                                </form>
                            @elseif($item->isPrivacyViolation())
                                <span class="badge badge-warning" style="font-size: 0.7rem;" title="Blocked by Anti-Stigma Policy (contains sensitive medical terms)">
                                    🛡️ Policy Blocked
                                </span>
                            @elseif($item->isInvalidRecipient())
                                <span class="badge badge-secondary" style="font-size: 0.7rem;" title="Recipient phone number missing or invalid">
                                    📵 Invalid Phone
                                </span>
                            @else
                                <span style="color: #059669; font-size: 0.8rem; font-weight: 500;">Delivered</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" style="text-align: center; padding: 36px; color: #64748b;">
                            No SMS notification records found.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if($notifications->hasPages())
        <div class="card-footer">
            {{ $notifications->links() }}
        </div>
    @endif
</div>
@endsection
