<?php
/**
 * Filename: FakeLedger.php
 * Author: Krafty Sprouts Media, LLC
 * Description: An in-memory slot ledger and item scheduler for the engine's tests.
 *
 * @package KraftySprouts\Schedulely
 * @since   0.1.0
 */

declare(strict_types=1);

namespace KraftySprouts\Schedulely\Tests\Support;

use KraftySprouts\Schedulely\Engine\ItemScheduler;
use KraftySprouts\Schedulely\Engine\SlotLedger;
use KraftySprouts\Schedulely\Queue\QueueItem;

/**
 * Holds scheduled timestamps in an array, and doubles as the ItemScheduler that adds
 * to them, so a run's own writes count toward the next day's totals as they do live.
 *
 * @since 0.1.0
 */
final class FakeLedger implements ItemScheduler, SlotLedger
{
    /** @var list<int> */
    public array $timestamps = [];

    /** @var list<array{id: string, at: int, person: string}> */
    public array $writes = [];

    /** @var array<string, int> how many times each item's write should fail before one works */
    public array $failures = [];

    /**
     * @param  list<int>  $timestamps  future items that already exist
     */
    public function __construct(array $timestamps = [])
    {
        $this->timestamps = $timestamps;
    }

    public function latest(): ?int
    {
        return $this->timestamps === [] ? null : max($this->timestamps);
    }

    public function between(int $from, int $to): array
    {
        return array_values(array_filter($this->timestamps, static fn (int $t): bool => $t >= $from && $t <= $to));
    }

    public function schedule(QueueItem $item, int $at, string $actingPerson): ?string
    {
        if (($this->failures[$item->id] ?? 0) > 0) {
            $this->failures[$item->id]--;

            return 'refused';
        }

        $this->timestamps[] = $at;
        $this->writes[] = ['id' => $item->id, 'at' => $at, 'person' => $actingPerson];

        return null;
    }
}
