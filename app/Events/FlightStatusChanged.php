<?php

namespace App\Events;

use App\Models\Flight;
use App\Models\FlightStatusChange;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired after a sync detects a status or estimated-time change.
 * Phase 2 hooks Telegram "track my flight" notifications onto this.
 */
class FlightStatusChanged
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Flight $flight,
        public FlightStatusChange $change,
    ) {}
}
