<?php
/**
 * Filename: ActingPeople.php
 * Author: Krafty Sprouts Media, LLC
 * Description: Who each write is made for.
 *
 * @package KraftySprouts\Schedulely
 *
 * @since   0.1.0
 */

declare(strict_types=1);

namespace KraftySprouts\Schedulely\Engine;

use Random\Randomizer;

/**
 * Every ContentWriter call names a person it acts for. With "random authors" on, each
 * write acts for a random one of the listed accounts; otherwise for the fallback.
 *
 * @since 0.1.0
 */
final readonly class ActingPeople
{
    /**
     * @param  string  $fallback  the account used when no pool is set
     * @param  list<string>  $pool  eligible account IDs, excluded ones already removed
     *
     * @since 0.1.0
     */
    public function __construct(private string $fallback, private array $pool, private Randomizer $random) {}

    /**
     * The account the next write acts for.
     *
     * @since 0.1.0
     */
    public function pick(): string
    {
        return $this->pool === [] ? $this->fallback : $this->pool[$this->random->getInt(0, count($this->pool) - 1)];
    }
}
