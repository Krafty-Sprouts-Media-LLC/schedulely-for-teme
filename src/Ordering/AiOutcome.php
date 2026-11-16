<?php
/**
 * Filename: AiOutcome.php
 * Author: Krafty Sprouts Media, LLC
 * Description: What one AI ordering attempt came to, for the scheduler and the log.
 *
 * @package KraftySprouts\Schedulely
 * @since   0.1.0
 */

declare(strict_types=1);

namespace KraftySprouts\Schedulely\Ordering;

/**
 * Either an ordered list of item IDs or an error, with the details the reorder log keeps.
 * Nothing here ever holds the API key.
 *
 * @since 0.1.0
 */
final readonly class AiOutcome
{
    /**
     * @param  list<string>  $orderedIds  the queue in the model's order, empty on failure
     *
     * @since 0.1.0
     */
    public function __construct(
        public bool $ok,
        public array $orderedIds = [],
        public string $errorCode = '',
        public string $errorMessage = '',
        public ?int $httpCode = null,
        public ?int $usageTokens = null,
        public string $assistantExcerpt = '',
        public string $rawExcerpt = '',
        public string $note = '',
    ) {}
}
