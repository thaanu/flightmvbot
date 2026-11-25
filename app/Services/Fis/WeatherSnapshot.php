<?php

namespace App\Services\Fis;

use Illuminate\Support\Facades\Cache;

/**
 * Current MLE weather, refreshed by `fis:weather` and read by the bot.
 */
class WeatherSnapshot
{
    public const KEY = 'fis:weather';

    public function put(array $weather): void
    {
        Cache::store(config('fis.snapshot_store'))->put(self::KEY, $weather + ['fetched_at' => now()->toIso8601String()], 3600);
    }

    public function get(): ?array
    {
        return Cache::store(config('fis.snapshot_store'))->get(self::KEY);
    }
}
