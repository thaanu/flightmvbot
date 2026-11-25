<?php

namespace App\Telegram;

use Carbon\CarbonImmutable;

/**
 * Renders flight arrays (snapshot shape) into Telegram HTML messages.
 */
class FlightFormatter
{
    public const STATUSES = [
        'scheduled' => ['🗓', 'Scheduled'],
        'on-time' => ['✅', 'On time'],
        'delayed' => ['⏰', 'Delayed'],
        'boarding' => ['🚶', 'Boarding'],
        'final-call' => ['📢', 'Final call'],
        'gate-closed' => ['🚪', 'Gate closed'],
        'check-in-closed' => ['🧳', 'Check-in closed'],
        'closed' => ['🔒', 'Closed'],
        'departed' => ['🛫', 'Departed'],
        'landed' => ['🛬', 'Landed'],
        'cancelled' => ['❌', 'Cancelled'],
        'diverted' => ['↪️', 'Diverted'],
    ];

    public function status(string $status): string
    {
        [$icon, $label] = self::STATUSES[$status] ?? ['ℹ️', ucfirst(str_replace('-', ' ', $status))];

        return "{$icon} {$label}";
    }

    /**
     * Full detail card for one flight.
     */
    public function card(array $f): string
    {
        $isArrival = $f['type'] === 'arrival';
        $scheduled = $this->time($f['scheduled_at']);
        $estimated = $f['estimated_at'] ? $this->time($f['estimated_at']) : null;
        $place = $isArrival
            ? $this->place($f['origin'], $f['origin_code'])
            : $this->place($f['destination'], $f['destination_code']);

        $lines = [
            sprintf('✈️ <b>%s</b> · %s', $this->e($f['flight_number']), $this->e($f['airline'] ?? '')),
            ($isArrival ? '🛬 Arrival from ' : '🛫 Departure to ').$place,
            '📅 '.$scheduled->format('D, j M Y'),
            '🕐 Scheduled <b>'.$scheduled->format('H:i').'</b>'
                .($estimated && ! $estimated->equalTo($scheduled) ? ' · Estimated <b>'.$this->clock($estimated, $scheduled).'</b>' : ''),
            '📍 Status: <b>'.$this->status($f['status']).'</b>'.$this->offset($f),
            '🏢 Terminal '.$this->e($f['terminal'] ?? '—').($f['gate'] ? ' · Gate '.$this->e($f['gate']) : '')
                .($f['category'] ? ' · '.ucfirst($f['category']) : ''),
        ];

        if (! empty($f['via_routes'])) {
            $lines[] = '🔁 Via '.$this->e(implode(', ', $f['via_routes']));
        }

        if (! empty($f['codeshares'])) {
            $lines[] = '🤝 Codeshare: '.$this->e(implode(', ', $f['codeshares']));
        }

        return implode("\n", $lines);
    }

    /**
     * One compact line for board lists.
     */
    public function line(array $f, bool $withPlace = true, bool $withAirline = false): string
    {
        $scheduled = $this->time($f['scheduled_at']);
        $estimated = $f['estimated_at'] ? $this->time($f['estimated_at']) : null;
        $place = $f['type'] === 'arrival' ? $f['origin'] : $f['destination'];

        $time = '<code>'.$scheduled->format('H:i').'</code>';

        if ($estimated && ! $estimated->equalTo($scheduled)) {
            $time .= ' → <code>'.$this->clock($estimated, $scheduled).'</code>';
        }

        [$icon] = self::STATUSES[$f['status']] ?? ['ℹ️'];

        // Spell out statuses that explain themselves poorly as an icon.
        $note = in_array($f['status'], ['cancelled', 'diverted'], true)
            ? ' · <b>'.self::STATUSES[$f['status']][1].'</b>'
            : '';

        return trim(sprintf(
            '%s %s <b>%s</b>%s%s%s',
            $icon,
            $time,
            $this->e($f['flight_number']),
            $withPlace ? ' '.$this->e($place ?? '') : '',
            $withAirline && $f['airline'] ? ' · '.$this->e($f['airline']) : '',
            $note,
        ));
    }

