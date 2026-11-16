<?php
/**
 * Filename: ReorderLogStore.php
 * Author: Krafty Sprouts Media, LLC
 * Description: The rolling log of queue-ordering attempts.
 *
 * @package KraftySprouts\Schedulely
 * @since   0.1.0
 */

declare(strict_types=1);

namespace KraftySprouts\Schedulely\Storage;

use Illuminate\Database\ConnectionInterface;
use KraftySprouts\Schedulely\Ordering\ReorderLog;
use KraftySprouts\Schedulely\Ordering\ReorderLogEntry;

/**
 * Keeps the newest attempts, 25 by default and never more than 100, as WordPress does.
 *
 * @since 0.1.0
 */
final readonly class ReorderLogStore implements ReorderLog
{
    private const string TABLE = 'schedulely_reorder_log';

    private int $keep;

    /** @since 0.1.0 */
    public function __construct(private ConnectionInterface $db, int $keep = 25)
    {
        $this->keep = max(1, min(100, $keep));
    }

    /** @since 0.1.0 */
    public function append(ReorderLogEntry $entry): void
    {
        $this->db->table(self::TABLE)->insert([
            'created_at' => time(),
            'outcome' => $entry->outcome,
            'model' => $entry->model,
            'item_count' => $entry->itemCount,
            'http_code' => $entry->httpCode,
            'usage_tokens' => $entry->usageTokens,
            'error_code' => $entry->errorCode,
            'error_message' => $entry->errorMessage,
            'assistant_excerpt' => $entry->assistantExcerpt,
            'raw_excerpt' => $entry->rawExcerpt,
            'note' => $entry->note,
        ]);

        $cut = $this->db->table(self::TABLE)->orderByDesc('id')->skip($this->keep)->limit(1)->value('id');

        if ($cut !== null) {
            $this->db->table(self::TABLE)->where('id', '<=', $cut)->delete();
        }
    }

    /**
     * @return list<ReorderLogEntry> newest first
     *
     * @since 0.1.0
     */
    public function recent(): array
    {
        return $this->db->table(self::TABLE)->orderByDesc('id')->limit($this->keep)->get()
            ->map(static fn (object $row): ReorderLogEntry => new ReorderLogEntry(
                (string) $row->outcome, (string) $row->model, (int) $row->item_count,
                $row->http_code === null ? null : (int) $row->http_code,
                $row->usage_tokens === null ? null : (int) $row->usage_tokens,
                (string) $row->error_code, (string) $row->error_message,
                (string) $row->assistant_excerpt, (string) $row->raw_excerpt, (string) $row->note,
            ))->all();
    }

    /** @since 0.1.0 */
    public function clear(): void
    {
        $this->db->table(self::TABLE)->delete();
    }
}
