<?php
/**
 * Filename: TestClock.php
 * Author: Krafty Sprouts Media, LLC
 * Description: A clock a test can set and move.
 *
 * @package KraftySprouts\Schedulely
 *
 * @since   0.1.0
 */

declare(strict_types=1);

namespace KraftySprouts\Schedulely\Tests\Support;

/**
 * The time a test says it is, in Unix seconds.
 *
 * @since 0.1.0
 */
final class TestClock
{
    public function __construct(public int $time) {}

    /** @since 0.1.0 */
    public function now(): int
    {
        return $this->time;
    }

    /**
     * Moves the clock on by this many seconds.
     *
     * @since 0.1.0
     */
    public function advance(int $seconds): void
    {
        $this->time += $seconds;
    }
}
