<?php

use App\Enums\StaffRole;
use App\Models\Staff;

it('adds a staff member with the given role', function () {
    $this->artisan('studio:add-staff', ['email' => ' Ann@Example.com ', 'name' => 'Ann', '--role' => 'owner'])
        ->expectsOutputToContain('Added Ann (ann@example.com) as Owner.')
        ->assertSuccessful();

    $staff = Staff::sole();
    expect($staff->email)->toBe('ann@example.com')
        ->and($staff->role)->toBe(StaffRole::Owner);
});

it('makes editors by default', function () {
    $this->artisan('studio:add-staff', ['email' => 'ann@example.com', 'name' => 'Ann'])->assertSuccessful();

    expect(Staff::sole()->role)->toBe(StaffRole::Editor);
});

it('updates an existing staff member instead of adding a second', function () {
    Staff::factory()->editor()->create(['email' => 'ann@example.com', 'name' => 'Ann']);

    $this->artisan('studio:add-staff', ['email' => 'ann@example.com', 'name' => 'Ann K', '--role' => 'owner'])
        ->expectsOutputToContain('Updated Ann K (ann@example.com) as Owner.')
        ->assertSuccessful();

    expect(Staff::sole())
        ->name->toBe('Ann K')
        ->role->toBe(StaffRole::Owner);
});

it('fails for an unknown role or an invalid email', function (array $arguments, string $error) {
    $this->artisan('studio:add-staff', $arguments + ['email' => 'ann@example.com', 'name' => 'Ann'])
        ->expectsOutputToContain($error)
        ->assertFailed();

    expect(Staff::count())->toBe(0);
})->with([
    'unknown role' => [['--role' => 'admin'], 'The role must be owner or editor.'],
    'invalid email' => [['email' => 'not-an-email'], 'The email field must be a valid email address.'],
]);
