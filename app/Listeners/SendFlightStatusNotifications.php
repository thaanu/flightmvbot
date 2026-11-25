<?php

namespace App\Listeners;

use App\Events\FlightStatusChanged;
use App\Models\FlightSubscription;
use App\Telegram\FlightFormatter;
use App\Telegram\Keyboards;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Exceptions\TelegramException;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;

/**
 * Pushes a Telegram message to every chat tracking a flight that changed.
 */
class SendFlightStatusNotifications implements ShouldQueue
{
    /** Ignore estimate wobbles smaller than this when the status is unchanged. */
    public const MIN_ESTIMATE_SHIFT_MINUTES = 5;

    public function __construct(
        protected Nutgram $bot,
        protected FlightFormatter $format,
    ) {}

    public function handle(FlightStatusChanged $event): void
    {
        $flight = $event->flight->fresh();
        $change = $event->change;

        if ($flight === null || ! $this->isWorthNotifying($change)) {
            return;
        }

        $subscriptions = $flight->subscriptions()->get();

        if ($subscriptions->isEmpty()) {
            return;
        }

        $headline = $change->old_status !== $change->new_status
            ? '🔔 <b>'.$this->format->e($flight->flight_number).'</b>: '
                .$this->format->status($change->old_status ?? 'scheduled').' → <b>'.$this->format->status($change->new_status).'</b>'
            : '🔔 <b>'.$this->format->e($flight->flight_number).'</b>: new estimated time <b>'
                .$change->new_estimated_at?->format('H:i').'</b> (was '.($change->old_estimated_at?->format('H:i') ?? '—').')';

        $card = $this->format->card($this->toArray($flight));
        $final = $flight->isFinal();
        $text = $headline."\n\n".$card.($final ? "\n\n<i>This flight is complete, so I've stopped tracking it.</i>" : '');

        foreach ($subscriptions as $subscription) {
            $this->send($subscription, $text, $final ? null : $this->toArray($flight));
        }

        if ($final) {
            $flight->subscriptions()->delete();
        }
    }

    protected function isWorthNotifying($change): bool
    {
        if ($change->old_status !== $change->new_status) {
            return true;
        }

        if ($change->old_estimated_at === null || $change->new_estimated_at === null) {
            return $change->old_estimated_at != $change->new_estimated_at;
        }

        return abs($change->old_estimated_at->diffInMinutes($change->new_estimated_at)) >= self::MIN_ESTIMATE_SHIFT_MINUTES;
    }

    protected function send(FlightSubscription $subscription, string $text, ?array $flight): void
    {
        try {
            $this->bot->sendMessage(
                text: $text,
                chat_id: $subscription->chat_id,
                parse_mode: ParseMode::HTML,
                reply_markup: $flight ? Keyboards::flight($flight, true) : null,
            );
        } catch (TelegramException $e) {
            // 403: user blocked the bot / left the chat; drop all their subscriptions.
            if ($e->getCode() === 403) {
                FlightSubscription::where('chat_id', $subscription->chat_id)->delete();

                return;
            }

            Log::warning('Failed to send flight notification', ['chat_id' => $subscription->chat_id, 'error' => $e->getMessage()]);
        }
    }

    protected function toArray($flight): array
    {
        return [
            'flight_key' => $flight->flight_key,
            'type' => $flight->type,
            'flight_number' => $flight->flight_number,
            'flight_number_compact' => $flight->flight_number_compact,
            'airline' => $flight->airline,
            'origin' => $flight->origin,
            'origin_code' => $flight->origin_code,
            'destination' => $flight->destination,
            'destination_code' => $flight->destination_code,
            'scheduled_at' => $flight->scheduled_at->toIso8601String(),
            'estimated_at' => $flight->estimated_at?->toIso8601String(),
            'terminal' => $flight->terminal,
            'gate' => $flight->gate,
            'status' => $flight->status,
            'category' => $flight->category,
            'delay_minutes' => $flight->delay_minutes,
            'early_minutes' => $flight->early_minutes,
            'via_routes' => $flight->via_routes ?? [],
            'codeshares' => $flight->codeshares ?? [],
        ];
    }
}
