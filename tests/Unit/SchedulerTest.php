<?php
/**
 * Filename: SchedulerTest.php
 * Author: Krafty Sprouts Media, LLC
 * Description: A whole scheduling run: where it starts, how it fills a half-full last
 *              day first, rolls to the next active day, handles failures and retries,
 *              and stops when told to.
 *
 * @package KraftySprouts\Schedulely
 * @since   0.1.0
 */

declare(strict_types=1);

use KraftySprouts\Schedulely\Engine\ActingPeople;
use KraftySprouts\Schedulely\Engine\RunSettings;
use KraftySprouts\Schedulely\Engine\Scheduler;
use KraftySprouts\Schedulely\Engine\SchedulingMode;
use KraftySprouts\Schedulely\Engine\SlotPlanner;
use KraftySprouts\Schedulely\Queue\QueueItem;
use KraftySprouts\Schedulely\Tests\Support\FakeLedger;
use KraftySprouts\Schedulely\Time\PublishingWindow;
use KraftySprouts\Schedulely\Time\SiteCalendar;
use KraftySprouts\Schedulely\Time\TimeOfDay;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Teme\Contracts\Jobs\JobStopped;

/**
 * @param  list<int>  $activeDays
 */
function engine(
    FakeLedger $ledger,
    SchedulingMode $mode = SchedulingMode::Sequential,
    int $quota = 3,
    string $start = '9:00 AM',
    string $end = '5:00 PM',
    array $activeDays = [0, 1, 2, 3, 4, 5, 6],
    int $intervalMinutes = 40,
    int $seed = 1,
    array $actors = [],
): Scheduler {
    $calendar = SiteCalendar::fromName('UTC');
    $window = new PublishingWindow($calendar, TimeOfDay::parse($start), TimeOfDay::parse($end));
    $random = new Randomizer(new Mt19937($seed));

    return new Scheduler(
        $window,
        $calendar,
        new SlotPlanner($window, $random, 1800, $intervalMinutes * 60),
        new RunSettings($quota, $mode, $activeDays, 1800),
        $ledger,
        $ledger,
        new ActingPeople('acting-1', $actors, new Randomizer(new Mt19937($seed))),
    );
}

function utc(string $datetime): int
{
    return (new DateTimeImmutable($datetime, new DateTimeZone('UTC')))->getTimestamp();
}

function drafts(int $count, string $prefix = 'p'): array
{
    $items = [];
    for ($i = 1; $i <= $count; $i++) {
        $items[] = new QueueItem("{$prefix}{$i}", 'post', "Draft {$i}", 'en', 'draft', $i);
    }

    return $items;
}

function days(FakeLedger $ledger): array
{
    $byDay = [];
    foreach ($ledger->timestamps as $t) {
        $byDay[gmdate('Y-m-d', $t)][] = gmdate('H:i', $t);
    }
    ksort($byDay);

    return $byDay;
}

it('starts today when the window is still open and fills each day to its quota', function (): void {
    $ledger = new FakeLedger;
    $report = engine($ledger)->run(drafts(7), utc('2026-11-16 08:00'));

    expect($report->success)->toBeTrue()
        ->and($report->scheduledCount)->toBe(7)
        ->and(days($ledger))->toBe([
            '2026-11-16' => ['09:00', '11:40', '14:20'],
            '2026-11-17' => ['09:00', '11:40', '14:20'],
            '2026-11-18' => ['09:00'],
        ])
        ->and($report->completedLastDate)->toBeFalse()
        ->and($report->dates)->toBe(['2026-11-16', '2026-11-17', '2026-11-18']);
});

it('starts the next day when today\'s window has closed', function (): void {
    $ledger = new FakeLedger;
    engine($ledger)->run(drafts(2), utc('2026-11-16 18:00'));

    expect(array_keys(days($ledger)))->toBe(['2026-11-17']);
});

it('skips days that are not active', function (): void {
    $ledger = new FakeLedger;
    // 2026-11-20 is a Friday. Active days are Monday to Friday.
    engine($ledger, quota: 2, activeDays: [1, 2, 3, 4, 5])->run(drafts(3), utc('2026-11-20 08:00'));

    expect(array_keys(days($ledger)))->toBe(['2026-11-20', '2026-11-23']);
});

