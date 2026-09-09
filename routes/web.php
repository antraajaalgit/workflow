<?php

use App\Http\Controllers\StateController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\DashboardTaskController;
use App\Http\Controllers\GoogleCalendarController;
use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\AdminAttendanceController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\LeaveController;
use App\Http\Controllers\AdminLeaveController;

Route::prefix('api')->group(function () {
    Route::get('/admin/holidays', [\App\Http\Controllers\AdminHolidayController::class, 'index']);
    Route::post('/admin/holidays', [\App\Http\Controllers\AdminHolidayController::class, 'store']);
    Route::put('/admin/holidays/{id}', [\App\Http\Controllers\AdminHolidayController::class, 'update']);
    Route::delete('/admin/holidays/{id}', [\App\Http\Controllers\AdminHolidayController::class, 'destroy']);
    Route::get('/admin/attendance', [AdminAttendanceController::class, 'index']);
    Route::post('/admin/attendance/overrides', [AdminAttendanceController::class, 'store']);
    Route::delete('/admin/attendance/overrides/{id}', [AdminAttendanceController::class, 'destroy']);
    Route::get('/attendance/today', [AttendanceController::class, 'today']);
    Route::post('/attendance/check-in', [AttendanceController::class, 'checkIn']);
    Route::post('/attendance/check-out', [AttendanceController::class, 'checkOut']);

    Route::get('/leave/mine', [LeaveController::class, 'mine']);
    Route::get('/leave/balance', [LeaveController::class, 'balance']);
   Route::post('/leave', [LeaveController::class, 'store']);
   Route::get('/admin/leave', [AdminLeaveController::class, 'index']);
Route::post('/admin/leave/{id}/approve', [AdminLeaveController::class, 'approve']);
Route::post('/admin/leave/{id}/reject', [AdminLeaveController::class, 'reject']);


    Route::post('/projects', [DashboardTaskController::class, 'project']);
    Route::patch('/projects/{id}/tasks', [DashboardTaskController::class, 'project']);
    Route::delete('/projects/{id}', [DashboardTaskController::class, 'deleteProject']);
    Route::post('/clients', [DashboardTaskController::class, 'client']);
    Route::patch('/clients/{id}', [DashboardTaskController::class, 'client']);
    Route::delete('/clients/{id}', [DashboardTaskController::class, 'deleteClient']);
    Route::delete('/team-members/{id}', [DashboardTaskController::class, 'deleteMember']);
    Route::post('/departments', [DashboardTaskController::class, 'department']);
    Route::patch('/departments/{id}', [DashboardTaskController::class, 'department']);
    Route::delete('/departments/{id}', [DashboardTaskController::class, 'deleteDepartment']);
    Route::post('/briefs', [DashboardTaskController::class, 'brief']);
    Route::get('/tasks/{id}', [TaskController::class, 'show']);
    Route::post('/tasks', [TaskController::class, 'store']);
    Route::patch('/tasks/{id}', [TaskController::class, 'update']);
    Route::patch('/tasks/{id}/status', [TaskController::class, 'status']);
    Route::patch('/tasks/{id}/progress', [TaskController::class, 'progress']);
    Route::patch('/tasks/{id}/assignees', [TaskController::class, 'assignees']);
    Route::delete('/tasks/{id}', [TaskController::class, 'destroy']);
    Route::get('/session', [StateController::class, 'session']);
    Route::post('/session', [StateController::class, 'signIn'])->middleware('throttle:10,1');
    Route::delete('/session', [StateController::class, 'signOut']);
    Route::get('/state', [StateController::class, 'show']);
    Route::put('/state', [StateController::class, 'update']);
    Route::post('/state/reset', [StateController::class, 'reset']);
    Route::post('/recurring-tasks/generate', [StateController::class, 'generateRecurringTasks'])->middleware('throttle:10,1');
    Route::post('/chat-attachments', [StateController::class, 'uploadChatAttachments']);
    Route::post('/team-member-image', [StateController::class, 'uploadTeamMemberImage']);
    Route::get('/team-member-image', [StateController::class, 'showTeamMemberImage']);
    Route::post('/chat-email', [StateController::class, 'sendChatEmail'])->middleware('throttle:30,1');
    //Route::get('/chat-attachments/{file}', [StateController::class, 'showChatAttachment']);
    Route::get('/chat-attachment', [StateController::class, 'showChatAttachment']);
    Route::prefix('google-calendar')->group(function () {
        Route::get('/connect', [GoogleCalendarController::class, 'connect']);
        Route::get('/callback', [GoogleCalendarController::class, 'callback']);
        Route::get('/status', [GoogleCalendarController::class, 'status']);
        Route::get('/events', [GoogleCalendarController::class, 'events']);
        Route::delete('/disconnect', [GoogleCalendarController::class, 'disconnect']);
    });
});

Route::get('/login', function () {
    return redirect('/');
})->name('login');

Route::view('/{path?}', 'app')->where('path', '^(?!api).*$');
