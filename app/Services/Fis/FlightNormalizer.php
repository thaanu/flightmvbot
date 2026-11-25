<?php

namespace App\Services\Fis;

use App\Data\FlightData;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Converts raw fis.com.mv rows into FlightData objects.
 */
class FlightNormalizer
{
    /**
     * Normalize the whole feed. Malformed rows are skipped (and logged) and
     * duplicate natural keys keep the last occurrence.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, FlightData> keyed by flight_key
     */
    public function normalizeMany(array $rows): array
    {
        $flights = [];

        foreach ($rows as $row) {
            try {
                $flight = $this->normalize($row);
            } catch (Throwable $e) {
                Log::warning('Skipping malformed FIS flight row', ['row' => $row, 'error' => $e->getMessage()]);

                continue;
            }

            if ($flight !== null) {
                $flights[$flight->flightKey] = $flight;
            }
        }

        return $flights;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public function normalize(array $row): ?FlightData
    {
        $type = $row['type'] ?? null;
        $number = trim((string) ($row['flightNumber'] ?? ''));
        $date = $row['date'] ?? null;
        $scheduled = $row['scheduledTime'] ?? null;

        if (! in_array($type, ['arrival', 'departure'], true) || $number === '' || ! $date || ! $this->isTime($scheduled)) {
            return null;
        }

        $tz = config('fis.timezone');
        $scheduledAt = CarbonImmutable::createFromFormat('Y-m-d H:i', "{$date} {$scheduled}", $tz)->startOfMinute();
        $estimatedAt = $this->estimatedAt($scheduledAt, $row['estimatedTime'] ?? null);
        $compact = FlightData::compact($number);

        return new FlightData(
            flightKey: implode('|', [$type, $compact, $scheduledAt->format('Y-m-d'), $scheduledAt->format('H:i')]),
            type: $type,
            flightNumber: preg_replace('/\s+/', ' ', $number),
            flightNumberCompact: $compact,
            airline: $this->str($row['airline'] ?? null),
            airlineCode: $this->str($row['airlineCode'] ?? null),
            origin: $this->str($row['origin'] ?? null),
            originCode: $this->str($row['originCode'] ?? null),
            destination: $this->str($row['destination'] ?? null),
            destinationCode: $this->str($row['destinationCode'] ?? null),
            scheduledAt: $scheduledAt,
            estimatedAt: $estimatedAt,
            terminal: $this->str($row['terminal'] ?? null),
            gate: $this->str($row['gate'] ?? null),
            status: $this->str($row['status'] ?? null) ?? 'scheduled',
            category: $this->str($row['category'] ?? null),
            delayMinutes: isset($row['delayMinutes']) ? (int) $row['delayMinutes'] : null,
            earlyMinutes: isset($row['earlyMinutes']) ? (int) $row['earlyMinutes'] : null,
            viaRoutes: array_values(array_map('strval', (array) ($row['viaRoutes'] ?? []))),
            codeshares: array_values(array_map('strval', (array) ($row['codeshares'] ?? []))),
            // The source id embeds a list position and changes between polls; drop it.
            raw: Arr::except($row, ['id']),
        );
    }

    /**
     * The feed only gives HH:MM for the estimate, so it may belong to the
     * previous/next day (e.g. scheduled 23:30, estimated 00:15).
     */
    protected function estimatedAt(CarbonImmutable $scheduledAt, mixed $estimated): ?CarbonImmutable
    {
        if (! $this->isTime($estimated)) {
            return null;
        }

        [$h, $m] = array_map('intval', explode(':', $estimated));
        $estimatedAt = $scheduledAt->setTime($h, $m);

        $diffMinutes = $scheduledAt->diffInMinutes($estimatedAt, false);

        if ($diffMinutes < -12 * 60) {
            return $estimatedAt->addDay();
        }

        if ($diffMinutes > 12 * 60) {
            return $estimatedAt->subDay();
        }

        return $estimatedAt;
    }

    protected function isTime(mixed $value): bool
    {
        return is_string($value) && preg_match('/^([01]?\d|2[0-3]):[0-5]\d$/', $value) === 1;
    }

    protected function str(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
