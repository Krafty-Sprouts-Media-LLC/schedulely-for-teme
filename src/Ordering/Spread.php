<?php
/**
 * Filename: Spread.php
 * Author: Krafty Sprouts Media, LLC
 * Description: How PHP ordering spaces the posts of a series apart.
 *
 * @package KraftySprouts\Schedulely
 * @since   0.1.0
 */

declare(strict_types=1);

namespace KraftySprouts\Schedulely\Ordering;

/**
 * Even spacing across the whole queue, or one post per series per pass.
 *
 * @since 0.1.0
 */
enum Spread: string
{
    case Even = 'even';
    case RoundRobin = 'round_robin';

    /**
     * The strategy named by a stored value, Even when it is unknown.
     *
     * @since 0.1.0
     */
    public static function fromStored(mixed $value): self
    {
        return is_string($value) ? (self::tryFrom($value) ?? self::Even) : self::Even;
    }
}
