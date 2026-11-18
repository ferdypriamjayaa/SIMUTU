<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    /*
    | HRIS employee API (docs/api-karyawan-rs.md). Called from the backend
    | only; credentials never reach the browser.
    */
    'hris' => [
        'url' => rtrim((string) env('HRIS_API_URL', ''), '/'),
        'username' => env('HRIS_API_USERNAME'),
        'password' => env('HRIS_API_PASSWORD'),
        // Hospital company codes that may be queried, e.g. "0001,0002".
        'company_codes' => array_values(array_filter(array_map('trim', explode(',', (string) env('HRIS_COMPANY_CODES', ''))))),
        'timeout' => (int) env('HRIS_TIMEOUT', 10),
        // How long a fetched employee list is reused before asking HRIS again.
        'cache_ttl' => (int) env('HRIS_CACHE_TTL', 3600),
        // How long the last good list is kept as a fallback while HRIS is unreachable.
        'stale_ttl' => (int) env('HRIS_STALE_TTL', 86400),
        'per_page' => 200,
        'max_pages' => 50,
    ],

    /*
    | Doctor API (docs/API-Dokter.md): active doctors of every hospital,
    | HTTP Basic Auth. DOKTER_API_URL is the full endpoint URL
    | (…/api/dokter). Called from the backend only; credentials never reach
    | the browser.
    */
    'dokter' => [
        'url' => rtrim((string) env('DOKTER_API_URL', ''), '/'),
        'username' => env('DOKTER_API_USERNAME'),
        'password' => env('DOKTER_API_PASSWORD'),
        'timeout' => (int) env('DOKTER_API_TIMEOUT', 10),
        // How long a fetched doctor list is reused before asking the API again.
        'cache_ttl' => (int) env('DOKTER_API_CACHE_TTL', 3600),
        // How long the last good list is kept as a fallback while the API is unreachable.
        'stale_ttl' => (int) env('DOKTER_API_STALE_TTL', 86400),
    ],

    /*
    | Jotform API: read-only access to form definitions (form list, one
    | form, its questions). SIMUTU never requests submissions. The key is
    | sent in the APIKEY header and never reaches the browser or the logs.
    */
    'jotform' => [
        'api_key' => env('JOTFORM_API_KEY'),
        'base_url' => rtrim((string) env('JOTFORM_BASE_URL', 'https://api.jotform.com'), '/'),
        'timeout' => (int) env('JOTFORM_TIMEOUT', 20),
        // Where exported definitions are read from (one folder per form).
        'export_path' => env('JOTFORM_EXPORT_PATH', base_path('JOTFORM-EXPORT')),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
