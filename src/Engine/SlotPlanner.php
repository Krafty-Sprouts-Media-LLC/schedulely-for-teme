<?php
/**
 * Filename: SlotPlanner.php
 * Author: Krafty Sprouts Media, LLC
 * Description: Chooses publish instants inside one day's window: precomputed even
 *              slots for Sequential and Hybrid, trial and error for Random.
 *
 * @package KraftySprouts\Schedulely
 * @since   0.1.0
 */

declare(strict_types=1);

namespace KraftySprouts\Schedulely\Engine;

use KraftySprouts\Schedulely\Time\PublishingWindow;
use Random\Randomizer;

/**
 * Picks the instants posts are published at. Randomness comes from the injected
 * Randomizer, so a seeded one repeats exactly.
 *
 * @since 0.1.0
 */
final readonly class SlotPlanner
{
    /** Random placement always tries at least this many times. */
    private const int BASE_ATTEMPTS = 200;

    /** And this many more for each time already used that day. */
    private const int ATTEMPTS_PER_USED_TIME = 50;

    /**
     * @param  int  $safetyBufferSeconds  nothing is placed sooner than this after "now"
     * @param  int  $minIntervalSeconds  least gap between two Random times
     *
     * @since 0.1.0
     */
    public function __construct(
        private PublishingWindow $window,
        private Randomizer $random,
        private int $safetyBufferSeconds,
        private int $minIntervalSeconds,
    ) {}

    /**
     * The day's slots, one per post of the quota, in order. Sequential takes each
     * slot's start; Hybrid takes a random second inside each. Fewer, or none, when
     * the window has mostly or wholly passed.
     *
     * @return list<int> Unix timestamps
     *
     * @since 0.1.0
     */
    public function daySlots(string $anchor, int $quota, SchedulingMode $mode, int $now): array
    {
        [$start, $end] = $this->window->bounds($anchor);

        if ($start >= $end || $quota < 1) {
            return [];
        }

        $floor = max($start, $now + max(0, $this->safetyBufferSeconds));

        if ($floor > $end) {
            return [];
        }

        $width = max(1, intdiv($end - $floor, $quota));
        $slots = [];

        for ($i = 0; $i < $quota; $i++) {
            $slotStart = $floor + $i * $width;
            $slotEnd = min($end, $slotStart + $width - 1);

            if ($slotStart > $end) {
                break;
            }

            $slots[] = ($mode === SchedulingMode::Hybrid && $slotStart < $slotEnd)
                ? $this->random->getInt($slotStart, $slotEnd)
                : $slotStart;
        }

        return $slots;
    }

    /**
     * A random instant in the day's window at least the minimum interval away from
     * every used instant, or null when none is found within the attempt budget or the
     * window has closed.
     *
     * @param  list<int>  $usedTimestamps  instants already taken that day
     * @param  array{0: int, 1: int}|null  $band  a sub-range to prefer (a timezone's active hours); ignored when it misses the window
     *
     * @since 0.1.0
     */
    public function randomTime(string $anchor, array $usedTimestamps, int $now, ?array $band = null): ?int
    {
        [$start, $end] = $this->window->bounds($anchor);

        if ($start >= $end) {
            return null;
        }

        if ($band !== null) {
            $bandStart = max($start, $band[0]);
            $bandEnd = min($end, $band[1]);

            if ($bandStart < $bandEnd) {
                [$start, $end] = [$bandStart, $bandEnd];
            }
        }

        $floor = max($start, $now + max(0, $this->safetyBufferSeconds));

        if ($floor > $end) {
            return null;
        }

        $attempts = self::BASE_ATTEMPTS + count($usedTimestamps) * self::ATTEMPTS_PER_USED_TIME;

        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            $candidate = $this->random->getInt($floor, $end);

            if ($this->clearOfUsed($candidate, $usedTimestamps)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param  list<int>  $used
     */
    private function clearOfUsed(int $candidate, array $used): bool
    {
        foreach ($used as $taken) {
            if ($candidate === $taken || abs($candidate - $taken) < $this->minIntervalSeconds) {
                return false;
            }
        }

        return true;
    }
}
