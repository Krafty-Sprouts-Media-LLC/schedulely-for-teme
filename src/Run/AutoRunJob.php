<?php
/**
 * Filename: AutoRunJob.php
 * Author: Krafty Sprouts Media, LLC
 * Description: The "auto-run" job Teme's hourly schedule queues.
 *
 * @package KraftySprouts\Schedulely
 * @since   0.1.0
 */

declare(strict_types=1);

namespace KraftySprouts\Schedulely\Run;

use Teme\Contracts\Jobs\JobContext;
use Teme\Contracts\Jobs\JobHandler;

/**
 * Asks the gate whether a run is due and, if so, runs. It stays silent when automatic
 * scheduling is off or no run is due, and says so when it could not run for a reason a
 * person can fix.
 *
 * @since 0.1.0
 */
final readonly class AutoRunJob implements JobHandler
{
    /** @since 0.1.0 */
    public function __construct(private AutoRunner $runner) {}

    /**
     * @since 0.1.0
     */
    public function handle(JobContext $context): void
    {
        $outcome = $this->runner->tick();

        if (! $outcome->ran && ! $outcome->quiet) {
            $context->progress(0, 0, $outcome->reason);
        }
    }
}
