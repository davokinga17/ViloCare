<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Facility;
use App\Models\Patient;
use App\Models\SampleCollection;
use App\Models\SampleRejection;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SampleController extends Controller
{
    public function index(Request $request): View
    {
        $query = SampleCollection::with(['patient.facility', 'facility', 'collector', 'receiver', 'rejection'])->latest('collection_date');

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('sample_code', 'like', "%{$search}%")
                  ->orWhereHas('patient', function ($pq) use ($search) {
                      $pq->where('art_number', 'like', "%{$search}%")
                         ->orWhere('first_name', 'like', "%{$search}%")
                         ->orWhere('last_name', 'like', "%{$search}%");
                  });
            });
        }

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        if ($sampleType = $request->input('sample_type')) {
            $query->where('sample_type', $sampleType);
        }

        if ($facilityId = $request->input('facility_id')) {
            $query->where('facility_id', $facilityId);
        }

        $samples = $query->paginate(15)->withQueryString();
        $facilities = Facility::orderBy('name')->get();

        $totalCount = SampleCollection::count();
        $receivedCount = SampleCollection::whereIn('status', ['received_at_lab', 'processed'])->count();
        $pendingReceptionCount = SampleCollection::whereIn('status', ['collected', 'in_transit'])->count();
        $rejectedCount = SampleCollection::where('status', 'rejected')->count();
        $rejectionRate = $totalCount > 0 ? round(($rejectedCount / $totalCount) * 100, 1) : 0;

        $stats = [
            'total' => $totalCount,
            'received' => $receivedCount,
            'pending_reception' => $pendingReceptionCount,
            'rejected' => $rejectedCount,
            'rejection_rate' => $rejectionRate,
        ];

        return view('samples.index', [
            'samples' => $samples,
            'facilities' => $facilities,
            'stats' => $stats,
        ]);
    }

    public function create(Request $request): View
    {
        $preselectedPatient = null;
        if ($patientId = $request->input('patient_id')) {
            $preselectedPatient = Patient::with('facility')->find($patientId);
        }

        $patients = Patient::where('status', 'active')->orderBy('first_name')->get();
        $facilities = Facility::with('county.state')->orderBy('name')->get();
        $collectors = User::where('is_active', true)->orderBy('name')->get();
        $suggestedCode = SampleCollection::generateSampleCode();

        return view('samples.create', [
            'patients' => $patients,
            'facilities' => $facilities,
            'collectors' => $collectors,
            'preselectedPatient' => $preselectedPatient,
            'suggestedCode' => $suggestedCode,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'sample_code' => ['required', 'string', 'max:50', 'unique:sample_collections,sample_code'],
            'patient_id' => ['required', 'uuid', 'exists:patients,id'],
            'collection_date' => ['required', 'date'],
            'sample_type' => ['required', 'string', 'in:Plasma,DBS,Whole Blood'],
            'facility_id' => ['nullable', 'uuid', 'exists:facilities,id'],
            'health_facility_code' => ['nullable', 'string', 'max:50'],
            'collector_id' => ['nullable', 'uuid', 'exists:users,id'],
            'collector_name' => ['nullable', 'string', 'max:150'],
            'status' => ['nullable', 'string', 'in:collected,in_transit,received_at_lab'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $patient = Patient::findOrFail($validated['patient_id']);
        $facilityId = $validated['facility_id'] ?? $patient->facility_id;
        $facility = Facility::find($facilityId);
        $facilityCode = $validated['health_facility_code'] ?? $facility?->code;
        $userId = auth()->id();

        $sample = SampleCollection::create([
            'sample_code' => strtoupper($validated['sample_code']),
            'patient_id' => $patient->id,
            'collection_date' => $validated['collection_date'],
            'sample_type' => $validated['sample_type'],
            'facility_id' => $facilityId,
            'health_facility_code' => $facilityCode,
            'collector_id' => $validated['collector_id'] ?? $userId,
            'collector_name' => $validated['collector_name'] ?? null,
            'status' => $validated['status'] ?? 'collected',
            'notes' => $validated['notes'] ?? null,
            'sync_status' => 'pending',
            'last_modified_at' => now(),
        ]);

        AuditLog::log(
            action: 'collected_sample',
            tableName: 'sample_collections',
            recordId: (string) $sample->id,
            oldValues: null,
            newValues: [
                'sample_code' => $sample->sample_code,
                'patient_art' => $patient->art_number,
                'sample_type' => $sample->sample_type,
                'collection_date' => $sample->collection_date->toDateString(),
            ],
            userId: $userId
        );

        return redirect()->route('samples.show', $sample)
            ->with('success', "Sample {$sample->sample_code} collected for patient {$patient->full_name} ({$patient->art_number}) successfully.");
    }

    public function show(SampleCollection $sample): View
    {
        $sample->load([
            'patient.facility',
            'patient.activeRegimen',
            'facility',
            'collector',
            'receiver',
            'rejection.rejectedBy',
        ]);

        return view('samples.show', [
            'sample' => $sample,
            'patient' => $sample->patient,
        ]);
    }

    public function receive(Request $request, SampleCollection $sample): RedirectResponse
    {
        $validated = $request->validate([
            'sample_reception_date' => ['required', 'date', 'after_or_equal:' . $sample->collection_date->toDateString()],
            'reception_notes' => ['nullable', 'string', 'max:500'],
        ]);

        $userId = auth()->id();
        $receptionNotes = $validated['reception_notes'] ?? null;

        $oldStatus = $sample->status;
        $sample->update([
            'status' => 'received_at_lab',
            'sample_reception_date' => $validated['sample_reception_date'],
            'received_by' => $userId,
            'notes' => $receptionNotes ? ($sample->notes ? $sample->notes . "\n[Reception]: " . $receptionNotes : $receptionNotes) : $sample->notes,
            'sync_status' => 'pending',
            'last_modified_at' => now(),
        ]);

        AuditLog::log(
            action: 'received_sample_at_lab',
            tableName: 'sample_collections',
            recordId: (string) $sample->id,
            oldValues: ['status' => $oldStatus],
            newValues: [
                'status' => 'received_at_lab',
                'sample_reception_date' => $sample->sample_reception_date->toDateString(),
                'received_by' => $userId,
            ],
            userId: $userId
        );

        return redirect()->route('samples.show', $sample)
            ->with('success', "Sample {$sample->sample_code} marked as Received at Lab on {$sample->sample_reception_date->format('Y-m-d')}. Ready for Viral Load analysis.");
    }

    public function reject(Request $request, SampleCollection $sample): RedirectResponse
    {
        $validated = $request->validate([
            'rejection_date' => ['required', 'date', 'after_or_equal:' . $sample->collection_date->toDateString()],
            'reason' => ['required', 'string', 'max:255'],
            'action_taken' => ['required', 'string', 'max:500'],
            'corrective_action' => ['nullable', 'string', 'max:500'],
        ]);

        $userId = auth()->id();

        // 1. Update SampleCollection status to rejected
        $oldStatus = $sample->status;
        $sample->update([
            'status' => 'rejected',
            'sync_status' => 'pending',
            'last_modified_at' => now(),
        ]);

        // 2. Create SampleRejection linked via foreign key
        $rejection = SampleRejection::create([
            'sample_collection_id' => $sample->id,
            'rejection_date' => $validated['rejection_date'],
            'reason' => $validated['reason'],
            'action_taken' => $validated['action_taken'],
            'corrective_action' => $validated['corrective_action'] ?? null,
            'rejected_by' => $userId,
            'sync_status' => 'pending',
            'last_modified_at' => now(),
        ]);

        AuditLog::log(
            action: 'rejected_sample',
            tableName: 'sample_rejections',
            recordId: (string) $rejection->id,
            oldValues: ['sample_status' => $oldStatus],
            newValues: [
                'sample_code' => $sample->sample_code,
                'patient_id' => $sample->patient_id,
                'reason' => $validated['reason'],
                'action_taken' => $validated['action_taken'],
            ],
            userId: $userId
        );

        return redirect()->route('samples.show', $sample)
            ->with('warning', "Sample {$sample->sample_code} has been marked as REJECTED. Reason: {$validated['reason']}. Immediate redraw indicated.");
    }
}
