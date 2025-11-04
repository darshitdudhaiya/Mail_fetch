<?php

use App\Http\Controllers\MicrosoftAuthConfigController;
use App\Http\Controllers\MicrosoftAuthController;
use Illuminate\Support\Facades\Route;

Route::prefix('microsoft')->group(function () {
    Route::get('/config', [MicrosoftAuthConfigController::class, 'getMicrosoftConfig']);
});

Route::prefix('auth')->group(function () {
    Route::post('/token', [MicrosoftAuthController::class, 'handleToken']);
    Route::get('/user', [MicrosoftAuthController::class, 'getCurrentUser']);
    Route::post('/logout', [MicrosoftAuthController::class, 'logout']);
});

Route::prefix('emails')->group(function () {
    Route::get('/unreplied', [MicrosoftAuthController::class, 'getUnrepliedEmails']);
    Route::get('/{messageId}', [MicrosoftAuthController::class, 'getEmailDetails']);
});
