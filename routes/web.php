<?php

use App\Http\Controllers\StateController;
use Illuminate\Support\Facades\Route;

Route::prefix('api')->group(function () {
    Route::get('/session', [StateController::class, 'session']);
    Route::post('/session', [StateController::class, 'signIn'])->middleware('throttle:10,1');
    Route::delete('/session', [StateController::class, 'signOut']);
    Route::get('/state', [StateController::class, 'show']);
    Route::put('/state', [StateController::class, 'update']);
    Route::post('/state/reset', [StateController::class, 'reset']);
});

Route::view('/{path?}', 'app')->where('path', '^(?!api).*$');
