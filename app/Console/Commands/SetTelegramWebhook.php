<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use SergiX44\Nutgram\Nutgram;
use Throwable;

class SetTelegramWebhook extends Command
{
    protected $signature = 'bot:webhook
        {url? : Full webhook URL (default: APP_URL/telegram/webhook)}
        {--drop-pending : Discard updates queued while no webhook was set}';

    protected $description = 'Register the Telegram webhook with the configured secret token';

    public function handle(Nutgram $bot): int
    {
        $url = $this->argument('url') ?? rtrim(config('app.url'), '/').route('telegram.webhook', absolute: false);
        $secret = (string) config('nutgram.webhook_secret');

        if (! str_starts_with($url, 'https://')) {
            $this->error("Telegram requires an https:// webhook URL, got: {$url}");

            return self::FAILURE;
        }

        if ($secret === '') {
            $this->error('Set TELEGRAM_WEBHOOK_SECRET first (same value as in production .env).');

            return self::FAILURE;
        }

        try {
            $bot->setWebhook(
                url: $url,
                max_connections: 40,
                allowed_updates: ['message', 'callback_query'],
                drop_pending_updates: (bool) $this->option('drop-pending'),
                secret_token: $secret,
            );
        } catch (Throwable $e) {
            $this->error('Telegram rejected the webhook: '.$e->getMessage());

            return self::FAILURE;
        }

        // API errors are routed to the bot's onApiError handler instead of
        // throwing, so confirm with Telegram that the URL actually took.
        $info = $bot->getWebhookInfo();

        if ($info?->url !== $url) {
            $this->error('Telegram did not accept the webhook. Last error: '
                .($info?->last_error_message ?? 'see storage/logs (Telegram API error)'));
            $this->line('Check that the domain resolves publicly and serves valid HTTPS.');

            return self::FAILURE;
        }

        $this->info("Webhook set: {$url}");

        return self::SUCCESS;
    }
}
