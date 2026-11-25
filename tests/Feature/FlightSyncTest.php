<?php

namespace Tests\Feature;

use App\Events\FlightStatusChanged;
use App\Models\Flight;
use App\Models\FlightStatusChange;
use App\Models\SyncRun;
use App\Repositories\FlightRepository;
use App\Services\Fis\FlightSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FlightSyncTest extends TestCase
{
    use RefreshDatabase;

    private array $feed;

    /** Response the fake HTTP client currently returns. */
    private mixed $response;

    protected function setUp(): void
    {
        parent::setUp();

        $this->feed = json_decode(file_get_contents(base_path('tests/Fixtures/flights.json')), true);
        Http::fake(['fis.com.mv/api/flights' => fn () => Http::response(...$this->response)]);
        $this->travelTo(now()->setTimezone('Indian/Maldives')->setDate(2026, 11, 25)->setTime(9, 0));
    }

    private function fakeFeed(mixed $body, int $status = 200): void
    {
        $this->response = [$body, $status];
    }

    public function test_sync_stores_flights_and_snapshot(): void
    {
        $this->fakeFeed($this->feed);

        $this->artisan('fis:sync')->assertSuccessful();

        $count = count($this->feed['flights']);
        $this->assertSame($count, Flight::count());
        $this->assertSame($count, app(FlightSnapshot::class)->meta()['count']);
        $this->assertSame('01:17', SyncRun::latest('id')->first()->source_last_updated);
        $this->assertCount(1, app(FlightSnapshot::class)->byNumber('ey 378'));
    }

    public function test_resync_does_not_duplicate_even_when_source_ids_shift(): void
    {
        $this->fakeFeed($this->feed);
        $this->artisan('fis:sync')->assertSuccessful();

        // Same flights, reversed order with re-numbered ids (as the source does).
        $shifted = $this->feed;
        $shifted['flights'] = array_reverse($shifted['flights']);
        foreach ($shifted['flights'] as $i => &$row) {
            $row['id'] = preg_replace('/-\d+$/', "-{$i}", $row['id']);
        }
        unset($row);

        $this->fakeFeed($shifted);
        $this->artisan('fis:sync')->assertSuccessful();

        $this->assertSame(count($this->feed['flights']), Flight::count());
        $this->assertSame(0, FlightStatusChange::count());
    }

    public function test_status_change_is_recorded_and_event_fired(): void
    {
        Event::fake([FlightStatusChanged::class]);

        $this->fakeFeed($this->feed);
        $this->artisan('fis:sync')->assertSuccessful();

        $changed = $this->feed;
        foreach ($changed['flights'] as &$row) {
            if ($row['flightNumber'] === 'EY 378') {
                $row['status'] = 'delayed';
                $row['estimatedTime'] = '05:45';
            }
        }
        unset($row);

        $this->fakeFeed($changed);
        $this->artisan('fis:sync')->assertSuccessful();

        $change = FlightStatusChange::sole();
        $this->assertSame('on-time', $change->old_status);
        $this->assertSame('delayed', $change->new_status);
        $this->assertSame('05:45', $change->new_estimated_at->format('H:i'));
        Event::assertDispatchedTimes(FlightStatusChanged::class, 1);

        $this->assertSame('delayed', app(FlightRepository::class)->findByNumber('EY378')->first()['status']);
    }

    public function test_empty_or_broken_response_keeps_existing_data(): void
    {
        $this->fakeFeed($this->feed);
        $this->artisan('fis:sync')->assertSuccessful();

        $this->fakeFeed(['flights' => [], 'lastUpdated' => '02:00']);
        $this->artisan('fis:sync')->assertFailed();

        $this->fakeFeed('<html>oops</html>', 500);
        $this->artisan('fis:sync')->assertFailed();

        $this->assertSame(count($this->feed['flights']), Flight::count());
        $this->assertSame('01:17', app(FlightSnapshot::class)->meta()['source_last_updated']);
        $this->assertSame(2, SyncRun::whereNotNull('error')->count());
    }

    public function test_repository_reads_and_falls_back_to_database(): void
    {
        $this->fakeFeed($this->feed);
        $this->artisan('fis:sync')->assertSuccessful();

        $repo = app(FlightRepository::class);

        $this->assertSame('VS 384', $repo->findByNumber('DL 5914')->first()['flight_number']);
        $this->assertNotEmpty($repo->search('Dubai', 'arrival'));
        $this->assertTrue($repo->arrivals()->every(fn ($f) => $f['type'] === 'arrival'));
        $this->assertNotEmpty($repo->departures());

        // Simulate Redis losing the snapshot.
        cache()->store(config('fis.snapshot_store'))->flush();

        $this->assertSame('VS 384', $repo->findByNumber('DL5914')->first()['flight_number']);
        $this->assertSame('EY 378', $repo->findByNumber('EY378')->first()['flight_number']);
        $this->assertNotEmpty($repo->search('DXB'));
    }
}
