<?php
/**
 * Filename: Scheduler.php
 * Author: Krafty Sprouts Media, LLC
 * Description: One scheduling run: finds where to start, fills the furthest day
 *              first, gives each queued item a time and rolls to the next active day.
 *
 * @package KraftySprouts\Schedulely
 *
 * @since   0.1.0
 */

declare(strict_types=1);

namespace KraftySprouts\Schedulely\Engine;

use KraftySprouts\Schedulely\Ordering\UsTimezones;
use KraftySprouts\Schedulely\Queue\QueueItem;
use KraftySprouts\Schedulely\Support\Words;
use KraftySprouts\Schedulely\Time\PublishingWindow;
use KraftySprouts\Schedulely\Time\SiteCalendar;

/**
 * The engine. It takes an ordered queue and "now", and writes through ItemScheduler.
 * It knows nothing of Teme, so every rule of WordPress's Schedulely_Scheduler is
 * testable on its own.
 *
 * @since 0.1.0
 */
final readonly class Scheduler
{
    /** A Random write that fails is retried this many times with a different time. */
    private const int RANDOM_RETRIES = 2;

    /** How many days ahead the first window is looked for before giving up and using today. */
    private const int LOOKAHEAD_DAYS = 21;

    /** @since 0.1.0 */
    public function __construct(
        private PublishingWindow $window,
        private SiteCalendar $calendar,
        private SlotPlanner $planner,
        private RunSettings $settings,
        private SlotLedger $ledger,
        private ItemScheduler $writer,
        private ActingPeople $people,
    ) {}

    /**
     * @param  list<QueueItem>  $queue  in the order they should be published
     * @param  int  $now  Unix time of the run
     * @param  (callable(int, int): void)|null  $progress  called with items done and total; may throw JobStopped to end the run
     * @param  int  $progressEvery  how many items between progress calls
     * @param  array<string, string>|null  $groups  item ID to US timezone group, when timezone ordering is on
     *
     * @since 0.1.0
     */
    public function run(array $queue, int $now, ?callable $progress = null, int $progressEvery = 25, ?array $groups = null): RunReport
    {
        $queue = array_values($queue);
        $total = count($queue);

        if ($total === 0) {
            return new RunReport(false, 0, 0, 0, false, Words::say('No items are waiting to be scheduled.'));
        }

        [$date, $completeFirst, $completedLast] = $this->resolveStart($now);
        $quota = max(1, $this->settings->quota);
        $slotMode = $this->settings->mode->usesSlots();

        $today = $completeFirst > 0 ? $quota - $completeFirst : 0;
        $used = [];
        $slots = [];
        $slotIndex = 0;

        if ($completeFirst > 0) {
            [$from, $to] = $this->window->bounds($date);
            $used = $this->ledger->between($from, $to);
        }

        if ($slotMode) {
            $slots = $this->planner->daySlots($date, $quota, $this->settings->mode, $now);
            $slotIndex = $today;
        }

        $scheduled = [];
        $errors = [];
        $processed = 0;

        foreach ($queue as $item) {
            if ($today >= $quota) {
                $date = $this->nextActiveDate($date);
                $today = 0;
                $used = [];

                if ($slotMode) {
                    $slots = $this->planner->daySlots($date, $quota, $this->settings->mode, $now);
                    $slotIndex = 0;
                }
            }

            $at = null;

            if ($slotMode) {
                $at = $slots[$slotIndex] ?? null;
                $slotIndex++;

                if ($at === null) {
                    $errors[] = Words::say('No slot was left for item :id; the day is full. Raise the quota or widen the window.', ['id' => $item->id]);
                    $today++;
                    $this->tick($progress, $progressEvery, ++$processed, $total);

                    continue;
                }
            } else {
                $band = $this->band($groups, $item, $date);
                $at = $this->planner->randomTime($date, $used, $now, $band);

                if ($at === null) {
                    $date = $this->nextActiveDate($date);
                    $today = 0;
                    $used = [];
                    $at = $this->planner->randomTime($date, [], $now, $this->band($groups, $item, $date));

                    if ($at === null) {
                        $errors[] = Words::say('No time could be found for item :id.', ['id' => $item->id]);
                        $this->tick($progress, $progressEvery, ++$processed, $total);

                        continue;
                    }
                }
            }

            $person = $this->people->pick();
            $failure = $this->write($item, $at, $person, $now);

            if ($failure === null) {
                $scheduled[] = new ScheduledItem($item->id, $item->title, $at, $date);
                $used[] = $at;
                $today++;
            } elseif ($slotMode) {
                $errors[] = Words::say('Item :id could not be scheduled: :reason', ['id' => $item->id, 'reason' => $failure]);
                $today++;
            } else {
                $retry = $this->retry($item, $date, $used, $now, $person);

                if ($retry === null) {
                    $errors[] = Words::say('Item :id could not be scheduled after :attempts attempts: :reason', [
                        'id' => $item->id, 'attempts' => self::RANDOM_RETRIES + 1, 'reason' => $failure,
                    ]);
                    $today++;
                } else {
                    $scheduled[] = new ScheduledItem($item->id, $item->title, $retry, $date);
                    $used[] = $retry;
                    $today++;
                }
            }

            $this->tick($progress, $progressEvery, ++$processed, $total);
        }

        return $this->report($scheduled, $errors, $total, $completedLast, $groups);
    }

    /**
     * Where the run starts: the furthest scheduled window when it is short of its quota
     * and still open (the "deficit"), the day after it when it is full, otherwise the
     * next window that can still take items.
     *
     * @return array{0: string, 1: int, 2: bool} the anchor date, how many items the first day still needs, and whether that is a deficit
     */
    private function resolveStart(int $now): array
    {
        $quota = max(1, $this->settings->quota);
        $latest = $this->ledger->latest();
        $start = $this->nextSchedulingDate($now);

        if ($latest === null) {
            return [$start, 0, false];
        }

        $last = $this->window->anchorFor($latest);
        [$from, $to] = $this->window->bounds($last);
        $count = count($this->ledger->between($from, $to));

        if ($count < $quota && $to >= $now) {
            return [$last, $quota - $count, true];
        }

        if ($count >= $quota) {
            return [$this->nextActiveDate($last), 0, false];
        }

        return [$start, 0, false];
    }

    /**
     * The first window that has not closed, on an active day. Yesterday's window counts
     * too, so an overnight window that opened last evening is still used this morning.
     */
    private function nextSchedulingDate(int $now): string
    {
        $today = $this->calendar->today($now);
        $yesterday = $this->calendar->addDays($today, -1);

        foreach ([$yesterday, $today] as $anchor) {
            if ($this->isActive($anchor) && $this->window->contains($now, $anchor)) {
                return $anchor;
            }
        }

        $date = $today;

        for ($i = 0; $i < self::LOOKAHEAD_DAYS; $i++) {
            if (! $this->isActive($date)) {
                $date = $this->nextActiveDate($date);

                continue;
            }

            if ($this->window->bounds($date)[1] < $now) {
                $date = $this->nextActiveDate($date);

                continue;
            }

            return $date;
        }

        return $today;
    }

    private function nextActiveDate(string $date): string
    {
        $next = $this->calendar->addDays($date, 1);

        for ($attempts = 0; $attempts < 7; $attempts++) {
            if ($this->isActive($next)) {
                return $next;
            }

            $next = $this->calendar->addDays($next, 1);
        }

        return $next;
    }

    private function isActive(string $date): bool
    {
        return in_array($this->calendar->weekday($date), $this->settings->activeDays, true);
    }

    /**
     * @param  array<string, string>|null  $groups
     *
     * @return array{0: int, 1: int}|null
     */
    private function band(?array $groups, QueueItem $item, string $date): ?array
    {
        if ($groups === null) {
            return null;
        }

        return UsTimezones::overlap($this->window, $date, $groups[$item->id] ?? 'general');
    }

    /**
     * Refuses a time too close to now, then writes. Null when the item was scheduled.
     */
    private function write(QueueItem $item, int $at, string $person, int $now): ?string
    {
        if ($at < $now + max(0, $this->settings->safetyBufferSeconds)) {
            return Words::say('The time is less than :minutes minutes from now.', ['minutes' => intdiv($this->settings->safetyBufferSeconds, 60)]);
        }

        return $this->writer->schedule($item, $at, $person);
    }

    /**
     * @param  list<int>  $used
     *
     * @return int|null the time the item was scheduled for, or null when every retry failed
     */
    private function retry(QueueItem $item, string $date, array $used, int $now, string $person): ?int
    {
        for ($attempt = 1; $attempt <= self::RANDOM_RETRIES; $attempt++) {
            $at = $this->planner->randomTime($date, $used, $now);

            if ($at === null) {
                return null;
            }

            if ($this->write($item, $at, $person, $now) === null) {
                return $at;
            }
        }

        return null;
    }

    /**
     * @param  (callable(int, int): void)|null  $progress
     */
    private function tick(?callable $progress, int $every, int $done, int $total): void
    {
        if ($progress !== null && ($done % max(1, $every) === 0 || $done === $total)) {
            $progress($done, $total);
        }
    }

    /**
     * @param  list<ScheduledItem>  $scheduled
     * @param  list<string>  $errors
     * @param  array<string, string>|null  $groups
     */
    private function report(array $scheduled, array $errors, int $total, bool $completedLast, ?array $groups): RunReport
    {
        $count = count($scheduled);
        $dates = array_values(array_unique(array_map(static fn (ScheduledItem $s): string => $s->window, $scheduled)));
        sort($dates);

        $distribution = $groups !== null && $count > 0
            ? UsTimezones::distribution($groups, array_map(static fn (ScheduledItem $s): string => $s->id, $scheduled))
            : null;

        return new RunReport(
            success: $count > 0,
            scheduledCount: $count,
            queueTotal: $total,
            failedCount: max(0, $total - $count),
            completedLastDate: $completedLast,
            message: Words::say('Scheduled :count of :total queued items.', ['count' => $count, 'total' => $total]),
            scheduled: $scheduled,
            errors: $errors,
            dates: $dates,
            lastPostAt: $count > 0 ? max(array_map(static fn (ScheduledItem $s): int => $s->at, $scheduled)) : null,
            timezoneDistribution: $distribution,
        );
    }
}
