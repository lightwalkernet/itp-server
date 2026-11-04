<?php

use App\Models\Staff;

it('signs the staff member out and returns to the sign-in page', function () {
    $this->actingAs(Staff::factory()->create(), 'studio')
        ->post(route('studio.logout'))
        ->assertRedirect(route('studio.login'));

    $this->assertGuest('studio');
});

it('sends a guest to the sign-in page', function () {
    $this->post(route('studio.logout'))->assertRedirect(route('studio.login'));
});
