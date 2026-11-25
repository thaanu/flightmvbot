<?php

namespace Tests\Feature;

use SergiX44\Nutgram\Nutgram;
use Tests\TestCase;

class TelegramWebhookTest extends TestCase
{
    private function update(): array
    {
        return [
            'update_id' => 1,
            'message' => [
                'message_id' => 1,
                'date' => time(),
                'chat' => ['id' => 99, 'type' => 'private'],
                'from' => ['id' => 99, 'is_bot' => false, 'first_name' => 'T'],
                'text' => '/help',
            ],
        ];
    }

    public function test_rejects_missing_or_wrong_secret(): void
    {
        config(['nutgram.webhook_secret' => 'right']);

        $this->postJson('/telegram/webhook', $this->update())->assertForbidden();
        $this->postJson('/telegram/webhook', $this->update(), ['X-Telegram-Bot-Api-Secret-Token' => 'wrong'])->assertForbidden();
    }

    public function test_accepts_correct_secret(): void
    {
        config(['nutgram.webhook_secret' => 'right']);
        $this->mock(Nutgram::class)->shouldReceive('run')->once();

        $this->postJson('/telegram/webhook', $this->update(), ['X-Telegram-Bot-Api-Secret-Token' => 'right'])->assertOk();
    }

    public function test_production_without_secret_is_rejected(): void
    {
        config(['nutgram.webhook_secret' => null]);
        $this->app->detectEnvironment(fn () => 'production');

        $this->postJson('/telegram/webhook', $this->update())->assertForbidden();
    }
}
