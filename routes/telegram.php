<?php

/** @var Nutgram $bot */

use App\Telegram\Handlers\AskHandler;
use App\Telegram\Handlers\BoardHandler;
use App\Telegram\Handlers\FlightHandler;
use App\Telegram\Handlers\StartHandler;
use App\Telegram\Handlers\TrackingHandler;
use App\Telegram\Handlers\WeatherHandler;
use Illuminate\Support\Facades\Log;
use SergiX44\Nutgram\Nutgram;

/*
|--------------------------------------------------------------------------
| Telegram bot handlers (Velana International Airport flight info)
|--------------------------------------------------------------------------
|
| Every handler reads local storage only (Redis snapshot / MySQL), never
| fis.com.mv directly. Run locally with `php artisan nutgram:run`.
|
*/

$bot->onCommand('start', [StartHandler::class, 'start'])->description('Start the bot');
$bot->onCommand('help', [StartHandler::class, 'help'])->description('How to use this bot');

$bot->onCommand('arrivals', [BoardHandler::class, 'arrivals'])->description('Upcoming arrivals at MLE');
$bot->onCommand('departures', [BoardHandler::class, 'departures'])->description('Upcoming departures from MLE');
$bot->onCallbackQueryData('board:{type}:{category}:{page}', [BoardHandler::class, 'callback']);

$bot->onCommand('flight', [FlightHandler::class, 'command'])->description('Flight details, e.g. /flight EK652');
$bot->onCommand('flight {number}', [FlightHandler::class, 'command']);
$bot->onCallbackQueryData('flight:{compact}', [FlightHandler::class, 'refresh']);

$bot->onCommand('track', [TrackingHandler::class, 'command'])->description('Get notified when a flight changes');
$bot->onCommand('track {number}', [TrackingHandler::class, 'command']);
$bot->onCommand('untrack', [TrackingHandler::class, 'untrackCommand'])->description('Stop tracking a flight');
$bot->onCommand('untrack {number}', [TrackingHandler::class, 'untrackCommand']);
$bot->onCommand('mytracks', [TrackingHandler::class, 'list'])->description('Flights you are tracking');
$bot->onCallbackQueryData('track:{key}', [TrackingHandler::class, 'trackCallback']);
$bot->onCallbackQueryData('untrack:{key}', [TrackingHandler::class, 'untrackCallback']);

$bot->onCommand('weather', WeatherHandler::class)->description('Weather at the airport');

// Natural-language results: refresh, open a flight card, toggle tracking.
$bot->onCallbackQueryData('ask:{id}', [AskHandler::class, 'refresh']);
$bot->onCallbackQueryData('show:{key}', [AskHandler::class, 'show']);
$bot->onCallbackQueryData('qt:{id}:{key}', [AskHandler::class, 'toggleTrack']);

// Plain text (not a command): flight number, question, or city / airline search.
$bot->onText('(?!/){text}', [FlightHandler::class, 'text']);

$bot->fallback(function (Nutgram $bot) {
    if ($bot->isCallbackQuery()) {
        $bot->answerCallbackQuery();

        return;
    }

    $bot->sendMessage('Sorry, I did not understand that. Try /help');
});

$bot->onException(function (Nutgram $bot, Throwable $e) {
    Log::error('Telegram handler failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);

    rescue(fn () => $bot->sendMessage('Something went wrong on our side. Please try again in a moment.'), report: false);
});

$bot->onApiError(function (Nutgram $bot, Throwable $e) {
    Log::warning('Telegram API error', ['error' => $e->getMessage()]);
});
