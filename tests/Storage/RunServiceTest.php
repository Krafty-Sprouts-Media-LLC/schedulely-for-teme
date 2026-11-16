<?php
/**
 * Filename: RunServiceTest.php
 * Author: Krafty Sprouts Media, LLC
 * Description: A complete run through the plugin's own parts: settings, the queue table,
 *              ordering, the engine, a fake ContentWriter, and the recorded outcome.
 *
 * @package KraftySprouts\Schedulely
 * @since   0.1.0
 */

declare(strict_types=1);

use Illuminate\Database\ConnectionInterface;
use KraftySprouts\Schedulely\Queue\QueueItem;
use KraftySprouts\Schedulely\Run\RunService;
use KraftySprouts\Schedulely\Storage\QueueStore;
use KraftySprouts\Schedulely\Storage\ReorderLogStore;
use KraftySprouts\Schedulely\Storage\SlotStore;
use KraftySprouts\Schedulely\Storage\StateStore;
use KraftySprouts\Schedulely\Tests\Support\ArraySettings;
use KraftySprouts\Schedulely\Tests\Support\FakeHttp;
use KraftySprouts\Schedulely\Tests\Support\TestDatabase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Teme\Contracts\Content\ContentWriter;
use Teme\Contracts\Content\ItemChanges;
use Teme\Contracts\Content\ItemRef;
use Teme\Contracts\Content\NewItem;
use Teme\Contracts\Content\WriteRefused;
use Teme\Contracts\Http\HttpAnswer;
use Teme\Contracts\Settings\SecretReader;

/** A ContentWriter that remembers schedule() calls and refuses chosen items. */
final class FakeWriter implements ContentWriter
{
    /** @var list<array{id: string, at: int, person: string}> */
    public array $scheduled = [];

    /** @param list<string> $refuse */
    public function __construct(public array $refuse = []) {}

    public function create(NewItem $item, string $actingPersonId): ItemRef
    {
        throw new LogicException('not used');
    }

    public function change(ItemRef $item, ItemChanges $changes, string $actingPersonId): ItemRef
    {
        throw new LogicException('not used');
    }

