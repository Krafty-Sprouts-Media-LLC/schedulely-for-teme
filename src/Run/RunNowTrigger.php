<?php
/**
 * Filename: RunNowTrigger.php
 * Author: Krafty Sprouts Media, LLC
 * Description: Starts a run when the "Run now" setting is saved on.
 *
 * @package KraftySprouts\Schedulely
 * @since   0.1.0
 */

declare(strict_types=1);

namespace KraftySprouts\Schedulely\Run;

use KraftySprouts\Schedulely\Settings\Defaults;
use KraftySprouts\Schedulely\Settings\Options;
use KraftySprouts\Schedulely\Support\Words;
use Teme\Contracts\Hooks\Hooks;
use Teme\Contracts\Jobs\Jobs;
use Teme\Contracts\Settings\SettingsChanged;
use Teme\Contracts\Settings\SettingsReader;

/**
 * Teme has no socket for a button on a plugin's screen, so "Run Schedule Now" is a toggle:
 * saving the group with it on queues a run for the person who saved. The plugin cannot
 * switch its own setting back, so running again means turning it off, saving, and on again.
 *
 * @since 0.1.0
 */
final readonly class RunNowTrigger
{
    /** @since 0.1.0 */
    public function __construct(private SettingsReader $settings, private Jobs $jobs) {}

    /**
     * Subscribes to settings changes.
     *
     * @since 0.1.0
     */
    public function register(Hooks $hooks): void
    {
        $hooks->on(SettingsChanged::class, $this->settingsChanged(...));
    }

    /**
     * @return string|null the queued job's ID, or null when nothing was queued
     *
     * @since 0.1.0
     */
    public function settingsChanged(SettingsChanged $event): ?string
    {
        if ($event->group !== Defaults::GROUP || ! in_array('run_now', $event->keys, true)) {
            return null;
        }

        if (! Options::read($this->settings)->runNow) {
            return null;
        }

        return $this->jobs->queue('run', ['actor' => (string) ($event->actorId ?? '')], Words::say('Schedule the queued items'));
    }
}
