<?php
/**
 * Filename: QueueTracker.php
 * Author: Krafty Sprouts Media, LLC
 * Description: Keeps the queue and the ledger of scheduled times in step with what
 *              Teme's content hooks say happened to each item.
 *
 * @package KraftySprouts\Schedulely
 *
 * @since   0.1.0
 */

declare(strict_types=1);

namespace KraftySprouts\Schedulely\Storage;

use Closure;
use KraftySprouts\Schedulely\Queue\QueueItem;
use Teme\Contracts\Content\ContentDeleted;
use Teme\Contracts\Content\ContentPublished;
use Teme\Contracts\Content\ContentRestored;
use Teme\Contracts\Content\ContentSaved;
use Teme\Contracts\Content\ContentScheduled;
use Teme\Contracts\Content\ContentTrashed;
use Teme\Contracts\Content\ContentUnpublished;
use Teme\Contracts\Content\ItemRef;
use Teme\Contracts\Hooks\Hooks;

/**
 * Teme offers no door that lists items, so Schedulely learns the queue from hooks as
 * they happen. A draft that existed before the plugin was enabled, or that came in
 * through an import, is not seen until it is saved again.
 *
 * @since 0.1.0
 */
final readonly class QueueTracker
{
    /** @var list<string> the statuses an item can wait in */
    private const array WAITING = ['draft', 'review'];

    /**
     * @param  Closure(): int  $clock  Unix time, injected so tests can fix it
     *
     * @since 0.1.0
     */
    public function __construct(private QueueStore $queue, private SlotStore $slots, private Closure $clock) {}

    /**
     * Subscribes to every content hook Schedulely listens to.
     *
     * @since 0.1.0
     */
    public function register(Hooks $hooks): void
    {
        $hooks->on(ContentSaved::class, $this->saved(...));
        $hooks->on(ContentScheduled::class, $this->scheduled(...));
        $hooks->on(ContentPublished::class, $this->published(...));
        $hooks->on(ContentUnpublished::class, $this->unpublished(...));
        $hooks->on(ContentTrashed::class, $this->trashed(...));
        $hooks->on(ContentRestored::class, $this->restored(...));
        $hooks->on(ContentDeleted::class, $this->deleted(...));
    }

    /**
     * A draft was saved: queue it while it waits, drop it once it is scheduled or public.
     *
     * @since 0.1.0
     */
    public function saved(ContentSaved $event): void
    {
        $this->settle($event->item);
    }

    /**
     * An item was scheduled, by Schedulely or by a person: it leaves the queue and takes a slot.
     *
     * @since 0.1.0
     */
    public function scheduled(ContentScheduled $event): void
    {
        $this->queue->forget($event->item->id);
        $this->slots->record($event->item->id, $event->item->type, $event->publishAt->getTimestamp());
    }

    /**
     * An item went live: it has no slot and no place in the queue.
     *
     * @since 0.1.0
     */
    public function published(ContentPublished $event): void
    {
        $this->queue->forget($event->item->id);
        $this->slots->forget($event->item->id);
    }

    /**
     * A published item went back to a draft: it waits in the queue again.
     *
     * @since 0.1.0
     */
    public function unpublished(ContentUnpublished $event): void
    {
        $this->settle($event->item);
    }

    /**
     * An item was trashed: it is neither queued nor scheduled.
     *
     * @since 0.1.0
     */
    public function trashed(ContentTrashed $event): void
    {
        $this->forget($event->item->id);
    }

    /**
     * An item came out of the trash. A draft or an item in review is queued again. A
     * scheduled one is not recovered, because the hook does not say when it was scheduled for.
     *
     * @since 0.1.0
     */
    public function restored(ContentRestored $event): void
    {
        if (in_array($event->item->status, self::WAITING, true)) {
            $this->settle($event->item);
        }
    }

    /**
     * An item was deleted for good.
     *
     * @since 0.1.0
     */
    public function deleted(ContentDeleted $event): void
    {
        $this->forget($event->id);
    }

    private function settle(ItemRef $item): void
    {
        if (in_array($item->status, self::WAITING, true)) {
            $this->slots->forget($item->id);
            $this->queue->put(new QueueItem($item->id, $item->type, $item->title, $item->language, $item->status, ($this->clock)()));

            return;
        }

        $this->queue->forget($item->id);
    }

    private function forget(string $id): void
    {
        $this->queue->forget($id);
        $this->slots->forget($id);
    }
}
