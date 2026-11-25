<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Velana International Airport - Flight Information System (fis.com.mv)
    |--------------------------------------------------------------------------
    |
    | The public site exposes a single JSON endpoint that returns every flight
    | for today plus the edges of yesterday / tomorrow. We poll it on a
    | schedule and serve bot queries from local storage only.
    |
    */

    'base_url' => env('FIS_BASE_URL', 'https://fis.com.mv'),

    'flights_path' => '/api/flights',

    'weather_path' => '/api/weather',

    'timeout' => (int) env('FIS_TIMEOUT', 10),

    'user_agent' => env('FIS_USER_AGENT', 'FlightMvBot/1.0 (flight info cache)'),

    // Source times carry no timezone; they are Maldives local time.
    'timezone' => 'Indian/Maldives',

    'sync_enabled' => (bool) env('FIS_SYNC_ENABLED', true),

    // Cache store used for the live snapshot the bot reads from.
    'snapshot_store' => env('FIS_SNAPSHOT_STORE', 'redis'),

    // Snapshot expires if syncing stops, so the bot never serves very stale data.
    'snapshot_ttl' => (int) env('FIS_SNAPSHOT_TTL', 600),

    'prune_after_days' => (int) env('FIS_PRUNE_AFTER_DAYS', 30),

];
