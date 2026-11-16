<?php
/**
 * Filename: RunOutcome.php
 * Author: Krafty Sprouts Media, LLC
 * Description: What a run came to, including the queue counts WordPress's email carried.
 *
 * @package KraftySprouts\Schedulely
 *
 * @since   0.1.0
 */

declare(strict_types=1);

namespace KraftySprouts\Schedulely\Run;

use KraftySprouts\Schedulely\Engine\RunReport;

/**
 * Whether a run happened, why not if it did not, and the engine's report with how many
 * items were eligible, not loaded because of the pool size, and still waiting after.
 *
 * @since 0.1.0
 */
final readonly class RunOutcome
{
    /** @since 0.1.0 */
    public function __construct(
        public bool $ran,
        public string $reason,
        public RunReport $report,
        public int $eligibleAtStart = 0,
        public int $notLoaded = 0,
        public int $eligibleRemaining = 0,
        public string $orderedBy = '',
        public bool $quiet = false,
    ) {}

    /**
     * A run that did not start, and the sentence saying why. A quiet skip is expected
     * (automatic scheduling is off, or no run is due) and is not worth telling anyone.
     *
     * @since 0.1.0
     */
    public static function skipped(string $reason, bool $quiet = false): self
    {
        return new self(false, $reason, new RunReport(false, 0, 0, 0, false, $reason), quiet: $quiet);
    }
}
