<?php
/**
 * Filename: ArraySettings.php
 * Author: Krafty Sprouts Media, LLC
 * Description: A SettingsReader over plain arrays, for tests.
 *
 * @package KraftySprouts\Schedulely
 *
 * @since   0.1.0
 */

declare(strict_types=1);

namespace KraftySprouts\Schedulely\Tests\Support;

use Teme\Contracts\Settings\SettingsReader;

/**
 * Settings held as [group => [key => value]]; an unset key answers null, as a setting
 * with no declared default would.
 *
 * @since 0.1.0
 */
final class ArraySettings implements SettingsReader
{
    /**
     * @param  array<string, array<string, mixed>>  $values
     *
     * @since 0.1.0
     */
    public function __construct(private array $values = []) {}

    public function group(string $group): array
    {
        return $this->values[$group] ?? [];
    }

    public function get(string $group, string $key): mixed
    {
        return $this->values[$group][$key] ?? null;
    }

    public function string(string $group, string $key): string
    {
        return (string) ($this->get($group, $key) ?? '');
    }

    public function int(string $group, string $key): int
    {
        return (int) ($this->get($group, $key) ?? 0);
    }

    public function bool(string $group, string $key): bool
    {
        return (bool) ($this->get($group, $key) ?? false);
    }

    public function stringList(string $group, string $key): array
    {
        $value = $this->get($group, $key);

        return is_array($value) ? array_map('strval', array_values($value)) : [];
    }
}
