<?php

namespace App\Models;

use App\Enums\StaffRole;
use Database\Factories\StaffFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * A Studio staff member, signed in on the `studio` guard. Passwordless (email OTP), like app
 * users, so there is no password or remember token.
 */
#[Fillable(['email', 'name', 'role'])]
#[Table('staff')]
class Staff extends Authenticatable
{
    /** @use HasFactory<StaffFactory> */
    use HasFactory;

    public function isOwner(): bool
    {
        return $this->role === StaffRole::Owner;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => StaffRole::class,
        ];
    }
}
