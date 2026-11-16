<?php
/**
 * Filename: SchedulingMode.php
 * Author: Krafty Sprouts Media, LLC
 * Description: The three ways Schedulely places items inside a day's window.
 *
 * @package KraftySprouts\Schedulely
 *
 * @since   0.1.0
 */

declare(strict_types=1);

namespace KraftySprouts\Schedulely\Engine;

/**
 * How times are chosen: at random, in even slots, or at random inside even slots.
 *
 * @since 0.1.0
 */
enum SchedulingMode: string
{
    case Random = 'random';
    case Sequential = 'sequential';
    case Hybrid = 'hybrid';

    /**
     * Whether times come from precomputed slots rather than random trial.
     *
     * @since 0.1.0
     */
    public function usesSlots(): bool
    {
        return $this !== self::Random;
    }

    /**
     * The mode named by a stored value, Random when the value is unknown.
     *
     * @since 0.1.0
     */
    public static function fromStored(mixed $value): self
    {
        return is_string($value) ? (self::tryFrom($value) ?? self::Random) : self::Random;
    }
}
