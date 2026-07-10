<?php

/*
|--------------------------------------------------------------------------
| Pennsylvania Legislature (palegis.us) Driver Configuration
|--------------------------------------------------------------------------
|
| This driver reads the public RSS feeds published by the Pennsylvania General
| Assembly at https://www.palegis.us/data and registers itself with the Lobbyist
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
    | given feed type (e.g. the Senate does not publish a "reports" feed).
    |
    */
    'endpoints' => [
        'house' => [
            'calendar' => 'https://www.palegis.us/house/rss/session/calendar',
            'calendar-tabled-bill' => 'https://www.palegis.us/house/rss/session/calendar?calendarType=calendar-tabled-bill',
            'journals' => 'https://www.palegis.us/house/rss/session/journals',
            'reports' => 'https://www.palegis.us/house/rss/session/reports',
            'votes' => 'https://www.palegis.us/house/rss/session/votes',
            'amendments' => 'https://www.palegis.us/house/rss/session/amendments',
            'members' => 'https://www.palegis.us/house/rss/session/members',
            'committee-schedule' => 'https://www.palegis.us/house/rss/committee/schedule',
            'committee-assignments' => 'https://www.palegis.us/house/rss/committee/assignments',
            'memos' => 'https://www.palegis.us/house/rss/legislation/memos',
        ],
        'senate' => [
            'calendar' => 'https://www.palegis.us/senate/rss/session/calendar',
            'journals' => 'https://www.palegis.us/senate/rss/session/journals',
            'notes' => 'https://www.palegis.us/senate/rss/session/notes',
            'votes' => 'https://www.palegis.us/senate/rss/session/votes',
            'amendments' => 'https://www.palegis.us/senate/rss/session/amendments',
            'members' => 'https://www.palegis.us/senate/rss/session/members',
            'executive-nominations' => 'https://www.palegis.us/senate/rss/session/executive-nominations',
            'committee-schedule' => 'https://www.palegis.us/senate/rss/committee/schedule',
            'committee-assignments' => 'https://www.palegis.us/senate/rss/committee/assignments',
            'memos' => 'https://www.palegis.us/senate/rss/legislation/memos',
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

    /*
    |--------------------------------------------------------------------------
    | Bill History Data
    |--------------------------------------------------------------------------
    |
    | The Bill History Data export (a ZIP-wrapped XML listing every bill and
    | resolution in a session, refreshed hourly on weekdays) is downloaded from
    | a fixed palegis.us endpoint. The URL is generated automatically and the
    | session defaults to the current regular session.
    |
    | Rather than caching the (large) export as a single blob, each bill is
    | cached under its own key, so no cache write ever has to serialize more
    | than one bill's data at a time. Run `php artisan palegis:sync-bill-history`
    | to populate/refresh the cache; getBillHistory()/bill() lookups fall back
    | to a live download automatically if the cache is cold or has expired.
    |
    | To keep the cache warm, schedule the sync command in the app's own
    | console kernel/routes, e.g.:
    |
    |     Schedule::command('palegis:sync-bill-history')->hourly();
    |
    */
    'data' => [
        'ttl' => (int) env('PALEGIS_BILL_HISTORY_TTL', 3600),
    ],
];
