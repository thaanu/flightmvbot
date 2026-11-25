<?php

namespace App\Services\Query;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * A structured question about flights, produced by FlightQueryParser.
 */
final class FlightQuery
{
    /**
     * @param  ?string  $type  arrival|departure|null (both)
     * @param  list<string>  $placeCodes  airport codes of the far end
     * @param  list<string>  $airlines  lower-cased airline names
     * @param  list<string>  $placeNames  lower-cased names, also matched against intermediate stops
     * @param  bool  $upcomingOnly  hide flights that already departed/landed
     */
    public function __construct(
        public ?string $type = null,
        public array $placeCodes = [],
        public ?string $placeLabel = null,
        public array $placeNames = [],
        public array $airlines = [],
        public ?string $airlineLabel = null,
        public ?string $status = null,
        public ?string $category = null,
        public ?CarbonImmutable $from = null,
        public ?CarbonImmutable $until = null,
        public ?string $timeLabel = null,
        public bool $upcomingOnly = true,
        public bool $last = false,
    ) {}

    /**
     * Flights matching this query, ordered by time.
     */
    public function apply(Collection $flights): Collection
    {
        return $flights
            ->filter(fn (array $f) => $this->matches($f))
            ->sortBy(fn (array $f) => $this->time($f)->getTimestamp())
            ->values();
    }

    public function matches(array $f): bool
    {
        if ($this->type && $f['type'] !== $this->type) {
            return false;
        }

        if ($this->placeCodes !== []) {
            $code = $f['type'] === 'arrival' ? $f['origin_code'] : $f['destination_code'];
            $via = array_map('mb_strtolower', $f['via_routes'] ?? []);

            if (! in_array($code, $this->placeCodes, true) && array_intersect($via, $this->placeNames) === []) {
                return false;
            }
        }

        if ($this->airlines !== [] && ! in_array(mb_strtolower((string) $f['airline']), $this->airlines, true)) {
            return false;
        }

        if ($this->category && $f['category'] !== $this->category) {
            return false;
        }

        if ($this->status === 'delayed') {
            if ($f['status'] !== 'delayed' && empty($f['delay_minutes'])) {
                return false;
            }
        } elseif ($this->status && $f['status'] !== $this->status) {
            return false;
        }

        if ($this->upcomingOnly && in_array($f['status'], ['departed', 'landed'], true)) {
            return false;
        }

        $time = $this->time($f);

        if ($this->from && $time->lt($this->from)) {
            return false;
        }

        if ($this->until && $time->gt($this->until)) {
            return false;
        }

        return true;
    }

    /**
     * "departures to Kuala Lumpur (KUL)", "delayed arrivals", "Emirates flights"...
     */
    public function describe(): string
    {
        $noun = match ($this->type) {
            'arrival' => 'arrivals',
            'departure' => 'departures',
            default => 'flights',
        };

        $parts = array_filter([
            $this->status ? str_replace('-', ' ', $this->status) : null,
            $this->category,
            $this->airlineLabel,
            $noun,
        ]);

        $text = implode(' ', $parts);

        if ($this->placeLabel) {
            $text .= match ($this->type) {
                'arrival' => ' from ',
                'departure' => ' to ',
                default => ' to/from ',
            }.$this->placeLabel;
        }

        return $text;
    }

    public function time(array $f): CarbonImmutable
    {
        return self::timeOf($f);
    }

    /**
     * Best-known time of a flight (estimate if present), in Malé time.
     */
    public static function timeOf(array $f): CarbonImmutable
    {
        return CarbonImmutable::parse($f['estimated_at'] ?? $f['scheduled_at'])->setTimezone(config('fis.timezone'));
    }
}
