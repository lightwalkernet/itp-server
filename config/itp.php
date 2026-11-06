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

    /*
    |--------------------------------------------------------------------------
    | Generation pipeline
    |--------------------------------------------------------------------------
    |
    | The safety check is off until its prompt is ready: with it off, a new
    | generation goes straight from queued to assessing. Follow-up rounds
    | cap how often the pipeline may ask the user for more detail.
    |
    | Each user may start this many generations an hour (POST
    | /v1/generations). The pipeline jobs run on their own queue
    | connection, whose retry_after outlasts their 600s timeout.
    |
    */

    'generation' => [
        'safety_check' => (bool) env('ITP_SAFETY_CHECK', false),
        'max_followup_rounds' => (int) env('ITP_MAX_FOLLOWUP_ROUNDS', 2),
        'max_per_hour' => (int) env('ITP_GENERATIONS_PER_HOUR', 10),
        'queue_connection' => env('ITP_GENERATION_QUEUE_CONNECTION', 'redis-generation'),
    ],

    /*
    |--------------------------------------------------------------------------
    | User-facing messages
    |--------------------------------------------------------------------------
    |
    | Shown by the app for held or failed generations. Never surface
    | provider or outage detail here.
    |
    */

    'messages' => [
        'deferred' => "We've received your responses and they're being held with care. A member of our team will be in touch to support you personally.",
        'blocked' => "We're unable to create this ITP. Please contact support for help.",
        'failed' => 'Something unexpected happened while creating your ITP. Please try again, or contact support.',
    ],

    /*
    |--------------------------------------------------------------------------
    | AI stages
    |--------------------------------------------------------------------------
    |
    | Model, effort and output cap per pipeline stage, each overridable in
    | .env. System prompts are not here: they are versioned in Studio
    | (`prompt_versions`), one active version per stage.
    |
    | Prices are USD per million tokens. Thinking tokens bill as output;
    | cache write is the 5-minute TTL rate.
    |
    */

    'ai' => [

        'stages' => [
            'safety' => [
                'model' => env('ITP_AI_SAFETY_MODEL', 'claude-haiku-5-5'),
                'effort' => env('ITP_AI_SAFETY_EFFORT', 'low'),
                'max_tokens' => (int) env('ITP_AI_SAFETY_MAX_TOKENS', 16000),
            ],
            'extraction' => [
                'model' => env('ITP_AI_EXTRACTION_MODEL', 'claude-haiku-5-5'),
                'effort' => env('ITP_AI_EXTRACTION_EFFORT', 'low'),
                'max_tokens' => (int) env('ITP_AI_EXTRACTION_MAX_TOKENS', 16000),
            ],
            'followups' => [
                'model' => env('ITP_AI_FOLLOWUPS_MODEL', 'claude-sonnet-5-5'),
                'effort' => env('ITP_AI_FOLLOWUPS_EFFORT', 'medium'),
                'max_tokens' => (int) env('ITP_AI_FOLLOWUPS_MAX_TOKENS', 16000),
            ],
            'reversal' => [
                'model' => env('ITP_AI_REVERSAL_MODEL', 'claude-opus-5-5'),
                'effort' => env('ITP_AI_REVERSAL_EFFORT', 'high'),
                'max_tokens' => (int) env('ITP_AI_REVERSAL_MAX_TOKENS', 16000),
            ],
            'attunement' => [
                'model' => env('ITP_AI_ATTUNEMENT_MODEL', 'claude-opus-5-5'),
                'effort' => env('ITP_AI_ATTUNEMENT_EFFORT', 'high'),
                'max_tokens' => (int) env('ITP_AI_ATTUNEMENT_MAX_TOKENS', 16000),
            ],
            'regeneration' => [
                'model' => env('ITP_AI_REGENERATION_MODEL', 'claude-opus-5-5'),
                'effort' => env('ITP_AI_REGENERATION_EFFORT', 'high'),
                'max_tokens' => (int) env('ITP_AI_REGENERATION_MAX_TOKENS', 16000),
            ],
            'breathe_11' => [
                'model' => env('ITP_AI_BREATHE_11_MODEL', 'claude-sonnet-5-5'),
                'effort' => env('ITP_AI_BREATHE_11_EFFORT', 'medium'),
                'max_tokens' => (int) env('ITP_AI_BREATHE_11_MAX_TOKENS', 16000),
            ],
            'quantum_whys' => [
                'model' => env('ITP_AI_QUANTUM_WHYS_MODEL', 'claude-sonnet-5-5'),
                'effort' => env('ITP_AI_QUANTUM_WHYS_EFFORT', 'medium'),
                'max_tokens' => (int) env('ITP_AI_QUANTUM_WHYS_MAX_TOKENS', 16000),
            ],
            'title' => [
                'model' => env('ITP_AI_TITLE_MODEL', 'claude-sonnet-5-5'),
                'effort' => env('ITP_AI_TITLE_EFFORT', 'medium'),
                'max_tokens' => (int) env('ITP_AI_TITLE_MAX_TOKENS', 16000),
            ],
        ],

        'prices' => [
            'claude-haiku-5-5' => ['input' => 0.10, 'output' => 0.50, 'cache_read' => 0.01, 'cache_write' => 0.125],
            'claude-sonnet-5-5' => ['input' => 2.00, 'output' => 10.00, 'cache_read' => 0.20, 'cache_write' => 2.50],
            'claude-opus-5-5' => ['input' => 4.00, 'output' => 20.00, 'cache_read' => 0.20, 'cache_write' => 5.00],
        ],

    ],

];
