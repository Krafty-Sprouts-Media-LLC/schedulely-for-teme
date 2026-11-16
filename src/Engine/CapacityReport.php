<?php
/**
 * Filename: CapacityReport.php
 * Author: Krafty Sprouts Media, LLC
 * Description: The answer to "does this window hold this many posts a day?".
 *
 * @package KraftySprouts\Schedulely
 *
 * @since   0.1.0
 */

declare(strict_types=1);

namespace KraftySprouts\Schedulely\Engine;

/**
 * What the capacity check found: whether the window is usable, how many items it
 * holds, and what to change when the quota does not fit.
 *
 * @since 0.1.0
 */
final readonly class CapacityReport
{
    /**
     * @param  list<Suggestion>  $suggestions
     *
     * @since 0.1.0
     */
    public function __construct(
        public bool $valid,
        public int $capacity,
        public int $desiredQuota,
        public bool $meetsQuota,
        public float $totalMinutes,
        public int $minInterval,
        public ?string $error,
        public array $suggestions = [],
    ) {}
}
