<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\EacEpisode;
use App\Models\EacSession;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class EacController extends Controller
{
    public function index(Request $request): View
    {
        $query = EacEpisode::with(['patient.facility', 'viralLoadResult', 'sessions'])->latest();

        if ($search = $request->input('search')) {
            $query->whereHas('patient', function ($q) use ($search) {
                $q->where('art_number', 'like', "%{$search}%")
                  ->orWhere('first_name', 'like', "%{$search}%")
                  ->orWhere('last_name', 'like', "%{$search}%");
            });
        }

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        $episodes = $query->paginate(15)->withQueryString();

        $stats = [
            'total' => EacEpisode::count(),
            'active' => EacEpisode::where('status', 'active')->count(),
            'due_repeat_vl' => EacEpisode::where('status', 'sessions_completed')->count(),
            'resolved' => EacEpisode::where('status', 'resolved_suppressed')->count(),
            'flagged' => EacEpisode::where('status', 'flagged_for_regimen_switch')->count(),
        ];

        return view('eac.index', [
            'episodes' => $episodes,
            'stats' => $stats,
        ]);
    }

    public function show(EacEpisode $eac): View
    {
        $eac->load([
            'patient.facility',
            'patient.activeRegimen',
            'viralLoadResult',
            'repeatViralLoadResult',
            'sessions.counselor',
        ]);

        return view('eac.show', [
            'episode' => $eac,
            'patient' => $eac->patient,
        ]);
    }

    public function updateSession(Request $request, EacSession $session): RedirectResponse
    {
        $validated = $request->validate([
            'session_date' => ['required', 'date'],
            'barriers' => ['nullable', 'array'],
            'barriers_other' => ['nullable', 'string', 'max:255'],
            'action_plan' => ['required', 'string', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'next_session_date' => ['nullable', 'date', 'after:session_date'],
        ]);

        $episode = $session->episode;
        $userId = auth()->id();

        // A cancelled session, or one of a closed episode, can no longer be counselled.
        if ($session->completion_status === 'cancelled' || ! $episode->isOpen()) {
            return redirect()->route('eac.show', $episode)
                ->with('error', 'This EAC session was cancelled or its episode is closed, so it cannot be completed.');
        }

        // Combine standard checklist barriers with write-in
        $barriersList = $validated['barriers'] ?? [];
        if (!empty($validated['barriers_other'])) {
            $barriersList[] = 'Other: ' . $validated['barriers_other'];
        }

        $barriersString = !empty($barriersList) ? implode('; ', $barriersList) : 'None identified';

        // 1. Mark current session as completed
        $session->update([
            'session_date' => $validated['session_date'],
            'counselor_id' => $userId,
            'barriers' => $barriersString,
            'action_plan' => $validated['action_plan'],
            'notes' => $validated['notes'] ?? null,
            'completion_status' => 'completed',
            'completed_at' => now(),
            'next_session_date' => $validated['next_session_date'] ?? null,
            'last_modified_at' => now(),
        ]);

        // 2. Automated session progression workflow
        if ($session->session_number < 3) {
            $nextNumber = $session->session_number + 1;
            $nextDate = $validated['next_session_date'] ?? Carbon::parse($validated['session_date'])->addDays(14)->toDateString();

            // Ensure next session exists or create it
            $existingNext = $episode->sessions()->where('session_number', $nextNumber)->first();
            if (!$existingNext) {
                $newSession = $episode->sessions()->create([
                    'patient_id' => $session->patient_id,
                    'session_number' => $nextNumber,
                    'session_date' => $nextDate,
                    'completion_status' => 'pending',
                    'notes' => "Follow-up EAC Session #{$nextNumber}: Review of adherence plan & pill count.",
                    'sync_status' => 'pending',
                    'last_modified_at' => now(),
                ]);

                // Module 10: Trigger discrete EAC session reminder
                try {
                    $patient = $episode->patient;
                    if ($patient) {
                        app(\App\Services\SmsService::class)->sendEacSessionReminderNotification($patient, Carbon::parse($nextDate));
                    }
                } catch (\Exception $e) {
                    \Illuminate\Support\Facades\Log::error('EAC SMS notification error: ' . $e->getMessage());
                }
            }
        } elseif ($session->session_number === 3) {
            // 3rd session completed: automatically schedule repeat VL test
            $repeatVlDate = Carbon::parse($validated['session_date'])->addMonths(1)->toDateString();

            $episode->update([
                'status' => 'sessions_completed',
                'repeat_vl_due_date' => $repeatVlDate,
                'last_modified_at' => now(),
            ]);
        }

        AuditLog::log(
            action: "completed_eac_session_{$session->session_number}",
            tableName: 'eac_sessions',
            recordId: (string) $session->id,
            oldValues: ['completion_status' => 'pending'],
            newValues: [
                'session_number' => $session->session_number,
                'completion_status' => 'completed',
                'barriers' => $barriersString,
            ],
            userId: $userId
        );

        $msg = "EAC Session #{$session->session_number} completed successfully.";
        if ($session->session_number === 3) {
            $msg .= " All 3 adherence sessions completed! Repeat Viral Load test automatically scheduled for {$episode->repeat_vl_due_date->format('Y-m-d')}.";
        }

        return redirect()->route('eac.show', $episode)->with('success', $msg);
    }
}
