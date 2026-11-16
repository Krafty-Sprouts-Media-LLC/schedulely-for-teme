<?php
/**
 * Filename: TimeOfDay.php
 * Author: Krafty Sprouts Media, LLC
 * Description: A clock time without a date, parsed from the 12 hour or 24 hour
 *              text people type into the window settings.
 *
 * @package KraftySprouts\Schedulely
 *
 * @since   0.1.0
 */

declare(strict_types=1);

namespace KraftySprouts\Schedulely\Time;

/**
 * A time of day, held as seconds since midnight. Parsed from "5:00 PM" or "17:00".
 *
 * @since 0.1.0
 */
final readonly class TimeOfDay
{
    /**
     * @param  int  $seconds  seconds since midnight, 0 to 86399
     *
     * @since 0.1.0
     */
    public function __construct(public int $seconds) {}

    /**
     * Reads "5:00 PM", "5pm", "5 PM" or "17:45"; null when the text is no time.
     *
     * @since 0.1.0
     */
    public static function parse(string $text): ?self
    {
        if (preg_match('/^\s*(\d{1,2})(?::(\d{2}))?\s*([ap]m)?\s*$/i', $text, $m) !== 1) {
            return null;
        }

        $hour = (int) $m[1];
        $minute = isset($m[2]) && $m[2] !== '' ? (int) $m[2] : 0;
        $meridiem = isset($m[3]) ? strtolower($m[3]) : '';

        if ($minute > 59) {
            return null;
        }

        if ($meridiem !== '') {
            if ($hour < 1 || $hour > 12) {
                return null;
            }

            $hour = $hour % 12 + ($meridiem === 'pm' ? 12 : 0);
        } elseif ($hour > 23) {
            return null;
        }

        return new self($hour * 3600 + $minute * 60);
    }

    /**
     * The time as a 12 hour clock, such as "5:05 PM".
     *
     * @since 0.1.0
     */
    public function format12(): string
    {
        $hour = intdiv($this->seconds, 3600);
        $minute = intdiv($this->seconds % 3600, 60);

        return sprintf('%d:%02d %s', $hour % 12 === 0 ? 12 : $hour % 12, $minute, $hour < 12 ? 'AM' : 'PM');
    }
}
