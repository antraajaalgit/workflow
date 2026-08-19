<?php

use App\Http\Controllers\StateController;
use Illuminate\Support\Facades\Route;

Route::prefix('api')->group(function () {
    Route::get('/state', [StateController::class, 'show']);
    Route::put('/state', [StateController::class, 'update']);
    Route::post('/state/reset', [StateController::class, 'reset']);
    Route::get('/session', [StateController::class, 'session']);
    Route::put('/session', [StateController::class, 'signIn']);
    Route::delete('/session', [StateController::class, 'signOut']);
});

Route::view('/{path?}', 'app')->where('path', '^(?!api).*$');
