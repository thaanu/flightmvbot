<?php

namespace Tests\Feature;

use App\Repositories\FlightRepository;
use App\Services\Query\FlightQuery;
use App\Services\Query\FlightQueryParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FlightQueryParserTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(['fis.com.mv/api/flights' => Http::response(json_decode(file_get_contents(base_path('tests/Fixtures/flights.json')), true))]);
        $this->travelTo(now()->setTimezone('Indian/Maldives')->setDate(2026, 11, 25)->setTime(4, 0));
        $this->artisan('fis:sync')->assertSuccessful();
    }

    private function parse(string $text): ?FlightQuery
    {
        return app(FlightQueryParser::class)->parse($text);
    }

    /**
     * @return list<string> flight numbers
     */
    private function answer(string $text): array
    {
        return $this->parse($text)->apply(app(FlightRepository::class)->all())->pluck('flight_number')->all();
    }

    public function test_next_flight_to_kuala_lumpur_lists_all_airlines_in_order(): void
    {
        $q = $this->parse('When is the next flight to Kuala Lumpur?');

        $this->assertSame('departure', $q->type);
        $this->assertSame(['KUL'], $q->placeCodes);
        $this->assertSame(['AK 075', 'MH 484', 'OD 294', 'MH 486'], $this->answer('When is the next flight to Kuala Lumpur?'));
    }

    #[DataProvider('placeVariants')]
    public function test_place_aliases_codes_and_typos(string $text, array $codes): void
    {
        $this->assertSame($codes, $this->parse($text)->placeCodes);
    }

    public static function placeVariants(): array
    {
        return [
            'alias' => ['next flight to KL', ['KUL']],
            'typo' => ['flights to kaula lumpur', ['KUL']],
            'code upper' => ['flights to DXB', ['DXB']],
            'code lower after preposition' => ['flights to dxb', ['DXB']],
            'first word' => ['flights from london', ['LHR']],
            'country' => ['can i fly to sri lanka', ['CMB']],
            'accent home + place' => ['departures from Malé to Dubai', ['DXB']],
        ];
    }

    public function test_direction_from_prepositions_and_keywords(): void
    {
        $this->assertSame('arrival', $this->parse('flights from Colombo')->type);
        $this->assertSame('departure', $this->parse('flights to Colombo')->type);
        $this->assertSame('arrival', $this->parse('what time does the flight from Colombo land')->type);
        $this->assertSame('departure', $this->parse('when does Emirates leave')->type);
        $this->assertNull($this->parse('Colombo flights')->type);
    }

    public function test_airline_full_name_is_not_read_as_place(): void
    {
        $q = $this->parse('Singapore Airlines flights');
        $this->assertSame(['singapore airlines'], $q->airlines);
        $this->assertSame([], $q->placeCodes);

        $q = $this->parse('next flight to singapore');
        $this->assertSame([], $q->airlines);
        $this->assertSame(['SIN'], $q->placeCodes);

        $q = $this->parse('Qatar flights to Doha');
        $this->assertSame(['qatar airways'], $q->airlines);
        $this->assertSame(['DOH'], $q->placeCodes);
    }

    public function test_time_windows(): void
    {
        $q = $this->parse('Emirates departures tonight');
        $this->assertSame(['EK 661', 'EK 653'], $this->answer('Emirates departures tonight'));
        $this->assertSame('tonight', $q->timeLabel);

        $q = $this->parse('flights to Gan after 5pm');
        $this->assertSame('17:00', $q->from->format('H:i'));

        $q = $this->parse('domestic departures in the next 2 hours');
        $this->assertSame('domestic', $q->category);
        $this->assertSame('06:00', $q->until->format('H:i'));

        $q = $this->parse('flights to DXB tomorrow');
        $this->assertSame('2026-11-26', $q->from->format('Y-m-d'));

        $q = $this->parse('arrivals around 14:00');
        $this->assertSame(['13:00', '15:00'], [$q->from->format('H:i'), $q->until->format('H:i')]);
    }

    public function test_status_filters_include_finished_flights(): void
    {
        $this->assertSame(['Q2 8198'], $this->answer('which flights have landed'));
        $this->assertSame('delayed', $this->parse('any delayed arrivals from dubai')->status);
    }

    public function test_intermediate_stops_match(): void
    {
        $this->assertContains('Q2 220', $this->answer('is there a flight to Ifuru'));
    }

    public function test_small_talk_is_not_a_question(): void
    {
        foreach (['hello there', 'thanks!', 'how are you', 'ok', 'can you help me'] as $text) {
            $this->assertNull($this->parse($text), $text);
        }
    }
}
