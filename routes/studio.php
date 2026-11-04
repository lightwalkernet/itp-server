<?php

use App\Http\Controllers\Studio\LogoutController;
use App\Livewire\Studio\Dashboard;
use App\Livewire\Studio\Login;
use Illuminate\Support\Facades\Route;

/*
 * Studio, on `itp.studio_domain`, with the `web` middleware and the `studio` guard. Horizon is
 * mounted beside these at /horizon (config/horizon.php).
 */
Route::middleware('guest:studio')->group(function () {
    Route::livewire('login', Login::class)->name('login');
});

Route::middleware('auth:studio')->group(function () {
    Route::livewire('/', Dashboard::class)->name('dashboard');
    Route::post('logout', LogoutController::class)->name('logout');
});
