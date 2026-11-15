<?php

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Migrations\Migration;
use Itp\Core\Models\User;

/**
 * Every user has a settings row from their first sign-in on. Give one to every user who signed in
 * before that, through the model so each row gets its sync stamp and reaches the phone. Safe to run
 * again: users who already have a row are skipped.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        User::query()
            ->whereDoesntHave('settings')
            ->chunkById(500, function (Collection $users): void {
                $users->each(fn (User $user) => $user->settings()->firstOrCreate());
            });
    }

    /**
     * Reverse the migrations. The rows stay: they are the users' settings now.
     */
    public function down(): void
    {
        //
    }
};