it('does not start on an inactive day', function (): void {
    $ledger = new FakeLedger;
    // 2026-11-21 is a Saturday.
    engine($ledger, quota: 2, activeDays: [1, 2, 3, 4, 5])->run(drafts(2), utc('2026-11-21 08:00'));

    expect(array_keys(days($ledger)))->toBe(['2026-11-23']);
});

it('continues after the furthest scheduled day when that day is already full', function (): void {
    $ledger = new FakeLedger([utc('2026-11-18 10:00'), utc('2026-11-18 12:00'), utc('2026-11-18 14:00')]);
    engine($ledger)->run(drafts(1), utc('2026-11-16 08:00'));

    expect(array_keys(days($ledger)))->toBe(['2026-11-18', '2026-11-19'])
        ->and(days($ledger)['2026-11-19'])->toBe(['09:00']);
});

it('fills the furthest day first when it is short of its quota and still open', function (): void {
    $ledger = new FakeLedger([utc('2026-11-18 10:00')]);
    $report = engine($ledger)->run(drafts(3), utc('2026-11-16 08:00'));

    expect($report->completedLastDate)->toBeTrue()
        ->and(days($ledger)['2026-11-18'])->toHaveCount(3)
        ->and(days($ledger)['2026-11-19'])->toHaveCount(1);
});

it('starts a fresh day when the short furthest day has already closed', function (): void {
    $ledger = new FakeLedger([utc('2026-11-15 10:00')]);
    $report = engine($ledger)->run(drafts(2), utc('2026-11-16 08:00'));

    expect($report->completedLastDate)->toBeFalse()
        ->and(array_keys(days($ledger)))->toBe(['2026-11-15', '2026-11-16']);
});

it('counts an overnight window as the evening it opened on', function (): void {
    $ledger = new FakeLedger([utc('2026-11-17 00:30')]);
    $report = engine($ledger, quota: 3, start: '10:00 PM', end: '2:00 AM')->run(drafts(2), utc('2026-11-16 12:00'));

    // The 00:30 item belongs to the window opening on the 16th, which still has room.
    expect($report->completedLastDate)->toBeTrue()
        ->and($report->dates)->toBe(['2026-11-16']);
});

it('places overnight items after the evening opens and before the morning closes', function (): void {
    $ledger = new FakeLedger;
    engine($ledger, quota: 4, start: '10:00 PM', end: '2:00 AM')->run(drafts(4), utc('2026-11-16 12:00'));

    foreach ($ledger->timestamps as $t) {
        expect($t)->toBeGreaterThanOrEqual(utc('2026-11-16 22:00'))->and($t)->toBeLessThanOrEqual(utc('2026-11-17 02:00'));
    }
});

it('keeps Random times a minimum interval apart', function (): void {
    $ledger = new FakeLedger;
    engine($ledger, SchedulingMode::Random, quota: 5, intervalMinutes: 40)->run(drafts(5), utc('2026-11-15 08:00'));

    $times = $ledger->timestamps;
    sort($times);
    for ($i = 1; $i < count($times); $i++) {
        expect($times[$i] - $times[$i - 1])->toBeGreaterThanOrEqual(40 * 60);
    }
});

it('rolls to the next day in Random mode when the day cannot take another time', function (): void {
    $ledger = new FakeLedger;
    // A one hour window with a 40 minute interval holds one item a day.
    $report = engine($ledger, SchedulingMode::Random, quota: 3, start: '9:00 AM', end: '10:00 AM')->run(drafts(3), utc('2026-11-15 08:00'));

    // At most two 40 minute-apart times fit in an hour, so three items need at least two days.
    expect($report->scheduledCount)->toBe(3)->and(count(days($ledger)))->toBeGreaterThanOrEqual(2);
});

it('is repeatable for a seed', function (): void {
    $a = new FakeLedger;
    $b = new FakeLedger;
    engine($a, SchedulingMode::Hybrid, seed: 21)->run(drafts(6), utc('2026-11-15 08:00'));
    engine($b, SchedulingMode::Hybrid, seed: 21)->run(drafts(6), utc('2026-11-15 08:00'));

    expect($a->timestamps)->toBe($b->timestamps);
});

it('records each failed Sequential write as an error, counting it toward the day', function (): void {
    $ledger = new FakeLedger;
    $ledger->failures['p2'] = 1;
    $report = engine($ledger)->run(drafts(4), utc('2026-11-15 08:00'));

    expect($report->scheduledCount)->toBe(3)
        ->and($report->failedCount)->toBe(1)
        ->and($report->errors)->toHaveCount(1)
        ->and($report->errors[0])->toContain('p2');
});

