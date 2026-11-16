<?php
/**
 * Filename: SlotStore.php
 * Author: Krafty Sprouts Media, LLC
 * Description: The ledger of scheduled publish times, kept from the hooks that tell of them.
 *
 * @package KraftySprouts\Schedulely
 * @since   0.1.0
 */

declare(strict_types=1);

namespace KraftySprouts\Schedulely\Storage;

use Illuminate\Database\ConnectionInterface;
use KraftySprouts\Schedulely\Engine\SlotLedger;

/**
 * Every scheduled item of the managed content types and when it will be published,
 * whoever scheduled it: Schedulely itself or an editor in the admin.
 *
 * @since 0.1.0
 */
final readonly class SlotStore implements SlotLedger
{
    private const string TABLE = 'schedulely_slots';

    /**
     * @param  list<string>  $types  the content types Schedulely manages
     *
     * @since 0.1.0
     */
    public function __construct(private ConnectionInterface $db, private array $types) {}

    /**
     * Records or moves an item's scheduled time.
     *
     * @since 0.1.0
     */
    public function record(string $id, string $type, int $publishAt): void
    {
        $this->db->table(self::TABLE)->upsert([['item_id' => $id, 'type' => $type, 'publish_at' => $publishAt]], ['item_id'], ['type', 'publish_at']);
    }

    /**
     * Removes an item's slot; true when it had one.
     *
     * @since 0.1.0
     */
    public function forget(string $id): bool
    {
        return $this->db->table(self::TABLE)->where('item_id', $id)->delete() > 0;
    }

    /** @since 0.1.0 */
    public function latest(): ?int
    {
        $max = $this->db->table(self::TABLE)->whereIn('type', $this->types)->max('publish_at');

        return $max === null ? null : (int) $max;
    }

    /**
     * @return list<int>
     *
     * @since 0.1.0
     */
    public function between(int $from, int $to): array
    {
        return $this->db->table(self::TABLE)
            ->whereIn('type', $this->types)
            ->whereBetween('publish_at', [$from, $to])
            ->orderBy('publish_at')
            ->pluck('publish_at')
            ->map(static fn (mixed $value): int => (int) $value)
            ->values()
            ->all();
    }
}
