<?php

namespace App\Services\Query;

use App\Repositories\FlightRepository;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Rule-based natural-language parser for flight questions, e.g.
 *   "When is the next flight to Kuala Lumpur?"
 *   "any delayed arrivals from dubai tonight"
 *   "Emirates departures tomorrow morning"
 *   "flights to Gan after 5pm"
 *
 * The vocabulary (places, airlines) comes from the live feed itself, plus a
 * small alias list, so new destinations work without code changes.
 */
class FlightQueryParser
{
    /** Extra names people use for places. value: [label, codes] */
    protected const PLACE_ALIASES = [
        'kl' => ['Kuala Lumpur', ['KUL']],
        'malaysia' => ['Kuala Lumpur', ['KUL']],
        'bangkok' => ['Bangkok', ['BKK', 'DMK']],
        'thailand' => ['Thailand', ['BKK', 'DMK', 'HKT']],
        'london' => ['London', ['LHR', 'LGW', 'STN']],
        'kochi' => ['Cochin', ['COK']],
        'bengaluru' => ['Bangalore', ['BLR']],
        'bombay' => ['Mumbai', ['BOM']],
        'new delhi' => ['Delhi', ['DEL']],
        'madras' => ['Chennai', ['MAA']],
        'thiruvananthapuram' => ['Trivandrum', ['TRV']],
        'india' => ['India', ['BLR', 'MAA', 'COK', 'DEL', 'BOM', 'TRV', 'HYD', 'CCJ']],
        'sri lanka' => ['Colombo', ['CMB']],
        'srilanka' => ['Colombo', ['CMB']],
        'addu' => ['Gan (Addu)', ['GAN']],
        'uae' => ['UAE', ['DXB', 'AUH', 'SHJ']],
        'china' => ['China', ['PEK', 'PVG', 'CAN', 'CKG', 'KMG', 'CTU', 'SZX']],
    ];

    /** Extra names for airlines. value: [label, lower-cased airline names as in the feed] */
    protected const AIRLINE_ALIASES = [
        'airasia' => ['AirAsia', ['air asia', 'thai airasia']],
        'sri lankan' => ['SriLankan', ['srilankan airlines']],
        'saudi' => ['Saudia', ['saudia']],
        'etihad' => ['Etihad', ['etihad airways']],
        'turkish' => ['Turkish Airlines', ['turkish airlines']],
        'fly dubai' => ['Flydubai', ['flydubai']],
    ];

    /** Names of the home airport. */
    protected const HOME = ['male', 'mle', 'velana', 'hulhule', 'malé'];

    /** Words never used for fuzzy matching (they look like place names too often). */
    protected const STOPWORDS = [
        'when', 'what', 'where', 'which', 'there', 'their', 'flight', 'flights', 'plane', 'planes', 'next', 'today',
        'tomorrow', 'tonight', 'morning', 'evening', 'afternoon', 'night', 'from', 'leave', 'leaves', 'leaving',
        'arrive', 'arrives', 'arriving', 'arrival', 'arrivals', 'depart', 'departs', 'departure', 'departures',
        'going', 'coming', 'delayed', 'landed', 'departed', 'please', 'hours', 'minutes', 'after', 'before',
        'about', 'around', 'until', 'first', 'last', 'later', 'early', 'there', 'these', 'those', 'have', 'does',
        'domestic', 'international', 'local', 'upcoming', 'available', 'airline', 'airlines', 'airways', 'show',
        'list', 'tell', 'know', 'want', 'need', 'help', 'still', 'cancelled', 'canceled', 'boarding', 'status',
    ];

    /** Words too generic to identify an airline on their own. */
    protected const GENERIC_AIRLINE_WORDS = ['air', 'airlines', 'airline', 'airways', 'thai', 'aero', 'fly', 'gulf'];

    protected const FLIGHT_WORDS = '/\b(flights?|planes?|arrivals?|departures?|arriv\w*|depart\w*|land\w*|leav\w*|take ?offs?|schedules?)\b/';

    public function __construct(protected FlightRepository $flights) {}

