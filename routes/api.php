<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Middleware\EnsureMinimumAppVersion;
use Illuminate\Support\Facades\Route;

/*
 * The API host for both app builds. Studio routes sit beside these on `itp.studio_domain`.
 */
Route::domain(config('itp.api_domain'))
    ->middleware(EnsureMinimumAppVersion::class)
    ->name('api.')
    ->group(function () {

        Route::prefix('auth')->name('auth.')->controller(AuthController::class)->group(function () {
            Route::post('send-code', 'sendCode')->middleware('throttle:send-code')->name('send-code');
            Route::post('verify-code', 'verifyCode')->middleware('throttle:verify-code')->name('verify-code');

            Route::middleware('auth:sanctum')->group(function () {
                Route::post('complete-profile', 'completeProfile')->name('complete-profile');
                Route::post('logout', 'logout')->name('logout');
            });
        });

        Route::middleware('auth:sanctum')->group(function () {
            Route::sync();
        });

    });
