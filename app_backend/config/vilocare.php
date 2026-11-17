<?php

return [

    /*
    | Facility the dashboard opens on when the user has not chosen a state/county/facility filter, identified by
    | its facility code (e.g. KCH-001). The built-in default is the pilot clinic's code. Set
    | VILOCARE_DEFAULT_FACILITY_CODE to your own facility's code; leave it empty to use the first facility
    | in the list.
    */
    'default_facility_code' => env('VILOCARE_DEFAULT_FACILITY_CODE', 'KCH-001'),

    /*
    | Facility name printed on the report verification page. Empty = the name of the default facility above.
    */
    'clinic_name' => env('VILOCARE_CLINIC_NAME'),

    /*
    | Forgot-password requests all take at least this long to answer (milliseconds), so the time taken does not
    | reveal whether an account exists.
    */
    'reset_min_response_ms' => (int) env('VILOCARE_RESET_MIN_RESPONSE_MS', 600),

    /*
    | EAC schedule (confirmed by the client). All values are whole days.
    |  first_session_delay_days: session 1 = high viral load result date + this (0 = same day).
    |  session_gap_days:         session 2 = session 1 date + this; session 3 = session 2 date + this.
    |  repeat_vl_days:           repeat viral load = session 3 date + this.
    */
    'eac' => [
        'first_session_delay_days' => (int) env('VILOCARE_EAC_FIRST_SESSION_DELAY_DAYS', 0),
        'session_gap_days' => (int) env('VILOCARE_EAC_SESSION_GAP_DAYS', 30),
        'repeat_vl_days' => (int) env('VILOCARE_EAC_REPEAT_VL_DAYS', 30),
    ],

];
