<?php
/**
 * Filename: RunJob.php
 * Author: Krafty Sprouts Media, LLC
 * Description: The "run" job: one manual scheduling run, with progress on the Jobs screen.
 *
 * @package KraftySprouts\Schedulely
 * @since   0.1.0
 */

declare(strict_types=1);

namespace KraftySprouts\Schedulely\Run;

use KraftySprouts\Schedulely\Support\Words;
use Teme\Contracts\Jobs\JobContext;
use Teme\Contracts\Jobs\JobHandler;

/**
 * Replaces WordPress's batched "Schedule Now" AJAX loop: one background job, reporting
 * progress as it goes, that stops when the person cancels it.
 *
 * @since 0.1.0
 */
final readonly class RunJob implements JobHandler
{
    /** @since 0.1.0 */
    public function __construct(private RunService $service) {}

    /**
     * @since 0.1.0
     */
    public function handle(JobContext $context): void
    {
        $actor = (string) ($context->payload()['actor'] ?? '');

        $outcome = $this->service->run(
            manual: true,
            actor: $actor === '' ? null : $actor,
            progress: static function (int $done, int $total) use ($context): void {
                $context->progress($done, $total, Words::say('Scheduled :done of :total items.', ['done' => $done, 'total' => $total]));
            },
        );

        if (! $outcome->ran || $outcome->report->queueTotal === 0) {
            $context->progress(0, 0, $outcome->reason !== '' ? $outcome->reason : $outcome->report->message);
        }
    }
}
