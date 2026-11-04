<?php

namespace App\Livewire\Studio;

use App\Models\Staff;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Studio home. A placeholder until the Studio sections are built; it shows who is signed in
 * and links to what their role can open.
 */
#[Layout('layouts.studio')]
#[Title('Studio')]
class Dashboard extends Component
{
    public function render(): View
    {
        /** @var Staff $staff */
        $staff = Auth::guard('studio')->user();

        return view('livewire.studio.dashboard', [
            'staff' => $staff,
            'canViewHorizon' => $staff->can('viewHorizon'),
        ]);
    }
}
