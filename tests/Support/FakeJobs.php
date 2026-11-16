<?php
/**
 * Filename: FakeJobs.php
 * Author: Krafty Sprouts Media, LLC
 * Description: A Jobs door that only remembers what was queued.
 *
 * @package KraftySprouts\Schedulely
 *
 * @since   0.1.0
 */

declare(strict_types=1);

namespace KraftySprouts\Schedulely\Tests\Support;

use Teme\Contracts\Jobs\Jobs;

/**
 * Records every queue() call and hands back a numbered job ID.
 *
 * @since 0.1.0
 */
class FakeJobs implements Jobs
{
    /** @var list<array{key: string, payload: array<string, mixed>, label: string|null}> */
    public array $queued = [];

    public function queue(string $key, array $payload = [], ?string $label = null): string
    {
        $this->queued[] = ['key' => $key, 'payload' => $payload, 'label' => $label];

        return 'job-'.count($this->queued);
    }
}
