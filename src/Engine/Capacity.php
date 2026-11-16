<?php
/**
 * Filename: Capacity.php
 * Author: Krafty Sprouts Media, LLC
 * Description: Works out how many items a window and minimum interval can hold in
 *              a day, and suggests changes when the daily quota does not fit.
 *
 * @package KraftySprouts\Schedulely
 *
 * @since   0.1.0
 */

declare(strict_types=1);

namespace KraftySprouts\Schedulely\Engine;

use KraftySprouts\Schedulely\Support\Words;
use KraftySprouts\Schedulely\Time\PublishingWindow;
use KraftySprouts\Schedulely\Time\SiteCalendar;
use KraftySprouts\Schedulely\Time\TimeOfDay;

/**
 * The capacity check. Slot modes lose nothing to collisions, Random mode loses
 * more the shorter the interval, and a long window gives Random more room.
 *
 * @since 0.1.0
 */
final readonly class Capacity
{
    /** The latest a same-day window may end, in seconds after midnight. */
    private const int LAST_MINUTE = 23 * 3600 + 59 * 60;

    /** @since 0.1.0 */
    public function __construct(private SiteCalendar $calendar, private SchedulingMode $mode) {}

    /**
     * @param  string  $today  the site's current date, Y-m-d, used to draw the window
     *
     * @since 0.1.0
     */
    public function calculate(?TimeOfDay $start, ?TimeOfDay $end, int $minInterval, int $quota, string $today): CapacityReport
    {
        $minInterval = max(1, $minInterval);

        if ($start === null || $end === null) {
            return $this->invalid($quota, $minInterval, Words::say('A time could not be read. Use a time such as 5:00 PM.'));
        }

        $window = new PublishingWindow($this->calendar, $start, $end);
        $overnight = $window->isOvernight();
        [$startTs, $endTs] = $window->bounds($today);

        if ($startTs === 0 || $endTs <= $startTs) {
            return $this->invalid($quota, $minInterval, $overnight
                ? Words::say('Invalid overnight window. End time on the next morning must be after the start time on the first day.')
                : Words::say('Invalid time window. End time must be after start time on the same day.'));
        }

        $totalMinutes = ($endTs - $startTs) / 60;
        $theoretical = (int) floor($totalMinutes / $minInterval);
        $efficiency = $this->efficiency($minInterval, $totalMinutes);
        $capacity = max(1, (int) floor($theoretical * $efficiency));

        if ($theoretical <= 3) {
            $capacity = max(1, $theoretical - 1);
        }

        $meets = $capacity >= $quota;
        $suggestions = $meets ? [] : $this->suggest(
            $start, $end, $minInterval, $quota, $capacity, $efficiency, $totalMinutes, $overnight, $startTs, $endTs, $today,
        );

        return new CapacityReport(true, $capacity, $quota, $meets, $totalMinutes, $minInterval, null, $suggestions);
    }

    private function invalid(int $quota, int $minInterval, string $error): CapacityReport
    {
        return new CapacityReport(false, 0, $quota, false, 0.0, $minInterval, $error);
    }

    private function efficiency(int $minInterval, float $totalMinutes): float
    {
        if ($this->mode === SchedulingMode::Sequential) {
            return 1.0;
        }

        if ($this->mode === SchedulingMode::Hybrid) {
            return 0.95;
        }

        $efficiency = self::randomEfficiency($minInterval);

        return $totalMinutes >= 12 * 60 ? min(0.70, $efficiency + 0.10) : $efficiency;
    }

    private static function randomEfficiency(int $interval): float
    {
        return match (true) {
            $interval >= 60 => 0.70,
            $interval >= 30 => 0.65,
            $interval >= 20 => 0.55,
            default => 0.50,
        };
    }

    /**
     * @return list<Suggestion>
     */
    private function suggest(
        TimeOfDay $start,
        TimeOfDay $end,
        int $minInterval,
        int $quota,
        int $capacity,
        float $efficiency,
        float $totalMinutes,
        bool $overnight,
        int $startTs,
        int $endTs,
        string $today,
    ): array {
        $suggestions = [];

        $targetTheoretical = (int) ceil($quota / $efficiency);
        $neededInterval = (int) floor($totalMinutes / $targetTheoretical);

        if ($neededInterval > 0 && $neededInterval < $minInterval) {
            $realistic = max(1, (int) floor(floor($totalMinutes / $neededInterval) * self::randomEfficiency($neededInterval)));
            $suggestions[] = new Suggestion(
                'reduce_interval',
                Words::say('Change interval from :from to :to minutes, which fits about :count posts.', [
                    'from' => $minInterval, 'to' => $neededInterval, 'count' => $realistic,
                ]),
                suggested: $neededInterval,
                newCapacity: $realistic,
            );
        }

        $suggestions[] = new Suggestion(
            'reduce_quota',
            Words::say('Lower the quota from :from to :to posts per day.', ['from' => $quota, 'to' => $capacity]),
            suggested: $capacity,
        );

        $neededMinutes = $targetTheoretical * $minInterval;
        $neededHours = (int) ceil($neededMinutes / 60);
        $minutesToAdd = $neededMinutes - $totalMinutes;

        if ($overnight) {
            $suggestions[] = new Suggestion(
                'expand_window',
                Words::say('Widen the overnight window by starting earlier on the first day or ending later the next morning; about :hours hours of span helps.', ['hours' => $neededHours]),
                suggestedStart: $start->format12(),
                suggestedEnd: $end->format12(),
            );

            return $suggestions;
        }

        $lastTs = $this->calendar->timestamp($today, new TimeOfDay(self::LAST_MINUTE)) ?? $endTs;
        $roomAtEnd = ($lastTs - $endTs) / 60;
        $suggestedStart = $start->format12();
        $suggestedEnd = $end->format12();

        if ($minutesToAdd <= $roomAtEnd) {
            $suggestedEnd = $this->calendar->format('g:i A', (int) ($endTs + $minutesToAdd * 60));
            $message = Words::say('Extend the end time from :start to :end to :newEnd, about :hours hours in all.', [
                'start' => $suggestedStart, 'end' => $end->format12(), 'newEnd' => $suggestedEnd, 'hours' => $neededHours,
            ]);
        } elseif ($roomAtEnd > 0) {
            $suggestedEnd = '11:59 PM';
            $suggestedStart = $this->calendar->format('g:i A', (int) ($startTs - ($minutesToAdd - $roomAtEnd) * 60));
            $message = Words::say('Start earlier, at :newStart, and extend the end to 11:59 PM, about :hours hours in all.', [
                'newStart' => $suggestedStart, 'hours' => $neededHours,
            ]);
        } else {
            $suggestedStart = $this->calendar->format('g:i A', (int) ($startTs - $minutesToAdd * 60));
            $message = Words::say('Start earlier, at :newStart. The end time cannot pass 11:59 PM; about :hours hours are needed.', [
                'newStart' => $suggestedStart, 'hours' => $neededHours,
            ]);
        }

        $suggestions[] = new Suggestion('expand_window', $message, suggestedStart: $suggestedStart, suggestedEnd: $suggestedEnd);

        return $suggestions;
    }
}
