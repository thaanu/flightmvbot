<?php

namespace Tests\Feature;

use App\Models\Flight;
use App\Models\FlightSubscription;
use App\Services\Fis\WeatherSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Chat\Chat;
use SergiX44\Nutgram\Telegram\Types\User\User;
use SergiX44\Nutgram\Testing\FakeNutgram;
use Tests\TestCase;

class TelegramBotTest extends TestCase
{
    use RefreshDatabase;

    private const CHAT_ID = 4242;

    private array $feed;

    private mixed $response;

    protected function setUp(): void
    {
        parent::setUp();

        $this->feed = json_decode(file_get_contents(base_path('tests/Fixtures/flights.json')), true);
        $this->response = $this->feed;
        Http::fake(['fis.com.mv/api/flights' => fn () => Http::response($this->response)]);
        $this->travelTo(now()->setTimezone('Indian/Maldives')->setDate(2026, 11, 25)->setTime(4, 0));

        $this->artisan('fis:sync')->assertSuccessful();
    }

    private function bot(): FakeNutgram
    {
        /** @var FakeNutgram $bot */
        $bot = app(Nutgram::class);

        return $bot
            ->setCommonUser(User::make(id: self::CHAT_ID, is_bot: false, first_name: 'Test'))
            ->setCommonChat(Chat::make(id: self::CHAT_ID, type: 'private'));
    }

    /**
     * Every outgoing request as [method, params].
     *
     * @return list<array{0: string, 1: array}>
     */
    private function sent(FakeNutgram $bot): array
    {
        return array_map(function ($reqRes) {
            [$request] = array_values($reqRes);

            return [ltrim($request->getUri()->getPath(), '/'), FakeNutgram::getActualData($request)];
        }, $bot->getRequestHistory());
    }

    private function sentTexts(FakeNutgram $bot): string
    {
        return collect($this->sent($bot))->map(fn ($r) => $r[1]['text'] ?? '')->implode("\n---\n");
    }

    public function test_start_shows_help_and_menu(): void
    {
        $bot = $this->bot()->hearText('/start')->reply();

        $this->assertStringContainsString('Velana International Airport', $this->sentTexts($bot));
        $this->assertStringContainsString('board:arrival:all:0', json_encode($this->sent($bot)));
    }

    public function test_flight_number_text_returns_card(): void
    {
        $bot = $this->bot()->hearText('ey 378')->reply();
        $text = $this->sentTexts($bot);

        $this->assertStringContainsString('EY 378', $text);
        $this->assertStringContainsString('Abu Dhabi', $text);
        $this->assertStringContainsString('track:arrival|EY378|2026-11-25|05:05', json_encode($this->sent($bot)));
    }

    public function test_flight_command_and_codeshare_lookup(): void
    {
        $bot = $this->bot()->hearText('/flight DL5914')->reply();

        $this->assertStringContainsString('VS 384', $this->sentTexts($bot));
    }

    public function test_unknown_flight(): void
    {
        $bot = $this->bot()->hearText('/flight ZZ999')->reply();

        $this->assertStringContainsString("couldn't find flight <b>ZZ999</b>", $this->sentTexts($bot));
    }

    public function test_city_name_alone_lists_flights_both_ways(): void
    {
        $bot = $this->bot()->hearText('Dubai')->reply();
        $text = $this->sentTexts($bot);

        $this->assertStringContainsString('Dubai (DXB)', $text);
        $this->assertStringContainsString('Departures to Dubai', $text);
        $this->assertStringContainsString('Arrivals from Dubai', $text);
    }

    public function test_partial_word_falls_back_to_search(): void
    {
        $bot = $this->bot()->hearText('Emira')->reply();

        $this->assertStringContainsString('Flights matching <b>Emira</b>', $this->sentTexts($bot));
    }

    public function test_natural_language_question_answers_with_buttons(): void
    {
        $bot = $this->bot()->hearText('When is the next flight to Kuala Lumpur?')->reply();
        $text = $this->sentTexts($bot);
        $markup = json_encode($this->sent($bot));

        $this->assertStringContainsString('The next departure to Kuala Lumpur (KUL) is <b>AK 075</b> (Air Asia) at <b>10:45</b>', $text);
        $this->assertStringContainsString('MH 484', $text);
        $this->assertStringContainsString('Malaysia Airlines', $text);
        $this->assertStringContainsString('show:departure|AK075|2026-11-25|10:45', $markup);
        $this->assertMatchesRegularExpression('/qt:\\w{8}:departure\|MH484\|/', $markup);
        $this->assertStringContainsString('ask:', $markup);
    }