it('retries a failed Random write with a different time', function (): void {
    $ledger = new FakeLedger;
    $ledger->failures['p1'] = 2;
    $report = engine($ledger, SchedulingMode::Random, quota: 3)->run(drafts(2), utc('2026-11-15 08:00'));

    expect($report->scheduledCount)->toBe(2)->and($report->errors)->toBe([]);
});

it('gives up on a Random item after two retries', function (): void {
    $ledger = new FakeLedger;
    $ledger->failures['p1'] = 3;
    $report = engine($ledger, SchedulingMode::Random, quota: 3)->run(drafts(2), utc('2026-11-15 08:00'));

    expect($report->scheduledCount)->toBe(1)->and($report->errors)->toHaveCount(1)->and($report->errors[0])->toContain('3 attempts');
});

it('says so when there is nothing to schedule', function (): void {
    $report = engine(new FakeLedger)->run([], utc('2026-11-16 08:00'));

    expect($report->success)->toBeFalse()->and($report->scheduledCount)->toBe(0);
});

it('reports progress and stops when the job is stopped', function (): void {
    $ledger = new FakeLedger;
    $seen = [];

    $run = function () use ($ledger, &$seen): void {
        engine($ledger)->run(drafts(7), utc('2026-11-15 08:00'), function (int $done, int $total) use (&$seen): void {
            $seen[] = [$done, $total];

            if ($done >= 3) {
                throw new JobStopped('stop');
            }
        }, 1);
    };

    expect($run)->toThrow(JobStopped::class)
        ->and($seen)->toBe([[1, 7], [2, 7], [3, 7]])
        ->and($ledger->timestamps)->toHaveCount(3);
});

it('acts as the fallback person, or as one of a pool when there is one', function (): void {
    $solo = new FakeLedger;
    engine($solo)->run(drafts(2), utc('2026-11-15 08:00'));

    $pooled = new FakeLedger;
    engine($pooled, actors: ['u1', 'u2', 'u3'])->run(drafts(6), utc('2026-11-15 08:00'));

    expect(array_unique(array_column($solo->writes, 'person')))->toBe(['acting-1'])
        ->and(array_diff(array_column($pooled->writes, 'person'), ['u1', 'u2', 'u3']))->toBe([])
        ->and(count(array_unique(array_column($pooled->writes, 'person'))))->toBeGreaterThan(1);
});

it('never writes a time less than the safety buffer from now', function (): void {
    $ledger = new FakeLedger;
    engine($ledger, SchedulingMode::Random, quota: 3)->run(drafts(3), utc('2026-11-16 12:00'));

    foreach ($ledger->timestamps as $t) {
        expect($t)->toBeGreaterThanOrEqual(utc('2026-11-16 12:30'));
    }
});

it('puts a US timezone group inside its waking-hours overlap in Random mode', function (): void {
    $ledger = new FakeLedger;
    $calendar = SiteCalendar::fromName('America/New_York');
    $window = new PublishingWindow($calendar, TimeOfDay::parse('5:00 AM'), TimeOfDay::parse('11:00 AM'));
    $planner = new SlotPlanner($window, new Randomizer(new Mt19937(4)), 1800, 40 * 60);
    $engine = new Scheduler($window, $calendar, $planner, new RunSettings(3, SchedulingMode::Random, [0, 1, 2, 3, 4, 5, 6], 1800), $ledger, $ledger, new ActingPeople('a', [], new Randomizer(new Mt19937(1))));

    $engine->run(drafts(3), utc('2026-11-14 00:00'), groups: ['p1' => 'pacific', 'p2' => 'pacific', 'p3' => 'pacific']);

    // Pacific wakes at 7 AM PST = 10 AM EST.
    foreach ($ledger->timestamps as $t) {
        expect($calendar->format('H:i', $t) >= '10:00')->toBeTrue();
    }
});

it('counts a run\'s timezone distribution', function (): void {
    $ledger = new FakeLedger;
    $report = engine($ledger, SchedulingMode::Random)->run(drafts(3), utc('2026-11-14 00:00'), groups: ['p1' => 'eastern', 'p2' => 'pacific', 'p3' => 'eastern']);

    expect($report->timezoneDistribution)->toBe(['eastern' => 2, 'central' => 0, 'mountain' => 0, 'pacific' => 1, 'general' => 0]);
});
