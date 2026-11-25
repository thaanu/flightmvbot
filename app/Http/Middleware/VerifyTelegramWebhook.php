<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rejects webhook calls that don't carry the secret token we registered
 * with Telegram (sent in the X-Telegram-Bot-Api-Secret-Token header).
 */
class VerifyTelegramWebhook
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('nutgram.webhook_secret');

        if ($secret === '') {
            // Never accept unauthenticated updates in production.
            abort_if(app()->isProduction(), 403, 'Webhook secret not configured.');

            return $next($request);
        }

        abort_unless(hash_equals($secret, (string) $request->header('X-Telegram-Bot-Api-Secret-Token')), 403);

        return $next($request);
    }
}
