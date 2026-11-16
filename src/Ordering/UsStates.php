<?php
/**
 * Filename: UsStates.php
 * Author: Krafty Sprouts Media, LLC
 * Description: The US states, grouped by the timezone Schedulely treats each as in.
 *
 * @package KraftySprouts\Schedulely
 *
 * @since   0.1.0
 */

declare(strict_types=1);

namespace KraftySprouts\Schedulely\Ordering;

/**
 * Full state names by timezone group. A state that spans two zones sits in its
 * eastern-most one, so no tie ever needs breaking. The District of Columbia is
 * handled by the caller, because "washington" alone is the Pacific state.
 *
 * @since 0.1.0
 */
final class UsStates
{
    /**
     * @var array<string, list<string>>
     *
     * @since 0.1.0
     */
    public const array BY_ZONE = [
        'eastern' => [
            'connecticut', 'maine', 'massachusetts', 'new hampshire', 'rhode island',
            'vermont', 'new york', 'new jersey', 'pennsylvania', 'delaware', 'maryland',
            'west virginia', 'virginia', 'north carolina', 'south carolina', 'georgia',
            'florida', 'ohio', 'michigan', 'indiana', 'kentucky', 'tennessee',
        ],
        'central' => [
            'wisconsin', 'illinois', 'missouri', 'arkansas', 'louisiana', 'mississippi',
            'alabama', 'minnesota', 'iowa', 'north dakota', 'south dakota', 'nebraska',
            'kansas', 'oklahoma', 'texas',
        ],
        'mountain' => [
            'montana', 'idaho', 'wyoming', 'colorado', 'new mexico', 'arizona', 'utah', 'nevada',
        ],
        'pacific' => [
            'washington', 'oregon', 'california', 'alaska', 'hawaii',
        ],
    ];

    /**
     * Every state name, longest first, so "west virginia" is removed before "virginia".
     *
     * @return list<string>
     *
     * @since 0.1.0
     */
    public static function longestFirst(): array
    {
        static $list = null;

        if ($list === null) {
            $list = array_merge(...array_values(self::BY_ZONE));
            usort($list, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
        }

        return $list;
    }
}
