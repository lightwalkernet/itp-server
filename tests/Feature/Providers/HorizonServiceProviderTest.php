<?php

use App\Models\Staff;
use Itp\Core\Models\User;

it('opens Horizon for the owner', function () {
    $this->actingAs(Staff::factory()->owner()->create(), 'studio')
        ->get(route('horizon.index'))
        ->assertOk();
});

it('forbids Horizon to an editor, in local too', function (string $environment) {
    $this->app['env'] = $environment;

    $this->actingAs(Staff::factory()->editor()->create(), 'studio')
        ->get(route('horizon.index'))
        ->assertForbidden();
})->with(['production', 'local']);

it('sends guests and app users to the Studio sign-in', function (bool $asAppUser) {
    if ($asAppUser) {
        $this->actingAs(User::factory()->create(), 'web');
    }

    $this->get(route('horizon.index'))->assertRedirect(route('studio.login'));
})->with([
    'guest' => [false],
    'app user' => [true],
]);

it('mounts Horizon on the Studio domain only', function () {
    $owner = Staff::factory()->owner()->create();

    $this->actingAs($owner, 'studio')->get('http://localhost/horizon')->assertNotFound();
    $this->actingAs($owner, 'studio')->get('http://'.config('itp.api_domain').'/horizon')->assertNotFound();
});
