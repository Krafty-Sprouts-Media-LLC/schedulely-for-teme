<?php
/**
 * Filename: Suggestion.php
 * Author: Krafty Sprouts Media, LLC
 * Description: One suggested fix when the daily quota does not fit the window.
 *
 * @package KraftySprouts\Schedulely
 * @since   0.1.0
 */

declare(strict_types=1);

namespace KraftySprouts\Schedulely\Engine;

/**
 * A way to make the quota fit: lower the interval, lower the quota, or widen the window.
 *
 * @since 0.1.0
 */
final readonly class Suggestion
{
    /**
     * @param  string  $type  reduce_interval, reduce_quota or expand_window
     * @param  string  $message  one plain sentence
     * @param  int|null  $suggested  the new interval or quota, where the type has one
     * @param  int|null  $newCapacity  what the suggested interval would hold
     * @param  string|null  $suggestedStart  the window start to use, for expand_window
     * @param  string|null  $suggestedEnd  the window end to use, for expand_window
     *
     * @since 0.1.0
     */
    public function __construct(
        public string $type,
        public string $message,
        public ?int $suggested = null,
        public ?int $newCapacity = null,
        public ?string $suggestedStart = null,
        public ?string $suggestedEnd = null,
    ) {}
}
