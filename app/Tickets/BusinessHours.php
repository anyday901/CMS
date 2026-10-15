<?php

namespace App\Tickets;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The business's opening hours: a start and end time for each open weekday,
 * closed dates such as public holidays, and the timezone they're in. Used
 * to count response time targets in business hours.
 */
final readonly class BusinessHours
{
    public const DAYS = ['mon' => 'Monday', 'tue' => 'Tuesday', 'wed' => 'Wednesday', 'thu' => 'Thursday', 'fri' => 'Friday', 'sat' => 'Saturday', 'sun' => 'Sunday'];

    /** How far ahead to look for opening hours, so a schedule of only holidays can't loop forever. */
    private const MAX_DAYS = 800;

    /**
     * @param  array<string, array{0: string, 1: string}>  $week  day key => [opens, closes] as HH:MM
     * @param  list<string>  $holidays  closed dates as YYYY-MM-DD
     */
    public function __construct(public array $week, public array $holidays, public string $timezone) {}

    public static function fromConfig(): self
    {
        $week = [];

        foreach ((array) config('tickets.business_hours') as $day => $hours) {
            if (isset(self::DAYS[$day]) && is_array($hours) && count($hours) === 2 && self::minutes($hours[0]) < self::minutes($hours[1])) {
                $week[$day] = [(string) $hours[0], (string) $hours[1]];
            }
        }

        $timezone = (string) config('tickets.timezone');

        return new self(
            $week,
            array_values(array_filter((array) config('tickets.holidays'), fn ($date) => is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date))),
            in_array($timezone, timezone_identifiers_list(), true) ? $timezone : 'UTC',
        );
    }

    /** Open at least one day a week. With no opening hours, targets fall back to calendar hours. */
    public function isSet(): bool
    {
        return $this->week !== [];
    }

    /**
     * The moment $hours of opening time after $from. Time before opening, after
     * closing, on closed days and on holidays doesn't count.
     */
    public function addHours(CarbonInterface $from, float $hours): CarbonImmutable
    {
        $start = CarbonImmutable::instance($from);

        if (! $this->isSet()) {
            return $start->addMinutes((int) round($hours * 60));
        }

        $remaining = (int) round($hours * 60);
        $cursor = $start->setTimezone($this->timezone);

        for ($i = 0; $i < self::MAX_DAYS; $i++) {
            $window = $this->window($cursor);

            if ($window !== null) {
                [$opens, $closes] = $window;
                $begin = $cursor->greaterThan($opens) ? $cursor : $opens;

                if ($begin->lessThan($closes)) {
                    $available = (int) $begin->diffInMinutes($closes);

                    if ($remaining <= $available) {
                        return $begin->addMinutes($remaining)->setTimezone($start->getTimezone());
                    }

                    $remaining -= $available;
                }
            }

            $cursor = $cursor->addDay()->startOfDay();
        }

        return $start->addMinutes((int) round($hours * 60));
    }

    /** Whether the business is open at this moment. */
    public function isOpen(CarbonInterface $at): bool
    {
        $local = CarbonImmutable::instance($at)->setTimezone($this->timezone);
        $window = $this->window($local);

        return $window !== null && $local->greaterThanOrEqualTo($window[0]) && $local->lessThan($window[1]);
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable}|null the day's opening and closing times, or null when closed all day */
    private function window(CarbonImmutable $day): ?array
    {
        $key = strtolower($day->format('D'));

        if (! isset($this->week[$key]) || in_array($day->toDateString(), $this->holidays, true)) {
            return null;
        }

        [$opens, $closes] = $this->week[$key];

        return [$day->setTimeFromTimeString($opens), $day->setTimeFromTimeString($closes)];
    }

    private static function minutes(mixed $time): int
    {
        return preg_match('/^(\d{1,2}):(\d{2})$/', (string) $time, $m) ? (int) $m[1] * 60 + (int) $m[2] : -1;
    }
}
