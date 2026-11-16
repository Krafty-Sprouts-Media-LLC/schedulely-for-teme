<?php
/**
 * Filename: PhpOrderingTest.php
 * Author: Krafty Sprouts Media, LLC
 * Description: Ordering the queue in PHP: grouping items into series by title stem
 *              (target US state removed) and spreading each series evenly.
 *
 * @package KraftySprouts\Schedulely
 *
 * @since   0.1.0
 */

declare(strict_types=1);

use KraftySprouts\Schedulely\Ordering\PhpOrderer;
use KraftySprouts\Schedulely\Ordering\SeriesStem;
use KraftySprouts\Schedulely\Ordering\Spread;
use KraftySprouts\Schedulely\Queue\QueueItem;

function queued(string $id, string $title): QueueItem
{
    return new QueueItem($id, 'post', $title, 'en', 'draft', 1000);
}

function ids(array $items): array
{
    return array_map(static fn (QueueItem $item): string => $item->id, $items);
}

function seriesFixture(): array
{
    return [
        queued('1', 'Best Pizza in Texas'),
        queued('2', 'Best Pizza in Ohio'),
        queued('3', 'Best Pizza in Utah'),
        queued('4', 'Best Tacos in Texas'),
        queued('5', 'Best Tacos in Ohio'),
        queued('6', 'An Unrelated Post'),
    ];
}

it('strips the target state from a title to find its series', function (string $title, string $stem): void {
    expect(SeriesStem::of($title))->toBe($stem);
})->with([
    ['Best Pizza in Texas', 'best pizza in'],
    ['Rabies Vaccine Requirements for Cats in North Carolina', 'rabies vaccine requirements for cats in'],
    ['Hunting Seasons in West Virginia', 'hunting seasons in'],
    ['Hunting Seasons in Virginia', 'hunting seasons in'],
    ['Best Pizza in Arkansas', 'best pizza in'],
    ['An Unrelated Post!', 'an unrelated post'],
    ['Texas', 'texas'],
]);

it('keeps a title that is nothing but a state as its own stem', function (): void {
    expect(SeriesStem::of('Ohio'))->toBe('ohio');
});

it('spreads each series evenly across the queue by default', function (): void {
    expect(ids((new PhpOrderer(Spread::Even))->order(seriesFixture())))->toBe(['1', '4', '2', '6', '5', '3']);
});

it('takes one item per series on each pass in round robin', function (): void {
    expect(ids((new PhpOrderer(Spread::RoundRobin))->order(seriesFixture())))->toBe(['1', '4', '6', '2', '5', '3']);
});

it('keeps every item exactly once', function (Spread $spread): void {
    $items = seriesFixture();
    $ordered = ids((new PhpOrderer($spread))->order($items));

    expect($ordered)->toHaveCount(6)->and(array_unique($ordered))->toHaveCount(6);
})->with([Spread::Even, Spread::RoundRobin]);

it('never puts two items of a big series side by side when the rest can separate them', function (): void {
    $items = [];
    foreach (range(1, 6) as $i) {
        $items[] = queued("a{$i}", 'Guide to Parking in '.['Texas', 'Ohio', 'Utah', 'Iowa', 'Maine', 'Idaho'][$i - 1]);
    }
    foreach (range(1, 6) as $i) {
        $items[] = queued("b{$i}", "Standalone Article Number {$i}");
    }

    $ordered = (new PhpOrderer(Spread::Even))->order($items);
    $stems = array_map(static fn (QueueItem $item): string => SeriesStem::of($item->title), $ordered);

    foreach ($stems as $position => $stem) {
        if ($position > 0 && str_starts_with($stem, 'guide to parking')) {
            expect($stems[$position - 1])->not->toBe($stem);
        }
    }
});

it('returns an empty queue and a single item unchanged', function (): void {
    $orderer = new PhpOrderer(Spread::Even);

    expect($orderer->order([]))->toBe([])
        ->and(ids($orderer->order([queued('x', 'Only One')])))->toBe(['x']);
});

it('orders the same way every time', function (): void {
    $orderer = new PhpOrderer(Spread::Even);

    expect(ids($orderer->order(seriesFixture())))->toBe(ids($orderer->order(seriesFixture())));
});

it('describes what it found for the reorder log', function (): void {
    $summary = (new PhpOrderer(Spread::Even))->summary(seriesFixture());

    expect($summary)->toContain('3 series')->and($summary)->toContain('6 items')
        ->and($summary)->toContain('best pizza in')->and($summary)->toContain('even');
});