    /**
     * Render a list of flights, inserting a day heading ("📅 Tomorrow, Thu 26 Nov")
     * whenever the day changes. Headings are skipped when every flight is today.
     *
     * @param  iterable<array>  $flights
     * @param  \Closure(array): string  $render
     * @return list<string>
     */
    public function linesWithDays(iterable $flights, \Closure $render): array
    {
        $flights = collect($flights);
        $today = now(config('fis.timezone'))->format('Y-m-d');
        $days = $flights->map(fn (array $f) => $this->day($f)->format('Y-m-d'))->unique();
        $headings = $days->count() > 1 || ($days->first() !== null && $days->first() !== $today);

        $lines = [];
        $current = null;

        foreach ($flights as $f) {
            $day = $this->day($f);

            if ($headings && $day->format('Y-m-d') !== $current) {
                $current = $day->format('Y-m-d');
                $lines[] = '📅 <i>'.$this->dayLabel($day).'</i>';
            }

            $lines[] = $render($f);
        }

        return $lines;
    }

    /**
     * "Today, Wed 25 Nov" / "Tomorrow, Thu 26 Nov" / "Yesterday, Tue 24 Nov" / "Fri 27 Nov"
     */
    public function dayLabel(CarbonImmutable $day): string
    {
        $now = now(config('fis.timezone'));
        $date = $day->format('D j M');

        return match (true) {
            $day->isSameDay($now) => "Today, {$date}",
            $day->isSameDay($now->copy()->addDay()) => "Tomorrow, {$date}",
            $day->isSameDay($now->copy()->subDay()) => "Yesterday, {$date}",
            default => $date,
        };
    }

    /**
     * Short day prefix for buttons: "" for today, otherwise "Thu ".
     */
    public function shortDay(array $f): string
    {
        $day = $this->day($f);

        return $day->isSameDay(now(config('fis.timezone'))) ? '' : $day->format('D').' ';
    }

    /**
     * The flight's day, by scheduled time (the date the airport lists it under).
     */
    public function day(array $f): CarbonImmutable
    {
        return $this->time($f['scheduled_at']);
    }

    /**
     * Footer telling the user how fresh the data is.
     */
    public function freshness(?CarbonImmutable $syncedAt, bool $stale): string
    {
        if ($syncedAt === null) {
            return '⚠️ <i>Live data is temporarily unavailable.</i>';
        }

        $text = '<i>Updated '.$syncedAt->setTimezone(config('fis.timezone'))->format('H:i').' (Malé time)</i>';

        return $stale ? '⚠️ '.$text.' <i>— may be out of date</i>' : $text;
    }

    public function e(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    protected function offset(array $f): string
    {
        if (! empty($f['delay_minutes'])) {
            return ' ('.$this->duration($f['delay_minutes']).' late)';
        }

        if (! empty($f['early_minutes'])) {
            return ' ('.$this->duration($f['early_minutes']).' early)';
        }

        return '';
    }

    protected function duration(int $minutes): string
    {
        return $minutes >= 60 ? intdiv($minutes, 60).'h '.($minutes % 60).'m' : "{$minutes} min";
    }

    protected function place(?string $name, ?string $code): string
    {
        return '<b>'.$this->e($name).'</b>'.($code ? ' ('.$this->e($code).')' : '');
    }

    /**
     * HH:MM, with a day marker when it falls on a different date than $reference.
     */
    protected function clock(CarbonImmutable $time, CarbonImmutable $reference): string
    {
        $clock = $time->format('H:i');

        if (! $time->isSameDay($reference)) {
            $clock .= $time->gt($reference) ? ' (+1d)' : ' (-1d)';
        }

        return $clock;
    }

    protected function time(string $iso): CarbonImmutable
    {
        return CarbonImmutable::parse($iso)->setTimezone(config('fis.timezone'));
    }
}
