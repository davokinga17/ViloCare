@extends('layouts.app')

@section('title', 'Bulk Patient CSV Import')

@section('content')
<div class="page-header">
    <div>
        <h1 class="page-title">Bulk Patient CSV Import</h1>
        <p class="page-subtitle">Batch import existing clinic records with automatic row validation, duplicate check, and baseline regimen creation.</p>
    </div>
    <div class="page-actions">
        <a href="{{ route('patients.download-template') }}" class="btn btn-secondary">
            <span>📥</span> Download Sample CSV Template
        </a>
        <a href="{{ route('patients.index') }}" class="btn btn-secondary">
            &larr; Back to Patients Directory
        </a>
    </div>
</div>

<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 24px;">
    <!-- Upload Card -->
    <div class="card">
        <div class="card-header">
            <h2 class="card-title">1. Upload Patient CSV File</h2>
        </div>
        <div class="card-body">
            <form method="POST" action="{{ route('patients.import.process') }}" enctype="multipart/form-data">
                @csrf

                <div class="form-group">
                    <label for="csv_file" class="form-label">Select CSV File (.csv) <span class="required">*</span></label>
                    <input type="file" id="csv_file" name="csv_file" accept=".csv,text/csv" required class="form-control" style="padding: 12px;">
                    <div class="form-help">Maximum file size: 5MB. Must be valid UTF-8 formatted CSV.</div>
                </div>

                <div style="margin-top: 20px;">
                    <button type="submit" class="btn btn-primary" style="width: 100%; padding: 12px; font-size: 0.95rem;">
                        🚀 Process & Validate Import Batch
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Instructions & Required Format -->
    <div class="card">
        <div class="card-header">
            <h2 class="card-title">2. CSV Column Specification</h2>
        </div>
        <div class="card-body" style="font-size: 0.85rem;">
            <p style="color: #475569; margin-bottom: 12px;">Ensure your CSV header matches the expected column names:</p>
            <div class="table-responsive"><table class="table" style="font-size: 0.775rem;">
                <thead>
                    <tr>
                        <th>Column</th>
                        <th>Required?</th>
                        <th>Format / Values</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><code>art_number</code></td>
                        <td><strong style="color: #dc2626;">Yes</strong></td>
                        <td>Unique string, e.g. <code>ART-10025</code></td>
                    </tr>
                    <tr>
                        <td><code>first_name</code></td>
                        <td><strong style="color: #dc2626;">Yes</strong></td>
                        <td>e.g. <code>Amina</code></td>
                    </tr>
                    <tr>
                        <td><code>last_name</code></td>
                        <td><strong style="color: #dc2626;">Yes</strong></td>
                        <td>e.g. <code>Hassan</code></td>
                    </tr>
                    <tr>
                        <td><code>sex</code></td>
                        <td><strong style="color: #dc2626;">Yes</strong></td>
                        <td><code>male</code> or <code>female</code></td>
                    </tr>
                    <tr>
                        <td><code>age</code></td>
                        <td><strong style="color: #dc2626;">Yes</strong></td>
                        <td>Integer between <code>0</code> and <code>120</code></td>
                    </tr>
                    <tr>
                        <td><code>art_start_date</code></td>
                        <td><strong style="color: #dc2626;">Yes</strong></td>
                        <td><code>YYYY-MM-DD</code> e.g. <code>2023-01-15</code></td>
                    </tr>
                    <tr>
                        <td><code>phone</code></td>
                        <td>Optional</td>
                        <td>e.g. <code>+211910000001</code></td>
                    </tr>
                    <tr>
                        <td><code>is_pregnant</code></td>
                        <td>Optional</td>
                        <td><code>yes</code> or <code>no</code></td>
                    </tr>
                    <tr>
                        <td><code>is_breastfeeding</code></td>
                        <td>Optional</td>
                        <td><code>yes</code> or <code>no</code></td>
                    </tr>
                    <tr>
                        <td><code>facility_code</code></td>
                        <td>Optional</td>
                        <td>e.g. <code>KCH-001</code></td>
                    </tr>
                    <tr>
                        <td><code>regimen</code></td>
                        <td>Optional</td>
                        <td>e.g. <code>TDF + 3TC + DTG (TLD)</code></td>
                    </tr>
                </tbody>
            </table></div>
        </div>
    </div>
</div>
@endsection
