# FlightMvBot

Telegram bot (`@flightmvbot`) with live flight information for Velana International Airport (MLE).

Flight data is pulled from the public `https://fis.com.mv/api/flights` feed once a minute into
MySQL (history + status changes) and a Redis snapshot. Bot replies read local storage only.

## Running locally

```bash
php artisan migrate
php artisan fis:sync              # one-off sync
php artisan schedule:work         # sync every minute, weather every 10 min, daily prune
php artisan queue:work            # sends "track my flight" notifications
php artisan nutgram:run           # bot via long polling (requires no webhook set)
```

## Production (https://fis.codered.mv)

`.env` on the server:

```
APP_ENV=production
APP_DEBUG=false
APP_URL=https://fis.codered.mv
TELEGRAM_BOT_TOKEN=...
TELEGRAM_WEBHOOK_SECRET=...   # random string; Telegram echoes it on every webhook call
```

- Cron: `* * * * * php artisan schedule:run` (flight sync every minute, weather, prune)
- A supervised queue worker: `php artisan queue:work --tries=3` (tracking notifications)
- After deploy: `php artisan migrate --force && php artisan fis:sync && php artisan bot:webhook`
  (`bot:webhook` registers `APP_URL/telegram/webhook` with the secret and verifies Telegram accepted it)
- After changing commands: `php artisan nutgram:register-commands`

## Bot commands

`/arrivals`, `/departures`, `/flight EK652`, `/track EK652`, `/untrack`, `/mytracks`, `/weather`, `/help`.
Plain text works too: a flight number (`EK652`) or a city / airport code / airline (`Dubai`, `DXB`, `Emirates`).

## Layout

| Path | Purpose |
|---|---|
| `app/Services/Fis/` | FIS HTTP client, normalizer, sync service, Redis snapshots |
| `app/Repositories/FlightRepository.php` | Read API for the bot (Redis first, MySQL fallback) |
| `app/Telegram/` | Bot handlers, keyboards, message formatting |
| `routes/telegram.php` | Bot command / callback routing |
| `app/Listeners/SendFlightStatusNotifications.php` | Pushes status changes to trackers |
