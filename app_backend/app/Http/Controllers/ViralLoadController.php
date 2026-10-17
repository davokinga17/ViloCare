<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\EacEpisode;
use App\Models\Patient;
use App\Models\TestingLaboratory;
use App\Models\User;
use App\Models\ViralLoadResult;
use App\Services\SuppressionService;
use App\Services\ViralLoadEngine;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ViralLoadController extends Controller
{
    public function index(Request $request): View
    {
        $query = ViralLoadResult::with(['patient.facility', 'requestingClinician', 'recorder'])->latest('result_date');

        if ($search = $request->input('search')) {
            $query->whereHas('patient', function ($q) use ($search) {
                $q->where('art_number', 'like', "%{$search}%")
                  ->orWhere('first_name', 'like', "%{$search}%")
                  ->orWhere('last_name', 'like', "%{$search}%");
            });
        }

        if ($request->filled('status')) {
            if ($request->input('status') === 'suppressed') {
                $query->where('is_suppressed', true);
            } elseif ($request->input('status') === 'unsuppressed') {
                $query->where('is_suppressed', false);
            }
        }

        if ($request->filled('flagged')) {
            $query->where('flagged_for_clinical_review', true);
        }

        if ($request->filled('repeat')) {
            $query->where('is_post_eac_repeat', true);
        }

        $results = $query->paginate(15)->withQueryString();

        // Per patient (latest result of each active patient), single shared definition (see SuppressionService).
        $suppression = app(SuppressionService::class)->summary();
        $flaggedCount = ViralLoadResult::where('flagged_for_clinical_review', true)->count();

        $stats = [
            'total' => $suppression['total_vl'],
            'suppressed' => $suppression['suppressed_count'],
            'unsuppressed' => $suppression['unsuppressed_count'],
            'suppression_rate' => $suppression['suppression_rate'],
            'flagged' => $flaggedCount,
            'patients_tested' => $suppression['patients_tested'],
            'patients_suppressed' => $suppression['patients_suppressed'],
            'patients_unsuppressed' => $suppression['patients_unsuppressed'],
            'active_patients' => $suppression['active_patients'],
            'vl_coverage_percent' => $suppression['vl_coverage_percent'],
        ];
        return view('viral-load.index', [
            'results' => $results,
            'stats' => $stats,
        ]);
    }

    public function create(Request $request): View
    {
        $preselectedPatient = null;
        if ($patientId = $request->input('patient_id')) {
            $preselectedPatient = Patient::with(['activeRegimen', 'activeEacEpisode'])->find($patientId);
        }

        $patients = Patient::where('status', 'active')->orderBy('first_name')->get();
        $clinicians = User::whereIn('role', ['clinician', 'administrator'])->get();

        // Open EAC episode per patient, so the form can say up front what a result will do.
        $openEacByPatient = EacEpisode::whereIn('status', EacEpisode::OPEN_STATUSES)
            ->withCount(['sessions as completed_sessions_count' => fn ($q) => $q->where('completion_status', 'completed')])
            ->orderBy('created_at')
            ->get()
            ->keyBy('patient_id')
            ->map(fn (EacEpisode $e) => [
                'after_session_3' => $e->status === 'sessions_completed' || $e->completed_sessions_count >= 3,
                'session' => min(3, $e->completed_sessions_count + 1),
            ]);

        return view('viral-load.create', [
            'openEacByPatient' => $openEacByPatient,
            'patients' => $patients,
            'clinicians' => $clinicians,
            'preselectedPatient' => $preselectedPatient,
            'labs' => TestingLaboratory::active()->pluck('name'),
            // Preselect the lab inside the patient's own facility, when there is one.
            'defaultLab' => $preselectedPatient
                ? TestingLaboratory::active()->where('facility_id', $preselectedPatient->facility_id)->value('name')
                : null,
        ]);
    }

    public function store(Request $request, ViralLoadEngine $engine): RedirectResponse
    {
        $validated = $request->validate([
            'patient_id' => ['required', 'uuid', 'exists:patients,id'],
            'sample_date' => ['required', 'date'],
            'result_date' => ['required', 'date', 'after_or_equal:sample_date'],
            'result_value' => ['required', 'integer', 'min:0'],
            'is_target_not_detected' => ['nullable', 'boolean'],
            'sample_type' => ['required', 'string', 'in:Plasma,DBS,Whole Blood'],
            'lab_name' => ['required', 'string', Rule::exists('testing_laboratories', 'name')->where('is_active', true)],
            'requesting_clinician_id' => ['nullable', 'uuid', 'exists:users,id'],
            'notes' => ['nullable', 'string', 'max:500'],
        ], [
            'lab_name.required' => 'Please select the testing laboratory.',
            'lab_name.exists' => 'The selected testing laboratory is not valid. Choose one of the listed laboratories.',
        ]);

        $userId = auth()->id();
        $resultValue = (bool)($validated['is_target_not_detected'] ?? false) ? 0 : (int)$validated['result_value'];

        $patient = Patient::findOrFail($validated['patient_id']);

        // is_post_eac_repeat / eac_episode_id are never read from the form: the engine decides them
        // from the patient's open EAC episode and its session progress.
        $result = ViralLoadResult::create([
            'patient_id' => $patient->id,
            'sample_date' => $validated['sample_date'],
            'result_date' => $validated['result_date'],
            'result_value' => $resultValue,
            'is_target_not_detected' => (bool)($validated['is_target_not_detected'] ?? false),
            'lab_name' => $validated['lab_name'],
            'sample_type' => $validated['sample_type'],
            'requesting_clinician_id' => $validated['requesting_clinician_id'] ?? null,
            'recorded_by' => $userId,
            'notes' => $validated['notes'] ?? null,
            'is_post_eac_repeat' => false,
            'eac_episode_id' => null,
            'sync_status' => 'pending',
            'last_modified_at' => now(),
        ]);

        // Process viral load algorithmic calculation & triggers
        $eac = $engine->processResult($result);

        // Module 10: Trigger discrete result ready SMS notice if patient has phone
        try {
            app(\App\Services\SmsService::class)->sendResultReadyNotification($patient);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Viral load SMS notification error: ' . $e->getMessage());
        }

        AuditLog::log(
            action: 'entered_viral_load_result',
            tableName: 'viral_load_results',
            recordId: (string) $result->id,
            oldValues: null,
            newValues: [
                'patient_art' => $patient->art_number,
                'result_value' => $result->result_value,
                'is_suppressed' => $result->is_suppressed,
                'algorithm_reason' => $result->algorithm_reason,
            ],
            userId: $userId
        );

        $flashMessage = $result->is_suppressed
            ? "Viral Load result recorded successfully: Suppressed ({$result->formatted_result}). Next due date: {$result->next_due_date?->format('Y-m-d')}."
            : "⚠️ High Viral Load recorded ({$result->formatted_result}). Adherence counselling (EAC Episode) automatically triggered!";

        if ($eac['outcome'] === ViralLoadEngine::OUTCOME_CLOSED_EARLY) {
            $flashMessage .= ' EAC closed early, pending sessions cancelled.';
        } elseif ($eac['outcome'] === ViralLoadEngine::OUTCOME_CONTINUING) {
            $flashMessage = "⚠️ High Viral Load ({$result->formatted_result}): EAC in progress (session {$eac['session_number']} of 3), result recorded, counselling continues.";
        } elseif ($eac['outcome'] === ViralLoadEngine::OUTCOME_FLAGGED) {
            $flashMessage = "🚨 Suspected treatment failure: clinical review. Post-EAC repeat VL is still high ({$result->formatted_result}).";
        }

        return redirect()->route('viral-load.show', $result)
            ->with($result->is_suppressed ? 'success' : 'warning', $flashMessage);
    }

    public function show(ViralLoadResult $viral_load): View
    {
        $viral_load->load(['patient.facility', 'patient.activeRegimen', 'requestingClinician', 'recorder', 'eacEpisode.sessions']);

        return view('viral-load.show', [
            'result' => $viral_load,
            'patient' => $viral_load->patient,
        ]);
    }
}
