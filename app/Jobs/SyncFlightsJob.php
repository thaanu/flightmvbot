<?php

namespace App\Jobs;

use App\Services\Fis\FlightSyncService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Queue-able wrapper around the sync, for dispatching from code.
 * The scheduler runs `fis:sync` directly so no queue worker is required.
 */
class SyncFlightsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $uniqueFor = 120;

    public function handle(FlightSyncService $sync): void
    {
        $sync->sync();
    }
}
