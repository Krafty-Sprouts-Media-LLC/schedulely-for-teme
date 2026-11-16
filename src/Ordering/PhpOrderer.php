<?php
/**
 * Filename: PhpOrderer.php
 * Author: Krafty Sprouts Media, LLC
 * Description: Orders the queue without a network call, so posts of one series are
 *              spaced apart instead of landing back to back.
 *
 * @package KraftySprouts\Schedulely
 * @since   0.1.0
 */

declare(strict_types=1);

namespace KraftySprouts\Schedulely\Ordering;

use KraftySprouts\Schedulely\Queue\QueueItem;
use KraftySprouts\Schedulely\Support\Words;

/**
 * Groups items by series stem, largest series first, then interleaves the groups.
 * Instant, free and the same every time; also the fallback when AI ordering fails.
 *
 * @since 0.1.0
 */
final readonly class PhpOrderer
{
    /** @since 0.1.0 */
    public function __construct(private Spread $spread) {}

    /**
     * @param  list<QueueItem>  $items
     * @return list<QueueItem> the same items in publishing order
     *
     * @since 0.1.0
     */
    public function order(array $items): array
    {
        $groups = $this->group($items);

        return $this->spread === Spread::RoundRobin ? $this->roundRobin($groups) : $this->even($groups);
    }

    /**
     * A sentence for the reorder log saying how the queue was grouped.
     *
     * @param  list<QueueItem>  $items
     *
     * @since 0.1.0
     */
    public function summary(array $items): string
    {
        $groups = $this->group($items);
        $largestStem = '';
        $largest = 0;

        foreach ($groups as $stem => $members) {
            if (count($members) > $largest) {
                $largest = count($members);
                $largestStem = (string) $stem;
            }
        }

        return Words::say(':series series detected across :items items; largest ":stem" = :largest items, spread by :strategy.', [
            'series' => count($groups),
            'items' => count($items),
            'stem' => $largestStem,
            'largest' => $largest,
            'strategy' => $this->spread === Spread::RoundRobin ? Words::say('round-robin') : Words::say('even distribution'),
        ]);
    }

    /**
     * @param  list<QueueItem>  $items
     * @return array<string, list<QueueItem>> stem to members, largest group first
     */
    private function group(array $items): array
    {
        $groups = [];

        foreach ($items as $item) {
            $groups[SeriesStem::of($item->title)][] = $item;
        }

        // uasort is stable, so groups of equal size keep the order their first item came in.
        uasort($groups, static fn (array $a, array $b): int => count($b) <=> count($a));

        return $groups;
    }

    /**
     * Each item gets the target position (i + 0.5) / n within its own series; sorting by
     * it keeps every series evenly spaced from the first publish day to the last.
     *
     * @param  array<string, list<QueueItem>>  $groups
     * @return list<QueueItem>
     */
    private function even(array $groups): array
    {
        $tagged = [];

        foreach ($groups as $members) {
            $count = count($members);

            foreach (array_values($members) as $index => $item) {
                $tagged[] = [($index + 0.5) / $count, $item];
            }
        }

        usort($tagged, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        return array_map(static fn (array $pair): QueueItem => $pair[1], $tagged);
    }

    /**
     * @param  array<string, list<QueueItem>>  $groups
     * @return list<QueueItem>
     */
    private function roundRobin(array $groups): array
    {
        $ordered = [];

        do {
            $progress = false;

            foreach ($groups as $stem => $members) {
                if ($members !== []) {
                    $ordered[] = array_shift($members);
                    $groups[$stem] = $members;
                    $progress = true;
                }
            }
        } while ($progress);

        return $ordered;
    }
}
