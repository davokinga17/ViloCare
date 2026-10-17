<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Patient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class RegimenHistoryController extends Controller
{
    public function store(Request $request, Patient $patient): RedirectResponse
    {
        $validated = $request->validate([
            'regimen_name' => ['required', 'string', 'max:150'],
            'regimen_line' => ['required', 'string', 'in:1st Line,2nd Line,3rd Line'],
            'start_date' => ['required', 'date'],
            'reason_for_change' => ['required', 'string', 'max:500'],
        ]);

        $userId = (string) auth()->id();
        $previousActive = $patient->activeRegimen;

        $newRegimen = $patient->assignNewRegimen(
            regimenName: $validated['regimen_name'],
            regimenLine: $validated['regimen_line'],
            startDate: $validated['start_date'],
            reason: $validated['reason_for_change'],
            userId: $userId
        );

        AuditLog::log(
            action: 'switched_regimen',
            tableName: 'regimen_histories',
            recordId: (string) $newRegimen->id,
            oldValues: $previousActive ? ['regimen_name' => $previousActive->regimen_name, 'end_date' => $previousActive->end_date] : null,
            newValues: [
                'patient_art' => $patient->art_number,
                'new_regimen' => $validated['regimen_name'],
                'line' => $validated['regimen_line'],
                'reason' => $validated['reason_for_change'],
            ],
            userId: $userId
        );

        return redirect()->route('patients.show', $patient)
            ->with('success', "Treatment regimen updated to '{$validated['regimen_name']}' successfully.");
    }
}
