<?php
/**
 * Filename: OrderingMethod.php
 * Author: Krafty Sprouts Media, LLC
 * Description: Which engine orders the queue when reordering is on.
 *
 * @package KraftySprouts\Schedulely
 *
 * @since   0.1.0
 */

declare(strict_types=1);

namespace KraftySprouts\Schedulely\Ordering;

/**
 * PHP ordering (instant, no network) or AI ordering (falls back to PHP on failure).
 *
 * @since 0.1.0
 */
enum OrderingMethod: string
{
    case Php = 'php';
    case Ai = 'ai';

    /**
     * The method named by a stored value, PHP when it is unknown.
     *
     * @since 0.1.0
     */
    public static function fromStored(mixed $value): self
    {
        return is_string($value) ? (self::tryFrom($value) ?? self::Php) : self::Php;
    }
}
