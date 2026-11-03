<?php

namespace App\Http\Controllers;

use App\Models\Facility;
use App\Models\Patient;
use App\Models\PatientTransfer;
use App\Services\PatientTransferService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Patient transfers between facilities (see PatientTransferService). Both lists read the patient through the
 * transfer row and select only ART number and name: the receiving facility never sees the full record before
 * it accepts, and the sending facility sees no clinical data after the patient has moved.
 */
class TransferController extends Controller
{
    /** Columns of the patient shown on the lists (nothing clinical). */
    private const PATIENT_COLUMNS = 'patient:id,art_number,first_name,last_name';

    public function incoming(): View
    {
        $transfers = PatientTransfer::with([self::PATIENT_COLUMNS, 'fromFacility:id,name', 'toFacility:id,name'])
            ->where('status', PatientTransfer::PENDING)
            ->when(! auth()->user()->isAdmin(), fn ($q) => $q->where('to_facility_id', auth()->user()->facility_id))
            ->orderBy('transfer_date')
            ->get();

        return view('transfers.incoming', ['transfers' => $transfers]);
    }

    public function outgoing(): View
    {
        $transfers = PatientTransfer::with([self::PATIENT_COLUMNS, 'fromFacility:id,name', 'toFacility:id,name'])
            ->when(! auth()->user()->isAdmin(), fn ($q) => $q->where('from_facility_id', auth()->user()->facility_id))
            ->latest('transfer_date')
            ->latest('created_at')
            ->get();

        return view('transfers.outgoing', ['transfers' => $transfers]);
    }

    public function store(Request $request, Patient $patient, PatientTransferService $service): RedirectResponse
    {
        $validated = $request->validate([
            'to_facility_id' => ['required', 'uuid', 'exists:facilities,id'],
            'transfer_date' => ['required', 'date', 'before_or_equal:today'],
            'letter_reference' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $service->transferOut($patient, $request->user(), $validated);

        return redirect()->route('transfers.outgoing')
            ->with('success', "Transfer out recorded for {$patient->art_number}. The patient stays on your list as transferred out until the receiving facility accepts.");
    }

    public function accept(PatientTransfer $transfer, Request $request, PatientTransferService $service): RedirectResponse
    {
        $service->accept($transfer, $request->user());

        return redirect()->route('transfers.incoming')
            ->with('success', 'Transfer accepted. The patient record, ART number and history are now at your facility.');
    }

    public function cancel(PatientTransfer $transfer, Request $request, PatientTransferService $service): RedirectResponse
    {
        $service->cancel($transfer, $request->user());

        return redirect()->route('transfers.outgoing')->with('success', 'Transfer cancelled. The patient is active again.');
    }

    /** Facilities a patient can be sent to (every facility except the current one). */
    public static function destinations(Patient $patient)
    {
        return Facility::with('county')->where('id', '!=', $patient->facility_id)->orderBy('name')->get()
            ->groupBy(fn ($f) => $f->county?->name ?? 'Other')->sortKeys();
    }
}
