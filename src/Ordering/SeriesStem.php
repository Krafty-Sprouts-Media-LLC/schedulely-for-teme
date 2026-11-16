<?php
/**
 * Filename: SeriesStem.php
 * Author: Krafty Sprouts Media, LLC
 * Description: Finds the series an item belongs to by removing the US state from
 *              its slug.
 *
 * @package KraftySprouts\Schedulely
 * @since   0.1.0
 */

declare(strict_types=1);

namespace KraftySprouts\Schedulely\Ordering;

/**
 * "best-pizza-in-texas" and "best-pizza-in-ohio" share the stem "best pizza in".
 * Teme tells a plugin an item's title, not its slug, so the slug is made from the
 * title, which is what WordPress does for a draft that has no slug yet.
 *
 * @since 0.1.0
 */
final class SeriesStem
{
    /**
     * The stem of a title (or of a slug, when one is given).
     *
     * @since 0.1.0
     */
    public static function of(string $title, string $slug = ''): string
    {
        $slug = $slug !== '' ? strtolower($slug) : self::slugify($title);
        $hay = ' '.trim((string) preg_replace('/[^a-z]+/', ' ', $slug)).' ';

        foreach (UsStates::longestFirst() as $state) {
            $hay = str_replace(' '.$state.' ', ' ', $hay);
        }

        $stem = trim((string) preg_replace('/\s+/', ' ', $hay));

        return $stem === '' ? $slug : $stem;
    }

    /**
     * A URL-style slug for a title: lower-case letters and digits joined by hyphens.
     *
     * @since 0.1.0
     */
    public static function slugify(string $title): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $title);
        $text = strtolower($ascii === false ? $title : $ascii);

        return trim((string) preg_replace('/[^a-z0-9]+/', '-', $text), '-');
    }
}
