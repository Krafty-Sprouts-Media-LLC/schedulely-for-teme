<?php
/**
 * Filename: SiteCalendar.php
 * Author: Krafty Sprouts Media, LLC
 * Description: Calendar maths in the site's timezone: local date and time to
 *              timestamp, calendar days that survive daylight saving, weekdays.
 *
 * @package KraftySprouts\Schedulely
 * @since   0.1.0
 */

declare(strict_types=1);

namespace KraftySprouts\Schedulely\Time;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Turns site-local dates and times into timestamps and back. Every date here is
 * a plain Y-m-d string in the site's own timezone, never the server's.
 *
 * @since 0.1.0
 */
final readonly class SiteCalendar
{
    /** @since 0.1.0 */
    public function __construct(private DateTimeZone $timezone) {}

    /**
     * Builds the calendar for a timezone name, UTC when the name is unknown.
     *
     * @since 0.1.0
     */
    public static function fromName(string $name): self
    {
        try {
            return new self(new DateTimeZone($name));
        } catch (Throwable) {
            return new self(new DateTimeZone('UTC'));
        }
    }

    /** @since 0.1.0 */
    public function timezone(): DateTimeZone
    {
        return $this->timezone;
    }

    /**
     * The instant a site-local date and time falls on; null when the date is not a date.
     *
     * @since 0.1.0
     */
    public function timestamp(string $ymd, TimeOfDay $time): ?int
    {
        $day = $this->parseDay($ymd);

        if ($day === null) {
            return null;
        }

        $hour = intdiv($time->seconds, 3600);
        $minute = intdiv($time->seconds % 3600, 60);
        $second = $time->seconds % 60;

        return $day->setTime($hour, $minute, $second)->getTimestamp();
    }

    /**
     * The date a number of calendar days away, counted from local noon so a
     * daylight-saving change never skips or repeats a day.
     *
     * @since 0.1.0
     */
    public function addDays(string $ymd, int $days): string
    {
        $day = $this->parseDay($ymd);

        if ($day === null) {
            return $ymd;
        }

        return $day->setTime(12, 0)->modify(sprintf('%+d days', $days))->format('Y-m-d');
    }

    /**
     * The weekday of a date, Sunday 0 through Saturday 6.
     *
     * @since 0.1.0
     */
    public function weekday(string $ymd): int
    {
        $day = $this->parseDay($ymd);

        return $day === null ? 0 : (int) $day->setTime(12, 0)->format('w');
    }

    /**
     * Formats an instant in the site's timezone.
     *
     * @since 0.1.0
     */
    public function format(string $format, int $timestamp): string
    {
        return (new DateTimeImmutable('@'.$timestamp))->setTimezone($this->timezone)->format($format);
    }

    /**
     * Today's date on the site's calendar.
     *
     * @since 0.1.0
     */
    public function today(int $now): string
    {
        return $this->format('Y-m-d', $now);
    }

    private function parseDay(string $ymd): ?DateTimeImmutable
    {
        $day = DateTimeImmutable::createFromFormat('!Y-m-d', $ymd, $this->timezone);

        if ($day === false || $day->format('Y-m-d') !== $ymd) {
            return null;
        }

        return $day;
    }
}
