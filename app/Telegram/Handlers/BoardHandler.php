<?php

namespace App\Telegram\Handlers;

use App\Repositories\FlightRepository;
use App\Telegram\FlightFormatter;
use App\Telegram\Keyboards;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

/**
 * Paged arrivals / departures boards.
 */
class BoardHandler
{
    public const PER_PAGE = 12;

    public const CATEGORIES = ['all', 'international', 'domestic'];

    public function __construct(
        protected FlightRepository $flights,
        protected FlightFormatter $format,
    ) {}

    public function arrivals(Nutgram $bot): void
    {
        $this->send($bot, 'arrival', 'all', 0);
    }

    public function departures(Nutgram $bot): void
    {
        $this->send($bot, 'departure', 'all', 0);
    }

    /**
     * Callback: board:{type}:{category}:{page}
     */
    public function callback(Nutgram $bot, string $type, string $category, string $page): void
    {
        $bot->answerCallbackQuery();

        if (! in_array($type, ['arrival', 'departure'], true) || ! in_array($category, self::CATEGORIES, true)) {
            return;
        }

        [$text, $keyboard] = $this->render($type, $category, max(0, (int) $page));

        rescue(fn () => $bot->editMessageText(
            text: $text,
            parse_mode: ParseMode::HTML,
            reply_markup: $keyboard,
        ), report: false); // "message is not modified" on a no-op refresh
    }

    protected function send(Nutgram $bot, string $type, string $category, int $page): void
    {
        [$text, $keyboard] = $this->render($type, $category, $page);

        $bot->sendMessage(text: $text, parse_mode: ParseMode::HTML, reply_markup: $keyboard);
    }

    /**
     * @return array{0: string, 1: InlineKeyboardMarkup}
     */
    protected function render(string $type, string $category, int $page): array
    {
        $board = $this->flights->board($type, $category === 'all' ? null : $category);
        $pages = max(1, (int) ceil($board->count() / self::PER_PAGE));
        $page = min($page, $pages - 1);

        $title = ($type === 'arrival' ? '🛬 <b>Arrivals</b>' : '🛫 <b>Departures</b>')
            .' · Velana International (MLE)'
            .($category === 'all' ? '' : ' · '.ucfirst($category));

        $lines = [$title, ''];

        $items = $board->slice($page * self::PER_PAGE, self::PER_PAGE);

        if ($items->isEmpty()) {
            $lines[] = 'No more flights on the board right now.';
        } else {
            $lines = array_merge($lines, $this->format->linesWithDays($items, fn ($f) => $this->format->line($f)));
        }

        $lines[] = '';
        $lines[] = 'Page '.($page + 1)."/{$pages} · send a flight number for details";
        $lines[] = $this->format->freshness($this->flights->lastSyncedAt(), $this->flights->isStale());

        return [implode("\n", $lines), Keyboards::board($type, $category, $page, $pages)];
    }
}
