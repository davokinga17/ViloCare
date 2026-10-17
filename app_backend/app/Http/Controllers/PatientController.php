<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\County;
use App\Models\Facility;
use App\Models\Patient;
use App\Models\State;
use App\Services\PatientImportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PatientController extends Controller
{
    public function index(Request $request): View
    {
        $query = Patient::query()->with(['facility', 'county', 'activeRegimen']);

        // Search query
        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('art_number', 'like', "%{$search}%")
                  ->orWhere('first_name', 'like', "%{$search}%")
                  ->orWhere('last_name', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        // Sex filter
        if ($sex = $request->input('sex')) {
            $query->where('sex', $sex);
        }

        // Cohort filter (<15 child vs 15+ adult)
        if ($cohort = $request->input('cohort')) {
            if ($cohort === 'pediatric') {
                $query->where('age', '<', 15);
            } elseif ($cohort === 'adult') {
                $query->where('age', '>=', 15);
            }
        }

        // Pregnancy & Breastfeeding flags
        if ($request->filled('condition')) {
            if ($request->input('condition') === 'pregnant') {
                $query->where('is_pregnant', true);
            } elseif ($request->input('condition') === 'breastfeeding') {
                $query->where('is_breastfeeding', true);
            }
        }

        // Facility filter
        if ($facilityId = $request->input('facility_id')) {
            $query->where('facility_id', $facilityId);
        }

        // Status filter
        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        $patients = $query->latest('art_start_date')->paginate(15)->withQueryString();
        $facilities = Facility::orderBy('name')->get();

        $stats = [
            'total' => Patient::count(),
            'pediatric' => Patient::where('age', '<', 15)->count(),
            'pregnant' => Patient::where('is_pregnant', true)->count(),
            'breastfeeding' => Patient::where('is_breastfeeding', true)->count(),
        ];

        return view('patients.index', [
            'patients' => $patients,
            'facilities' => $facilities,
            'stats' => $stats,
        ]);
    }

    public function create(): View
    {
        $states = State::with('counties.facilities')->get();
        $facilities = Facility::with('county.state')->orderBy('name')->get();

        return view('patients.create', [
            'states' => $states,
            'facilities' => $facilities,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'art_number' => ['required', 'string', 'max:50', 'unique:patients,art_number'],
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'sex' => ['required', 'string', 'in:male,female'],
            'age' => ['required', 'integer', 'min:0', 'max:120'],
            'dob' => ['nullable', 'date'],
            'phone' => ['nullable', 'string', 'max:25'],
            'address' => ['nullable', 'string', 'max:255'],
            'art_start_date' => ['required', 'date'],
            'is_pregnant' => ['nullable', 'boolean'],
            'is_breastfeeding' => ['nullable', 'boolean'],
            'facility_id' => ['required', 'uuid', 'exists:facilities,id'],
            'regimen_name' => ['required', 'string', 'max:100'],
            'regimen_line' => ['required', 'string', 'in:1st Line,2nd Line,3rd Line'],
        ]);

        $facility = Facility::with('county.state')->findOrFail($validated['facility_id']);
        $county = $facility->county;
        $state = $county->state;

        $userId = auth()->id();

        $patient = DB::transaction(function () use ($validated, $facility, $county, $state, $userId) {
            $patient = Patient::create([
                'art_number' => $validated['art_number'],
                'first_name' => $validated['first_name'],
                'last_name' => $validated['last_name'],
                'sex' => $validated['sex'],
                'age' => $validated['age'],
                'dob' => $validated['dob'] ?? null,
                'phone' => $validated['phone'] ?? null,
                'address' => $validated['address'] ?? null,
                'art_start_date' => $validated['art_start_date'],
                'is_pregnant' => (bool) ($validated['is_pregnant'] ?? false),
                'is_breastfeeding' => (bool) ($validated['is_breastfeeding'] ?? false),
                'facility_id' => $facility->id,
                'county_id' => $county->id,
                'state_id' => $state->id,
                'status' => 'active',
                'created_by' => $userId,
                'sync_status' => 'pending',
                'last_modified_at' => now(),
            ]);

            // Create initial baseline regimen in regimen_histories
            $patient->assignNewRegimen(
                regimenName: $validated['regimen_name'],
                regimenLine: $validated['regimen_line'],
                startDate: $validated['art_start_date'],
                reason: 'Baseline initial regimen at enrollment',
                userId: $userId
            );

            return $patient;
        });

        AuditLog::log(
            action: 'registered_patient',
            tableName: 'patients',
            recordId: (string) $patient->id,
            oldValues: null,
            newValues: [
                'art_number' => $patient->art_number,
                'name' => $patient->full_name,
                'age' => $patient->age,
                'regimen' => $validated['regimen_name'],
            ],
            userId: $userId
        );

        return redirect()->route('patients.show', $patient)
            ->with('success', "Patient {$patient->full_name} ({$patient->art_number}) registered successfully.");
    }

    public function show(Patient $patient): View
    {
        $patient->load([
            'facility',
            'county',
            'state',
            'creator',
            'regimenHistories.recorder',
            'viralLoadResults.requestingClinician',
            'viralLoadResults.recorder',
            'eacEpisodes.sessions',
            'sampleCollections.collector',
            'sampleCollections.rejection',
            'appointments.scheduler',
        ]);

        return view('patients.show', [
            'patient' => $patient,
        ]);
    }

    public function edit(Patient $patient): View
    {
        $facilities = Facility::with('county.state')->orderBy('name')->get();

        return view('patients.edit', [
            'patient' => $patient,
            'facilities' => $facilities,
        ]);
    }

    public function update(Request $request, Patient $patient): RedirectResponse
    {
        $validated = $request->validate([
            'art_number' => ['required', 'string', 'max:50', Rule::unique('patients', 'art_number')->ignore($patient->id)],
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'sex' => ['required', 'string', 'in:male,female'],
            'age' => ['required', 'integer', 'min:0', 'max:120'],
            'dob' => ['nullable', 'date'],
            'phone' => ['nullable', 'string', 'max:25'],
            'address' => ['nullable', 'string', 'max:255'],
            'art_start_date' => ['required', 'date'],
            'is_pregnant' => ['nullable', 'boolean'],
            'is_breastfeeding' => ['nullable', 'boolean'],
            'facility_id' => ['required', 'uuid', 'exists:facilities,id'],
            'status' => ['required', 'string', 'in:active,transferred_out,lost_to_followup,deceased'],
        ]);

        $facility = Facility::with('county.state')->findOrFail($validated['facility_id']);

        $oldValues = $patient->getAuditableAttributes();
        $isNewlyPregnant = !$patient->is_pregnant && (bool) ($validated['is_pregnant'] ?? false);
        $pregnancyOnsetAt = $patient->pregnancy_onset_at;

        if ($isNewlyPregnant) {
            $pregnancyOnsetAt = now();
        } elseif (isset($validated['is_pregnant']) && !$validated['is_pregnant']) {
            $pregnancyOnsetAt = null;
        }

        $patient->update([
            'art_number' => $validated['art_number'],
            'first_name' => $validated['first_name'],
            'last_name' => $validated['last_name'],
            'sex' => $validated['sex'],
            'age' => $validated['age'],
            'dob' => $validated['dob'] ?? null,
            'phone' => $validated['phone'] ?? null,
            'address' => $validated['address'] ?? null,
            'art_start_date' => $validated['art_start_date'],
            'is_pregnant' => (bool) ($validated['is_pregnant'] ?? false),
            'is_breastfeeding' => (bool) ($validated['is_breastfeeding'] ?? false),
            'pregnancy_onset_at' => $pregnancyOnsetAt,
            'facility_id' => $facility->id,
            'county_id' => $facility->county_id,
            'state_id' => $facility->county->state_id,
            'status' => $validated['status'],
            'last_modified_at' => now(),
        ]);

        AuditLog::log(
            action: 'updated_patient',
            tableName: 'patients',
            recordId: (string) $patient->id,
            oldValues: $oldValues,
            newValues: $patient->getAuditableAttributes(),
            userId: auth()->id()
        );

        if ($isNewlyPregnant) {
            AuditLog::log(
                action: 'pregnancy_onset_recorded',
                tableName: 'patients',
                recordId: (string) $patient->id,
                oldValues: ['is_pregnant' => false],
                newValues: [
                    'is_pregnant' => true,
                    'pregnancy_onset_at' => (string) $pregnancyOnsetAt,
                    'algorithm_action' => 'Immediate Viral Load test indicated for PMTCT cohort',
                ],
                userId: auth()->id()
            );
        }

        $successMsg = "Patient {$patient->full_name} updated successfully.";
        if ($isNewlyPregnant) {
            $successMsg .= " ⚠️ Immediate Viral Load test indicated for PMTCT cohort.";
        }

        return redirect()->route('patients.show', $patient)
            ->with('success', $successMsg);
    }

    public function destroy(Request $request, Patient $patient): RedirectResponse
    {
        if (!auth()->user()->isAdmin()) {
            abort(403, 'Unauthorized: Patient deletion is restricted to Administrators only.');
        }

        $patientName = $patient->full_name;
        $artNumber = $patient->art_number;
        $patientId = (string) $patient->id;

        $historySummary = [
            'viral_loads_count' => $patient->viralLoadResults()->count(),
            'eac_episodes_count' => $patient->eacEpisodes()->count(),
            'samples_count' => $patient->sampleCollections()->count(),
            'appointments_count' => $patient->appointments()->count(),
            'regimens_count' => $patient->regimenHistories()->count(),
            'notifications_count' => $patient->notifications()->count(),
        ];

        DB::transaction(function () use ($patient) {
            // Clean up linked clinical entities cleanly
            foreach ($patient->eacEpisodes as $episode) {
                $episode->sessions()->delete();
                $episode->delete();
            }

            foreach ($patient->sampleCollections as $sample) {
                $sample->rejection()?->delete();
                $sample->delete();
            }

            $patient->viralLoadResults()->delete();
            $patient->appointments()->delete();
            $patient->regimenHistories()->delete();
            $patient->notifications()->delete();

            $patient->delete();
        });

        AuditLog::log(
            action: 'deleted_patient',
            tableName: 'patients',
            recordId: $patientId,
            oldValues: array_merge([
                'art_number' => $artNumber,
                'name' => $patientName,
            ], $historySummary),
            newValues: null,
            userId: auth()->id()
        );

        return redirect()->route('patients.index')
            ->with('success', "Patient record for {$patientName} ({$artNumber}) was permanently deleted by Administrator.");
    }

    public function importForm(): View
    {
        return view('patients.import');
    }

    public function import(Request $request, PatientImportService $importer): View|RedirectResponse
    {
        $request->validate([
            'csv_file' => ['required', 'file', 'mimes:csv,txt', 'max:5120'], // max 5MB
        ]);

        $result = $importer->importCsv($request->file('csv_file'), (string) auth()->id());

        return view('patients.import-results', [
            'result' => $result,
        ]);
    }

    public function downloadTemplate(PatientImportService $importer): StreamedResponse
    {
        $csvContent = $importer->generateSampleCsv();
        $fileName = 'vilocare_patient_bulk_import_template.csv';

        return response()->streamDownload(function () use ($csvContent) {
            echo $csvContent;
        }, $fileName, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$fileName}\"",
        ]);
    }
}
