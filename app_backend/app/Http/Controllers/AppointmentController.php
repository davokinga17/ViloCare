<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Patient;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AppointmentController extends Controller
{
    public function index(Request $request): View
    {
        $query = Appointment::with(['patient.facility', 'scheduler', 'attender'])->latest('appointment_date');

        // Period filter
        $period = $request->input('period', 'upcoming');
        if ($period === 'today') {
            $query->whereDate('appointment_date', today());
        } elseif ($period === 'this_week') {
            $query->whereBetween('appointment_date', [now()->startOfWeek()->toDateString(), now()->endOfWeek()->toDateString()]);
        } elseif ($period === 'this_month') {
            $query->whereBetween('appointment_date', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()]);
        } elseif ($period === 'overdue') {
            $query->where('status', 'scheduled')->whereDate('appointment_date', '<', today());
        } elseif ($period === 'upcoming') {
            $query->whereDate('appointment_date', '>=', today())->where('status', 'scheduled');
        }

        // Status filter
        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        // Reason filter
        if ($reason = $request->input('reason')) {
            $query->where('reason', $reason);
        }

        // Patient search
        if ($search = $request->input('search')) {
            $query->whereHas('patient', function ($q) use ($search) {
                $q->where('art_number', 'like', "%{$search}%")
                  ->orWhere('first_name', 'like', "%{$search}%")
                  ->orWhere('last_name', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        $appointments = $query->paginate(15)->withQueryString();

        $todayCount = Appointment::whereDate('appointment_date', today())->count();
        $upcomingCount = Appointment::whereDate('appointment_date', '>=', today())->where('status', 'scheduled')->count();
        $overdueCount = Appointment::where('status', 'scheduled')->whereDate('appointment_date', '<', today())->count();
        $completedCount = Appointment::where('status', 'completed')->count();

        $stats = [
            'today' => $todayCount,
            'upcoming' => $upcomingCount,
            'overdue' => $overdueCount,
            'completed' => $completedCount,
            'total' => Appointment::count(),
        ];

        return view('appointments.index', [
            'appointments' => $appointments,
            'stats' => $stats,
            'currentPeriod' => $period,
        ]);
    }

    public function create(Request $request): View
    {
        $preselectedPatient = null;
        if ($patientId = $request->input('patient_id')) {
            $preselectedPatient = Patient::with('facility')->find($patientId);
        }

        $patients = Patient::where('status', 'active')->orderBy('first_name')->get();

        return view('appointments.create', [
            'patients' => $patients,
            'preselectedPatient' => $preselectedPatient,
            'suggestedReason' => $request->input('reason', 'Routine Clinical Review'),
            'suggestedDate' => $request->input('date', now()->addDays(7)->toDateString()),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'patient_id' => ['required', 'uuid', 'exists:patients,id'],
            'appointment_date' => ['required', 'date'],
            'appointment_time' => ['nullable', 'string', 'max:50'],
            'reason' => ['required', 'string', 'max:150'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $patient = Patient::findOrFail($validated['patient_id']);
        $userId = auth()->id();

        $appointment = Appointment::create([
            'patient_id' => $patient->id,
            'appointment_date' => $validated['appointment_date'],
            'appointment_time' => $validated['appointment_time'] ?? null,
            'reason' => $validated['reason'],
            'status' => 'scheduled',
            'scheduled_by' => $userId,
            'notes' => $validated['notes'] ?? null,
            'sync_status' => 'pending',
            'last_modified_at' => now(),
        ]);

        AuditLog::log(
            action: 'scheduled_appointment',
            tableName: 'appointments',
            recordId: (string) $appointment->id,
            oldValues: null,
            newValues: [
                'patient_art' => $patient->art_number,
                'date' => $appointment->appointment_date->toDateString(),
                'reason' => $appointment->reason,
            ],
            userId: $userId
        );

        // Module 10: Trigger discrete appointment reminder SMS
        try {
            app(\App\Services\SmsService::class)->sendAppointmentReminder($appointment);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Appointment SMS notification error: ' . $e->getMessage());
        }

        return redirect()->route('appointments.index')
            ->with('success', "Appointment scheduled for {$patient->full_name} on {$appointment->appointment_date->format('Y-m-d')} ({$appointment->reason}).");
    }

    public function show(Appointment $appointment): View
    {
        $appointment->load(['patient.facility', 'patient.activeRegimen', 'scheduler', 'attender']);

        return view('appointments.show', [
            'appointment' => $appointment,
            'patient' => $appointment->patient,
        ]);
    }

    public function updateStatus(Request $request, Appointment $appointment): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'string', 'in:completed,missed,cancelled'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $userId = auth()->id();
        $oldStatus = $appointment->status;

        $updateData = [
            'status' => $validated['status'],
            'sync_status' => 'pending',
            'last_modified_at' => now(),
        ];

        if ($validated['status'] === 'completed') {
            $updateData['attended_at'] = now();
            $updateData['attended_by'] = $userId;
        }

        if (!empty($validated['notes'])) {
            $updateData['notes'] = $appointment->notes ? $appointment->notes . "\n[Update]: " . $validated['notes'] : $validated['notes'];
        }

        $appointment->update($updateData);

        AuditLog::log(
            action: "updated_appointment_status_{$validated['status']}",
            tableName: 'appointments',
            recordId: (string) $appointment->id,
            oldValues: ['status' => $oldStatus],
            newValues: ['status' => $validated['status']],
            userId: $userId
        );

        return redirect()->back()
            ->with('success', "Appointment status updated to '{$appointment->statusLabel()}'.");
    }

    public function reschedule(Request $request, Appointment $appointment): RedirectResponse
    {
        $validated = $request->validate([
            'new_date' => ['required', 'date'],
            'new_time' => ['nullable', 'string', 'max:50'],
            'reschedule_reason' => ['nullable', 'string', 'max:255'],
        ]);

        $userId = auth()->id();

        // 1. Mark current appointment as rescheduled
        $appointment->update([
            'status' => 'rescheduled',
            'notes' => ($appointment->notes ? $appointment->notes . "\n" : '') . "Rescheduled to {$validated['new_date']}. Reason: " . ($validated['reschedule_reason'] ?? 'Patient request'),
            'sync_status' => 'pending',
            'last_modified_at' => now(),
        ]);

        // 2. Create new scheduled appointment
        $newAppointment = Appointment::create([
            'patient_id' => $appointment->patient_id,
            'appointment_date' => $validated['new_date'],
            'appointment_time' => $validated['new_time'] ?? $appointment->appointment_time,
            'reason' => $appointment->reason,
            'status' => 'scheduled',
            'scheduled_by' => $userId,
            'notes' => "Rescheduled follow-up from appointment on {$appointment->appointment_date->toDateString()}.",
            'sync_status' => 'pending',
            'last_modified_at' => now(),
        ]);

        AuditLog::log(
            action: 'rescheduled_appointment',
            tableName: 'appointments',
            recordId: (string) $newAppointment->id,
            oldValues: ['previous_date' => $appointment->appointment_date->toDateString()],
            newValues: ['new_date' => $newAppointment->appointment_date->toDateString()],
            userId: $userId
        );

        return redirect()->route('appointments.index')
            ->with('success', "Appointment rescheduled successfully to {$newAppointment->appointment_date->format('Y-m-d')}.");
    }
}
