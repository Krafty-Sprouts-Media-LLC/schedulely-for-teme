<?php
/**
 * Filename: ItemScheduler.php
 * Author: Krafty Sprouts Media, LLC
 * Description: The one door the scheduler writes through.
 *
 * @package KraftySprouts\Schedulely
 * @since   0.1.0
 */

declare(strict_types=1);

namespace KraftySprouts\Schedulely\Engine;

use KraftySprouts\Schedulely\Queue\QueueItem;

/**
 * Schedules one item for one instant. In the plugin this is ContentWriter; in tests,
 * an in-memory fake.
 *
 * @since 0.1.0
 */
interface ItemScheduler
{
    /**
     * @param  string  $actingPerson  the account the write is made for
     * @return string|null null when the item was scheduled, otherwise one plain sentence saying why not
     *
     * @since 0.1.0
     */
    public function schedule(QueueItem $item, int $at, string $actingPerson): ?string;
}
