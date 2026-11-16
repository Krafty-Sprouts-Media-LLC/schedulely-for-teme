<?php
/**
 * Filename: RunReport.php
 * Author: Krafty Sprouts Media, LLC
 * Description: What a scheduling run did.
 *
 * @package KraftySprouts\Schedulely
 *
 * @since   0.1.0
 */

declare(strict_types=1);

namespace KraftySprouts\Schedulely\Engine;

/**
 * The result of one run: counts, the items scheduled, the windows touched, and errors.
 *
 * @since 0.1.0
 */
final readonly class RunReport
{
    /**
     * @param  list<ScheduledItem>  $scheduled
     * @param  list<string>  $errors  one plain sentence each
     * @param  list<string>  $dates  anchor dates of every window the run touched, ascending
     * @param  array<string, int>|null  $timezoneDistribution  items per US timezone group, when timezone ordering was on
     *
     * @since 0.1.0
     */
    public function __construct(
        public bool $success,
        public int $scheduledCount,
        public int $queueTotal,
        public int $failedCount,
        public bool $completedLastDate,
        public string $message,
        public array $scheduled = [],
        public array $errors = [],
        public array $dates = [],
        public ?int $lastPostAt = null,
        public ?array $timezoneDistribution = null,
    ) {}
}
