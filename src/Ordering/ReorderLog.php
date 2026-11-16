<?php
/**
 * Filename: ReorderLog.php
 * Author: Krafty Sprouts Media, LLC
 * Description: Where ordering attempts are recorded.
 *
 * @package KraftySprouts\Schedulely
 * @since   0.1.0
 */

declare(strict_types=1);

namespace KraftySprouts\Schedulely\Ordering;

/**
 * A rolling record of ordering attempts, newest first, kept short.
 *
 * @since 0.1.0
 */
interface ReorderLog
{
    /** @since 0.1.0 */
    public function append(ReorderLogEntry $entry): void;
}
