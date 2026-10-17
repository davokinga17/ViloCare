@extends('layouts.app')

@section('title', 'System Audit Trail')

@section('content')
<div class="page-header">
    <div>
        <h1 class="page-title">Security & System Audit Trail</h1>
        <p class="page-subtitle">Full immutable log of user activity, patient modifications, logins and clinical actions.</p>
    </div>
</div>

<!-- Filter Bar -->
<div class="card" style="margin-bottom: 20px;">
    <div class="card-body" style="padding: 16px 20px;">
        <form method="GET" action="{{ route('audit-logs.index') }}" style="display: flex; gap: 12px; flex-wrap: wrap; align-items: center;">
            <div style="width: 200px;">
                <select name="user_id" class="form-select">
                    <option value="">All Users</option>
                    @foreach($users as $u)
                        <option value="{{ $u->id }}" {{ request('user_id') == $u->id ? 'selected' : '' }}>
                            {{ $u->name }} ({{ $u->role->label() }})
                        </option>
                    @endforeach
                </select>
            </div>

            <div style="width: 180px;">
                <select name="action" class="form-select">
                    <option value="">All Actions</option>
                    @foreach($actions as $act)
                        <option value="{{ $act }}" {{ request('action') == $act ? 'selected' : '' }}>
                            {{ strtoupper($act) }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div style="width: 180px;">
                <select name="table_name" class="form-select">
                    <option value="">All Tables</option>
                    @foreach($tables as $tbl)
                        <option value="{{ $tbl }}" {{ request('table_name') == $tbl ? 'selected' : '' }}>
                            {{ $tbl }}
                        </option>
                    @endforeach
                </select>
            </div>

            <button type="submit" class="btn btn-secondary">Filter Logs</button>
            @if(request()->hasAny(['user_id', 'action', 'table_name']))
                <a href="{{ route('audit-logs.index') }}" class="btn btn-secondary" style="color: #64748b;">Reset</a>
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
                    <th>User</th>
                    <th>Action</th>
                    <th>Target Table</th>
                    <th>Record ID</th>
                    <th>IP / Device</th>
                    <th>Details</th>
                </tr>
            </thead>
            <tbody>
                @forelse($logs as $log)
                    <tr>
                        <td style="white-space: nowrap; font-size: 0.8rem; color: #475569;">
                            {{ $log->created_at->format('Y-m-d H:i:s') }}
                            <div style="font-size: 0.75rem; color: #94a3b8;">{{ $log->created_at->diffForHumans() }}</div>
                        </td>
                        <td>
                            @if($log->user)
                                <strong>{{ $log->user->name }}</strong>
                                <div style="font-size: 0.75rem; color: #64748b;">{{ $log->user->role->label() }}</div>
                            @else
                                <span style="color: #94a3b8; font-style: italic;">Unauthenticated / System</span>
                            @endif
                        </td>
                        <td>
                            <span class="badge badge-secondary" style="font-weight: 700;">
                                {{ strtoupper($log->action) }}
                            </span>
                        </td>
                        <td><code>{{ $log->table_name }}</code></td>
                        <td><span style="font-family: monospace; font-size: 0.75rem;">{{ substr($log->record_id, 0, 8) }}...</span></td>
                        <td style="font-size: 0.8rem; color: #64748b;">{{ $log->ip_address ?? '127.0.0.1' }}</td>
                        <td>
                            @if($log->new_values || $log->old_values)
                                <details style="cursor: pointer; font-size: 0.75rem;">
                                    <summary style="color: var(--primary); font-weight: 600;">View JSON Diff</summary>
                                    <div style="margin-top: 6px; padding: 8px; background: #f1f5f9; border-radius: var(--radius-sm); max-width: 320px; overflow-x: auto;">
                                        @if($log->old_values)
                                            <div><strong>Old:</strong> <code>{{ json_encode($log->old_values) }}</code></div>
                                        @endif
                                        @if($log->new_values)
                                            <div><strong>New:</strong> <code>{{ json_encode($log->new_values) }}</code></div>
                                        @endif
                                    </div>
                                </details>
                            @else
                                <span style="color: #94a3b8; font-size: 0.8rem;">—</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" style="text-align: center; padding: 32px; color: #64748b;">
                            No audit logs found.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if($logs->hasPages())
        <div class="card-footer">
            {{ $logs->links() }}
        </div>
    @endif
</div>
@endsection
