<?php
/**
 * Filename: RunService.php
 * Author: Krafty Sprouts Media, LLC
 * Description: Runs Schedulely once: reads the settings, loads and orders the queue,
 *              drives the engine and records what happened.
 *
 * @package KraftySprouts\Schedulely
 * @since   0.1.0
 */

declare(strict_types=1);

namespace KraftySprouts\Schedulely\Run;

use Closure;
use Illuminate\Database\ConnectionInterface;
use KraftySprouts\Schedulely\Engine\ActingPeople;
use KraftySprouts\Schedulely\Engine\RunSettings;
use KraftySprouts\Schedulely\Engine\Scheduler;
use KraftySprouts\Schedulely\Engine\SlotPlanner;
use KraftySprouts\Schedulely\Ordering\AiOrderer;
use KraftySprouts\Schedulely\Ordering\OrderingMethod;
use KraftySprouts\Schedulely\Ordering\PhpOrderer;
use KraftySprouts\Schedulely\Ordering\QueueOrderer;
use KraftySprouts\Schedulely\Ordering\UsTimezones;
use KraftySprouts\Schedulely\Settings\Defaults;
use KraftySprouts\Schedulely\Settings\Options;
use KraftySprouts\Schedulely\Storage\QueueStore;
use KraftySprouts\Schedulely\Storage\ReorderLogStore;
use KraftySprouts\Schedulely\Storage\SlotStore;
use KraftySprouts\Schedulely\Storage\StateStore;
use KraftySprouts\Schedulely\Support\Words;
use KraftySprouts\Schedulely\Time\PublishingWindow;
use KraftySprouts\Schedulely\Time\SiteCalendar;
use Random\Randomizer;
use Teme\Contracts\Content\ContentWriter;
use Teme\Contracts\Http\OutboundHttp;
use Teme\Contracts\Settings\SecretReader;
use Teme\Contracts\Settings\SettingsReader;

/**
 * One run, start to finish. Everything it touches is passed in, so the whole path is
 * testable against SQLite and fakes. Settings are read here, at the start of every run,
 * never in a constructor.
 *
 * @since 0.1.0
 */
final readonly class RunService
{
    /**
     * @param  Closure(): int  $clock  Unix time
     *
     * @since 0.1.0
     */
    public function __construct(
        private SettingsReader $settings,
        private SecretReader $secrets,
        private ContentWriter $writer,
        private OutboundHttp $http,
        private ConnectionInterface $db,
        private Closure $clock,
        private Randomizer $random,
    ) {}

    /**
     * @param  bool  $manual  true when a person started it; only a manual run may reorder the queue
     * @param  string|null  $actor  the account that started a manual run
     * @param  (callable(int, int): void)|null  $progress  items done and total
     *
     * @since 0.1.0
     */
    public function run(bool $manual, ?string $actor = null, ?callable $progress = null): RunOutcome
    {
        $options = Options::read($this->settings);
        $now = ($this->clock)();

        $fallback = $manual && $actor !== null && $actor !== '' ? $actor : $options->actingAccount;
        $pool = $options->authorPool();

        if ($fallback === '' && $pool === []) {
            return RunOutcome::skipped(Words::say('No account is set to schedule as. Set "Account to act as" in the Schedulely settings.'));
        }

        $queue = new QueueStore($this->db);
        $slots = new SlotStore($this->db, $options->types);
        $statuses = [$options->status];

        $eligible = $queue->count($options->types, $statuses);
        $items = $queue->oldest($options->types, $statuses, $options->poolSize);

        $calendar = SiteCalendar::fromName($options->timezone);
        $window = new PublishingWindow($calendar, $options->start, $options->end);
        $planner = new SlotPlanner($window, $this->random, $options->safetyBufferSeconds, $options->minInterval * 60);
        $scheduler = new Scheduler(
            $window, $calendar, $planner,
            new RunSettings($options->quota, $options->mode, $options->activeDays, $options->safetyBufferSeconds),
            $slots, new WriterItemScheduler($this->writer, $queue, $slots),
            new ActingPeople($fallback, $pool, $this->random),
        );

        $ordered = $this->orderer($options)->order($items, $manual);
        $groups = $options->timezoneOrdering && $ordered->reordered ? $this->groups($ordered->items) : null;

        $report = $scheduler->run($ordered->items, $now, $progress, $options->batchSize, $groups);
        $remaining = $queue->count($options->types, $statuses);

        if ($items !== []) {
            $this->remember($report, $now, $manual, $ordered->method);
        }

        return new RunOutcome(true, '', $report, $eligible, max(0, $eligible - count($items)), $remaining, $ordered->method);
    }

    private function orderer(Options $options): QueueOrderer
    {
        $ai = null;

        if ($options->method === OrderingMethod::Ai) {
            $ai = new AiOrderer(
                $this->http, $options->aiProvider, $options->aiModel,
                $this->secrets->secret(Defaults::GROUP, 'ai_api_key'), $options->aiMaxItems,
            );
        }

        return new QueueOrderer(
            $options->reorder, $options->method, $options->shuffle, new PhpOrderer($options->spread),
            $ai, $this->random, new ReorderLogStore($this->db),
        );
    }

    /**
     * @param  list<\KraftySprouts\Schedulely\Queue\QueueItem>  $items
     * @return array<string, string>
     */
    private function groups(array $items): array
    {
        $groups = [];

        foreach ($items as $item) {
            $groups[$item->id] = UsTimezones::classify($item->title);
        }

        return $groups;
    }

    private function remember(\KraftySprouts\Schedulely\Engine\RunReport $report, int $now, bool $manual, string $method): void
    {
        $state = new StateStore($this->db);
        $state->put('last_run', $now);
        $state->put('last_report', [
            'at' => $now,
            'manual' => $manual,
            'scheduled' => $report->scheduledCount,
            'queued' => $report->queueTotal,
            'failed' => $report->failedCount,
            'windows' => $report->dates,
            'last_post_at' => $report->lastPostAt,
            'ordered_by' => $method,
            'errors' => array_slice($report->errors, 0, 5),
        ]);
    }
}
