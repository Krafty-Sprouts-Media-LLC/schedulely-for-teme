<?php
/**
 * Filename: QueueTrackerTest.php
 * Author: Krafty Sprouts Media, LLC
 * Description: Keeping the queue and the ledger of scheduled times in step with what
 *              the hooks say happened to each item.
 *
 * @package KraftySprouts\Schedulely
 * @since   0.1.0
 */

declare(strict_types=1);

use KraftySprouts\Schedulely\Storage\QueueStore;
use KraftySprouts\Schedulely\Storage\QueueTracker;
use KraftySprouts\Schedulely\Storage\SlotStore;
use KraftySprouts\Schedulely\Tests\Support\TestDatabase;
use Teme\Contracts\Content\ContentDeleted;
use Teme\Contracts\Content\ContentPublished;
use Teme\Contracts\Content\ContentRestored;
use Teme\Contracts\Content\ContentSaved;
use Teme\Contracts\Content\ContentScheduled;
use Teme\Contracts\Content\ContentTrashed;
use Teme\Contracts\Content\ContentUnpublished;
use Teme\Contracts\Content\ItemRef;
use Teme\Contracts\Hooks\Hooks;
use Teme\Contracts\Hooks\Order;
use Teme\Contracts\Hooks\Subscription;

/** Remembers what a plugin subscribed to. */
final class RecordingHooks implements Hooks
{
    /** @var array<string, callable> */
    public array $listeners = [];

    public function on(string $hook, callable $listener, ?Order $order = null): Subscription
    {
        $this->listeners[$hook] = $listener;

        return new Subscription("krafty-sprouts/schedulely#{$hook}#1");
    }

    public function off(Subscription $subscription): void {}
}

function trackerFixture(int $now = 5_000): array
{
    $db = TestDatabase::fresh();
    $queue = new QueueStore($db);
    $slots = new SlotStore($db, ['post']);
    $tracker = new QueueTracker($queue, $slots, static fn (): int => $now);

    return [$tracker, $queue, $slots];
}

function ref(string $status = 'draft', string $id = '01a', string $type = 'post', string $title = 'A title'): ItemRef
{
    return new ItemRef($id, $type, $title, $status, null, 'en');
}

function queueIds(QueueStore $queue, string $status = 'draft'): array
{
    return array_map(static fn ($i): string => $i->id, $queue->oldest(['post', 'page'], [$status], 50));
}

it('adds a saved draft to the queue, stamped with when it was first seen', function (): void {
    [$tracker, $queue] = trackerFixture(5_000);
    $tracker->saved(new ContentSaved(ref(), 'rev1', true, null));

    $items = $queue->oldest(['post'], ['draft'], 10);

    expect($items)->toHaveCount(1)->and($items[0]->firstSeenAt)->toBe(5_000)->and($items[0]->title)->toBe('A title');
});

it('keeps the first-seen time and refreshes the title when a draft is saved again', function (): void {
    $db = TestDatabase::fresh();
    $queue = new QueueStore($db);
    $slots = new SlotStore($db, ['post']);
    $early = new QueueTracker($queue, $slots, static fn (): int => 1_000);
    $late = new QueueTracker($queue, $slots, static fn (): int => 9_000);

    $early->saved(new ContentSaved(ref(title: 'Old'), 'r1', true, null));
    $late->saved(new ContentSaved(ref(title: 'New'), 'r2', false, null));
    $item = $queue->oldest(['post'], ['draft'], 10)[0];

    expect($item->title)->toBe('New')->and($item->firstSeenAt)->toBe(1_000);
});

it('queues an item in review separately from a draft', function (): void {
    [$tracker, $queue] = trackerFixture();
    $tracker->saved(new ContentSaved(ref('review'), 'r', true, null));

    expect(queueIds($queue, 'review'))->toBe(['01a'])->and(queueIds($queue, 'draft'))->toBe([]);
});

it('takes an item out of the queue once it is scheduled or published, whoever did it', function (string $status): void {
    [$tracker, $queue] = trackerFixture();
    $tracker->saved(new ContentSaved(ref(), 'r1', true, null));
    $tracker->saved(new ContentSaved(ref($status), 'r2', false, null));

    expect(queueIds($queue))->toBe([]);
})->with(['scheduled', 'published']);

