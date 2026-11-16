<?php
/**
 * Filename: QueueItem.php
 * Author: Krafty Sprouts Media, LLC
 * Description: One item waiting in Schedulely's queue.
 *
 * @package KraftySprouts\Schedulely
 * @since   0.1.0
 */

declare(strict_types=1);

namespace KraftySprouts\Schedulely\Queue;

/**
 * A draft Schedulely knows about, as plain values read from the hooks that told of it.
 *
 * @since 0.1.0
 */
final readonly class QueueItem
{
    /**
     * @param  string  $id  the item's ID, a lower-case ULID
     * @param  string  $type  its content type's key
     * @param  string  $title  its title when last saved
     * @param  string  $language  its BCP 47 language tag
     * @param  string  $status  draft or review when it was last seen
     * @param  int  $firstSeenAt  Unix time Schedulely first saw it; "oldest first" means this
     *
     * @since 0.1.0
     */
    public function __construct(
        public string $id,
        public string $type,
        public string $title,
        public string $language,
        public string $status,
        public int $firstSeenAt,
    ) {}
}
