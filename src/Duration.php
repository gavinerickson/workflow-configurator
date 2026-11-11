<?php

namespace WorkflowConfigurator;

/**
 * ISO-8601 durations as an operator reads and writes them
 * (specs/DynamicWorkflows.md §10.3): a number and a unit on the way in,
 * words on the way out. The stored value stays the ISO string the sweep
 * already parses (§8.3) — this only translates.
 */
final class Duration
{
    /** Unit → [ISO prefix, ISO designator], in the order the form offers them. */
    private const UNITS = [
        'seconds' => ['PT', 'S'],
        'minutes' => ['PT', 'M'],
        'hours' => ['PT', 'H'],
        'days' => ['P', 'D'],
        'weeks' => ['P', 'W'],
    ];

    /**
     * @return list<string>
     */
    public static function units(): array
    {
        return array_keys(self::UNITS);
    }

    /**
     * Days are written as days, never as hours: P1D and PT24H differ across
     * a clock change, which is why §8.3 chose ISO durations in the first place.
     */
    public static function toIso(int $amount, string $unit): ?string
    {
        if ($amount < 1 || !isset(self::UNITS[$unit])) {
            return null;
        }

        [$prefix, $designator] = self::UNITS[$unit];

        return $prefix.$amount.$designator;
    }

    /**
     * A duration of one component in an offered unit, as that number and
     * unit; null for anything else (composite durations, months, years),
     * which the form shows as an exact ISO value instead.
     *
     * @return array{amount: int, unit: string}|null
     */
    public static function split(string $iso): ?array
    {
        foreach (self::UNITS as $unit => [$prefix, $designator]) {
            if (1 === preg_match('/^'.$prefix.'([1-9][0-9]*)'.$designator.'$/', $iso, $match)) {
                return ['amount' => (int) $match[1], 'unit' => $unit];
            }
        }

        return null;
    }

    public static function parse(string $iso): ?\DateInterval
    {
        try {
            return new \DateInterval($iso);
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Under a minute is "straight away" to an operator: the wait is shorter
     * than the gap between two deadline sweeps (§8.4).
     */
    public static function isStraightAway(\DateInterval $interval): bool
    {
        return 0 === $interval->y && 0 === $interval->m && 0 === $interval->d
            && 0 === $interval->h && 0 === $interval->i;
    }

    /** "3 days", "1 hour", "1 day 12 hours". */
    public static function words(\DateInterval $interval): string
    {
        $parts = [
            'year' => $interval->y, 'month' => $interval->m, 'day' => $interval->d,
            'hour' => $interval->h, 'minute' => $interval->i, 'second' => $interval->s,
        ];

        $said = [];
        foreach ($parts as $unit => $amount) {
            if ($amount > 0) {
                $said[] = $amount.' '.$unit.(1 === $amount ? '' : 's');
            }
        }

        return [] === $said ? 'no time' : implode(' ', $said);
    }
}
