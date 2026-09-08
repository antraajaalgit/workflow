<?php

return [
    // Explicit domain timezone; never inherit a browser or PHP default timezone.
    'timezone' => 'Asia/Kolkata',
    'shift_start' => '09:30',
    'shift_end' => '18:30',
    'grace_minutes' => 15,
    'annual_paid_leave_days' => 12,
    // Fail closed until the real office location and policy limits are supplied.
    'geofence' => [
        'office_latitude' => env('ATTENDANCE_OFFICE_LATITUDE'),
        'office_longitude' => env('ATTENDANCE_OFFICE_LONGITUDE'),
        'radius_metres' => env('ATTENDANCE_GEOFENCE_RADIUS_METRES'),
        'max_accuracy_metres' => env('ATTENDANCE_MAX_ACCURACY_METRES'),
    ],
];
