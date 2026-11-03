@extends('layouts.app')

@section('title', 'Transferred out patients')

@section('content')
<div class="page-header">
    <div>
        <h1 class="page-title">Transferred out patients</h1>
        <p class="page-subtitle">A read-only record of patients you have sent to other facilities. After the other facility accepts, their clinical data is theirs.</p>
    </div>
    <div class="page-actions">
        <a href="{{ route('transfers.incoming') }}" class="btn btn-secondary">Incoming transfers</a>
    </div>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>ART number</th>
                    <th>Name</th>
                    @if(auth()->user()->isAdmin())<th>From facility</th>@endif
                    <th>To facility</th>
                    <th>Transfer date</th>
                    <th>Letter reference</th>
                    <th>Status</th>
                    <th style="text-align: right;">Action</th>
                </tr>
            </thead>
            <tbody>
                @forelse($transfers as $transfer)
                    <tr>
                        <td><strong>{{ $transfer->patient?->art_number }}</strong></td>
                        <td>{{ $transfer->patient?->first_name }} {{ $transfer->patient?->last_name }}</td>
                        @if(auth()->user()->isAdmin())<td>{{ $transfer->fromFacility?->name }}</td>@endif
                        <td>{{ $transfer->toFacility?->name }}</td>
                        <td>{{ $transfer->transfer_date->format('Y-m-d') }}</td>
                        <td>{{ $transfer->letter_reference ?? '—' }}</td>
                        <td>
                            @if($transfer->status === 'accepted')
                                <span class="badge badge-success">Accepted {{ $transfer->accepted_at?->format('Y-m-d') }}</span>
                            @elseif($transfer->status === 'cancelled')
                                <span class="badge badge-secondary">Cancelled</span>
                            @else
                                <span class="badge badge-warning">Waiting for acceptance</span>
                            @endif
                        </td>
                        <td style="text-align: right;">
                            @if($transfer->isPending() && in_array(auth()->user()->role->value, ['administrator', 'clinician']))
                                <form method="POST" action="{{ route('transfers.cancel', $transfer) }}" onsubmit="return confirm('Cancel this transfer? The patient will be active at your facility again.');">
                                    @csrf
                                    <button type="submit" class="btn btn-secondary btn-sm">Cancel transfer</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" style="text-align: center; padding: 32px; color: #64748b;">No transfers out yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
