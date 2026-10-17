@extends('layouts.app')

@section('title', 'Log Sample Collection')

@section('content')
<div class="page-header">
    <div>
        <h1 class="page-title">Log Sample Collection</h1>
        <p class="page-subtitle">Record blood specimen draw for routine viral load testing or EAC adherence monitoring.</p>
    </div>
    <div class="page-actions">
        <a href="{{ route('samples.index') }}" class="btn btn-secondary">
            &larr; Back to Samples List
        </a>
    </div>
</div>

<form method="POST" action="{{ route('samples.store') }}">
    @csrf

    <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 24px;">
        <div>
            <!-- Patient & Sample Identification Card -->
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">1. Patient & Sample Identification</h2>
                </div>
                <div class="card-body">
                    <div class="form-group">
                        <label for="patient_id" class="form-label">Select Patient <span class="required">*</span></label>
                        <select id="patient_id" name="patient_id" required class="form-select">
                            <option value="">-- Select Patient by ART # or Name --</option>
                            @foreach($patients as $p)
                                <option value="{{ $p->id }}" {{ (old('patient_id', $preselectedPatient?->id) == $p->id) ? 'selected' : '' }}>
                                    {{ $p->art_number }} — {{ $p->full_name }} ({{ $p->age }} yrs, {{ $p->sex }}{{ $p->is_pregnant ? ', Pregnant' : '' }}{{ $p->is_breastfeeding ? ', Breastfeeding' : '' }})
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="form-grid">
                        <div class="form-group">
                            <label for="sample_code" class="form-label">Sample Accession Code <span class="required">*</span></label>
                            <input type="text" id="sample_code" name="sample_code" value="{{ old('sample_code', $suggestedCode) }}" required class="form-control" style="font-family: monospace; font-weight: 700;">
                            <div class="form-help">Unique barcode / laboratory specimen accession identifier.</div>
                        </div>

                        <div class="form-group">
                            <label for="sample_type" class="form-label">Sample Specimen Type <span class="required">*</span></label>
                            <select id="sample_type" name="sample_type" required class="form-select">
                                <option value="Plasma" {{ old('sample_type') === 'Plasma' ? 'selected' : '' }}>Plasma (Standard EDTA Tube)</option>
                                <option value="DBS" {{ old('sample_type') === 'DBS' ? 'selected' : '' }}>DBS (Dried Blood Spot - 5 spots)</option>
                                <option value="Whole Blood" {{ old('sample_type') === 'Whole Blood' ? 'selected' : '' }}>Whole Blood</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-grid">
                        <div class="form-group">
                            <label for="collection_date" class="form-label">Date of Collection <span class="required">*</span></label>
                            <input type="date" id="collection_date" name="collection_date" value="{{ old('collection_date', now()->toDateString()) }}" required class="form-control">
                        </div>

                        <div class="form-group">
                            <label for="status" class="form-label">Initial Status <span class="required">*</span></label>
                            <select id="status" name="status" required class="form-select">
                                <option value="collected" {{ old('status') === 'collected' ? 'selected' : '' }}>Collected at Facility</option>
                                <option value="in_transit" {{ old('status') === 'in_transit' ? 'selected' : '' }}>In Transit to Testing Lab</option>
                                <option value="received_at_lab" {{ old('status') === 'received_at_lab' ? 'selected' : '' }}>Received at Lab</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Facility & Phlebotomist Metadata Card -->
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">2. Facility & Collector Details</h2>
                </div>
                <div class="card-body">
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="facility_id" class="form-label">Health Facility</label>
                            <select id="facility_id" name="facility_id" class="form-select">
                                @foreach($facilities as $f)
                                    <option value="{{ $f->id }}" {{ (old('facility_id', $preselectedPatient?->facility_id) == $f->id) ? 'selected' : '' }}>
                                        {{ $f->name }} ({{ $f->code }})
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="collector_id" class="form-label">Collector / Phlebotomist</label>
                            <select id="collector_id" name="collector_id" class="form-select">
                                @foreach($collectors as $u)
                                    <option value="{{ $u->id }}" {{ (old('collector_id', auth()->id()) == $u->id) ? 'selected' : '' }}>
                                        {{ $u->name }} ({{ $u->role->label() }})
                                    </option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="notes" class="form-label">Specimen Draw Notes / Observations</label>
                        <textarea id="notes" name="notes" rows="2" class="form-control" placeholder="Any phlebotomy difficulties, temperature notes, or transit instructions...">{{ old('notes') }}</textarea>
                    </div>
                </div>
            </div>
        </div>

        <!-- Right Side: Phlebotomy Guidelines Card -->
        <div>
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">🔬 Phlebotomy Protocol</h2>
                </div>
                <div class="card-body" style="font-size: 0.85rem; color: #475569; line-height: 1.6;">
                    <div style="padding: 12px; background: #f0fdfa; border-radius: var(--radius-md); border: 1px solid #ccfbf1; margin-bottom: 14px;">
                        <strong style="color: #0f766e; display: block; margin-bottom: 4px;">Plasma Specimen:</strong>
                        Collect 4–5 mL in PPT/EDTA purple-top tube. Centrifuge within 6 hours. Store cold (2–8°C).
                    </div>

                    <div style="padding: 12px; background: #eff6ff; border-radius: var(--radius-md); border: 1px solid #bfdbfe; margin-bottom: 14px;">
                        <strong style="color: #1e40af; display: block; margin-bottom: 4px;">DBS Specimen:</strong>
                        Apply minimum 5 full blood spots onto Whatman 903 filter card. Dry for 3–4 hours away from sunlight.
                    </div>

                    <div style="color: #991b1b; background: #fef2f2; border: 1px solid #fecaca; border-radius: var(--radius-md); padding: 12px;">
                        <strong>Rejection Criteria:</strong>
                        &bull; Hemolyzed or clotted blood<br>
                        &bull; Insufficient volume (&lt;2mL plasma or &lt;3 DBS spots)<br>
                        &bull; Delay &gt;24h at room temperature
                    </div>
                </div>
                <div class="card-footer">
                    <button type="submit" class="btn btn-primary" style="width: 100%; padding: 12px;">
                        💾 Save Sample Collection
                    </button>
                </div>
            </div>
        </div>
    </div>
</form>
@endsection
