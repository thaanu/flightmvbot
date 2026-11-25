<?php

namespace App\Telegram\Handlers;

use App\Data\FlightData;
use App\Models\Flight;
use App\Models\FlightSubscription;
use App\Repositories\FlightRepository;
use App\Telegram\FlightFormatter;
use App\Telegram\Keyboards;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

/**
 * "Track my flight" subscriptions. Notifications are sent by
 * App\Listeners\SendFlightStatusNotifications.
 */
class TrackingHandler
{
    public const MAX_PER_CHAT = 10;

    public function __construct(
        protected FlightRepository $flights,
        protected FlightFormatter $format,
    ) {}

    public function command(Nutgram $bot, ?string $number = null): void
    {
        if ($number === null || trim($number) === '') {
            $bot->sendMessage(text: 'Which flight? e.g. <code>/track EK652</code>', parse_mode: ParseMode::HTML);

            return;
        }

        $candidates = $this->flights->findByNumber($number)
            ->reject(fn ($f) => in_array($f['status'], Flight::FINAL_STATUSES, true))
            ->values();

        if ($candidates->isEmpty()) {
            $bot->sendMessage(
                text: 'There is no upcoming flight <b>'.$this->format->e(strtoupper(trim($number))).'</b> on the MLE board to track.',
                parse_mode: ParseMode::HTML,
            );

            return;
        }

        if ($candidates->count() > 1) {
            // Let the user pick (e.g. a codeshare matching several flights).
            foreach ($candidates as $flight) {
                $bot->sendMessage(text: $this->format->card($flight), parse_mode: ParseMode::HTML, reply_markup: Keyboards::flight($flight));
            }

            return;
        }

        $bot->sendMessage(text: $this->subscribe($bot->chatId(), $candidates->first()['flight_key']), parse_mode: ParseMode::HTML);
    }

    public function untrackCommand(Nutgram $bot, ?string $number = null): void
    {
        if ($number === null || trim($number) === '') {
            $this->list($bot);

            return;
        }

        $compact = FlightData::compact($number);

        $deleted = FlightSubscription::where('chat_id', $bot->chatId())
            ->whereHas('flight', fn ($q) => $q->where('flight_number_compact', $compact))
            ->delete();

        $bot->sendMessage(
            text: $deleted
                ? '🔕 Stopped tracking <b>'.$this->format->e($compact).'</b>.'
                : 'You are not tracking <b>'.$this->format->e($compact).'</b>. See /mytracks.',
            parse_mode: ParseMode::HTML,
        );
    }

    public function list(Nutgram $bot): void
    {
        $subscriptions = FlightSubscription::with('flight')
            ->where('chat_id', $bot->chatId())
            ->get()
            ->sortBy('flight.scheduled_at');

        if ($subscriptions->isEmpty()) {
            $bot->sendMessage(text: "You are not tracking any flights.\n\nSend <code>/track EK652</code> to start.", parse_mode: ParseMode::HTML);

            return;
        }

        $lines = ['🔔 <b>Flights you are tracking</b>', ''];
        $keyboard = InlineKeyboardMarkup::make();

        foreach ($subscriptions as $sub) {
            $f = $sub->flight;
            $lines[] = sprintf(
                '%s <b>%s</b> %s %s · %s',
                $f->type === 'arrival' ? '🛬' : '🛫',
                $this->format->e($f->flight_number),
                $f->type === 'arrival' ? 'from' : 'to',
                $this->format->e($f->type === 'arrival' ? $f->origin : $f->destination),
                $f->scheduled_at->format('D H:i'),
            );
            $keyboard->addRow(InlineKeyboardButton::make('🔕 Stop '.$f->flight_number, callback_data: 'untrack:'.$f->flight_key));
        }

        $bot->sendMessage(text: implode("\n", $lines), parse_mode: ParseMode::HTML, reply_markup: $keyboard);
    }

    /**
     * Callback: track:{flight_key}
     */
    public function trackCallback(Nutgram $bot, string $flightKey): void
    {
        $message = $this->subscribe($bot->chatId(), $flightKey);
        $bot->answerCallbackQuery(text: strip_tags($message), show_alert: false);

        $this->refreshButtons($bot, $flightKey);
    }

    /**
     * Callback: untrack:{flight_key}
     */
    public function untrackCallback(Nutgram $bot, string $flightKey): void
    {
        FlightSubscription::where('chat_id', $bot->chatId())
            ->whereHas('flight', fn ($q) => $q->where('flight_key', $flightKey))
            ->delete();

        $bot->answerCallbackQuery(text: 'Stopped tracking');

        $this->refreshButtons($bot, $flightKey);
    }

    protected function subscribe(?int $chatId, string $flightKey): string
    {
        $flight = Flight::where('flight_key', $flightKey)->first();

        if ($chatId === null || $flight === null) {
            return 'That flight is no longer on the board.';
        }

        if ($flight->isFinal()) {
            return 'Flight '.$this->format->e($flight->flight_number).' is already '.$this->format->status($flight->status).'.';
        }

        $existing = FlightSubscription::where('chat_id', $chatId)->count();

        if ($existing >= self::MAX_PER_CHAT && ! $flight->subscriptions()->where('chat_id', $chatId)->exists()) {
            return 'You can track up to '.self::MAX_PER_CHAT.' flights. Remove one with /mytracks first.';
        }

        $flight->subscriptions()->firstOrCreate(['chat_id' => $chatId]);

        return '🔔 Tracking <b>'.$this->format->e($flight->flight_number).'</b>. I will message you when its status or time changes.';
    }

    /**
     * Swap Track/Stop on the message the button was pressed on.
     */
    protected function refreshButtons(Nutgram $bot, string $flightKey): void
    {
        $flight = $this->flights->findByKey($flightKey);

        if ($flight === null) {
            return;
        }

        $tracked = FlightSubscription::where('chat_id', $bot->chatId())
            ->whereHas('flight', fn ($q) => $q->where('flight_key', $flightKey))
            ->exists();

        rescue(fn () => $bot->editMessageReplyMarkup(reply_markup: Keyboards::flight($flight, $tracked)), report: false);
    }
}
