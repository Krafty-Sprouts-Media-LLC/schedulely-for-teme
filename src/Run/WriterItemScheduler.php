<?php
/**
 * Filename: WriterItemScheduler.php
 * Author: Krafty Sprouts Media, LLC
 * Description: Schedules an item through Teme's ContentWriter and keeps the plugin's
 *              own tables in step.
 *
 * @package KraftySprouts\Schedulely
 * @since   0.1.0
 */

declare(strict_types=1);

namespace KraftySprouts\Schedulely\Run;

use DateTimeImmutable;
use KraftySprouts\Schedulely\Engine\ItemScheduler;
use KraftySprouts\Schedulely\Queue\QueueItem;
use KraftySprouts\Schedulely\Storage\QueueStore;
use KraftySprouts\Schedulely\Storage\SlotStore;
use Teme\Contracts\Content\ContentWriter;
use Teme\Contracts\Content\ItemRef;
use Teme\Contracts\Content\WriteRefused;

/**
 * The scheduler's only way to write. A refused write is an answer, not a crash: it
 * comes back as a sentence and the item stays queued.
 *
 * @since 0.1.0
 */
final readonly class WriterItemScheduler implements ItemScheduler
{
    /** @since 0.1.0 */
    public function __construct(private ContentWriter $writer, private QueueStore $queue, private SlotStore $slots) {}

    /**
     * @since 0.1.0
     */
    public function schedule(QueueItem $item, int $at, string $actingPerson): ?string
    {
        $ref = new ItemRef($item->id, $item->type, $item->title, $item->status, null, $item->language);

        try {
            $this->writer->schedule($ref, new DateTimeImmutable('@'.$at), $actingPerson);
        } catch (WriteRefused $refused) {
            return $refused->getMessage();
        }

        // The ContentScheduled hook does this too, after the commit; doing it now keeps
        // the rest of this run from counting the item twice.
        $this->slots->record($item->id, $item->type, $at);
        $this->queue->forget($item->id);

        return null;
    }
}
