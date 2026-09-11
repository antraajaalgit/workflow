<?php

return [
    'ddns' => [
        'enabled' => env('ATTENDANCE_DDNS_ENABLED', true),
        'hostname' => env('ATTENDANCE_DDNS_HOSTNAME', 'karya-office.dedyn.io'),
        'token' => env('ATTENDANCE_DDNS_TOKEN', ''),
        'heartbeat_secret' => env('ATTENDANCE_DDNS_HEARTBEAT_SECRET', ''),
    ],
    // Explicit domain timezone; never inherit a browser or PHP default timezone.
    'timezone' => 'Asia/Kolkata',
    'shift_start' => '09:30',
    'shift_end' => '18:30',
    'grace_minutes' => 15,
    'annual_paid_leave_days' => 12,
    'office_ips' => array_values(array_filter(array_map('trim', explode(',', env('ATTENDANCE_OFFICE_IPS', ''))))),
    'office_hostnames' => array_values(array_filter(array_map('trim', explode(',', env('ATTENDANCE_OFFICE_HOSTNAMES', ''))))),
    // Fail closed until the real office location and policy limits are supplied.
    'geofence' => [
        'office_latitude' => env('ATTENDANCE_OFFICE_LATITUDE'),
        'office_longitude' => env('ATTENDANCE_OFFICE_LONGITUDE'),
        'radius_metres' => env('ATTENDANCE_GEOFENCE_RADIUS_METRES'),
        'max_accuracy_metres' => env('ATTENDANCE_MAX_ACCURACY_METRES'),
    ],
];