    public function test_question_with_no_results_explains(): void
    {
        $bot = $this->bot()->hearText('flights to Gan after 5pm')->reply();

        $this->assertStringContainsString('No departures to Gan (GAN) after 17:00', $this->sentTexts($bot));
    }

    public function test_flight_number_inside_sentence(): void
    {
        $bot = $this->bot()->hearText('is EK 652 on time?')->reply();
        $text = $this->sentTexts($bot);

        $this->assertStringContainsString('EK 652', $text);
        $this->assertStringContainsString('Status:', $text);
    }

    public function test_track_toggle_and_details_from_question_results(): void
    {
        $bot = $this->bot()->hearText('next flight to KL')->reply();
        preg_match('/qt:(\w{8}):departure\|MH484/', json_encode($this->sent($bot)), $m);
        $id = $m[1];

        $bot = $this->bot()->hearCallbackQueryData("qt:{$id}:departure|MH484|2026-11-25|12:00")->reply();
        $this->assertSame(1, FlightSubscription::where('chat_id', self::CHAT_ID)->count());
        $this->assertStringContainsString('Untrack', json_encode($this->sent($bot)));

        $this->bot()->hearCallbackQueryData("qt:{$id}:departure|MH484|2026-11-25|12:00")->reply();
        $this->assertSame(0, FlightSubscription::count());

        $bot = $this->bot()->hearCallbackQueryData('show:departure|MH484|2026-11-25|12:00')->reply();
        $this->assertStringContainsString('Departure to <b>Kuala Lumpur</b>', $this->sentTexts($bot));

        $bot = $this->bot()->hearCallbackQueryData("ask:{$id}")->reply();
        $this->assertContains('editMessageText', array_column($this->sent($bot), 0));
    }

    public function test_arrivals_board_and_paging_callback(): void
    {
        $bot = $this->bot()->hearText('/arrivals')->reply();
        $text = $this->sentTexts($bot);

        $this->assertStringContainsString('Arrivals', $text);
        $this->assertStringContainsString('Page 1/', $text);

        $bot = $this->bot()->hearCallbackQueryData('board:departure:domestic:1')->reply();
        $methods = array_column($this->sent($bot), 0);

        $this->assertContains('answerCallbackQuery', $methods);
        $this->assertContains('editMessageText', $methods);
        $this->assertStringContainsString('Departures', $this->sentTexts($bot));
        $this->assertStringContainsString('Domestic', $this->sentTexts($bot));
    }

    public function test_track_list_and_untrack(): void
    {
        $this->bot()->hearText('/track EY378')->reply();
        $this->assertSame(1, FlightSubscription::where('chat_id', self::CHAT_ID)->count());

        $bot = $this->bot()->hearText('/mytracks')->reply();
        $this->assertStringContainsString('EY 378', $this->sentTexts($bot));

        $this->bot()->hearText('/untrack EY 378')->reply();
        $this->assertSame(0, FlightSubscription::count());
    }

    public function test_track_via_button(): void
    {
        $this->bot()->hearCallbackQueryData('track:arrival|EY378|2026-11-25|05:05')->reply();

        $this->assertSame(1, FlightSubscription::count());
    }

    public function test_status_change_notifies_trackers_and_final_status_unsubscribes(): void
    {
        $flight = Flight::where('flight_key', 'arrival|EY378|2026-11-25|05:05')->sole();
        $flight->subscriptions()->create(['chat_id' => self::CHAT_ID]);

        // Delay first.
        $this->setStatus('EY 378', 'delayed', '05:45');
        $bot = $this->runListenerFor($flight);
        $this->assertStringContainsString('Delayed', $this->sentTexts($bot));
        $this->assertSame(1, FlightSubscription::count());

        // Then it lands: notify and stop tracking.
        $this->setStatus('EY 378', 'landed', '05:40');
        $bot = $this->runListenerFor($flight);
        $this->assertStringContainsString('Landed', $this->sentTexts($bot));
        $this->assertStringContainsString('stopped tracking', $this->sentTexts($bot));
        $this->assertSame(0, FlightSubscription::count());
    }

