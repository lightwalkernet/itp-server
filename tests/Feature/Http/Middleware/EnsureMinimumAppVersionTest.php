<?php

use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    config(['itp.min_app_version' => '1.2.0']);
});

it('returns 426 below the minimum version', function () {
    $this->withHeader('X-App-Version', '1.1.9')
        ->postJson(route('api.auth.send-code'), ['email' => 'ann@example.com'])
        ->assertStatus(426)
        ->assertExactJson([
            'message' => 'This version of the app needs an update.',
            'data' => ['min_app_version' => '1.2.0'],
            'errors' => ['app_version' => ['Version 1.1.9 is no longer supported.']],
        ]);
});

it('returns 426 without the header', function (array $headers) {
    $this->withHeaders($headers)
        ->postJson(route('api.auth.send-code'), ['email' => 'ann@example.com'])
        ->assertStatus(426)
        ->assertJsonPath('data.min_app_version', '1.2.0')
        ->assertJsonPath('errors.app_version', ['The X-App-Version header is required.']);
})->with([
    'missing' => [[]],
    'empty' => [['X-App-Version' => '']],
]);

it('returns 426 for a malformed header', function (string $version) {
    $this->withHeader('X-App-Version', $version)
        ->postJson(route('api.auth.send-code'), ['email' => 'ann@example.com'])
        ->assertStatus(426)
        ->assertJsonPath('errors.app_version', ['The X-App-Version header must look like 1.2.3.']);
})->with([
    'words' => 'banana',
    'two parts' => '1.2',
    'pre-release suffix' => '1.2.0-beta',
    'leading v' => 'v1.2.0',
    'trailing newline' => "1.2.0\n",
]);

it('returns 426 before authentication runs', function () {
    $this->withHeader('X-App-Version', '1.1.9')
        ->postJson(route('api.auth.logout'))
        ->assertStatus(426);
});

it('returns 426 before the rate limit runs', function () {
    Mail::fake();
    $this->withHeader('X-App-Version', '1.2.0')
        ->postJson(route('api.auth.send-code'), ['email' => 'ann@example.com'])
        ->assertOk();

    $this->withHeader('X-App-Version', '1.1.9')
        ->postJson(route('api.auth.send-code'), ['email' => 'ann@example.com'])
        ->assertStatus(426);
});

it('lets the minimum version and newer through', function (string $version) {
    Mail::fake();

    $this->withHeader('X-App-Version', $version)
        ->postJson(route('api.auth.send-code'), ['email' => 'ann@example.com'])
        ->assertOk();
})->with([
    'the minimum' => '1.2.0',
    'a two-digit minor' => '1.10.0',
    'a new major' => '2.0.0',
]);
