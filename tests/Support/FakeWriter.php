<?php
/**
 * Filename: FakeWriter.php
 * Author: Krafty Sprouts Media, LLC
 * Description: A ContentWriter that remembers schedule() calls and refuses chosen items.
 *
 * @package KraftySprouts\Schedulely
 *
 * @since   0.1.0
 */

declare(strict_types=1);

namespace KraftySprouts\Schedulely\Tests\Support;

use DateTimeImmutable;
use LogicException;
use Teme\Contracts\Content\ContentWriter;
use Teme\Contracts\Content\ItemChanges;
use Teme\Contracts\Content\ItemRef;
use Teme\Contracts\Content\NewItem;
use Teme\Contracts\Content\WriteRefused;

/**
 * Only schedule() is real; Schedulely never creates, changes or publishes.
 *
 * @since 0.1.0
 */
final class FakeWriter implements ContentWriter
{
    /** @var list<array{id: string, at: int, person: string}> */
    public array $scheduled = [];

    /**
     * @param  list<string>  $refuse  item IDs whose write is refused
     */
    public function __construct(public array $refuse = []) {}

    public function create(NewItem $item, string $actingPersonId): ItemRef
    {
        throw new LogicException('Schedulely never creates items.');
    }

    public function change(ItemRef $item, ItemChanges $changes, string $actingPersonId): ItemRef
    {
        throw new LogicException('Schedulely never changes items.');
    }

    public function publish(ItemRef $item, string $actingPersonId): ItemRef
    {
        throw new LogicException('Schedulely never publishes items.');
    }

    public function linkTranslations(array $itemIds, string $actingPersonId): void
    {
        throw new LogicException('Schedulely never links translations.');
    }

    public function schedule(ItemRef $item, DateTimeImmutable $at, string $actingPersonId): ItemRef
    {
        if (in_array($item->id, $this->refuse, true)) {
            throw new WriteRefused('The item is locked.');
        }

        $this->scheduled[] = ['id' => $item->id, 'at' => $at->getTimestamp(), 'person' => $actingPersonId];

        return new ItemRef($item->id, $item->type, $item->title, 'scheduled', null, $item->language);
    }
}
