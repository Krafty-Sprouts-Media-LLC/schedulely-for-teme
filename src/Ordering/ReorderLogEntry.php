<?php
/**
 * Filename: ReorderLogEntry.php
 * Author: Krafty Sprouts Media, LLC
 * Description: One row of the reorder log.
 *
 * @package KraftySprouts\Schedulely
 * @since   0.1.0
 */

declare(strict_types=1);

namespace KraftySprouts\Schedulely\Ordering;

/**
 * One attempt to order the queue: which engine, how it went, and a plain note. Never
 * holds an API key.
 *
 * @since 0.1.0
 */
final readonly class ReorderLogEntry
{
    /**
     * @param  string  $outcome  success or error
     * @param  string  $model  the model's name, or php
     *
     * @since 0.1.0
     */
    public function __construct(
        public string $outcome,
        public string $model,
        public int $itemCount,
        public ?int $httpCode = null,
        public ?int $usageTokens = null,
        public string $errorCode = '',
        public string $errorMessage = '',
        public string $assistantExcerpt = '',
        public string $rawExcerpt = '',
        public string $note = '',
    ) {}
}
