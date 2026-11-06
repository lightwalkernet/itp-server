<?php

namespace App\Policies;

use App\Models\ItpGeneration;
use Illuminate\Auth\Access\Response;
use Itp\Core\Models\User;

/**
 * A generation belongs to one user. Anyone else gets a 404, so they can't tell it exists.
 */
class ItpGenerationPolicy
{
    public function view(User $user, ItpGeneration $generation): Response
    {
        return $generation->user_id === $user->id
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
