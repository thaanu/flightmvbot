<?php

namespace App\Telegram;

use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

/**
 * Inline keyboards. Callback data formats:
 *   board:{type}:{category}:{page}   arrivals/departures list
 *   flight:{COMPACT}                 refresh a flight lookup
 *   track:{flight_key}               subscribe to a flight
 *   untrack:{flight_key}             unsubscribe
 */
class Keyboards
{
    public static function mainMenu(): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()->addRow(
            InlineKeyboardButton::make('🛬 Arrivals', callback_data: 'board:arrival:all:0'),
            InlineKeyboardButton::make('🛫 Departures', callback_data: 'board:departure:all:0'),
        );
    }

    public static function board(string $type, string $category, int $page, int $pages): InlineKeyboardMarkup
    {
        $tab = fn (string $cat, string $label) => InlineKeyboardButton::make(
            ($cat === $category ? '• ' : '').$label,
            callback_data: "board:{$type}:{$cat}:0",
        );

        $keyboard = InlineKeyboardMarkup::make()->addRow(
            $tab('all', 'All'),
            $tab('international', 'International'),
            $tab('domestic', 'Domestic'),
        );

        $nav = [];

        if ($page > 0) {
            $nav[] = InlineKeyboardButton::make('◀️ Earlier', callback_data: "board:{$type}:{$category}:".($page - 1));
        }

        $nav[] = InlineKeyboardButton::make('🔄 Refresh', callback_data: "board:{$type}:{$category}:{$page}");

        if ($page < $pages - 1) {
            $nav[] = InlineKeyboardButton::make('Later ▶️', callback_data: "board:{$type}:{$category}:".($page + 1));
        }

        $other = $type === 'arrival'
            ? InlineKeyboardButton::make('🛫 Departures', callback_data: 'board:departure:all:0')
            : InlineKeyboardButton::make('🛬 Arrivals', callback_data: 'board:arrival:all:0');

        return $keyboard->addRow(...$nav)->addRow($other);
    }

    public static function flight(array $flight, bool $tracked = false): InlineKeyboardMarkup
    {
        $track = $tracked
            ? InlineKeyboardButton::make('🔕 Stop tracking', callback_data: 'untrack:'.$flight['flight_key'])
            : InlineKeyboardButton::make('🔔 Track', callback_data: 'track:'.$flight['flight_key']);

        return InlineKeyboardMarkup::make()->addRow(
            $track,
            InlineKeyboardButton::make('🔄 Refresh', callback_data: 'flight:'.$flight['flight_number_compact']),
        );
    }
}
