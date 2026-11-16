<?php
/**
 * Filename: StateStore.php
 * Author: Krafty Sprouts Media, LLC
 * Description: Small named values the plugin remembers between requests.
 *
 * @package KraftySprouts\Schedulely
 * @since   0.1.0
 */

declare(strict_types=1);

namespace KraftySprouts\Schedulely\Storage;

use Illuminate\Database\ConnectionInterface;

/**
 * A tiny key to JSON store: the time of the last run, the last run's report.
 * Settings stay in Teme's own settings; these are values a person never edits.
 *
 * @since 0.1.0
 */
final readonly class StateStore
{
    private const string TABLE = 'schedulely_state';

    /** @since 0.1.0 */
    public function __construct(private ConnectionInterface $db) {}

    /** @since 0.1.0 */
    public function get(string $name, mixed $default = null): mixed
    {
        $value = $this->db->table(self::TABLE)->where('name', $name)->value('value');

        return $value === null ? $default : json_decode((string) $value, true);
    }

    /** @since 0.1.0 */
    public function put(string $name, mixed $value): void
    {
        $this->db->table(self::TABLE)->upsert([['name' => $name, 'value' => json_encode($value, JSON_THROW_ON_ERROR)]], ['name'], ['value']);
    }

    /** @since 0.1.0 */
    public function forget(string $name): void
    {
        $this->db->table(self::TABLE)->where('name', $name)->delete();
    }
}