    /**
     * Returns null when the text does not look like a flight question.
     */
    public function parse(string $text, ?CarbonImmutable $now = null): ?FlightQuery
    {
        $now ??= CarbonImmutable::now(config('fis.timezone'));
        $now = $now->setTimezone(config('fis.timezone'));

        $original = $this->clean($text, lower: false);
        $lower = mb_strtolower($original);
        $tokens = $lower === '' ? [] : explode(' ', $lower);
        $origTokens = $original === '' ? [] : explode(' ', $original);
        $consumed = [];

        $query = new FlightQuery;
        $recognised = false;

        // --- Airlines (full names first so "Singapore Airlines" is not read as the place Singapore).
        [$airlineMatches, $consumed] = $this->matchPhrases($tokens, $this->airlinePhrases(fullOnly: true), $consumed);

        // --- Places.
        [$placeMatches, $consumed] = $this->matchPhrases($tokens, $this->placePhrases(), $consumed);

        // Uppercase airport codes ("DXB"), or lower-case ones after to/from ("flights to dxb").
        $codes = array_keys($this->places());
        foreach ($origTokens as $i => $tok) {
            if (isset($consumed[$i]) || ! in_array(strtoupper($tok), $codes, true)) {
                continue;
            }

            $isUpper = $tok === strtoupper($tok) && strlen($tok) === 3;
            $afterPreposition = in_array($tokens[$i - 1] ?? '', ['to', 'from', 'for'], true);

            if ($isUpper || $afterPreposition || count($tokens) === 1) {
                $code = strtoupper($tok);
                $placeMatches[] = ['start' => $i, 'value' => [$this->places()[$code].' ('.$code.')', [$code]]];
                $consumed[$i] = true;
            }
        }

        // --- Airlines by distinctive first word ("Emirates", "Qatar") and aliases.
        [$more, $consumed] = $this->matchPhrases($tokens, $this->airlinePhrases(fullOnly: false), $consumed);
        $airlineMatches = array_merge($airlineMatches, $more);

        // Uppercase 2-character airline codes ("EK flights").
        $airlineCodes = $this->airlineCodes();
        foreach ($origTokens as $i => $tok) {
            if (! isset($consumed[$i]) && strlen($tok) === 2 && $tok === strtoupper($tok) && isset($airlineCodes[$tok])) {
                $airlineMatches[] = ['start' => $i, 'value' => [$airlineCodes[$tok], [mb_strtolower($airlineCodes[$tok])]]];
                $consumed[$i] = true;
            }
        }

        if ($placeMatches !== []) {
            $recognised = true;
            $codes = array_values(array_unique(array_merge(...array_map(fn ($m) => $m['value'][1], $placeMatches))));
            $query->placeCodes = $codes;
            $query->placeLabel = implode(' / ', array_unique(array_map(fn ($m) => $m['value'][0], $placeMatches)));
            $query->placeNames = array_values(array_filter(array_map(fn ($c) => mb_strtolower($this->places()[$c] ?? ''), $codes)));

            // Direction from the word right before the first place: "to X" / "from X".
            $before = $tokens[$placeMatches[0]['start'] - 1] ?? null;
            $query->type = match ($before) {
                'to', 'for', 'towards', 'into' => 'departure',
                'from' => 'arrival',
                default => null,
            };
        }

        if ($airlineMatches !== []) {
            $recognised = true;
            $query->airlines = array_values(array_unique(array_merge(...array_map(fn ($m) => $m['value'][1], $airlineMatches))));
            $query->airlineLabel = implode(' / ', array_unique(array_map(fn ($m) => $m['value'][0], $airlineMatches)));
        }

        // --- Direction keywords override prepositions ("arrivals from", "departures to").
        if (preg_match('/\b(arriv\w*|land(s|ing)?|incoming|inbound|coming)\b/', $lower)) {
            $query->type = 'arrival';
            $recognised = true;
        } elseif (preg_match('/\b(depart\w*|leav\w*|outgoing|outbound|take ?off|taking off|going|fly(ing)? (to|out))\b/', $lower)) {
            $query->type = 'departure';
            $recognised = true;
        }

        // Mentions of Malé itself: "from Male" = departures, "to/into Male" = arrivals.
        $home = implode('|', array_map('preg_quote', self::HOME));
        if (preg_match("/\\bfrom ($home)\\b/u", $lower) && ! preg_match('/\bfrom (?!'.$home.')\w+/u', $lower)) {
            $query->type ??= 'departure';
            $recognised = true;
        } elseif (preg_match("/\\b(to|into) ($home)\\b/u", $lower)) {
            $query->type ??= 'arrival';
            $recognised = true;
        }

        // --- Status.
        $status = match (true) {
            (bool) preg_match('/\b(delay\w*|late|running late)\b/', $lower) => 'delayed',
            (bool) preg_match('/\b(cancel\w*)\b/', $lower) => 'cancelled',
            (bool) preg_match('/\b(landed|arrived|touched down)\b/', $lower) => 'landed',
            (bool) preg_match('/\b(departed|took off|left)\b/', $lower) => 'departed',
            (bool) preg_match('/\b(boarding)\b/', $lower) => 'boarding',
            (bool) preg_match('/\b(diverted)\b/', $lower) => 'diverted',
            default => null,
        };

        if ($status) {
            $query->status = $status;
            $recognised = true;

            if ($status === 'landed') {
                $query->type = 'arrival';
            } elseif ($status === 'departed') {
                $query->type = 'departure';
            }
        }

        // --- Category.
        if (preg_match('/\b(domestic|local|island|atoll)\b/', $lower)) {
            $query->category = 'domestic';
            $recognised = true;
        } elseif (preg_match('/\b(international|overseas|abroad)\b/', $lower)) {
            $query->category = 'international';
            $recognised = true;
        }

        // --- Time window.
        $hasTime = $this->applyTime($query, $lower, $now);

        if (! $recognised && ! ($hasTime && preg_match(self::FLIGHT_WORDS, $lower))) {
            // A bare "flights" / "next flight" with nothing else: show both boards.
            if (! preg_match('/^(show |list |any )?(the |all )?(next |upcoming )?(flights?|planes?)( please)?$/', $lower)) {
                return null;
            }
        }

        $query->last = (bool) preg_match('/\b(last|final|latest)\b/', $lower);

        // Asking about finished flights: include them and look back over today.
        if (in_array($query->status, ['landed', 'departed', 'cancelled', 'diverted'], true)) {
            $query->upcomingOnly = false;

            if (! $hasTime) {
                $query->from = $now->startOfDay();
                $query->timeLabel = 'today';
            }
        }

        return $query;
    }

