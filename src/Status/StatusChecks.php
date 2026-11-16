<?php
/**
 * Filename: StatusChecks.php
 * Author: Krafty Sprouts Media, LLC
 * Description: The notices Schedulely raises on Teme's admin pages: capacity, set-up
 *              problems, and how the last run went.
 *
 * @package KraftySprouts\Schedulely
 *
 * @since   0.1.0
 */

declare(strict_types=1);

namespace KraftySprouts\Schedulely\Status;

use Closure;
use Illuminate\Database\ConnectionInterface;
use KraftySprouts\Schedulely\Engine\Capacity;
use KraftySprouts\Schedulely\Engine\SchedulingMode;
use KraftySprouts\Schedulely\Ordering\OrderingMethod;
use KraftySprouts\Schedulely\Settings\Defaults;
use KraftySprouts\Schedulely\Settings\Options;
use KraftySprouts\Schedulely\Storage\QueueStore;
use KraftySprouts\Schedulely\Storage\StateStore;
use KraftySprouts\Schedulely\Support\Words;
use KraftySprouts\Schedulely\Time\SiteCalendar;
use Teme\Contracts\Settings\SecretReader;
use Teme\Contracts\Settings\SettingsReader;
use Teme\Contracts\Status\ChecksStatus;
use Teme\Contracts\Status\StatusNotice;

/**
 * Teme gives a plugin no screen of its own, no dashboard card and no mail, so what
 * WordPress showed in a capacity pill, three stat cards and an email is said here, as
 * notices on every admin page. Each is cheap, because Teme asks on every page.
 *
 * @since 0.1.0
 */
final readonly class StatusChecks implements ChecksStatus
{
    /** A run is mentioned for this long after it finishes. */
    private const int RUN_NOTICE_SECONDS = 86400;

    /**
     * @param  Closure(): int  $clock
     *
     * @since 0.1.0
     */
    public function __construct(
        private SettingsReader $settings,
        private SecretReader $secrets,
        private ConnectionInterface $db,
        private Closure $clock,
    ) {}

    /**
     * @return list<NoticeCheck>
     *
     * @since 0.1.0
     */
    public function statusChecks(): array
    {
        return [
            new NoticeCheck($this->capacity(...)),
            new NoticeCheck($this->account(...)),
            new NoticeCheck($this->timezoneMode(...)),
            new NoticeCheck($this->aiKey(...)),
            new NoticeCheck($this->emptyQueue(...)),
            new NoticeCheck($this->pool(...)),
            new NoticeCheck($this->lastRun(...)),
            new NoticeCheck($this->lastRunProblems(...)),
        ];
    }

    private function capacity(): ?StatusNotice
    {
        $options = Options::read($this->settings);
        $calendar = SiteCalendar::fromName($options->timezone);
        $report = (new Capacity($calendar, $options->mode))->calculate(
            $options->start, $options->end, $options->minInterval, $options->quota, $calendar->today(($this->clock)()),
        );

        if (! $report->valid || $report->meetsQuota) {
            return null;
        }

        $fix = $report->suggestions[0]->message ?? '';

        return new StatusNotice('warning', Words::say('Schedulely: the window holds about :capacity posts a day, but the quota is :quota. :fix', [
            'capacity' => $report->capacity, 'quota' => $report->desiredQuota, 'fix' => $fix,
        ]), true);
    }

    private function account(): ?StatusNotice
    {
        $options = Options::read($this->settings);

        if ($options->autoSchedule && $options->actingAccount === '' && $options->authorPool() === []) {
            return new StatusNotice('error', Words::say('Schedulely: automatic scheduling is on, but no account is set to schedule as. Set "Account to act as" in its settings.'), true);
        }

        return null;
    }

    private function timezoneMode(): ?StatusNotice
    {
        $options = Options::read($this->settings);

        if ($options->timezoneOrdering && $options->mode !== SchedulingMode::Random) {
            return new StatusNotice('info', Words::say('Schedulely: US timezone-aware ordering only works in Random mode.'), true);
        }

        return null;
    }

    private function aiKey(): ?StatusNotice
    {
        $options = Options::read($this->settings);

        if (! $options->reorder || $options->method !== OrderingMethod::Ai) {
            return null;
        }

        $key = $this->secrets->secret(Defaults::GROUP, 'ai_api_key');

        if ($key !== null && trim($key) !== '') {
            return null;
        }

        return new StatusNotice('warning', Words::say('Schedulely: AI ordering is chosen but no AI service key is saved, so PHP ordering is used.'), true);
    }

    private function emptyQueue(): ?StatusNotice
    {
        $options = Options::read($this->settings);

        if ((new StateStore($this->db))->get('last_run') !== null) {
            return null;
        }

        if ((new QueueStore($this->db))->count($options->types, [$options->status]) > 0) {
            return null;
        }

        return new StatusNotice('info', Words::say("Schedulely's queue is empty. Drafts join it when they are saved, so drafts saved before Schedulely was enabled join once they are saved again."));
    }

    private function pool(): ?StatusNotice
    {
        $options = Options::read($this->settings);
        $eligible = (new QueueStore($this->db))->count($options->types, [$options->status]);

        if ($eligible <= $options->poolSize) {
            return null;
        }

        return new StatusNotice('info', Words::say('Schedulely: :eligible items are queued, but each run loads :pool, oldest first. The rest wait for the next run; raise the pool size to take them all.', [
            'eligible' => $eligible, 'pool' => $options->poolSize,
        ]), true);
    }

    private function lastRun(): ?StatusNotice
    {
        $report = $this->recentReport();

        if ($report === null) {
            return null;
        }

        $windows = (array) ($report['windows'] ?? []);
        $range = $windows === [] ? '' : Words::say(' Windows: :first to :last.', ['first' => (string) reset($windows), 'last' => (string) end($windows)]);

        return new StatusNotice('info', Words::say('Schedulely\'s last run scheduled :scheduled of :queued items.:range', [
            'scheduled' => (int) ($report['scheduled'] ?? 0), 'queued' => (int) ($report['queued'] ?? 0), 'range' => $range,
        ]));
    }

    private function lastRunProblems(): ?StatusNotice
    {
        $report = $this->recentReport();

        if ($report === null || (int) ($report['failed'] ?? 0) < 1) {
            return null;
        }

        $first = (string) (((array) ($report['errors'] ?? []))[0] ?? '');

        return new StatusNotice('warning', Words::say('Schedulely: :failed items from the last run could not be scheduled. :first', [
            'failed' => (int) $report['failed'], 'first' => $first,
        ]));
    }

    /**
     * @return array<string, mixed>|null the last run's report while it is recent
     */
    private function recentReport(): ?array
    {
        $report = (new StateStore($this->db))->get('last_report');

        if (! is_array($report) || (int) ($report['at'] ?? 0) < ($this->clock)() - self::RUN_NOTICE_SECONDS) {
            return null;
        }

        return $report;
    }
}
