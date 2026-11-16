<?php
/**
 * Filename: NoticeCheck.php
 * Author: Krafty Sprouts Media, LLC
 * Description: One status check, answered by a closure.
 *
 * @package KraftySprouts\Schedulely
 *
 * @since   0.1.0
 */

declare(strict_types=1);

namespace KraftySprouts\Schedulely\Status;

use Closure;
use Teme\Contracts\Status\StatusCheck;
use Teme\Contracts\Status\StatusNotice;

/**
 * Teme asks every check on every admin page; this one asks its closure.
 *
 * @since 0.1.0
 */
final readonly class NoticeCheck implements StatusCheck
{
    /**
     * @param  Closure(): ?StatusNotice  $answer
     *
     * @since 0.1.0
     */
    public function __construct(private Closure $answer) {}

    /**
     * @since 0.1.0
     */
    public function notice(): ?StatusNotice
    {
        return ($this->answer)();
    }
}
