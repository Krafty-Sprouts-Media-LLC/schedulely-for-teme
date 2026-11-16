<?php
/**
 * Filename: Options.php
 * Author: Krafty Sprouts Media, LLC
 * Description: Schedulely's settings, read and checked once into one value object.
 *
 * @package KraftySprouts\Schedulely
 *
 * @since   0.1.0
 */

declare(strict_types=1);

namespace KraftySprouts\Schedulely\Settings;

use DateTimeZone;
use KraftySprouts\Schedulely\Engine\SchedulingMode;
use KraftySprouts\Schedulely\Ordering\AiProvider;
use KraftySprouts\Schedulely\Ordering\OrderingMethod;
use KraftySprouts\Schedulely\Ordering\Spread;
use KraftySprouts\Schedulely\Time\TimeOfDay;
use Teme\Contracts\Settings\SettingsReader;
use Throwable;

/**
 * What the settings say, clamped to the ranges the WordPress plugin enforced. Read it
 * when a run starts, not in a constructor, so a saved change counts on the next run.
 *
 * @since 0.1.0
 */
final readonly class Options
{
    /**
     * @param  list<string>  $types
     * @param  list<int>  $activeDays
     * @param  list<string>  $authorAccounts
     * @param  list<string>  $excludedAccounts
     *
     * @since 0.1.0
     */
    public function __construct(
        public string $status,
        public array $types,
        public int $quota,
        public int $poolSize,
        public int $batchSize,
        public TimeOfDay $start,
        public TimeOfDay $end,
        public int $minInterval,
        public array $activeDays,
        public SchedulingMode $mode,
        public int $safetyBufferSeconds,
        public bool $reorder,
        public OrderingMethod $method,
        public Spread $spread,
        public bool $timezoneOrdering,
        public bool $shuffle,
        public AiProvider $aiProvider,
        public string $aiModel,
        public int $aiMaxItems,
        public bool $randomizeAuthors,
        public array $authorAccounts,
        public array $excludedAccounts,
        public string $actingAccount,
        public bool $autoSchedule,
        public int $autoIntervalHours,
        public bool $runNow,
        public string $timezone,
    ) {}

    /**
     * Reads the plugin's group, and the site's timezone from the `general` group.
     *
     * @since 0.1.0
     */
    public static function read(SettingsReader $settings): self
    {
        $get = static fn (string $key): mixed => $settings->get(Defaults::GROUP, $key) ?? Defaults::ALL[$key] ?? null;

        $status = (string) $get('post_status');
        $interval = (int) $get('auto_interval');

        return new self(
            status: in_array($status, ['draft', 'review'], true) ? $status : 'draft',
            types: self::words((string) $get('post_types'), ['post'], lower: true),
            quota: self::clamp($get('posts_per_day'), 1, 100),
            poolSize: self::clamp($get('pool_size'), 1, 10000),
            batchSize: self::clamp($get('batch_size'), 10, 500),
            start: TimeOfDay::parse((string) $get('start_time')) ?? TimeOfDay::parse((string) Defaults::ALL['start_time']) ?? new TimeOfDay(61200),
            end: TimeOfDay::parse((string) $get('end_time')) ?? TimeOfDay::parse((string) Defaults::ALL['end_time']) ?? new TimeOfDay(82800),
            minInterval: self::clamp($get('min_interval'), 1, 1440),
            activeDays: self::days($get('active_days')),
            mode: SchedulingMode::fromStored($get('scheduling_mode')),
            safetyBufferSeconds: self::clamp($get('safety_buffer'), 0, 1440) * 60,
            reorder: (bool) $get('reorder_queue'),
            method: OrderingMethod::fromStored($get('ordering_method')),
            spread: Spread::fromStored($get('php_spread')),
            timezoneOrdering: (bool) $get('timezone_ordering'),
            shuffle: (bool) $get('shuffle_queue'),
            aiProvider: AiProvider::fromStored($get('ai_provider')),
            aiModel: self::model((string) $get('ai_model')),
            aiMaxItems: self::clamp($get('ai_max_items'), 1, 1000),
            randomizeAuthors: (bool) $get('randomize_authors'),
            authorAccounts: self::words((string) $get('author_accounts'), []),
            excludedAccounts: self::words((string) $get('excluded_accounts'), []),
            actingAccount: trim((string) $get('acting_account')),
            autoSchedule: (bool) $get('auto_schedule'),
            autoIntervalHours: in_array($interval, [1, 6, 12, 24], true) ? $interval : 12,
            runNow: (bool) $get('run_now'),
            timezone: self::timezone($settings),
        );
    }

    /**
     * The accounts a random-author run may act for: the listed ones minus the excluded
     * ones, and none at all when random authors are off.
     *
     * @return list<string>
     *
     * @since 0.1.0
     */
    public function authorPool(): array
    {
        if (! $this->randomizeAuthors) {
            return [];
        }

        return array_values(array_diff($this->authorAccounts, $this->excludedAccounts));
    }

    private static function clamp(mixed $value, int $min, int $max): int
    {
        return max($min, min($max, is_numeric($value) ? (int) $value : $min));
    }

    /**
     * @param  list<string>  $fallback
     *
     * @return list<string>
     */
    private static function words(string $text, array $fallback, bool $lower = false): array
    {
        $parts = preg_split('/[\s,;]+/', $lower ? strtolower($text) : $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $parts = array_values(array_unique($parts));

        return $parts === [] ? $fallback : $parts;
    }

    /**
     * @return list<int>
     */
    private static function days(mixed $value): array
    {
        $days = [];

        foreach (is_array($value) ? $value : [] as $day) {
            if (is_numeric($day) && (int) $day >= 0 && (int) $day <= 6) {
                $days[(int) $day] = (int) $day;
            }
        }

        ksort($days);

        return $days === [] ? [0, 1, 2, 3, 4, 5, 6] : array_values($days);
    }

    private static function model(string $model): string
    {
        $model = trim($model);

        return $model === '' ? (string) Defaults::ALL['ai_model'] : substr($model, 0, 120);
    }

    private static function timezone(SettingsReader $settings): string
    {
        $name = $settings->get('general', 'timezone');

        if (! is_string($name) || $name === '') {
            return 'UTC';
        }

        try {
            new DateTimeZone($name);
        } catch (Throwable) {
            return 'UTC';
        }

        return $name;
    }
}
