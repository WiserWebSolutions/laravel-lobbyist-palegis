<?php

/*
|--------------------------------------------------------------------------
| Pennsylvania Legislature (palegis.us) Driver Configuration
|--------------------------------------------------------------------------
|
| This driver reads the public RSS feeds published by the Pennsylvania General
| Assembly at https://www.palegis.us and registers itself with the Lobbyist
| manager under the "pa" state abbreviation.
|
*/

return [
    /*
    |--------------------------------------------------------------------------
    | RSS Feed Endpoints
    |--------------------------------------------------------------------------
    |
    | The RSS feeds available per chamber. Only feed types listed here can be
    | fetched; the driver silently skips a chamber that does not publish a
    | given feed type (e.g. the Senate does not publish a "bills" feed).
    |
    */
    'endpoints' => [
        'house' => [
            'calendar' => 'https://www.palegis.us/house/rss/session/calendar',
            'journals' => 'https://www.palegis.us/house/rss/session/journals',
            'reports' => 'https://www.palegis.us/house/rss/session/reports',
            'votes' => 'https://www.palegis.us/house/rss/session/votes',
            'committee-schedule' => 'https://www.palegis.us/house/rss/session/committee-schedule',
            'roll-calls' => 'https://www.palegis.us/house/rss/session/roll-calls',
            'amendments' => 'https://www.palegis.us/house/rss/session/amendments',
            'cosponsorship' => 'https://www.palegis.us/house/rss/session/cosponsorship',
            'bills' => 'https://www.palegis.us/house/rss/session/bills',
            'members' => 'https://www.palegis.us/house/rss/session/members',
            'committee-assignments' => 'https://www.palegis.us/house/rss/session/committee-assignments',
        ],
        'senate' => [
            'calendar' => 'https://www.palegis.us/senate/rss/session/calendar',
            'journals' => 'https://www.palegis.us/senate/rss/session/journals',
            'notes' => 'https://www.palegis.us/senate/rss/session/notes',
            'votes' => 'https://www.palegis.us/senate/rss/session/votes',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Request Settings
    |--------------------------------------------------------------------------
    */
    'request' => [
        'timeout' => (int) env('PALEGIS_TIMEOUT', 30),
        'retry_times' => (int) env('PALEGIS_RETRY_TIMES', 2),
        'retry_sleep_ms' => (int) env('PALEGIS_RETRY_SLEEP_MS', 200),
    ],

    /*
    |--------------------------------------------------------------------------
    | Response Caching
    |--------------------------------------------------------------------------
    */
    'cache' => [
        'enabled' => env('PALEGIS_CACHE_ENABLED', true),
        'store' => env('PALEGIS_CACHE_STORE', env('CACHE_STORE')),
        'ttl' => (int) env('PALEGIS_CACHE_TTL', 3600),
    ],
];
