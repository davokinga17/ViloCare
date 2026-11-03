<?php

namespace App\Console\Commands;

use App\Models\Patient;
use App\Models\PatientTransfer;
use App\Services\AccessScope;
use Illuminate\Console\Command;

/**
 * Read-only consistency check between patient_transfers and the patient rows. Two computers can act on the same
 * transfer before they sync (one accepts, one cancels); last-write-wins then keeps one transfer status but the
 * patient row may disagree. This lists such cases (transfer ids only, no patient data) and changes nothing.
 */
class CheckTransfersCommand extends Command
{
    protected $signature = 'vilocare:check-transfers';

    protected $description = 'List patient transfers whose status disagrees with where the patient is (read-only)';

    public function handle(): int
    {
        $problems = AccessScope::unscoped(function () {
            $found = [];
            $latestPerPatient = PatientTransfer::withoutGlobalScopes()->orderBy('transfer_date')->orderBy('created_at')->get()->groupBy('patient_id');

            foreach ($latestPerPatient as $patientId => $transfers) {
                $patient = Patient::withoutGlobalScopes()->find($patientId);
                $latest = $transfers->last();
                if (! $patient) {
                    continue;
                }

                if ($latest->status === PatientTransfer::ACCEPTED && $patient->facility_id !== $latest->to_facility_id) {
                    $found[] = [$latest->id, 'accepted, but the patient is not at the receiving facility'];
                }
                if ($latest->status === PatientTransfer::PENDING && ($patient->facility_id !== $latest->from_facility_id || $patient->status !== 'transferred_out')) {
                    $found[] = [$latest->id, 'pending, but the patient is not waiting at the sending facility as transferred out'];
                }
                if ($latest->status === PatientTransfer::CANCELLED && $patient->facility_id === $latest->from_facility_id && $patient->status === 'transferred_out') {
                    $found[] = [$latest->id, 'cancelled, but the patient is still marked transferred out'];
                }
                if ($transfers->where('status', PatientTransfer::PENDING)->count() > 1) {
                    $found[] = [$latest->id, 'more than one pending transfer for the same patient'];
                }
            }

            return $found;
        });

        if ($problems === []) {
            $this->info('All transfers match their patients.');

            return self::SUCCESS;
        }

        $this->table(['Transfer id', 'Problem'], $problems);
        $this->warn(count($problems).' transfer(s) need an administrator to check them. Nothing was changed.');

        return self::FAILURE;
    }
}
