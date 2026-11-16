<?php
/**
 * Filename: StorageTest.php
 * Author: Krafty Sprouts Media, LLC
 * Description: The plugin's four tables and the stores that read and write them,
 *              run against in-memory SQLite through the real migration file.
 *
 * @package KraftySprouts\Schedulely
 *
 * @since   0.1.0
 */

declare(strict_types=1);

use Illuminate\Database\ConnectionInterface;
use KraftySprouts\Schedulely\Ordering\ReorderLogEntry;
use KraftySprouts\Schedulely\Queue\QueueItem;
use KraftySprouts\Schedulely\Storage\QueueStore;
use KraftySprouts\Schedulely\Storage\ReorderLogStore;
use KraftySprouts\Schedulely\Storage\SlotStore;
use KraftySprouts\Schedulely\Storage\StateStore;
use KraftySprouts\Schedulely\Tests\Support\TestDatabase;

function freshDatabase(): ConnectionInterface
{
    return TestDatabase::fresh();
}

function qItem(string $id, string $title = 'T', string $type = 'post', string $status = 'draft', int $seen = 100): QueueItem
{
    return new QueueItem($id, $type, $title, 'en', $status, $seen);
}

it('creates only tables that start with the plugin code and are all declared in the manifest', function (): void {
    $db = freshDatabase();
    $tables = array_map(static fn (object $row): string => $row->name, $db->select("select name from sqlite_master where type = 'table' and name not like 'sqlite_%' order by name"));
    $manifest = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/teme-plugin.json'), true);

    expect($tables)->each->toStartWith('schedulely_')
        ->and($manifest['owns']['tables'])->toEqualCanonicalizing($tables);
});

it('drops every table it made on the way down', function (): void {
    $db = freshDatabase();
    (require dirname(__DIR__, 2).'/database/migrations/2026_11_16_000001_create_schedulely_tables.php')->down();

    expect($db->select("select name from sqlite_master where type = 'table' and name like 'schedulely_%'"))->toBe([]);
});

it('adds a draft to the queue and keeps when it was first seen across saves', function (): void {
    $queue = new QueueStore(freshDatabase());
    $queue->put(qItem('a', 'First title', seen: 100));
    $queue->put(qItem('a', 'Edited title', 'post', 'review', 999));

    $items = $queue->oldest(['post'], ['draft', 'review'], 10);

    expect($items)->toHaveCount(1)
        ->and($items[0]->title)->toBe('Edited title')
        ->and($items[0]->status)->toBe('review')
        ->and($items[0]->firstSeenAt)->toBe(100);
});

it('lists the oldest first, by type and status, up to the limit', function (): void {
    $queue = new QueueStore(freshDatabase());
    $queue->put(qItem('c', seen: 300));
    $queue->put(qItem('a', seen: 100));
    $queue->put(qItem('b', seen: 200));
    $queue->put(qItem('page1', type: 'page', seen: 50));
    $queue->put(qItem('rev', status: 'review', seen: 10));

    expect(array_map(static fn (QueueItem $i): string => $i->id, $queue->oldest(['post'], ['draft'], 10)))->toBe(['a', 'b', 'c'])
        ->and(array_map(static fn (QueueItem $i): string => $i->id, $queue->oldest(['post'], ['draft'], 2)))->toBe(['a', 'b'])
        ->and(array_map(static fn (QueueItem $i): string => $i->id, $queue->oldest(['post', 'page'], ['draft'], 10)))->toBe(['page1', 'a', 'b', 'c'])
        ->and(array_map(static fn (QueueItem $i): string => $i->id, $queue->oldest(['post'], ['review'], 10)))->toBe(['rev']);
});

it('breaks ties between items first seen in the same second by ID', function (): void {
    $queue = new QueueStore(freshDatabase());
    $queue->put(qItem('b', seen: 100));
    $queue->put(qItem('a', seen: 100));

    expect(array_map(static fn (QueueItem $i): string => $i->id, $queue->oldest(['post'], ['draft'], 10)))->toBe(['a', 'b']);
});

