<?php
/**
 * Filename: OrderResult.php
 * Author: Krafty Sprouts Media, LLC
 * Description: The queue after ordering, and how it was ordered.
 *
 * @package KraftySprouts\Schedulely
 *
 * @since   0.1.0
 */

declare(strict_types=1);

namespace KraftySprouts\Schedulely\Ordering;

use KraftySprouts\Schedulely\Queue\QueueItem;

/**
 * The ordered queue; `reordered` is true only when PHP or AI ordering ran, not for a
 * plain shuffle or an untouched queue.
 *
 * @since 0.1.0
 */
final readonly class OrderResult
{
    /**
     * @param  list<QueueItem>  $items
     * @param  string  $method  php, ai or an empty string when nothing reordered the queue
     *
     * @since 0.1.0
     */
    public function __construct(
        public array $items,
        public bool $reordered,
        public string $method = '',
    ) {}
}
