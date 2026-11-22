<?php
/**
 * Filename: FakeContext.php
 * Author: Krafty Sprouts Media, LLC
 * Description: A JobContext that records progress and can stop a run.
 *
 * @package KraftySprouts\Schedulely
 *
 * @since   0.1.0
 */

declare(strict_types=1);

namespace KraftySprouts\Schedulely\Tests\Support;

use Teme\Contracts\Jobs\JobContext;
use Teme\Contracts\Jobs\JobStopped;

/**
 * What a running job sees: its payload, and a progress call that remembers what it was told.
 *
 * @since 0.1.0
 */
final class FakeContext implements JobContext
{
    /** @var list<array{0: int, 1: int, 2: string}> */
    public array $progress = [];

    /**
     * @param  array<string, mixed>  $payload
     * @param  int|null  $stopAfter  throw JobStopped on the call after this many progress reports
     */
    public function __construct(private array $payload = [], private ?int $stopAfter = null) {}

    public function payload(): array
    {
        return $this->payload;
    }

    public function attempt(): int
    {
        return 1;
    }

    public function personId(): string
    {
        return (string) ($this->payload['actor'] ?? 'test-person');
    }

    public function progress(int $done, int $total, string $message = ''): void
    {
        if ($this->stopAfter !== null && count($this->progress) >= $this->stopAfter) {
            throw new JobStopped('The run was cancelled.');
        }

        $this->progress[] = [$done, $total, $message];
    }
}
