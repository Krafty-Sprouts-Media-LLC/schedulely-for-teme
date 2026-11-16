<?php
/**
 * Filename: QueueStore.php
 * Author: Krafty Sprouts Media, LLC
 * Description: The table of drafts waiting to be scheduled.
 *
 * @package KraftySprouts\Schedulely
 *
 * @since   0.1.0
 */

declare(strict_types=1);

namespace KraftySprouts\Schedulely\Storage;

use Illuminate\Database\ConnectionInterface;
use KraftySprouts\Schedulely\Queue\QueueItem;

/**
 * Schedulely's own list of queued drafts, filled by hooks, because Teme gives a
 * plugin no door that lists items. "Oldest" is oldest first seen.
 *
 * @since 0.1.0
 */
final readonly class QueueStore
{
    private const string TABLE = 'schedulely_queue';

    /** @since 0.1.0 */
    public function __construct(private ConnectionInterface $db) {}

    /**
     * Adds an item, or refreshes its title, type and status. The moment it was first
     * seen never changes.
     *
     * @since 0.1.0
     */
    public function put(QueueItem $item): void
    {
        $this->db->table(self::TABLE)->upsert([[
            'item_id' => $item->id,
            'type' => $item->type,
            'title' => $item->title,
            'language' => $item->language,
            'status' => $item->status,
            'first_seen_at' => $item->firstSeenAt,
            'updated_at' => time(),
        ]], ['item_id'], ['type', 'title', 'language', 'status', 'updated_at']);
    }

    /**
     * @param  list<string>  $types
     * @param  list<string>  $statuses
     *
     * @return list<QueueItem>
     *
     * @since 0.1.0
     */
    public function oldest(array $types, array $statuses, int $limit): array
    {
        $rows = $this->db->table(self::TABLE)
            ->whereIn('type', $types)
            ->whereIn('status', $statuses)
            ->orderBy('first_seen_at')
            ->orderBy('item_id')
            ->limit(max(1, $limit))
            ->get();

        return $rows->map(static fn (object $row): QueueItem => new QueueItem(
            (string) $row->item_id, (string) $row->type, (string) $row->title,
            (string) $row->language, (string) $row->status, (int) $row->first_seen_at,
        ))->all();
    }

    /**
     * @param  list<string>  $types
     * @param  list<string>  $statuses
     *
     * @since 0.1.0
     */
    public function count(array $types, array $statuses): int
    {
        return $this->db->table(self::TABLE)->whereIn('type', $types)->whereIn('status', $statuses)->count();
    }

    /** @since 0.1.0 */
    public function has(string $id): bool
    {
        return $this->db->table(self::TABLE)->where('item_id', $id)->exists();
    }

    /**
     * Removes an item; true when it was there.
     *
     * @since 0.1.0
     */
    public function forget(string $id): bool
    {
        return $this->db->table(self::TABLE)->where('item_id', $id)->delete() > 0;
    }
}
