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
    Route::post('/chat-attachments', [StateController::class, 'uploadChatAttachments']);
    Route::get('/chat-attachments/{file}', [StateController::class, 'showChatAttachment']);
    Route::prefix('google-calendar')->group(function () {
        Route::get('/connect', [GoogleCalendarController::class, 'connect']);
        Route::get('/callback', [GoogleCalendarController::class, 'callback']);
        Route::get('/status', [GoogleCalendarController::class, 'status']);
        Route::get('/events', [GoogleCalendarController::class, 'events']);
        Route::delete('/disconnect', [GoogleCalendarController::class, 'disconnect']);
    });
});

Route::view('/{path?}', 'app')->where('path', '^(?!api).*$');
