<?php

namespace App\Telegram\Handlers;

use App\Services\Fis\WeatherSnapshot;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;

class WeatherHandler
{
    /** WMO weather interpretation codes (as used by the FIS weather endpoint). */
    protected const CODES = [
        0 => ['☀️', 'Clear sky'], 1 => ['🌤', 'Mainly clear'], 2 => ['⛅', 'Partly cloudy'], 3 => ['☁️', 'Overcast'],
        45 => ['🌫', 'Fog'], 48 => ['🌫', 'Fog'],
        51 => ['🌦', 'Light drizzle'], 53 => ['🌦', 'Drizzle'], 55 => ['🌦', 'Heavy drizzle'],
        61 => ['🌧', 'Light rain'], 63 => ['🌧', 'Rain'], 65 => ['🌧', 'Heavy rain'],
        80 => ['🌦', 'Rain showers'], 81 => ['🌧', 'Rain showers'], 82 => ['⛈', 'Violent rain showers'],
        95 => ['⛈', 'Thunderstorm'], 96 => ['⛈', 'Thunderstorm with hail'], 99 => ['⛈', 'Thunderstorm with hail'],
    ];

    public function __construct(protected WeatherSnapshot $weather) {}

    public function __invoke(Nutgram $bot): void
    {
        $w = $this->weather->get();

        if ($w === null) {
            $bot->sendMessage(text: 'Weather information is not available right now.');

            return;
        }

        $isNight = isset($w['isDay']) && (int) $w['isDay'] === 0;
        [$icon, $label] = self::CODES[(int) ($w['weatherCode'] ?? -1)] ?? ['🌡', 'Current conditions'];

        if ($isNight && in_array((int) ($w['weatherCode'] ?? -1), [0, 1], true)) {
            $icon = '🌙';
        }

        $bot->sendMessage(
            text: implode("\n", array_filter([
                '<b>Weather at Velana International (MLE)</b>',
                "{$icon} {$label}",
                isset($w['temp']) ? "🌡 {$w['temp']}°C" : null,
                isset($w['humidity']) ? "💧 Humidity {$w['humidity']}%" : null,
                isset($w['wind']) ? "💨 Wind {$w['wind']} km/h" : null,
            ])),
            parse_mode: ParseMode::HTML,
        );
    }
}
