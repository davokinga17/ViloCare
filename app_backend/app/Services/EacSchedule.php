<?php

namespace App\Services;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * The EAC schedule, in one place. The numbers live in config/vilocare.php (eac.*) so a later change
 * needs no code edit:
 *
 *   session 1  = the day the high result is entered + first_session_delay_days
 *   session 2  = actual date of session 1 + session_gap_days
 *   session 3  = actual date of session 2 + session_gap_days
 *   repeat VL  = actual date of session 3 + repeat_vl_days
 *
 * All values are whole days (addDays, never addMonths).
 */
class EacSchedule
{
    /**
     * Session 1 is measured from the day the result is ENTERED into the system, not from the lab's
     * result_date: a result entered late must not create a session that is already weeks overdue.
     */
    public function firstSessionDate(?CarbonInterface $enteredOn = null): Carbon
    {
        return Carbon::parse($enteredOn ?? today())->startOfDay()
            ->addDays($this->days('first_session_delay_days'));
    }

    /** Session 2 and 3: from the ACTUAL date of the session just completed. */
    public function nextSessionDate(CarbonInterface|string $completedSessionDate): Carbon
    {
        return Carbon::parse($completedSessionDate)->startOfDay()->addDays($this->days('session_gap_days'));
    }

    /** Repeat viral load: from the ACTUAL date of session 3. */
    public function repeatVlDate(CarbonInterface|string $sessionThreeDate): Carbon
    {
        return Carbon::parse($sessionThreeDate)->startOfDay()->addDays($this->days('repeat_vl_days'));
    }

    private function days(string $key): int
    {
        return max(0, (int) config("vilocare.eac.{$key}"));
    }
}
