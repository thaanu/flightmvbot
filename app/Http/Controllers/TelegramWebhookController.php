<?php

namespace App\Http\Controllers;

use SergiX44\Nutgram\Nutgram;

/**
 * Receives Telegram updates in production (webhook mode).
 * Register with: php artisan nutgram:hook:set https://your-domain/telegram/webhook
 */
class TelegramWebhookController extends Controller
{
    public function __invoke(Nutgram $bot): void
    {
        $bot->run();
    }
}
