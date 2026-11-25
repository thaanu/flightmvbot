<?php

namespace App\Telegram\Handlers;

use App\Models\Flight;
use App\Models\FlightSubscription;
use App\Repositories\FlightRepository;
use App\Telegram\FlightFormatter;
use App\Telegram\Keyboards;
use Illuminate\Support\Collection;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;

/**
 * Flight lookups by number, plus free-text search by city / code / airline.
 */
class FlightHandler
{
    /** "EK652", "ek 652", "Q2 8198", "6E1131" */
    public const FLIGHT_NUMBER_REGEX = '/^\s*([A-Z][A-Z0-9]|[0-9][A-Z])\s?\d{1,4}[A-Z]?\s*$/i';

    public const MAX_CARDS = 3;

    public const MAX_SEARCH_RESULTS = 15;

    public function __construct(
        protected FlightRepository $flights,
        protected FlightFormatter $format,
    ) {}

    public function command(Nutgram $bot, ?string $number = null): void
    {
        if ($number === null || trim($number) === '') {
            $bot->sendMessage(text: 'Send me a flight number, e.g. <code>/flight EK652</code>', parse_mode: ParseMode::HTML);

            return;
        }

        $this->lookup($bot, $number);
    }

    /**
     * Any plain text that is not a command: flight number or search term.
     */
    public function text(Nutgram $bot, ?string $text = null): void
    {
        $text = trim((string) ($text ?? $bot->message()?->text));

        if ($text === '') {
            return;
        }

        if (preg_match(self::FLIGHT_NUMBER_REGEX, $text)) {
            $this->lookup($bot, $text);

            return;
        }

        $this->search($bot, $text);
    }

    public function refresh(Nutgram $bot, string $compact): void
    {
        $matches = $this->flights->findByNumber($compact);
        $bot->answerCallbackQuery(text: $matches->isEmpty() ? 'Flight no longer on the board' : 'Updated');

        if ($matches->isEmpty()) {
            return;
        }

        $flight = $matches->first();

        rescue(fn () => $bot->editMessageText(
            text: $this->cardText($flight),
            parse_mode: ParseMode::HTML,
            reply_markup: Keyboards::flight($flight, $this->isTracked($bot, $flight)),
        ), report: false); // "message is not modified" when nothing changed
    }

    protected function lookup(Nutgram $bot, string $number): void
    {
        $matches = $this->flights->findByNumber($number);

        if ($matches->isEmpty()) {
            $bot->sendMessage(
                text: sprintf(
                    "I couldn't find flight <b>%s</b> on today's MLE board.\n\nThe board covers roughly today ± a few hours. Check the number, or try /arrivals or /departures.",
                    $this->format->e(strtoupper(trim($number))),
                ),
                parse_mode: ParseMode::HTML,
            );

            return;
        }

        foreach ($this->relevant($matches) as $flight) {
            $bot->sendMessage(
                text: $this->cardText($flight),
                parse_mode: ParseMode::HTML,
                reply_markup: Keyboards::flight($flight, $this->isTracked($bot, $flight)),
            );
        }
    }

    protected function search(Nutgram $bot, string $term): void
    {
        if (mb_strlen($term) < 3) {
            $bot->sendMessage(text: 'Send a flight number (e.g. <code>EK652</code>) or at least 3 letters of a city or airline.', parse_mode: ParseMode::HTML);

            return;
        }

        $results = $this->flights->search($term)
            ->filter(fn ($f) => $f['estimated_at'] === null || $f['estimated_at'] >= now()->subHours(2)->toIso8601String())
            ->sortBy('scheduled_at');

        if ($results->isEmpty()) {
            $bot->sendMessage(
                text: 'No flights found for <b>'.$this->format->e($term)."</b>.\n\nTry a flight number, city, airport code or airline name, or /help.",
                parse_mode: ParseMode::HTML,
            );

            return;
        }

        $lines = ['🔎 Flights matching <b>'.$this->format->e($term).'</b>'];

        foreach (['arrival' => '🛬 <b>Arrivals</b>', 'departure' => '🛫 <b>Departures</b>'] as $type => $heading) {
            $group = $results->where('type', $type)->take(self::MAX_SEARCH_RESULTS);

            if ($group->isNotEmpty()) {
                $lines[] = '';
                $lines[] = $heading;
                $group->each(function ($f) use (&$lines) {
                    $lines[] = $this->format->line($f);
                });
            }
        }

        $lines[] = '';
        $lines[] = 'Send a flight number for details.';
        $lines[] = $this->format->freshness($this->flights->lastSyncedAt(), $this->flights->isStale());

        $bot->sendMessage(text: implode("\n", $lines), parse_mode: ParseMode::HTML);
    }

    /**
     * When a number matches several flights (e.g. yesterday's and today's),
     * prefer ones that are not finished yet, nearest first.
     */
    protected function relevant(Collection $matches): Collection
    {
        $active = $matches->reject(fn ($f) => in_array($f['status'], Flight::FINAL_STATUSES, true));

        return ($active->isNotEmpty() ? $active : $matches->reverse())
            ->take(self::MAX_CARDS)
            ->values();
    }

    protected function cardText(array $flight): string
    {
        return $this->format->card($flight)."\n\n".$this->format->freshness($this->flights->lastSyncedAt(), $this->flights->isStale());
    }

    protected function isTracked(Nutgram $bot, array $flight): bool
    {
        $chatId = $bot->chatId();

        return $chatId !== null && FlightSubscription::query()
            ->where('chat_id', $chatId)
            ->whereHas('flight', fn ($q) => $q->where('flight_key', $flight['flight_key']))
            ->exists();
    }
}
