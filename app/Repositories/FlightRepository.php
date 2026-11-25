<?php

namespace App\Repositories;

use App\Data\FlightData;
use App\Models\Flight;
use App\Services\Fis\FlightSnapshot;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Read API for the bot. Reads the Redis snapshot first and falls back to
 * MySQL when the snapshot is missing (e.g. Redis restarted, sync stalled).
 *
 * Every method returns a collection of plain arrays in the snapshot shape
 * (see FlightData::toArray()).
 */
class FlightRepository
{
    public function __construct(protected FlightSnapshot $snapshot) {}

    /**
     * Find flights by number, e.g. "EY378", "ey 378", or a codeshare number.
     */
    public function findByNumber(string $number): Collection
    {
        $compact = FlightData::compact($number);

        if ($compact === '') {
            return collect();
        }

        if ($this->snapshot->exists()) {
            return collect($this->snapshot->byNumber($compact))->sortBy('scheduled_at')->values();
        }

        return $this->fromDatabase(
            Flight::query()
                ->where(fn ($q) => $q->where('flight_number_compact', $compact)
                    ->orWhereJsonContains('codeshares', $this->spaced($number)))
                ->where('scheduled_at', '>=', now()->subDay()->startOfDay())
        );
    }

    public function arrivals(?CarbonInterface $from = null, int $limit = 20): Collection
    {
        return $this->upcoming('arrival', $from, $limit);
    }

    public function departures(?CarbonInterface $from = null, int $limit = 20): Collection
    {
        return $this->upcoming('departure', $from, $limit);
    }

    /**
     * Search by city name or airport code ("Dubai", "DXB"), matching the far end of the flight.
     */
    public function search(string $term, ?string $type = null): Collection
    {
        $term = trim($term);

        if ($term === '') {
            return collect();
        }

        $needle = mb_strtolower($term);

        return $this->all()
            ->when($type, fn ($c) => $c->where('type', $type))
            ->filter(function (array $f) use ($needle) {
                foreach (['origin', 'origin_code', 'destination', 'destination_code', 'airline'] as $field) {
                    if ($f[$field] !== null && str_contains(mb_strtolower($f[$field]), $needle)) {
                        return true;
                    }
                }

                return false;
            })
            ->values();
    }

    public function lastSyncedAt(): ?CarbonImmutable
    {
        $meta = $this->snapshot->meta();

        return $meta ? CarbonImmutable::parse($meta['synced_at']) : null;
    }

    /**
     * Every flight in the current window.
     */
    public function all(): Collection
    {
        $all = $this->snapshot->all();

        if ($all !== null) {
            return collect($all);
        }

        return $this->fromDatabase(
            Flight::query()->whereBetween('scheduled_at', [
                now()->subDay()->startOfDay(),
                now()->addDay()->endOfDay(),
            ])
        );
    }

    /**
     * Arrivals or departures from a point in time, optionally filtered by
     * category (international|domestic). Ordered by scheduled time.
     */
    public function board(string $type, ?string $category = null, ?CarbonInterface $from = null): Collection
    {
        $from = CarbonImmutable::instance($from ?? now()->subMinutes(30));

        return $this->all()
            ->where('type', $type)
            ->when($category, fn ($c) => $c->where('category', $category))
            ->filter(fn (array $f) => CarbonImmutable::parse($f['estimated_at'] ?? $f['scheduled_at'])->gte($from))
            ->sortBy('scheduled_at')
            ->values();
    }

    public function findByKey(string $flightKey): ?array
    {
        return $this->all()->firstWhere('flight_key', $flightKey);
    }

    /**
     * The snapshot is considered stale when the last sync is older than this.
     */
    public function isStale(int $minutes = 5): bool
    {
        $synced = $this->lastSyncedAt();

        return $synced === null || $synced->lt(now()->subMinutes($minutes));
    }

    protected function upcoming(string $type, ?CarbonInterface $from, int $limit): Collection
    {
        return $this->board($type, null, $from ?? now()->subHour())->take($limit)->values();
    }

    protected function fromDatabase($query): Collection
    {
        return $query->orderBy('scheduled_at')->get()->map(fn (Flight $f) => [
            'flight_key' => $f->flight_key,
            'type' => $f->type,
            'flight_number' => $f->flight_number,
            'flight_number_compact' => $f->flight_number_compact,
            'airline' => $f->airline,
            'airline_code' => $f->airline_code,
            'origin' => $f->origin,
            'origin_code' => $f->origin_code,
            'destination' => $f->destination,
            'destination_code' => $f->destination_code,
            'scheduled_at' => $f->scheduled_at->shiftTimezone(config('fis.timezone'))->toIso8601String(),
            'estimated_at' => $f->estimated_at?->shiftTimezone(config('fis.timezone'))->toIso8601String(),
            'terminal' => $f->terminal,
            'gate' => $f->gate,
            'status' => $f->status,
            'category' => $f->category,
            'delay_minutes' => $f->delay_minutes,
            'early_minutes' => $f->early_minutes,
            'via_routes' => $f->via_routes ?? [],
            'codeshares' => $f->codeshares ?? [],
        ])->values();
    }

    /**
     * "dl5914" -> "DL 5914", the format codeshares are stored in.
     */
    protected function spaced(string $number): string
    {
        return preg_match('/^([A-Z0-9]{2})(\d+[A-Z]?)$/', FlightData::compact($number), $m)
            ? "{$m[1]} {$m[2]}"
            : trim($number);
    }
}