    public function test_small_estimate_wobble_is_not_notified(): void
    {
        $flight = Flight::where('flight_key', 'arrival|EY378|2026-11-25|05:05')->sole();
        $flight->subscriptions()->create(['chat_id' => self::CHAT_ID]);

        $this->setStatus('EY 378', 'on-time', '05:07');
        $bot = $this->runListenerFor($flight);

        $this->assertSame([], $this->sent($bot));
    }

    public function test_weather(): void
    {
        app(WeatherSnapshot::class)->put(['temp' => 31, 'weatherCode' => 2, 'isDay' => 1, 'humidity' => 78, 'wind' => 12]);

        $bot = $this->bot()->hearText('/weather')->reply();

        $this->assertStringContainsString('31°C', $this->sentTexts($bot));
        $this->assertStringContainsString('Partly cloudy', $this->sentTexts($bot));
    }

    public function test_lists_spanning_days_get_day_headings_and_cancelled_is_spelled_out(): void
    {
        // The situation from production: 16:54, one KUL departure tonight, the rest tomorrow.
        $row = fn ($date, $time, $number, $airline, $status) => [
            'id' => "departure-x-{$number}", 'flightNumber' => $number, 'airline' => $airline, 'airlineCode' => substr($number, 0, 2),
            'origin' => 'Male', 'originCode' => 'MLE', 'destination' => 'Kuala Lumpur', 'destinationCode' => 'KUL',
            'scheduledTime' => $time, 'estimatedTime' => $time, 'terminal' => 'T1', 'gate' => '', 'status' => $status,
            'type' => 'departure', 'category' => 'international', 'date' => $date,
        ];

        $this->response = ['lastUpdated' => '16:52', 'flights' => [
            $row('2026-11-25', '23:40', 'MH 486', 'Malaysia Airlines', 'on-time'),
            $row('2026-11-26', '10:45', 'AK 075', 'Air Asia', 'on-time'),
            $row('2026-11-26', '12:00', 'MH 484', 'Malaysia Airlines', 'on-time'),
            $row('2026-11-26', '22:55', 'OD 296', 'Batik Air', 'cancelled'),
        ]];
        $this->travelTo(now()->setTime(16, 54));
        $this->artisan('fis:sync')->assertSuccessful();

        $bot = $this->bot()->hearText('When is the next flight to Kuala Lumpur?')->reply();
        $text = $this->sentTexts($bot);
        $markup = json_encode($this->sent($bot), JSON_UNESCAPED_UNICODE);

        $this->assertStringContainsString('is <b>MH 486</b> (Malaysia Airlines) at <b>23:40</b>', $text);
        $this->assertMatchesRegularExpression('/Today, Wed 25 Nov.*MH 486.*Tomorrow, Thu 26 Nov.*AK 075/s', $text);
        $this->assertStringContainsString('OD 296</b> · Batik Air · <b>Cancelled</b>', $text);
        $this->assertStringContainsString('AK 075 · Thu 10:45', $markup);
        $this->assertStringContainsString('MH 486 · 23:40', $markup);
        $raw = json_encode($this->sent($bot));
        $this->assertStringContainsString('show:departure|OD296|2026-11-26|22:55', $raw);
        $this->assertDoesNotMatchRegularExpression('/qt:\\w{8}:departure\|OD296/', $raw);
        $this->assertMatchesRegularExpression('/qt:\\w{8}:departure\|AK075/', $raw);
    }

    public function test_same_day_lists_have_no_day_headings(): void
    {
        $bot = $this->bot()->hearText('next flight to KL')->reply();

        $this->assertStringNotContainsString('📅', $this->sentTexts($bot));
    }

    private function setStatus(string $number, string $status, string $estimated): void
    {
        foreach ($this->response['flights'] as &$row) {
            if ($row['flightNumber'] === $number) {
                $row['status'] = $status;
                $row['estimatedTime'] = $estimated;
            }
        }
        unset($row);
    }

    /**
     * Run a sync with a fresh fake bot bound. The (queued) listener runs
     * synchronously in tests, so the fake captures what would be sent.
     */
    private function runListenerFor(Flight $flight): FakeNutgram
    {
        $bot = Nutgram::fake();
        $this->app->instance(Nutgram::class, $bot);

        $this->artisan('fis:sync')->assertSuccessful();

        return $bot;
    }
}
