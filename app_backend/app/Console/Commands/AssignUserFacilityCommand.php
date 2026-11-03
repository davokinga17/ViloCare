<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Facility;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Fixes existing users after the facility-access upgrade: links one user to one facility by facility code.
 * Prints no patient data. The change syncs like any user edit.
 */
class AssignUserFacilityCommand extends Command
{
    protected $signature = 'vilocare:assign-user-facility {email : The staff member\'s email} {facility_code : The facility code, e.g. KCH-001}';

    protected $description = 'Assign an existing staff account to a facility (clears any laboratory link)';

    public function handle(): int
    {
        $user = User::where('email', $this->argument('email'))->first();
        if (! $user) {
            $this->error('No user with that email.');

            return self::FAILURE;
        }

        $facility = Facility::where('code', $this->argument('facility_code'))->first();
        if (! $facility) {
            $this->error('No facility with that code.');

            return self::FAILURE;
        }

        if ($user->isAdmin()) {
            $this->warn('Administrators see every facility and need no assignment. Nothing changed.');

            return self::SUCCESS;
        }

        $old = ['facility_id' => $user->facility_id, 'testing_laboratory_id' => $user->testing_laboratory_id];

        $user->forceFill(['facility_id' => $facility->id, 'testing_laboratory_id' => null])->save();

        AuditLog::log(
            action: 'assigned_user_facility',
            tableName: 'users',
            recordId: (string) $user->id,
            oldValues: $old,
            newValues: ['facility_id' => $facility->id, 'testing_laboratory_id' => null],
            userId: null
        );

        $this->info("{$user->name} is now assigned to {$facility->name}.");

        return self::SUCCESS;
    }
}
