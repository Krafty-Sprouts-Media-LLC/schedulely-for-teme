<?php
/**
 * Filename: PublishingWindow.php
 * Author: Krafty Sprouts Media, LLC
 * Description: The daily publishing window, same-day or overnight, anchored on
 *              the day it opens.
 *
 * @package KraftySprouts\Schedulely
 *
 * @since   0.1.0
 */

declare(strict_types=1);

namespace KraftySprouts\Schedulely\Time;

/**
 * The span of each day posts may be published in. An overnight window (end at or
 * before start) is named by the date it opens on, its "anchor", and ends the
 * next morning.
 *
 * @since 0.1.0
 */
final readonly class PublishingWindow
{
    /** @since 0.1.0 */
    public function __construct(
        private SiteCalendar $calendar,
        private TimeOfDay $start,
        private TimeOfDay $end,
    ) {}

    /**
     * Whether the window ends the morning after it opens.
     *
     * @since 0.1.0
     */
    public function isOvernight(): bool
    {
        return $this->end->seconds <= $this->start->seconds;
    }

    /**
     * Start and end instants, both inclusive, of the window anchored on a date.
     * [0, 0] when the date is not a date.
     *
     * @return array{0: int, 1: int}
     *
     * @since 0.1.0
     */
    public function bounds(string $anchor): array
    {
        $start = $this->calendar->timestamp($anchor, $this->start);

        if ($start === null) {
            return [0, 0];
        }

        $endDay = $this->isOvernight() ? $this->calendar->addDays($anchor, 1) : $anchor;
        $end = $this->calendar->timestamp($endDay, $this->end);

        return $end === null ? [0, 0] : [$start, $end];
    }

    /**
     * The anchor date of the window an instant belongs to. An instant inside no
     * window is named by its own calendar date.
     *
     * @since 0.1.0
     */
    public function anchorFor(int $timestamp): string
    {
        $day = $this->calendar->format('Y-m-d', $timestamp);

        if (! $this->isOvernight()) {
            return $day;
        }

        if ($this->contains($timestamp, $day)) {
            return $day;
        }

        $previous = $this->calendar->addDays($day, -1);

        return $this->contains($timestamp, $previous) ? $previous : $day;
    }

    /**
     * Whether an instant lies inside the window anchored on a date, ends included.
     *
     * @since 0.1.0
     */
    public function contains(int $timestamp, string $anchor): bool
    {
        [$start, $end] = $this->bounds($anchor);

        return $timestamp >= $start && $timestamp <= $end;
    }

    /**
     * The window's length in minutes, or 0 when it cannot be drawn.
     *
     * @since 0.1.0
     */
    public function minutes(string $anchor): float
    {
        [$start, $end] = $this->bounds($anchor);

        return $end > $start ? ($end - $start) / 60 : 0.0;
    }
}
