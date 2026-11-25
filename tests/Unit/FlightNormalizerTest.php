<?php

namespace Tests\Unit;

use App\Services\Fis\FlightNormalizer;
use Tests\TestCase;

class FlightNormalizerTest extends TestCase
{
    private function row(array $overrides = []): array
    {
        return array_merge([
            'id' => 'departure-SQ437-2026-11-24-1',
            'flightNumber' => 'SQ 437',
            'airline' => 'Singapore Airlines',
            'airlineCode' => 'SQ',
            'origin' => 'Male',
            'originCode' => 'MLE',
            'destination' => 'Singapore',
            'destinationCode' => 'SIN',
            'scheduledTime' => '23:30',
            'estimatedTime' => '23:36',
            'terminal' => 'T1',
            'gate' => '',
            'status' => 'departed',
            'type' => 'departure',
            'category' => 'international',
            'delayMinutes' => 6,
            'date' => '2026-11-24',
        ], $overrides);
    }

    public function test_it_builds_a_stable_natural_key_and_fields(): void
    {
        $flight = (new FlightNormalizer)->normalize($this->row());

        $this->assertSame('departure|SQ437|2026-11-24|23:30', $flight->flightKey);
        $this->assertSame('SQ437', $flight->flightNumberCompact);
        $this->assertSame('2026-11-24 23:30', $flight->scheduledAt->format('Y-m-d H:i'));
        $this->assertSame('Indian/Maldives', $flight->scheduledAt->tzName);
        $this->assertSame('2026-11-24 23:36', $flight->estimatedAt->format('Y-m-d H:i'));
        $this->assertNull($flight->gate);
        $this->assertSame(6, $flight->delayMinutes);
        $this->assertArrayNotHasKey('id', $flight->raw);
    }

    public function test_key_does_not_depend_on_the_source_id(): void
    {
        $n = new FlightNormalizer;

        $this->assertSame(
            $n->normalize($this->row(['id' => 'departure-SQ437-2026-11-24-1']))->flightKey,
            $n->normalize($this->row(['id' => 'departure-SQ437-2026-11-24-9']))->flightKey,
        );
    }

    public function test_estimated_time_rolls_over_midnight_forward(): void
    {
        $flight = (new FlightNormalizer)->normalize($this->row(['estimatedTime' => '00:15']));

        $this->assertSame('2026-11-25 00:15', $flight->estimatedAt->format('Y-m-d H:i'));
    }

    public function test_estimated_time_rolls_over_midnight_backward(): void
    {
        $flight = (new FlightNormalizer)->normalize($this->row(['scheduledTime' => '00:10', 'estimatedTime' => '23:50', 'date' => '2026-11-25']));

        $this->assertSame('2026-11-24 23:50', $flight->estimatedAt->format('Y-m-d H:i'));
    }

    public function test_invalid_rows_are_skipped(): void
    {
        $n = new FlightNormalizer;

        $this->assertNull($n->normalize($this->row(['type' => 'cargo'])));
        $this->assertNull($n->normalize($this->row(['scheduledTime' => ''])));
        $this->assertNull($n->normalize($this->row(['flightNumber' => ' '])));
        $this->assertNull($n->normalize($this->row(['estimatedTime' => '--']))->estimatedAt);
    }

    public function test_it_normalizes_the_real_fixture(): void
    {
        $rows = json_decode(file_get_contents(base_path('tests/Fixtures/flights.json')), true)['flights'];

        $flights = (new FlightNormalizer)->normalizeMany($rows);

        $this->assertCount(count($rows), $flights);

        $vs = collect($flights)->firstWhere('flightNumberCompact', 'VS384');
        $this->assertContains('DL5914', $vs->lookupNumbers());
    }
}
