@extends('layouts.app')

@section('title', 'Import Batch Results')

@section('content')
<div class="page-header">
    <div>
        <h1 class="page-title">Bulk Import Summary</h1>
        <p class="page-subtitle">Batch validation and enrollment report.</p>
    </div>
    <div class="page-actions">
        <a href="{{ route('patients.import') }}" class="btn btn-secondary">
            <span>📥</span> Import Another File
        </a>
        <a href="{{ route('patients.index') }}" class="btn btn-primary">
            👥 View Patients Directory
        </a>
    </div>
</div>

<div class="stats-grid" style="grid-template-columns: 1fr 1fr;">
    <div class="stat-card" style="border-left: 4px solid #10b981;">
        <div class="stat-header">
            <span class="stat-label">Successfully Enrolled</span>
            <div class="stat-icon-wrapper" style="background-color: #ecfdf5; color: #059669;">✅</div>
        </div>
        <div class="stat-val" style="color: #059669;">{{ $result['imported'] }}</div>
        <div class="stat-desc">New patients added with baseline regimens and sync tracking</div>
    </div>

    <div class="stat-card" style="border-left: 4px solid #ef4444;">
        <div class="stat-header">
            <span class="stat-label">Failed / Rejected Rows</span>
            <div class="stat-icon-wrapper" style="background-color: #fef2f2; color: #dc2626;">❌</div>
        </div>
        <div class="stat-val" style="color: #dc2626;">{{ $result['failed'] }}</div>
        <div class="stat-desc">Rows with validation errors or duplicates (not imported)</div>
    </div>
</div>

@if($result['failed'] > 0)
    <div class="card">
        <div class="card-header" style="background-color: #fef2f2;">
            <h2 class="card-title" style="color: #991b1b;">⚠️ Detailed Row Error Breakdown</h2>
        </div>
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th style="width: 90px;">Row #</th>
                        <th style="width: 160px;">ART Number</th>
                        <th>Specific Error Details</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($result['errors'] as $err)
                        <tr>
                            <td><strong>Row {{ $err['row'] }}</strong></td>
                            <td><code>{{ $err['art_number'] ?? 'N/A' }}</code></td>
                            <td>
                                <ul style="color: #dc2626; margin: 0; padding-left: 18px;">
                                    @foreach($err['errors'] as $msg)
                                        <li>{{ $msg }}</li>
                                    @endforeach
                                </ul>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endif
@endsection
