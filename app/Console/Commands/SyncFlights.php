<?php

namespace App\Console\Commands;

use App\Services\Fis\FlightSyncService;
use Illuminate\Console\Command;
use Throwable;

class SyncFlights extends Command
{
    protected $signature = 'fis:sync';

    protected $description = 'Fetch flights from fis.com.mv into the local database and Redis snapshot';

    public function handle(FlightSyncService $sync): int
    {
        try {
            $run = $sync->sync();
        } catch (Throwable $e) {
            $this->error('Sync failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Synced %d flights (%d new, %d status/time changes). Source last updated %s.',
            $run->flights_count,
            $run->created_count,
            $run->changes_count,
            $run->source_last_updated ?? 'n/a',
        ));

        return self::SUCCESS;
    }
}
