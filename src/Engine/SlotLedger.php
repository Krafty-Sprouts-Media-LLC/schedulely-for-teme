<?php
/**
 * Filename: SlotLedger.php
 * Author: Krafty Sprouts Media, LLC
 * Description: What the scheduler may ask about items that are already scheduled.
 *
 * @package KraftySprouts\Schedulely
 *
 * @since   0.1.0
 */

declare(strict_types=1);

namespace KraftySprouts\Schedulely\Engine;

/**
 * Teme offers a plugin no way to list scheduled items, so Schedulely keeps this
 * ledger itself, from the hooks that tell it of each schedule.
 *
 * @since 0.1.0
 */
interface SlotLedger
{
    /**
     * The latest scheduled publish time of the types Schedulely manages, or null when none.
     *
     * @since 0.1.0
     */
    public function latest(): ?int;

    /**
     * Every scheduled publish time from one instant to another, both included.
     *
     * @return list<int> Unix timestamps
     *
     * @since 0.1.0
     */
    public function between(int $from, int $to): array;
}
