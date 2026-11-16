<?php
/**
 * Filename: Words.php
 * Author: Krafty Sprouts Media, LLC
 * Description: The one place every word a person reads goes through, so a site in
 *              another language can translate it.
 *
 * @package KraftySprouts\Schedulely
 *
 * @since   0.1.0
 */

declare(strict_types=1);

namespace KraftySprouts\Schedulely\Support;

use Throwable;

/**
 * Says an English sentence through Teme's translator when one is running, and as
 * written when none is (in a unit test, say). Placeholders are `:name`.
 *
 * @since 0.1.0
 */
final class Words
{
    /**
     * @param  array<string, string|int|float>  $replace  placeholder name (without the colon) to value
     *
     * @since 0.1.0
     */
    public static function say(string $english, array $replace = []): string
    {
        $values = [];
        foreach ($replace as $name => $value) {
            $values[$name] = (string) $value;
        }

        if (function_exists('__')) {
            try {
                $translated = __($english, $values);

                if (is_string($translated)) {
                    return $translated;
                }
            } catch (Throwable) {
                // No translator is running; fall through to plain English.
            }
        }

        $pairs = [];
        foreach ($values as $name => $value) {
            $pairs[':'.$name] = $value;
        }

        return strtr($english, $pairs);
    }
}