    /**
     * Sets from/until/timeLabel. Returns true when the text contained a time expression.
     */
    protected function applyTime(FlightQuery $query, string $text, CarbonImmutable $now): bool
    {
        $day = $now->startOfDay();
        $dayLabel = null;
        $explicit = false;

        if (preg_match('/\b(tomorrow|tmrw|tmr)\b/', $text)) {
            $day = $day->addDay();
            $dayLabel = 'tomorrow';
            $explicit = true;
        } elseif (preg_match('/\b(today|tonight)\b/', $text)) {
            $dayLabel = 'today';
            $explicit = true;
        }

        // Default: from now on (a little slack for flights boarding / just late).
        $from = $now->subMinutes(30);
        $until = null;

        if ($dayLabel === 'tomorrow') {
            $from = $day;
            $until = $day->endOfDay();
            $query->upcomingOnly = false;
        } elseif ($dayLabel === 'today') {
            $until = $day->endOfDay();
        }

        $periods = [
            'early morning' => [0, 6], 'morning' => [5, 12], 'noon' => [11, 13], 'afternoon' => [12, 17],
            'evening' => [17, 21], 'tonight' => [18, 24], 'night' => [20, 24],
        ];

        foreach ($periods as $word => [$start, $end]) {
            if (preg_match('/\b'.$word.'\b/', $text)) {
                $from = $day->addHours($start);
                $until = $day->addHours($end)->subSecond();
                $dayLabel = trim(($dayLabel === 'tomorrow' ? 'tomorrow ' : ($word === 'tonight' ? '' : 'this ')).$word);
                $explicit = true;
                $query->upcomingOnly = $from->gt($now);
                break;
            }
        }

        // "in the next 2 hours", "within 90 minutes", "next hour"
        if (preg_match('/\b(?:next|within|in(?: the next)?)\s+(\d+|an?|one|two|three|four|five|six)?\s*(hours?|hrs?|h|minutes?|mins?)\b/', $text, $m)) {
            $n = match ($m[1] ?? '') {
                '', 'a', 'an', 'one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5, 'six' => 6,
                default => (int) $m[1],
            };
            $minutes = str_starts_with($m[2], 'h') ? $n * 60 : $n;
            $from = $now->subMinutes(15);
            $until = $now->addMinutes($minutes);
            $dayLabel = 'in the next '.($minutes % 60 === 0 ? ($minutes / 60).' h' : $minutes.' min');
            $explicit = true;
        }

        // "after 5pm", "before 18:30", "at 3 pm", "around 14:00"
        if (preg_match('/\b(after|before|by|until|till|at|around|about)\s+(\d{1,2})(?::(\d{2}))?\s*(am|pm)?\b/', $text, $m)
            && (($m[3] ?? '') !== '' || ($m[4] ?? '') !== '' || in_array($m[1], ['after', 'before', 'by', 'until', 'till'], true))) {
            $hour = (int) $m[2];
            $minute = (int) (($m[3] ?? '') ?: 0);
            $ampm = $m[4] ?? '';

            if ($ampm === 'pm' && $hour < 12) {
                $hour += 12;
            } elseif ($ampm === 'am' && $hour === 12) {
                $hour = 0;
            }

            if ($hour <= 23 && $minute <= 59) {
                $t = $day->setTime($hour, $minute);
                $clock = $t->format('H:i');

                [$from, $until, $label] = match ($m[1]) {
                    'after' => [$t, $day->endOfDay(), "after {$clock}"],
                    'before', 'by', 'until', 'till' => [$dayLabel === 'tomorrow' ? $day : $from, $t, "before {$clock}"],
                    default => [$t->subHour(), $t->addHour(), "around {$clock}"],
                };

                $dayLabel = ($dayLabel === 'tomorrow' ? 'tomorrow ' : '').$label;
                $query->upcomingOnly = $from->gt($now);
                $explicit = true;
            }
        }

        $query->from = $from;
        $query->until = $until;
        $query->timeLabel = $dayLabel;

        return $explicit;
    }