it('moves an item from the queue to the ledger when it is scheduled', function (): void {
    [$tracker, $queue, $slots] = trackerFixture();
    $tracker->saved(new ContentSaved(ref(), 'r1', true, null));
    $tracker->scheduled(new ContentScheduled(ref('scheduled'), 'r1', new DateTimeImmutable('@7000'), null));

    expect(queueIds($queue))->toBe([])->and($slots->between(0, 10_000))->toBe([7_000]);
});

it('records a schedule made by someone else for an item it never queued', function (): void {
    [$tracker, , $slots] = trackerFixture();
    $tracker->scheduled(new ContentScheduled(ref('scheduled', '01z'), 'r', new DateTimeImmutable('@8000'), 'someone'));

    expect($slots->latest())->toBe(8_000);
});

it('moves a rescheduled item rather than recording it twice', function (): void {
    [$tracker, , $slots] = trackerFixture();
    $tracker->scheduled(new ContentScheduled(ref('scheduled'), 'r', new DateTimeImmutable('@7000'), null));
    $tracker->scheduled(new ContentScheduled(ref('scheduled'), 'r', new DateTimeImmutable('@9000'), null));

    expect($slots->between(0, 20_000))->toBe([9_000]);
});

it('clears the ledger when the item is published', function (): void {
    [$tracker, , $slots] = trackerFixture();
    $tracker->scheduled(new ContentScheduled(ref('scheduled'), 'r', new DateTimeImmutable('@7000'), null));
    $tracker->published(new ContentPublished(ref('published'), 'r', true, new DateTimeImmutable('@7000'), null));

    expect($slots->latest())->toBeNull();
});

it('puts an unpublished item back in the queue', function (): void {
    [$tracker, $queue] = trackerFixture();
    $tracker->unpublished(new ContentUnpublished(ref('draft'), 'https://example.test/a', null));

    expect(queueIds($queue))->toBe(['01a']);
});

it('forgets an item that is trashed or deleted, from both the queue and the ledger', function (): void {
    [$tracker, $queue, $slots] = trackerFixture();
    $tracker->saved(new ContentSaved(ref(id: 'q'), 'r', true, null));
    $tracker->scheduled(new ContentScheduled(ref('scheduled', 's'), 'r', new DateTimeImmutable('@7000'), null));
    $tracker->saved(new ContentSaved(ref(id: 'd'), 'r', true, null));
    $tracker->scheduled(new ContentScheduled(ref('scheduled', 'd2'), 'r', new DateTimeImmutable('@7100'), null));

    $tracker->trashed(new ContentTrashed(ref('draft', 'q'), null, null));
    $tracker->trashed(new ContentTrashed(ref('draft', 's'), null, null));
    $tracker->deleted(new ContentDeleted('d', 'post', 'T', null));
    $tracker->deleted(new ContentDeleted('d2', 'post', 'T', null));

    expect(queueIds($queue))->toBe([])->and($slots->latest())->toBeNull();
});

it('queues a restored draft again', function (): void {
    [$tracker, $queue] = trackerFixture();
    $tracker->restored(new ContentRestored(ref('draft'), null));

    expect(queueIds($queue))->toBe(['01a']);
});

it('ignores a restored item that is not a draft', function (): void {
    [$tracker, $queue, $slots] = trackerFixture();
    $tracker->restored(new ContentRestored(ref('published'), null));

    expect(queueIds($queue))->toBe([])->and($slots->latest())->toBeNull();
});

it('subscribes to every hook the manifest declares for content, and no others', function (): void {
    [$tracker] = trackerFixture();
    $hooks = new RecordingHooks;
    $tracker->register($hooks);

    $manifest = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/teme-plugin.json'), true);
    $content = array_values(array_filter($manifest['hooks'], static fn (string $h): bool => str_starts_with($h, 'Content')));

    expect(array_map(static fn (string $class): string => substr(strrchr($class, '\\'), 1), array_keys($hooks->listeners)))->toEqualCanonicalizing($content);
});

it('follows a draft through its whole life', function (): void {
    [$tracker, $queue, $slots] = trackerFixture();
    $tracker->saved(new ContentSaved(ref(), 'r1', true, null));
    expect(queueIds($queue))->toBe(['01a']);

    $tracker->scheduled(new ContentScheduled(ref('scheduled'), 'r1', new DateTimeImmutable('@7000'), null));
    expect(queueIds($queue))->toBe([])->and($slots->latest())->toBe(7_000);

    $tracker->unpublished(new ContentUnpublished(ref('draft'), null, null));
    expect(queueIds($queue))->toBe(['01a']);
});
