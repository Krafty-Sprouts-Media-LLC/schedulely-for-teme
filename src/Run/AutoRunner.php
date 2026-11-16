<?php
/**
 * Filename: AutoRunner.php
 * Author: Krafty Sprouts Media, LLC
 * Description: The gate in front of the hourly schedule: runs only when automatic
 *              scheduling is on and the chosen interval has passed.
 *
 * @package KraftySprouts\Schedulely
 * @since   0.1.0
 */

declare(strict_types=1);

namespace KraftySprouts\Schedulely\Run;

use Closure;
use Illuminate\Database\ConnectionInterface;
use KraftySprouts\Schedulely\Settings\Options;
use KraftySprouts\Schedulely\Storage\StateStore;
use KraftySprouts\Schedulely\Support\Words;
use Teme\Contracts\Settings\SettingsReader;

/**
 * WordPress switched a cron event on and off and ran it twice a day. Teme's schedules
 * are fixed in the manifest, so the manifest says "hourly" and this class decides, on
 * each tick, whether a run is due.
 *
 * @since 0.1.0
 */
final readonly class AutoRunner
{
    /** The hourly tick can arrive a little early; this much slack keeps a 12 hour interval from becoming 13. */
    private const int DRIFT_SECONDS = 300;

    /**
     * @param  Closure(): int  $clock
     *
     * @since 0.1.0
     */
    public function __construct(
        private SettingsReader $settings,
        private ConnectionInterface $db,
        private Closure $clock,
        private RunService $service,
    ) {}

    /**
     * Runs if it is due; otherwise says quietly why not.
     *
     * @since 0.1.0
     */
    public function tick(): RunOutcome
    {
        $options = Options::read($this->settings);

        if (! $options->autoSchedule) {
            return RunOutcome::skipped(Words::say('Automatic scheduling is off.'), quiet: true);
        }

        $last = (new StateStore($this->db))->get('last_run');
        $now = ($this->clock)();

        if (is_int($last) && $now - $last < $options->autoIntervalHours * 3600 - self::DRIFT_SECONDS) {
            return RunOutcome::skipped(Words::say('The automatic run is not due yet.'), quiet: true);
        }

        return $this->service->run(manual: false);
    }
}