    /**
     * Greedy longest-first phrase matching with light fuzzy tolerance.
     *
     * @param  list<string>  $tokens
     * @param  array<string, mixed>  $phrases  lower-case phrase => value
     * @param  array<int, true>  $consumed  token indexes already used
     * @return array{0: list<array{start: int, value: mixed}>, 1: array<int, true>}
     */
    protected function matchPhrases(array $tokens, array $phrases, array $consumed): array
    {
        $byLength = [];
        foreach ($phrases as $phrase => $value) {
            $byLength[substr_count($phrase, ' ') + 1][$phrase] = $value;
        }
        krsort($byLength);

        $matches = [];

        foreach ($byLength as $n => $group) {
            for ($i = 0; $i + $n <= count($tokens); $i++) {
                $span = range($i, $i + $n - 1);

                if (array_intersect_key(array_flip($span), $consumed) !== []) {
                    continue;
                }

                $gram = implode(' ', array_slice($tokens, $i, $n));
                $value = $group[$gram] ?? $this->fuzzy($gram, $group);

                if ($value !== null) {
                    $matches[] = ['start' => $i, 'value' => $value];
                    foreach ($span as $k) {
                        $consumed[$k] = true;
                    }
                }
            }
        }

        usort($matches, fn ($a, $b) => $a['start'] <=> $b['start']);

        return [$matches, $consumed];
    }

    /**
     * Allow small typos ("kaula lumpur", "singapor") on longer phrases.
     */
    protected function fuzzy(string $gram, array $group): mixed
    {
        $len = strlen($gram);

        if ($len < 5 || in_array($gram, self::STOPWORDS, true)) {
            return null;
        }

        foreach (explode(' ', $gram) as $word) {
            if (in_array($word, self::STOPWORDS, true)) {
                return null;
            }
        }

        $maxDistance = $len >= 9 ? 2 : 1;
        $best = null;
        $bestDistance = PHP_INT_MAX;

        foreach ($group as $phrase => $value) {
            if (strlen($phrase) < 5 || abs(strlen($phrase) - $len) > $maxDistance) {
                continue;
            }

            $d = levenshtein($gram, $phrase);

            if ($d <= $maxDistance && $d < $bestDistance) {
                $best = $value;
                $bestDistance = $d;
            }
        }

        return $best;
    }

