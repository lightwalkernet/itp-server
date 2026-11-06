<?php

use App\Http\Controllers\Studio\LogoutController;
use App\Livewire\Studio\Costs;
use App\Livewire\Studio\Dashboard;
use App\Livewire\Studio\GenerationInspector;
use App\Livewire\Studio\Generations;
use App\Livewire\Studio\Login;
use App\Livewire\Studio\PromptEditor;
use App\Livewire\Studio\Prompts;
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

    Route::livewire('generations', Generations::class)->name('generations.index');
    Route::livewire('generations/{generation}', GenerationInspector::class)->name('generations.show');

    Route::middleware('can:managePrompts')->group(function () {
        Route::livewire('prompts', Prompts::class)->name('prompts.index');
        Route::livewire('prompts/{stage}', PromptEditor::class)->name('prompts.show');
    });

    Route::livewire('costs', Costs::class)->middleware('can:viewCosts')->name('costs');
});
