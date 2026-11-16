<?php
/**
 * Filename: ScheduledItem.php
 * Author: Krafty Sprouts Media, LLC
 * Description: One item a run scheduled.
 *
 * @package KraftySprouts\Schedulely
 *
 * @since   0.1.0
 */

declare(strict_types=1);

namespace KraftySprouts\Schedulely\Engine;

/**
 * An item, the instant it was scheduled for, and the window it falls in.
 *
 * @since 0.1.0
 */
final readonly class ScheduledItem
{
    /**
     * @param  string  $window  the window's anchor date, Y-m-d
     *
     * @since 0.1.0
     */
    public function __construct(
        public string $id,
        public string $title,
        public int $at,
        public string $window,
    ) {}
}
