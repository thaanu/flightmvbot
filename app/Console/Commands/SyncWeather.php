<?php

namespace App\Console\Commands;

use App\Services\Fis\FisClient;
use App\Services\Fis\WeatherSnapshot;
use Illuminate\Console\Command;
use Throwable;

class SyncWeather extends Command
{
    protected $signature = 'fis:weather';

    protected $description = 'Fetch current MLE weather from fis.com.mv into the cache';

    public function handle(FisClient $client, WeatherSnapshot $snapshot): int
    {
        try {
            $weather = $client->weather();
        } catch (Throwable $e) {
            $this->error('Weather fetch failed: '.$e->getMessage());

            return self::FAILURE;
        }

        if (! isset($weather['temp'])) {
            $this->error('Unexpected weather response.');

            return self::FAILURE;
        }

        $snapshot->put($weather);
        $this->info("Weather: {$weather['temp']}°C");

        return self::SUCCESS;
    }
}
