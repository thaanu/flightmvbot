<?php

namespace App\Services\Fis;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin HTTP client for the public fis.com.mv JSON endpoints.
 */
class FisClient
{
    /**
     * Fetch the full flight feed.
     *
     * @return array{flights: list<array<string, mixed>>, lastUpdated: ?string}
     *
     * @throws RuntimeException when the response is not a usable feed.
     */
    public function flights(): array
    {
        $payload = $this->request()
            ->get(config('fis.flights_path'))
            ->throw()
            ->json();

        if (! is_array($payload) || ! isset($payload['flights']) || ! is_array($payload['flights'])) {
            throw new RuntimeException('FIS response did not contain a "flights" array.');
        }

        if ($payload['flights'] === []) {
            // An empty board is almost certainly an upstream glitch; never wipe local data for it.
            throw new RuntimeException('FIS returned an empty flights list.');
        }

        return [
            'flights' => array_values($payload['flights']),
            'lastUpdated' => isset($payload['lastUpdated']) ? (string) $payload['lastUpdated'] : null,
        ];
    }

    /**
     * Fetch current weather at MLE.
     *
     * @return array<string, mixed>
     */
    public function weather(): array
    {
        return (array) $this->request()
            ->get(config('fis.weather_path'))
            ->throw()
            ->json();
    }

    protected function request(): PendingRequest
    {
        return Http::baseUrl(config('fis.base_url'))
            ->timeout(config('fis.timeout'))
            ->retry(2, 500, throw: false)
            ->withUserAgent(config('fis.user_agent'))
            ->acceptJson();
    }
}
