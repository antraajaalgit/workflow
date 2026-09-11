<?php

use App\Http\Controllers\AttendanceDdnsController;
use Illuminate\Support\Facades\Route;

// Deliberately outside the web group: no session or CSRF middleware.
Route::post('/api/attendance/ddns/heartbeat', AttendanceDdnsController::class)
    ->middleware('throttle:attendance-ddns')
    ->name('attendance.ddns.heartbeat');
