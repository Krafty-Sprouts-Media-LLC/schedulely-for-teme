<?php
/**
 * Filename: RunServiceTest.php
 * Author: Krafty Sprouts Media, LLC
 * Description: A complete run through the plugin's own parts: settings, the queue table,
 *              ordering, the engine, a fake ContentWriter, and the recorded outcome.
 *
 * @package KraftySprouts\Schedulely
 *
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
use KraftySprouts\Schedulely\Tests\Support\FakeHttp;
use KraftySprouts\Schedulely\Tests\Support\FakeWriter;
use KraftySprouts\Schedulely\Tests\Support\Harness;
use Teme\Contracts\Http\HttpAnswer;

/**
 * @param  array<string, mixed>  $settings
 *
 * @return array{0: RunService, 1: FakeWriter, 2: ConnectionInterface, 3: FakeHttp}
 */
function runFixture(array $settings = [], int $queued = 5, ?string $key = null, ?HttpAnswer $reply = null): array
{
    $h = Harness::make($settings, $queued, $key, $reply);

    return [$h->service, $h->writer, $h->db, $h->http];
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

    expect($state->get('last_run'))->toBe(Harness::start())
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
