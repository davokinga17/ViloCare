@extends('layouts.app')

@section('title', 'No facility assigned')

@section('content')
<div class="page-header">
    <div>
        <h1 class="page-title">No facility assigned</h1>
        <p class="page-subtitle">Your account is not linked to a facility yet, so no patient data is shown.</p>
    </div>
</div>

<div class="card" style="max-width: 640px;">
    <div class="card-body">
        <p style="font-size: 1.05rem;"><strong>No facility assigned. Ask an administrator.</strong></p>
        <p style="color: #64748b;">An administrator needs to assign you to your facility before you can see patients, results or reports. You can still sign out or update your profile.</p>
    </div>
</div>
@endsection
