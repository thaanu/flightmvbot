<?php

namespace App\Telegram\Handlers;

use App\Models\Flight;
use App\Models\FlightSubscription;
use App\Repositories\FlightRepository;
use App\Services\Query\FlightQuery;
use App\Services\Query\FlightQueryParser;
use App\Telegram\FlightFormatter;
use App\Telegram\Keyboards;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

/**
 * Answers natural-language questions ("next flight to Kuala Lumpur?").
 *
 * The question text is cached under a short id so the result message can be
 * refreshed and its Track buttons re-rendered. Callback data formats:
 *   ask:{id}               re-run the question and update the message
 *   show:{flight_key}      send the full flight card
 *   qt:{id}:{flight_key}   toggle tracking from a result list
 */
class AskHandler
{
    public const MAX_RESULTS = 8;

    public const QUESTION_TTL = 86400;

    public function __construct(
        protected FlightRepository $flights,
        protected FlightQueryParser $parser,
        protected FlightFormatter $format,
        protected TrackingHandler $tracking,
    ) {}

    /**
     * Try to answer $text. Returns false when it isn't a recognisable question.
     */
    public function answer(Nutgram $bot, string $text): bool
    {
        $query = $this->parser->parse($text);

        if ($query === null) {
            return false;
        }

        $id = Str::random(8);
        Cache::put("nlq:{$id}", $text, self::QUESTION_TTL);

        [$message, $keyboard] = $this->render($query, $id, $bot->chatId());

        $bot->sendMessage(text: $message, parse_mode: ParseMode::HTML, reply_markup: $keyboard);

        return true;
    }

    /**
     * Callback: ask:{id}
     */
    public function refresh(Nutgram $bot, string $id): void
    {
        $query = $this->restore($id);

        if ($query === null) {
            $bot->answerCallbackQuery(text: 'This search has expired, please ask again.');

            return;
        }

        $bot->answerCallbackQuery(text: 'Updated');
        [$message, $keyboard] = $this->render($query, $id, $bot->chatId());

        rescue(fn () => $bot->editMessageText(text: $message, parse_mode: ParseMode::HTML, reply_markup: $keyboard), report: false);
    }

    /**
     * Callback: show:{flight_key}
     */
    public function show(Nutgram $bot, string $flightKey): void
    {
        $flight = $this->flights->findByKey($flightKey);
        $bot->answerCallbackQuery();

        if ($flight === null) {
            $bot->sendMessage(text: 'That flight is no longer on the board.');

            return;
        }

        $tracked = $this->isTracked($bot->chatId(), $flightKey);

        $bot->sendMessage(
            text: $this->format->card($flight)."\n\n".$this->format->freshness($this->flights->lastSyncedAt(), $this->flights->isStale()),
            parse_mode: ParseMode::HTML,
            reply_markup: Keyboards::flight($flight, $tracked),
        );
    }

    /**
     * Callback: qt:{id}:{flight_key}, track or untrack from a result list.
     */
    public function toggleTrack(Nutgram $bot, string $id, string $flightKey): void
    {
        $chatId = $bot->chatId();

        if ($this->isTracked($chatId, $flightKey)) {
            FlightSubscription::where('chat_id', $chatId)
                ->whereHas('flight', fn ($q) => $q->where('flight_key', $flightKey))
                ->delete();
            $bot->answerCallbackQuery(text: 'Stopped tracking');
        } else {
            $bot->answerCallbackQuery(text: strip_tags($this->tracking->subscribe($chatId, $flightKey)), show_alert: true);
        }

        if ($query = $this->restore($id)) {
            [, $keyboard] = $this->render($query, $id, $chatId);
            rescue(fn () => $bot->editMessageReplyMarkup(reply_markup: $keyboard), report: false);
        }
    }

