<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Facility;
use App\Models\Patient;
use App\Models\PatientTransfer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Moves a patient between facilities WITHOUT creating a second patient row: the ART number stays unique and
 * regimen history, results, EAC episodes and appointments stay on the same record.
 *
 *   transferOut()  pending transfer; the patient stays at the sender as "transferred_out" (not due, read-only)
 *   accept()       the destination takes the patient: facility/county/state change, status active
 *   cancel()       the sender withdraws a pending transfer; the patient is active again
 *
 * Every step re-checks the current state inside a transaction, so a double click or a stale page cannot
 * apply twice. The caller (TransferController) decides which roles may call; this service checks the facility.
 */
class PatientTransferService
{
    public function transferOut(Patient $patient, User $user, array $data): PatientTransfer
    {
        return DB::transaction(function () use ($patient, $user, $data) {
            $patient = Patient::withoutGlobalScopes()->lockForUpdate()->findOrFail($patient->id);

            $this->assertFacility($user, $patient->facility_id, 'You can only transfer out patients of your own facility.');

            if ($patient->status !== Patient::STATUS_ACTIVE) {
                throw ValidationException::withMessages(['patient' => 'Only an active patient can be transferred out.']);
            }
            if (PatientTransfer::withoutGlobalScopes()->where('patient_id', $patient->id)->where('status', PatientTransfer::PENDING)->exists()) {
                throw ValidationException::withMessages(['patient' => 'This patient already has a pending transfer.']);
            }
            if ($data['to_facility_id'] === $patient->facility_id) {
                throw ValidationException::withMessages(['to_facility_id' => 'Choose a different facility than the current one.']);
            }
            if (! Facility::whereKey($data['to_facility_id'])->exists()) {
                throw ValidationException::withMessages(['to_facility_id' => 'The destination facility does not exist.']);
            }

            $transfer = PatientTransfer::create([
                'patient_id' => $patient->id,
                'from_facility_id' => $patient->facility_id,
                'to_facility_id' => $data['to_facility_id'],
                'transfer_date' => $data['transfer_date'],
                'letter_reference' => $data['letter_reference'] ?? null,
                'notes' => $data['notes'] ?? null,
                'status' => PatientTransfer::PENDING,
                'requested_by' => $user->id,
                'sync_status' => 'pending',
                'last_modified_at' => now(),
            ]);

            $patient->update(['status' => 'transferred_out', 'last_modified_at' => now()]);

            AuditLog::log('requested_patient_transfer', 'patient_transfers', (string) $transfer->id, null, [
                'patient_id' => $patient->id, 'from_facility_id' => $transfer->from_facility_id,
                'to_facility_id' => $transfer->to_facility_id, 'transfer_date' => $transfer->transfer_date->toDateString(),
            ], $user->id);

            return $transfer;
        });
    }

    public function accept(PatientTransfer $transfer, User $user): PatientTransfer
    {
        return AccessScope::unscoped(fn () => DB::transaction(function () use ($transfer, $user) {
            $transfer = PatientTransfer::withoutGlobalScopes()->lockForUpdate()->findOrFail($transfer->id);
            $patient = Patient::withoutGlobalScopes()->lockForUpdate()->findOrFail($transfer->patient_id);

            $this->assertFacility($user, $transfer->to_facility_id, 'Only the receiving facility can accept this transfer.');

            if (! $transfer->isPending()) {
                throw ValidationException::withMessages(['transfer' => 'This transfer is no longer pending.']);
            }
            if ($patient->facility_id !== $transfer->from_facility_id || $patient->status !== 'transferred_out') {
                throw ValidationException::withMessages(['transfer' => 'The patient record no longer matches this transfer. Ask an administrator to check it.']);
            }

            $destination = Facility::with('county')->findOrFail($transfer->to_facility_id);
            $before = ['facility_id' => $patient->facility_id, 'status' => $patient->status];

            // The same row moves: ART number, regimen history, results, EAC episodes and appointments stay on it.
            $patient->update([
                'facility_id' => $destination->id,
                'county_id' => $destination->county_id,
                'state_id' => $destination->county->state_id,
                'status' => Patient::STATUS_ACTIVE,
                'last_modified_at' => now(),
            ]);

            $transfer->update([
                'status' => PatientTransfer::ACCEPTED,
                'accepted_by' => $user->id,
                'accepted_at' => now(),
                'last_modified_at' => now(),
            ]);

            AuditLog::log('accepted_patient_transfer', 'patient_transfers', (string) $transfer->id, $before, [
                'patient_id' => $patient->id, 'facility_id' => $destination->id, 'status' => Patient::STATUS_ACTIVE,
            ], $user->id);

            return $transfer;
        }));
    }

    public function cancel(PatientTransfer $transfer, User $user): PatientTransfer
    {
        return AccessScope::unscoped(fn () => DB::transaction(function () use ($transfer, $user) {
            $transfer = PatientTransfer::withoutGlobalScopes()->lockForUpdate()->findOrFail($transfer->id);
            $patient = Patient::withoutGlobalScopes()->lockForUpdate()->findOrFail($transfer->patient_id);

            $this->assertFacility($user, $transfer->from_facility_id, 'Only the sending facility can cancel this transfer.');

            if (! $transfer->isPending()) {
                throw ValidationException::withMessages(['transfer' => 'Only a pending transfer can be cancelled.']);
            }

            $transfer->update(['status' => PatientTransfer::CANCELLED, 'last_modified_at' => now()]);

            if ($patient->facility_id === $transfer->from_facility_id && $patient->status === 'transferred_out') {
                $patient->update(['status' => Patient::STATUS_ACTIVE, 'last_modified_at' => now()]);
            }

            AuditLog::log('cancelled_patient_transfer', 'patient_transfers', (string) $transfer->id, ['status' => PatientTransfer::PENDING], ['status' => PatientTransfer::CANCELLED], $user->id);

            return $transfer;
        }));
    }

    /** Administrators act for either side; everyone else only for their own facility. */
    private function assertFacility(User $user, string $facilityId, string $message): void
    {
        if ($user->isAdmin()) {
            return;
        }

        if (! $user->facility_id || $user->facility_id !== $facilityId) {
            abort(403, $message);
        }
    }
}
