<?php

use App\Models\Staff;
use Itp\Core\Models\User;

beforeEach(function () {
    $this->withoutVite();
});

it('shows the signed-in staff member and their role', function () {
    $staff = Staff::factory()->editor()->create(['name' => 'Ann Example']);

    $this->actingAs($staff, 'studio')
        ->get(route('studio.dashboard'))
        ->assertOk()
        ->assertSee('Ann Example · Editor');
});

it('links to Horizon for the owner only', function (string $role, bool $seesLink) {
    $staff = Staff::factory()->{$role}()->create();

    $response = $this->actingAs($staff, 'studio')->get(route('studio.dashboard'));

    $seesLink
        ? $response->assertSee(route('horizon.index'))
        : $response->assertDontSee(route('horizon.index'));
})->with([
    'owner' => ['owner', true],
    'editor' => ['editor', false],
]);

it('shows the Studio navigation the role can open', function (string $role, array $sees, array $doesNotSee) {
    $response = $this->actingAs(Staff::factory()->{$role}()->create(), 'studio')->get(route('studio.dashboard'));

    foreach ($sees as $routeName) {
        $response->assertSee(route($routeName));
    }

    foreach ($doesNotSee as $routeName) {
        $response->assertDontSee(route($routeName));
    }
})->with([
    'owner' => ['owner', ['studio.generations.index', 'studio.prompts.index', 'studio.costs', 'horizon.index'], []],
    'editor' => ['editor', ['studio.generations.index'], ['studio.prompts.index', 'studio.costs', 'horizon.index']],
]);

it('does not let an app user in', function () {
    $this->actingAs(User::factory()->create(), 'web')
        ->get(route('studio.dashboard'))
        ->assertRedirect(route('studio.login'));
});
