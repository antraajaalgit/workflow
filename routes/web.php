<?php

use App\Http\Controllers\StateController;
use App\Http\Controllers\GoogleCalendarController;
use Illuminate\Support\Facades\Route;

Route::prefix('api')->group(function () {
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

Route::view('/{path?}', 'app')->where('path', '^(?!api).*$');
