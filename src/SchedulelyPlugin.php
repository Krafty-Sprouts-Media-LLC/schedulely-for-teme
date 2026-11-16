<?php
/**
 * Filename: SchedulelyPlugin.php
 * Author: Krafty Sprouts Media, LLC
 * Description: Schedulely's entry point: the class teme-plugin.json names, which Teme
 *              builds with the doors the plugin was granted.
 *
 * @package KraftySprouts\Schedulely
 *
 * @since   0.1.0
 */

declare(strict_types=1);

namespace KraftySprouts\Schedulely;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use KraftySprouts\Schedulely\Run\AutoRunJob;
use KraftySprouts\Schedulely\Run\AutoRunner;
use KraftySprouts\Schedulely\Run\RunJob;
use KraftySprouts\Schedulely\Run\RunNowTrigger;
use KraftySprouts\Schedulely\Run\RunService;
use KraftySprouts\Schedulely\Status\StatusChecks;
use KraftySprouts\Schedulely\Storage\QueueStore;
use KraftySprouts\Schedulely\Storage\QueueTracker;
use KraftySprouts\Schedulely\Storage\SlotStore;
use Random\Randomizer;
use Teme\Contracts\Content\ContentWriter;
use Teme\Contracts\Hooks\Hooks;
use Teme\Contracts\Http\OutboundHttp;
use Teme\Contracts\Jobs\Jobs;
use Teme\Contracts\Jobs\PluginHasJobs;
use Teme\Contracts\Plugins\Plugin;
use Teme\Contracts\Settings\SecretReader;
use Teme\Contracts\Settings\SettingsReader;
use Teme\Contracts\Status\ChecksStatus;

/**
 * Listens to content hooks to learn the queue, runs the scheduling engine as two
 * background jobs, and raises status notices. Settings are read when a run starts,
 * never here, so a saved change counts on the next run.
 *
 * @since 0.1.0
 */
final class SchedulelyPlugin implements ChecksStatus, Plugin, PluginHasJobs
{
    /** @since 0.1.0 */
    public function __construct(
        private readonly SettingsReader $settings,
        private readonly SecretReader $secrets,
        private readonly ContentWriter $writer,
        private readonly OutboundHttp $http,
        private readonly Jobs $jobs,
    ) {}

    /**
     * Subscribes to the content hooks and to settings changes. Nothing slow happens here.
     *
     * @since 0.1.0
     */
    public function register(Hooks $hooks): void
    {
        $db = $this->db();

        (new QueueTracker(new QueueStore($db), new SlotStore($db, []), static fn (): int => time()))->register($hooks);
        (new RunNowTrigger($this->settings, $this->jobs))->register($hooks);
    }

    /**
     * The handlers for the two jobs the manifest declares.
     *
     * @return array{run: RunJob, 'auto-run': AutoRunJob}
     *
     * @since 0.1.0
     */
    public function jobs(): array
    {
        $service = $this->service();

        return [
            'run' => new RunJob($service),
            'auto-run' => new AutoRunJob(new AutoRunner($this->settings, $this->db(), static fn (): int => time(), $service)),
        ];
    }

    /**
     * The notices Teme shows on every admin page.
     *
     * @since 0.1.0
     */
    public function statusChecks(): array
    {
        return (new StatusChecks($this->settings, $this->secrets, $this->db(), static fn (): int => time()))->statusChecks();
    }

    private function service(): RunService
    {
        return new RunService($this->settings, $this->secrets, $this->writer, $this->http, $this->db(), static fn (): int => time(), new Randomizer);
    }

    private function db(): ConnectionInterface
    {
        return DB::connection();
    }
}
