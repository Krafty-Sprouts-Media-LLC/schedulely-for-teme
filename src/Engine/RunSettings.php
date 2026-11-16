<?php
/**
 * Filename: RunSettings.php
 * Author: Krafty Sprouts Media, LLC
 * Description: The settings one scheduling run reads.
 *
 * @package KraftySprouts\Schedulely
 * @since   0.1.0
 */

declare(strict_types=1);

namespace KraftySprouts\Schedulely\Engine;

/**
 * Quota, mode, active weekdays and safety buffer, fixed for the length of a run.
 *
 * @since 0.1.0
 */
final readonly class RunSettings
{
    /**
     * @param  int  $quota  items per day
     * @param  list<int>  $activeDays  weekdays posts may be placed on, Sunday 0 through Saturday 6
     * @param  int  $safetyBufferSeconds  nothing is scheduled sooner than this from now
     *
     * @since 0.1.0
     */
    public function __construct(
        public int $quota,
        public SchedulingMode $mode,
        public array $activeDays,
        public int $safetyBufferSeconds,
    ) {}
}
