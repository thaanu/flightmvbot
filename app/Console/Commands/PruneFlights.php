<?php

namespace App\Console\Commands;

use App\Models\Flight;
use App\Models\SyncRun;
use Illuminate\Console\Command;

class PruneFlights extends Command
{
    protected $signature = 'fis:prune {--days= : Keep this many days (default from config)}';

    protected $description = 'Delete old flights, their status changes, and old sync runs';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?? config('fis.prune_after_days'));
        $cutoff = now()->subDays($days);

        // Status changes are removed by the cascading foreign key.
        $flights = Flight::where('scheduled_at', '<', $cutoff)->delete();
        $runs = SyncRun::where('started_at', '<', $cutoff)->delete();

        $this->info("Pruned {$flights} flights and {$runs} sync runs older than {$days} days.");

        return self::SUCCESS;
    }
}