    /**
     * @return array<string, array{0: string, 1: list<string>}>
     */
    protected function placePhrases(): array
    {
        $phrases = [];

        foreach ($this->places() as $code => $name) {
            $lower = mb_strtolower($name);
            $phrases[$lower] = ["{$name} ({$code})", [$code]];

            // "London" for "London Heathrow", "Kuala" is not useful but harmless.
            $first = explode(' ', $lower)[0];
            if ($first !== $lower && strlen($first) >= 5 && ! isset($phrases[$first])) {
                $phrases[$first] = [ucfirst($first), [$code]];
            }
        }

        foreach (self::PLACE_ALIASES as $alias => [$label, $codes]) {
            $phrases[$alias] ??= [count($codes) === 1 ? "{$label} ({$codes[0]})" : $label, $codes];
        }

        // Group places that share a first word ("london" => LHR + LGW).
        foreach ($this->places() as $code => $name) {
            $first = mb_strtolower(explode(' ', $name)[0]);
            if (isset($phrases[$first]) && $first !== mb_strtolower($name) && ! in_array($code, $phrases[$first][1], true)) {
                $phrases[$first][1][] = $code;
            }
        }

        return $phrases;
    }

    /**
     * @return array<string, array{0: string, 1: list<string>}>
     */
    protected function airlinePhrases(bool $fullOnly): array
    {
        $phrases = [];
        $placeWords = array_keys($this->placePhrases());

        foreach ($this->airlineNames() as $name) {
            $lower = mb_strtolower($name);

            if ($fullOnly) {
                $phrases[$lower] = [$name, [$lower]];

                continue;
            }

            $first = explode(' ', str_replace('-', ' ', $lower))[0];

            if (strlen($first) >= 4 && ! in_array($first, self::GENERIC_AIRLINE_WORDS, true) && ! in_array($first, $placeWords, true)) {
                if (isset($phrases[$first])) {
                    $phrases[$first][1][] = $lower;
                } else {
                    $phrases[$first] = [$name, [$lower]];
                }
            }
        }

        if (! $fullOnly) {
            foreach (self::AIRLINE_ALIASES as $alias => $value) {
                $phrases[$alias] ??= $value;
            }
        }

        return $phrases;
    }

    /** @var array<string, string>|null code => name */
    protected ?array $places = null;

    /** @var list<string>|null */
    protected ?array $airlineNames = null;

    /** @var array<string, string>|null code => name */
    protected ?array $airlineCodes = null;

    /**
     * @return array<string, string> airport code => name, excluding Malé
     */
    protected function places(): array
    {
        if ($this->places === null) {
            $this->places = [];

            foreach ($this->flights->all() as $f) {
                foreach ([[$f['origin_code'], $f['origin']], [$f['destination_code'], $f['destination']]] as [$code, $name]) {
                    if ($code && $name && $code !== 'MLE') {
                        $this->places[$code] ??= $name;
                    }
                }
            }
        }

        return $this->places;
    }

    /**
     * @return list<string>
     */
    protected function airlineNames(): array
    {
        return $this->airlineNames ??= $this->flights->all()->pluck('airline')->filter()->unique()->values()->all();
    }

    /**
     * @return array<string, string>
     */
    protected function airlineCodes(): array
    {
        if ($this->airlineCodes === null) {
            $this->airlineCodes = [];

            foreach ($this->flights->all() as $f) {
                if ($f['airline_code'] && $f['airline']) {
                    $this->airlineCodes[strtoupper($f['airline_code'])] ??= $f['airline'];
                }
            }
        }

        return $this->airlineCodes;
    }

    protected function clean(string $text, bool $lower): string
    {
        $text = Str::ascii($text);
        $text = preg_replace('/[^A-Za-z0-9:\-\s]/', ' ', $text);
        $text = trim(preg_replace('/\s+/', ' ', $text));

        return $lower ? mb_strtolower($text) : $text;
    }
}
