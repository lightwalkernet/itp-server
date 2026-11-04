<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Domains
    |--------------------------------------------------------------------------
    |
    | One deployment serves both hosts, split with Route::domain(). The API
    | is the single host for both app builds; Studio is the admin.
    |
    */

    'api_domain' => env('ITP_API_DOMAIN', 'api.itstotallypossible.life'),

    'studio_domain' => env('ITP_STUDIO_DOMAIN', 'studio.itstotallypossible.life'),

    /*
    |--------------------------------------------------------------------------
    | Minimum app version
    |--------------------------------------------------------------------------
    |
    | Both builds send X-App-Version (MAJOR.MINOR.PATCH) on every API call.
    | Anything below this version gets 426 Upgrade Required. Raise it to
    | retire a shared column (platform.md §4, schema changes).
    |
    */

    'min_app_version' => env('ITP_MIN_APP_VERSION', '0.0.0'),

];
