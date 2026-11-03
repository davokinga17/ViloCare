@extends('layouts.app')

@section('title', 'Incoming transfers')

@section('content')
<div class="page-header">
    <div>
        <h1 class="page-title">Incoming transfers</h1>
        <p class="page-subtitle">Patients other facilities have transferred to you. Only the ART number and name are shown until you accept.</p>
    </div>
    <div class="page-actions">
        <a href="{{ route('transfers.outgoing') }}" class="btn btn-secondary">Transferred out patients</a>
    </div>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>ART number</th>
                    <th>Name</th>
                    @if(auth()->user()->isAdmin())<th>To facility</th>@endif
                    <th>From facility</th>
                    <th>Transfer date</th>
                    <th>Letter reference</th>
                    <th style="text-align: right;">Action</th>
                </tr>
            </thead>
            <tbody>
                @forelse($transfers as $transfer)
                    <tr>
                        <td><strong>{{ $transfer->patient?->art_number }}</strong></td>
                        <td>{{ $transfer->patient?->first_name }} {{ $transfer->patient?->last_name }}</td>
                        @if(auth()->user()->isAdmin())<td>{{ $transfer->toFacility?->name }}</td>@endif
                        <td>{{ $transfer->fromFacility?->name }}</td>
                        <td>{{ $transfer->transfer_date->format('Y-m-d') }}</td>
                        <td>{{ $transfer->letter_reference ?? '—' }}</td>
                        <td style="text-align: right;">
                            <form method="POST" action="{{ route('transfers.accept', $transfer) }}" onsubmit="return confirm('Accept this patient into your facility? The full record, ART number and history will move to you.');">
                                @csrf
                                <button type="submit" class="btn btn-primary btn-sm">Accept transfer in</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" style="text-align: center; padding: 32px; color: #64748b;">No pending incoming transfers.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
