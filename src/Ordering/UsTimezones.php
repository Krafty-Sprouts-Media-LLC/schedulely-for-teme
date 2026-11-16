<?php
/**
 * Filename: UsTimezones.php
 * Author: Krafty Sprouts Media, LLC
 * Description: Timezone-aware ordering: which US timezone an item is aimed at, and
 *              the part of the publishing window that lies in that zone's waking hours.
 *
 * @package KraftySprouts\Schedulely
 *
 * @since   0.1.0
 */

declare(strict_types=1);

namespace KraftySprouts\Schedulely\Ordering;

use DateTimeImmutable;
use DateTimeZone;
use KraftySprouts\Schedulely\Time\PublishingWindow;

/**
 * Detects the target state in a title and slug, and clips the window to 7 AM to
 * 11 PM in that state's zone, so an item for a Pacific audience is not published
 * while the Pacific coast is asleep.
 *
 * @since 0.1.0
 */
final class UsTimezones
{
    /** @var array<string, string> zone group to a representative IANA timezone, for daylight saving */
    private const array ZONE_NAMES = [
        'eastern' => 'America/New_York',
        'central' => 'America/Chicago',
        'mountain' => 'America/Denver',
        'pacific' => 'America/Los_Angeles',
    ];

    /** First waking hour, local time. */
    private const int WAKE_HOUR = 7;

    /** Last waking hour, local time. */
    private const int SLEEP_HOUR = 23;

    /**
     * The timezone group an item is aimed at: eastern, central, mountain, pacific,
     * or general when no state is named. Whole state names only, so "arkansas" is not
     * "kansas" and "indianapolis" is not "indiana".
     *
     * @since 0.1.0
     */
    public static function classify(string $title, string $slug = ''): string
    {
        $hay = ' '.trim((string) preg_replace('/[^a-z]+/', ' ', strtolower($title.' '.$slug))).' ';

        foreach ([' district of columbia ', ' washington dc ', ' washington d c '] as $dc) {
            if (str_contains($hay, $dc)) {
                return 'eastern';
            }
        }

        foreach (UsStates::BY_ZONE as $zone => $states) {
            foreach ($states as $state) {
                if (str_contains($hay, ' '.$state.' ')) {
                    return $zone;
                }
            }
        }

        return 'general';
    }

    /**
     * The part of an anchor day's window inside the group's waking hours. When the
     * group is asleep for the whole window, or is general, the whole window, so
     * scheduling never fails for want of an overlap.
     *
     * @return array{0: int, 1: int} Unix start and end
     *
     * @since 0.1.0
     */
    public static function overlap(PublishingWindow $window, string $anchor, string $group): array
    {
        $bounds = $window->bounds($anchor);
        [$start, $end] = $bounds;

        if (! isset(self::ZONE_NAMES[$group]) || $start >= $end) {
            return $bounds;
        }

        $zone = new DateTimeZone(self::ZONE_NAMES[$group]);
        $first = (new DateTimeImmutable('@'.$start))->setTimezone($zone)->setTime(12, 0);
        $best = null;

        foreach ([-1, 0, 1] as $offset) {
            $day = $first->modify(sprintf('%+d days', $offset));
            $wake = $day->setTime(self::WAKE_HOUR, 0)->getTimestamp();
            $sleep = $day->setTime(self::SLEEP_HOUR, 0)->getTimestamp();
            $from = max($start, $wake);
            $to = min($end, $sleep);

            if ($from < $to && ($best === null || $to - $from > $best[1] - $best[0])) {
                $best = [$from, $to];
            }
        }

        return $best ?? $bounds;
    }

    /**
     * How many of a run's items were aimed at each group.
     *
     * @param  array<string, string>  $groupById  item ID to group
     * @param  list<string>  $ids  the items the run scheduled
     *
     * @return array{eastern: int, central: int, mountain: int, pacific: int, general: int}
     *
     * @since 0.1.0
     */
    public static function distribution(array $groupById, array $ids): array
    {
        $counts = ['eastern' => 0, 'central' => 0, 'mountain' => 0, 'pacific' => 0, 'general' => 0];

        foreach ($ids as $id) {
            $group = $groupById[$id] ?? 'general';
            $counts[isset($counts[$group]) ? $group : 'general']++;
        }

        return $counts;
    }
}
