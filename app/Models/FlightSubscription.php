<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Telegram chat that asked to be notified about a flight's changes.
 */
class FlightSubscription extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['chat_id' => 'integer'];
    }

    public function flight(): BelongsTo
    {
        return $this->belongsTo(Flight::class);
    }
}
