<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ContentController;
use App\Http\Controllers\Api\ItpGenerationController;
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

        Route::get('content/pull', [ContentController::class, 'pull'])->middleware('throttle:content')->name('content.pull');

        Route::middleware('auth:sanctum')->group(function () {
            Route::prefix('generations')->name('generations.')->controller(ItpGenerationController::class)->group(function () {
                Route::get('/', 'index')->name('index');
                Route::post('/', 'store')->middleware('throttle:generations')->name('store');

                Route::middleware('can:view,generation')->group(function () {
                    Route::get('{generation}', 'show')->name('show');
                    Route::post('{generation}/followups', 'storeFollowups')->name('followups.store');
                    Route::post('{generation}/sections/{part}/flags', 'storeSectionFlag')->whereNumber('part')->name('sections.flags.store');
                    Route::post('{generation}/sections/{part}/choice', 'storeSectionChoice')->whereNumber('part')->name('sections.choice.store');
                    Route::post('{generation}/accept', 'accept')->name('accept');
                });
            });

            Route::sync();
        });
    });
