<?php

namespace App\Services\Fis;

use App\Data\FlightData;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;

/**
 * The live copy of the feed kept in Redis. The bot reads from here.
 *
 * Keys (before the cache prefix):
 *   fis:flights:all        list of every flight in the latest feed
 *   fis:flight:{EY378}     flights matching a compact number (codeshares included)
 *   fis:meta               synced_at, source_last_updated, count
 */
class FlightSnapshot
{
    public const ALL_KEY = 'fis:flights:all';

    public const META_KEY = 'fis:meta';

    public const FLIGHT_KEY_PREFIX = 'fis:flight:';

    /**
     * @param  array<string, FlightData>  $flights
     */
    public function put(array $flights, ?string $sourceLastUpdated): void
    {
        $ttl = config('fis.snapshot_ttl');
        $all = [];
        $byNumber = [];

        foreach ($flights as $flight) {
            $item = $flight->toArray();
            $all[] = $item;

            foreach ($flight->lookupNumbers() as $number) {
                $byNumber[self::FLIGHT_KEY_PREFIX.$number][] = $item;
            }
        }

        usort($all, fn ($a, $b) => strcmp($a['scheduled_at'], $b['scheduled_at']));

        $values = $byNumber + [
            self::ALL_KEY => $all,
            self::META_KEY => [
                'synced_at' => now()->toIso8601String(),
                'source_last_updated' => $sourceLastUpdated,
                'count' => count($all),
            ],
        ];

        $this->store()->putMany($values, $ttl);
    }

    /**
     * @return list<array<string, mixed>>|null null when no snapshot exists.
     */
    public function all(): ?array
    {
        return $this->store()->get(self::ALL_KEY);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function byNumber(string $compactNumber): array
    {
        return $this->store()->get(self::FLIGHT_KEY_PREFIX.FlightData::compact($compactNumber), []);
    }

    /**
     * @return array{synced_at: string, source_last_updated: ?string, count: int}|null
     */
    public function meta(): ?array
    {
        return $this->store()->get(self::META_KEY);
    }

    public function exists(): bool
    {
        return $this->store()->has(self::ALL_KEY);
    }

    protected function store(): Repository
    {
        return Cache::store(config('fis.snapshot_store'));
    }
}