    public function publish(ItemRef $item, string $actingPersonId): ItemRef
    {
        throw new LogicException('not used');
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

final class FixedSecret implements SecretReader
{
    public function __construct(private ?string $value) {}

    public function secret(string $group, string $key): ?string
    {
        return $group === 'plugin.schedulely' && $key === 'ai_api_key' ? $this->value : null;
    }
}

function runNow(): int
{
    return (new DateTimeImmutable('2026-11-15 08:00', new DateTimeZone('UTC')))->getTimestamp();
}

/**
 * @param  array<string, mixed>  $settings
 * @return array{0: RunService, 1: FakeWriter, 2: ConnectionInterface, 3: FakeHttp}
 */
function runFixture(array $settings = [], int $queued = 5, ?string $key = null, ?HttpAnswer $reply = null): array
{
    $db = TestDatabase::fresh();
    $queue = new QueueStore($db);

    for ($i = 1; $i <= $queued; $i++) {
        $queue->put(new QueueItem(sprintf('item%02d', $i), 'post', "Draft number {$i}", 'en', 'draft', $i));
    }

    $writer = new FakeWriter;
    $http = new FakeHttp($reply ?? FakeHttp::chat('{"ordered_ids":[5,4,3,2,1]}'));
    $values = ['plugin.schedulely' => $settings + [
        'posts_per_day' => 2, 'scheduling_mode' => 'sequential', 'start_time' => '9:00 AM', 'end_time' => '5:00 PM',
        'acting_account' => 'acting-1', 'shuffle_queue' => false,
    ], 'general' => ['timezone' => 'UTC']];

    $service = new RunService(
        new ArraySettings($values), new FixedSecret($key), $writer, $http, $db,
        static fn (): int => runNow(), new Randomizer(new Mt19937(8)),
    );

    return [$service, $writer, $db, $http];
}

it('schedules the queue, oldest first, a day at a time, as the configured account', function (): void {
    [$service, $writer, $db] = runFixture();
    $outcome = $service->run(manual: true);

    expect($outcome->ran)->toBeTrue()
        ->and($outcome->report->scheduledCount)->toBe(5)
        ->and(array_column($writer->scheduled, 'id'))->toBe(['item01', 'item02', 'item03', 'item04', 'item05'])
        ->and(array_unique(array_column($writer->scheduled, 'person')))->toBe(['acting-1'])
        ->and(gmdate('Y-m-d H:i', $writer->scheduled[0]['at']))->toBe('2026-11-15 09:00')
        ->and(gmdate('Y-m-d H:i', $writer->scheduled[2]['at']))->toBe('2026-11-16 09:00')
        ->and((new QueueStore($db))->count(['post'], ['draft']))->toBe(0)
        ->and((new SlotStore($db, ['post']))->between(0, PHP_INT_MAX))->toHaveCount(5);
});

it('remembers the run for the status screen', function (): void {
    [$service, , $db] = runFixture();
    $service->run(manual: true);
    $state = new StateStore($db);

    expect($state->get('last_run'))->toBe(runNow())
        ->and($state->get('last_report')['scheduled'])->toBe(5)
        ->and($state->get('last_report')['windows'])->toBe(['2026-11-15', '2026-11-16', '2026-11-17']);
});

it('acts as the person who asked for a manual run, not the configured account', function (): void {
    [$service, $writer] = runFixture();
    $service->run(manual: true, actor: 'the-editor');

    expect(array_unique(array_column($writer->scheduled, 'person')))->toBe(['the-editor']);
});

it('will not run automatically without an account to act as', function (): void {
    [$service, $writer] = runFixture(['acting_account' => '']);
    $outcome = $service->run(manual: false);

    expect($outcome->ran)->toBeFalse()->and($outcome->reason)->toContain('account')->and($writer->scheduled)->toBe([]);
});

it('loads no more than the pool size and says how many were left', function (): void {
    [$service, $writer] = runFixture(['pool_size' => 2]);
    $outcome = $service->run(manual: true);

    expect(array_column($writer->scheduled, 'id'))->toBe(['item01', 'item02'])
        ->and($outcome->eligibleAtStart)->toBe(5)
        ->and($outcome->notLoaded)->toBe(3)
        ->and($outcome->eligibleRemaining)->toBe(3);
});

it('only schedules items in the status it was set to watch', function (): void {
    [$service, $writer, $db] = runFixture(['post_status' => 'review'], queued: 3);
    (new QueueStore($db))->put(new QueueItem('inreview', 'post', 'In review', 'en', 'review', 1));
    $service->run(manual: true);

    expect(array_column($writer->scheduled, 'id'))->toBe(['inreview']);
});

it('leaves an item in the queue when its write is refused, and tells why', function (): void {
    [$service, $writer, $db] = runFixture();
    $writer->refuse = ['item02'];
    $outcome = $service->run(manual: true);

    expect($outcome->report->scheduledCount)->toBe(4)
        ->and($outcome->report->errors)->toHaveCount(1)
        ->and($outcome->report->errors[0])->toContain('item02')
        ->and($outcome->report->errors[0])->toContain('locked')
        ->and((new QueueStore($db))->has('item02'))->toBeTrue()
        ->and((new QueueStore($db))->has('item01'))->toBeFalse();
});

it('says so, and writes nothing, when the queue is empty', function (): void {
    [$service, $writer] = runFixture(queued: 0);
    $outcome = $service->run(manual: true);

    expect($outcome->ran)->toBeTrue()->and($outcome->report->scheduledCount)->toBe(0)->and($writer->scheduled)->toBe([]);
});

it('orders with the AI on a manual run, and logs it', function (): void {
    [$service, $writer, $db, $http] = runFixture(['reorder_queue' => true, 'ordering_method' => 'ai'], key: 'dummy-key-not-real');
    $service->run(manual: true);

    expect($http->posts)->toHaveCount(1)
        ->and(array_column($writer->scheduled, 'id'))->toBe(['item05', 'item04', 'item03', 'item02', 'item01'])
        ->and((new ReorderLogStore($db))->recent())->toHaveCount(1);
});

it('never calls the AI on an automatic run', function (): void {
    [$service, $writer, , $http] = runFixture(['reorder_queue' => true, 'ordering_method' => 'ai'], key: 'dummy-key-not-real');
    $service->run(manual: false);

    expect($http->posts)->toBe([])->and(array_column($writer->scheduled, 'id'))->toBe(['item01', 'item02', 'item03', 'item04', 'item05']);
});

it('falls back to PHP ordering when the AI service fails, and the run still happens', function (): void {
    [$service, $writer, $db] = runFixture(['reorder_queue' => true, 'ordering_method' => 'ai'], key: 'dummy-key-not-real', reply: new HttpAnswer(503, 'down', []));
    $outcome = $service->run(manual: true);
    $log = (new ReorderLogStore($db))->recent();

    expect($outcome->report->scheduledCount)->toBe(5)
        ->and($writer->scheduled)->toHaveCount(5)
        ->and($log)->toHaveCount(2)
        ->and($log[1]->errorCode)->toBe('ai_http');
});

it('orders in PHP when that is the method, without any network', function (): void {
    [$service, , $db, $http] = runFixture(['reorder_queue' => true, 'ordering_method' => 'php']);
    $service->run(manual: true);

    expect($http->posts)->toBe([])->and((new ReorderLogStore($db))->recent()[0]->model)->toBe('php');
});

it('hands the engine a progress callback that reaches the job', function (): void {
    [$service] = runFixture(['batch_size' => 10], queued: 12);
    $seen = [];
    $service->run(manual: true, progress: function (int $done, int $total) use (&$seen): void {
        $seen[] = [$done, $total];
    });

    expect($seen)->toBe([[10, 12], [12, 12]]);
});

it('records the timezone spread when timezone ordering is on', function (): void {
    [$service, , $db] = runFixture(['reorder_queue' => true, 'ordering_method' => 'php', 'timezone_ordering' => true, 'scheduling_mode' => 'random']);
    (new QueueStore($db))->put(new QueueItem('tx', 'post', 'Best Pizza in Texas', 'en', 'draft', 0));
    $outcome = $service->run(manual: true);

    expect($outcome->report->timezoneDistribution)->not->toBeNull()
        ->and($outcome->report->timezoneDistribution['central'])->toBe(1);
});