    /**
     * @return array{0: string, 1: InlineKeyboardMarkup}
     */
    protected function render(FlightQuery $query, string $id, ?int $chatId): array
    {
        $all = $query->apply($this->flights->all());
        $types = $query->type ? [$query->type] : ['departure', 'arrival'];
        $singlePlace = count($query->placeCodes) === 1 ? $query->placeCodes[0] : null;

        $lines = [];
        $shown = collect();

        if ($all->isEmpty()) {
            $lines[] = $this->emptyMessage($query);
        } else {
            $lines[] = $this->headline($query, $all);

            $perType = $query->type ? self::MAX_RESULTS : (int) ceil(self::MAX_RESULTS / 2);

            foreach ($types as $type) {
                $group = $all->where('type', $type)->values();

                if ($group->isEmpty()) {
                    continue;
                }

                $list = $query->last ? $group->slice(-$perType)->values() : $group->take($perType);

                $lines[] = '';
                $lines[] = ($type === 'arrival' ? '🛬 <b>Arrivals' : '🛫 <b>Departures')
                    .($query->placeLabel ? ($type === 'arrival' ? ' from ' : ' to ').$this->format->e($query->placeLabel) : '')
                    .'</b>';

                $lines = array_merge($lines, $this->format->linesWithDays($list, function (array $f) use ($query, $singlePlace) {
                    $farEnd = $f['type'] === 'arrival' ? $f['origin_code'] : $f['destination_code'];
                    $line = $this->format->line($f, withPlace: $farEnd !== $singlePlace, withAirline: true);

                    // Matched through an intermediate stop: say so.
                    if ($query->placeCodes !== [] && ! in_array($farEnd, $query->placeCodes, true)) {
                        $line .= ' <i>(via '.$this->format->e(implode(', ', $f['via_routes'])).')</i>';
                    }

                    return $line;
                }));
                $shown = $shown->merge($list);

                if ($group->count() > $list->count()) {
                    $lines[] = '<i>+'.($group->count() - $list->count()).' more</i>';
                }
            }
        }

        $lines[] = '';
        $lines[] = $this->format->freshness($this->flights->lastSyncedAt(), $this->flights->isStale());

        return [implode("\n", $lines), $this->keyboard($shown, $id, $chatId)];
    }

    /**
     * One-sentence answer on top, e.g. "The next departure to Kuala Lumpur is MH 484 ..."
     */
    protected function headline(FlightQuery $query, Collection $results): string
    {
        $count = $results->count();
        $when = $query->timeLabel ? ' '.$this->format->e($query->timeLabel) : '';

        // A single direction and not a status filter: answer "the next one is ...".
        if ($query->type && ! $query->status) {
            $f = $query->last ? $results->last() : $results->first();
            $time = $query->time($f)->format('H:i');
            $day = $query->time($f)->isSameDay(now(config('fis.timezone'))) ? '' : ' on '.$query->time($f)->format('D j M');

            return sprintf(
                '%s %s is <b>%s</b>%s at <b>%s</b>%s · %s',
                $query->last ? 'The last' : 'The next',
                $this->format->e($this->singular($query)),
                $this->format->e($f['flight_number']),
                $f['airline'] ? ' ('.$this->format->e($f['airline']).')' : '',
                $time,
                $day,
                $this->format->status($f['status']),
            ).($count > 1 ? "\n{$count} matching flights{$when}:" : '');
        }

        return sprintf('Found <b>%d</b> %s%s:', $count, $this->format->e($query->describe()), $when);
    }

    protected function emptyMessage(FlightQuery $query): string
    {
        $when = $query->timeLabel ? ' '.$query->timeLabel : ' coming up';

        return 'No '.$this->format->e($query->describe()).$this->format->e($when)." on the MLE board.\n\n"
            .'<i>The board covers today and a few hours either side. Try /departures or /arrivals for everything.</i>';
    }

    protected function singular(FlightQuery $query): string
    {
        return preg_replace(['/\barrivals\b/', '/\bdepartures\b/', '/\bflights\b/'], ['arrival', 'departure', 'flight'], $query->describe(), 1);
    }

    protected function keyboard(Collection $flights, string $id, ?int $chatId): InlineKeyboardMarkup
    {
        $keyboard = InlineKeyboardMarkup::make();

        $tracked = $chatId === null ? [] : FlightSubscription::query()
            ->where('chat_id', $chatId)
            ->join('flights', 'flights.id', '=', 'flight_subscriptions.flight_id')
            ->whereIn('flights.flight_key', $flights->pluck('flight_key'))
            ->pluck('flights.flight_key')
            ->all();

        foreach ($flights as $f) {
            $isTracked = in_array($f['flight_key'], $tracked, true);
            $canTrack = ! in_array($f['status'], Flight::FINAL_STATUSES, true);

            $row = [InlineKeyboardButton::make(
                'ℹ️ '.$f['flight_number'].' · '.$this->format->shortDay($f).FlightQuery::timeOf($f)->format('H:i'),
                callback_data: 'show:'.$f['flight_key'],
            )];

            if ($canTrack || $isTracked) {
                $row[] = InlineKeyboardButton::make(
                    $isTracked ? '🔕 Untrack' : '🔔 Track',
                    callback_data: "qt:{$id}:".$f['flight_key'],
                );
            }

            $keyboard->addRow(...$row);
        }

        return $keyboard->addRow(InlineKeyboardButton::make('🔄 Refresh', callback_data: "ask:{$id}"));
    }

    protected function restore(string $id): ?FlightQuery
    {
        $text = Cache::get("nlq:{$id}");

        return $text === null ? null : $this->parser->parse($text);
    }

    protected function isTracked(?int $chatId, string $flightKey): bool
    {
        return $chatId !== null && FlightSubscription::where('chat_id', $chatId)
            ->whereHas('flight', fn ($q) => $q->where('flight_key', $flightKey))
            ->exists();
    }
}
