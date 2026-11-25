<?php

namespace App\Telegram\Handlers;

use App\Telegram\Keyboards;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;

class StartHandler
{
    public function start(Nutgram $bot): void
    {
        $name = htmlspecialchars($bot->user()?->first_name ?? 'there');

        $bot->sendMessage(
            text: "👋 Hi {$name}! I give live flight information for <b>Velana International Airport (MLE)</b>, Maldives.\n\n".$this->helpText(),
            parse_mode: ParseMode::HTML,
            reply_markup: Keyboards::mainMenu(),
        );
    }

    public function help(Nutgram $bot): void
    {
        $bot->sendMessage(text: $this->helpText(), parse_mode: ParseMode::HTML, reply_markup: Keyboards::mainMenu());
    }

    protected function helpText(): string
    {
        return implode("\n", [
            '<b>What you can do</b>',
            '• Send a flight number, e.g. <code>EK652</code> or <code>Q2 113</code>',
            '• Send a city, airport code or airline, e.g. <code>Dubai</code>, <code>DXB</code>, <code>Emirates</code>',
            '',
            '/arrivals — upcoming arrivals',
            '/departures — upcoming departures',
            '/flight <code>EK652</code> — flight details',
            '/track <code>EK652</code> — get notified when it changes',
            '/mytracks — flights you are tracking',
            '/weather — weather at the airport',
        ]);
    }
}