it('counts the eligible items without loading them', function (): void {
    $queue = new QueueStore(freshDatabase());
    $queue->put(qItem('a'));
    $queue->put(qItem('b'));
    $queue->put(qItem('c', type: 'page'));

    expect($queue->count(['post'], ['draft']))->toBe(2)->and($queue->count(['post', 'page'], ['draft']))->toBe(3)->and($queue->count(['post'], ['review']))->toBe(0);
});

it('forgets an item and says whether it was there', function (): void {
    $queue = new QueueStore(freshDatabase());
    $queue->put(qItem('a'));

    expect($queue->has('a'))->toBeTrue()
        ->and($queue->forget('a'))->toBeTrue()
        ->and($queue->has('a'))->toBeFalse()
        ->and($queue->forget('a'))->toBeFalse();
});

it('records scheduled times and answers the ledger questions for the types it manages', function (): void {
    $slots = new SlotStore(freshDatabase(), ['post']);
    $slots->record('a', 'post', 1_000);
    $slots->record('b', 'post', 5_000);
    $slots->record('c', 'post', 3_000);
    $slots->record('x', 'page', 9_999);

    expect($slots->latest())->toBe(5_000)
        ->and($slots->between(2_000, 5_000))->toEqualCanonicalizing([3_000, 5_000])
        ->and($slots->between(6_000, 8_000))->toBe([]);
});

it('moves an item to a new time rather than counting it twice', function (): void {
    $slots = new SlotStore(freshDatabase(), ['post']);
    $slots->record('a', 'post', 1_000);
    $slots->record('a', 'post', 2_000);

    expect($slots->between(0, 10_000))->toBe([2_000]);
});

it('drops a slot when the item is published, unscheduled, trashed or deleted', function (): void {
    $slots = new SlotStore(freshDatabase(), ['post']);
    $slots->record('a', 'post', 1_000);
    $slots->forget('a');

    expect($slots->latest())->toBeNull()->and($slots->forget('a'))->toBeFalse();
});

it('has no latest time when nothing is scheduled', function (): void {
    expect((new SlotStore(freshDatabase(), ['post']))->latest())->toBeNull();
});

it('keeps small values by name', function (): void {
    $state = new StateStore(freshDatabase());

    expect($state->get('last_run'))->toBeNull()->and($state->get('last_run', 0))->toBe(0);

    $state->put('last_run', 1_700_000_000);
    $state->put('last_report', ['count' => 4, 'errors' => ['x']]);
    $state->put('last_run', 1_700_000_100);

    expect($state->get('last_run'))->toBe(1_700_000_100)
        ->and($state->get('last_report'))->toBe(['count' => 4, 'errors' => ['x']]);

    $state->forget('last_run');

    expect($state->get('last_run'))->toBeNull();
});

it('logs ordering attempts newest first and keeps only the newest few', function (): void {
    $log = new ReorderLogStore(freshDatabase(), 3);

    foreach (['one', 'two', 'three', 'four'] as $i => $note) {
        $log->append(new ReorderLogEntry('success', 'php', 10 + $i, note: $note));
    }

    $recent = $log->recent();

    expect($recent)->toHaveCount(3)
        ->and(array_map(static fn (ReorderLogEntry $e): string => $e->note, $recent))->toBe(['four', 'three', 'two'])
        ->and($recent[0]->itemCount)->toBe(13);
});

it('stores an error row with its code and excerpts', function (): void {
    $log = new ReorderLogStore(freshDatabase(), 25);
    $log->append(new ReorderLogEntry('error', 'deepseek-v4-flash', 50, 401, 12, 'ai_http', 'bad key', '', 'raw body', 'The AI request failed'));
    $row = $log->recent()[0];

    expect($row->outcome)->toBe('error')
        ->and($row->httpCode)->toBe(401)
        ->and($row->usageTokens)->toBe(12)
        ->and($row->errorCode)->toBe('ai_http')
        ->and($row->rawExcerpt)->toBe('raw body');
});

it('clears the log', function (): void {
    $log = new ReorderLogStore(freshDatabase(), 25);
    $log->append(new ReorderLogEntry('success', 'php', 1));
    $log->clear();

    expect($log->recent())->toBe([]);
});
