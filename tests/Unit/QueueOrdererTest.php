<?php
/**
 * Filename: QueueOrdererTest.php
 * Author: Krafty Sprouts Media, LLC
 * Description: Choosing how the queue is ordered before scheduling: reorder with PHP
 *              or AI (falling back to PHP when AI fails), shuffle, or keep the order,
 *              and never reorder on an automatic run.
 *
 * @package KraftySprouts\Schedulely
 * @since   0.1.0
 */

declare(strict_types=1);

use KraftySprouts\Schedulely\Ordering\AiOrderer;
use KraftySprouts\Schedulely\Ordering\AiProvider;
use KraftySprouts\Schedulely\Ordering\OrderingMethod;
use KraftySprouts\Schedulely\Ordering\PhpOrderer;
use KraftySprouts\Schedulely\Ordering\QueueOrderer;
use KraftySprouts\Schedulely\Ordering\ReorderLog;
use KraftySprouts\Schedulely\Ordering\ReorderLogEntry;
use KraftySprouts\Schedulely\Ordering\Spread;
use KraftySprouts\Schedulely\Queue\QueueItem;
use KraftySprouts\Schedulely\Tests\Support\FakeHttp;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Teme\Contracts\Http\HttpAnswer;

final class MemoryLog implements ReorderLog
{
    /** @var list<ReorderLogEntry> */
    public array $entries = [];

    public function append(ReorderLogEntry $entry): void
    {
        $this->entries[] = $entry;
    }
}

function seriesQueue(): array
{
    return [
        new QueueItem('1', 'post', 'Best Pizza in Texas', 'en', 'draft', 1),
        new QueueItem('2', 'post', 'Best Pizza in Ohio', 'en', 'draft', 2),
        new QueueItem('3', 'post', 'Best Pizza in Utah', 'en', 'draft', 3),
        new QueueItem('4', 'post', 'Best Tacos in Texas', 'en', 'draft', 4),
        new QueueItem('5', 'post', 'Best Tacos in Ohio', 'en', 'draft', 5),
        new QueueItem('6', 'post', 'An Unrelated Post', 'en', 'draft', 6),
    ];
}

function orderIds(array $items): array
{
    return array_map(static fn (QueueItem $item): string => $item->id, $items);
}

function queueOrderer(bool $enabled, OrderingMethod $method, bool $shuffle, MemoryLog $log, ?AiOrderer $ai = null, int $seed = 3): QueueOrderer
{
    return new QueueOrderer($enabled, $method, $shuffle, new PhpOrderer(Spread::Even), $ai, new Randomizer(new Mt19937($seed)), $log);
}

it('keeps oldest-first order when reordering and shuffling are both off', function (): void {
    $log = new MemoryLog;
    $result = queueOrderer(false, OrderingMethod::Php, false, $log)->order(seriesQueue(), true);

    expect(orderIds($result->items))->toBe(['1', '2', '3', '4', '5', '6'])->and($result->reordered)->toBeFalse()->and($log->entries)->toBe([]);
});

it('shuffles when reordering is off and shuffling is on', function (): void {
    $result = queueOrderer(false, OrderingMethod::Php, true, new MemoryLog)->order(seriesQueue(), true);

    expect($result->reordered)->toBeFalse()
        ->and(orderIds($result->items))->not->toBe(['1', '2', '3', '4', '5', '6'])
        ->and(array_unique(orderIds($result->items)))->toHaveCount(6);
});

it('shuffles the same way for the same seed', function (): void {
    $a = queueOrderer(false, OrderingMethod::Php, true, new MemoryLog, seed: 11)->order(seriesQueue(), true);
    $b = queueOrderer(false, OrderingMethod::Php, true, new MemoryLog, seed: 11)->order(seriesQueue(), true);

    expect(orderIds($a->items))->toBe(orderIds($b->items));
});

it('orders in PHP when that is the method, and logs it without calling the network', function (): void {
    $log = new MemoryLog;
    $result = queueOrderer(true, OrderingMethod::Php, true, $log)->order(seriesQueue(), true);

    expect(orderIds($result->items))->toBe(['1', '4', '2', '6', '5', '3'])
        ->and($result->reordered)->toBeTrue()
        ->and($result->method)->toBe('php')
        ->and($log->entries)->toHaveCount(1)
        ->and($log->entries[0]->outcome)->toBe('success')
        ->and($log->entries[0]->model)->toBe('php')
        ->and($log->entries[0]->note)->toContain('series detected');
});

it('does not reorder an automatic run, which still shuffles when shuffling is on', function (): void {
    $log = new MemoryLog;
    $result = queueOrderer(true, OrderingMethod::Php, true, $log)->order(seriesQueue(), false);

    expect(orderIds($result->items))->not->toBe(['1', '4', '2', '6', '5', '3'])
        ->and($result->reordered)->toBeFalse()
        ->and($log->entries)->toBe([]);
});

it('uses the AI order when AI succeeds', function (): void {
    $http = new FakeHttp(FakeHttp::chat('{"ordered_ids":[6,5,4,3,2,1]}', tokens: 99));
    $ai = new AiOrderer($http, AiProvider::Deepseek, 'deepseek-v4-flash', 'dummy-key-not-real', 150);
    $log = new MemoryLog;
    $result = queueOrderer(true, OrderingMethod::Ai, true, $log, $ai)->order(seriesQueue(), true);

    expect(orderIds($result->items))->toBe(['6', '5', '4', '3', '2', '1'])
        ->and($result->method)->toBe('ai')
        ->and($log->entries)->toHaveCount(1)
        ->and($log->entries[0]->outcome)->toBe('success')
        ->and($log->entries[0]->model)->toBe('deepseek-v4-flash')
        ->and($log->entries[0]->usageTokens)->toBe(99);
});

it('falls back to PHP ordering and logs both rows when AI fails', function (): void {
    $http = new FakeHttp(new HttpAnswer(500, 'boom', []));
    $ai = new AiOrderer($http, AiProvider::Deepseek, 'deepseek-v4-flash', 'dummy-key-not-real', 150);
    $log = new MemoryLog;
    $result = queueOrderer(true, OrderingMethod::Ai, true, $log, $ai)->order(seriesQueue(), true);

    expect(orderIds($result->items))->toBe(['1', '4', '2', '6', '5', '3'])
        ->and($result->method)->toBe('php')
        ->and($result->reordered)->toBeTrue()
        ->and($log->entries)->toHaveCount(2)
        ->and($log->entries[0]->outcome)->toBe('error')
        ->and($log->entries[0]->errorCode)->toBe('ai_http')
        ->and($log->entries[1]->outcome)->toBe('success')
        ->and($log->entries[1]->model)->toBe('php')
        ->and($log->entries[1]->note)->toContain('fallback');
});

it('falls back to PHP when AI is chosen but no AI service is set up', function (): void {
    $log = new MemoryLog;
    $result = queueOrderer(true, OrderingMethod::Ai, false, $log, null)->order(seriesQueue(), true);

    expect($result->method)->toBe('php')->and($log->entries)->toHaveCount(1)->and($log->entries[0]->note)->toContain('fallback');
});

it('leaves a queue of one or none alone', function (): void {
    $log = new MemoryLog;
    $orderer = queueOrderer(true, OrderingMethod::Php, true, $log);

    expect($orderer->order([], true)->items)->toBe([])
        ->and(orderIds($orderer->order([seriesQueue()[0]], true)->items))->toBe(['1'])
        ->and($log->entries)->toBe([]);
});
