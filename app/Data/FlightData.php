<?php

namespace App\Data;

use Carbon\CarbonImmutable;

/**
 * A normalized flight record built from one row of the fis.com.mv feed.
 */
final readonly class FlightData
{
    /**
     * @param  list<string>  $viaRoutes
     * @param  list<string>  $codeshares
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public string $flightKey,
        public string $type,
        public string $flightNumber,
        public string $flightNumberCompact,
        public ?string $airline,
        public ?string $airlineCode,
        public ?string $origin,
        public ?string $originCode,
        public ?string $destination,
        public ?string $destinationCode,
        public CarbonImmutable $scheduledAt,
        public ?CarbonImmutable $estimatedAt,
        public ?string $terminal,
        public ?string $gate,
        public string $status,
        public ?string $category,
        public ?int $delayMinutes,
        public ?int $earlyMinutes,
        public array $viaRoutes,
        public array $codeshares,
        public array $raw,
    ) {}

    /**
     * Compact numbers this flight can be looked up by (own number + codeshares).
     *
     * @return list<string>
     */
    public function lookupNumbers(): array
    {
        $numbers = [$this->flightNumberCompact];

        foreach ($this->codeshares as $codeshare) {
            $numbers[] = self::compact($codeshare);
        }

        return array_values(array_unique(array_filter($numbers)));
    }

    public static function compact(string $flightNumber): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $flightNumber));
    }

    /**
     * Column values for the `flights` table.
     *
     * @return array<string, mixed>
     */
    public function toDatabase(): array
    {
        return [
            'flight_key' => $this->flightKey,
            'type' => $this->type,
            'flight_number' => $this->flightNumber,
            'flight_number_compact' => $this->flightNumberCompact,
            'airline' => $this->airline,
            'airline_code' => $this->airlineCode,
            'origin' => $this->origin,
            'origin_code' => $this->originCode,
            'destination' => $this->destination,
            'destination_code' => $this->destinationCode,
            'scheduled_at' => $this->scheduledAt->format('Y-m-d H:i:s'),
            'estimated_at' => $this->estimatedAt?->format('Y-m-d H:i:s'),
            'terminal' => $this->terminal,
            'gate' => $this->gate,
            'status' => $this->status,
            'category' => $this->category,
            'delay_minutes' => $this->delayMinutes,
            'early_minutes' => $this->earlyMinutes,
            'via_routes' => $this->viaRoutes,
            'codeshares' => $this->codeshares,
            'raw' => $this->raw,
        ];
    }

    /**
     * Plain array stored in the Redis snapshot.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'flight_key' => $this->flightKey,
            'type' => $this->type,
            'flight_number' => $this->flightNumber,
            'flight_number_compact' => $this->flightNumberCompact,
            'airline' => $this->airline,
            'airline_code' => $this->airlineCode,
            'origin' => $this->origin,
            'origin_code' => $this->originCode,
            'destination' => $this->destination,
            'destination_code' => $this->destinationCode,
            'scheduled_at' => $this->scheduledAt->toIso8601String(),
            'estimated_at' => $this->estimatedAt?->toIso8601String(),
            'terminal' => $this->terminal,
            'gate' => $this->gate,
            'status' => $this->status,
            'category' => $this->category,
            'delay_minutes' => $this->delayMinutes,
            'early_minutes' => $this->earlyMinutes,
            'via_routes' => $this->viaRoutes,
            'codeshares' => $this->codeshares,
        ];
    }
}
