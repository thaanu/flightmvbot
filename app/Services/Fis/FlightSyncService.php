<?php

namespace App\Services\Fis;

use App\Data\FlightData;
use App\Events\FlightStatusChanged;
use App\Models\Flight;
use App\Models\FlightStatusChange;
use App\Models\SyncRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Pulls the fis.com.mv feed into MySQL (history) and Redis (live snapshot).
 */
class FlightSyncService
{
    public function __construct(
        protected FisClient $client,
        protected FlightNormalizer $normalizer,
        protected FlightSnapshot $snapshot,
    ) {}

    /**
     * @throws Throwable re-thrown after being recorded on the sync run.
     */
    public function sync(): SyncRun
    {
        $run = SyncRun::create(['started_at' => now()]);

        try {
            $feed = $this->client->flights();
            $flights = $this->normalizer->normalizeMany($feed['flights']);

            if ($flights === []) {
                throw new RuntimeException('FIS feed contained no valid flights.');
            }

            [$created, $changes] = $this->persist($flights);

            // Only refresh the snapshot once the DB write succeeded.
            $this->snapshot->put($flights, $feed['lastUpdated']);

            foreach ($changes as [$flight, $change]) {
                FlightStatusChanged::dispatch($flight, $change);
            }

            $run->update([
                'finished_at' => now(),
                'flights_count' => count($flights),
                'created_count' => $created,
                'changes_count' => count($changes),
                'source_last_updated' => $feed['lastUpdated'],
            ]);

            return $run;
        } catch (Throwable $e) {
            // The previous snapshot stays in Redis until its TTL expires.
            $run->update(['finished_at' => now(), 'error' => mb_substr($e->getMessage(), 0, 2000)]);
            Log::error('FIS flight sync failed', ['error' => $e->getMessage()]);

            throw $e;
        }
    }

    /**
     * @param  array<string, FlightData>  $flights
     * @return array{0: int, 1: list<array{0: Flight, 1: FlightStatusChange}>}
     */
    protected function persist(array $flights): array
    {
        return DB::transaction(function () use ($flights) {
            $now = now();
            $existing = Flight::whereIn('flight_key', array_keys($flights))->get()->keyBy('flight_key');
            $created = 0;
            $changes = [];
            $unchangedIds = [];

            foreach ($flights as $key => $data) {
                /** @var Flight|null $flight */
                $flight = $existing->get($key);

                if ($flight === null) {
                    Flight::create($data->toDatabase() + ['last_seen_at' => $now]);
                    $created++;

                    continue;
                }

                $oldStatus = $flight->status;
                $oldEstimated = $flight->estimated_at;

                $flight->fill($data->toDatabase());

                if (! $flight->isDirty()) {
                    $unchangedIds[] = $flight->id;

                    continue;
                }

                $statusChanged = $flight->isDirty('status');
                $estimateChanged = $flight->isDirty('estimated_at');

                $flight->last_seen_at = $now;
                $flight->save();

                if ($statusChanged || $estimateChanged) {
                    $change = $flight->statusChanges()->create([
                        'old_status' => $oldStatus,
                        'new_status' => $flight->status,
                        'old_estimated_at' => $oldEstimated,
                        'new_estimated_at' => $flight->estimated_at,
                        'changed_at' => $now,
                    ]);

                    $changes[] = [$flight, $change];
                }
            }

            foreach (array_chunk($unchangedIds, 500) as $ids) {
                Flight::whereIn('id', $ids)->update(['last_seen_at' => $now]);
            }

            return [$created, $changes];
        });
    }
}
