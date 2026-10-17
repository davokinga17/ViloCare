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

];
