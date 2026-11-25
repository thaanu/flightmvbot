<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Flight extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'immutable_datetime',
            'estimated_at' => 'immutable_datetime',
            'last_seen_at' => 'immutable_datetime',
            'via_routes' => 'array',
            'codeshares' => 'array',
            'raw' => 'array',
            'delay_minutes' => 'integer',
            'early_minutes' => 'integer',
        ];
    }

    /** Statuses after which a flight will not change any more. */
    public const FINAL_STATUSES = ['departed', 'landed', 'cancelled', 'diverted'];

    public function isFinal(): bool
    {
        return in_array($this->status, self::FINAL_STATUSES, true);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(FlightSubscription::class);
    }

    public function statusChanges(): HasMany
    {
        return $this->hasMany(FlightStatusChange::class);
    }
}
