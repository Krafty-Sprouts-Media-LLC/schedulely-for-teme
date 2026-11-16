<?php
/**
 * Filename: AutomationTest.php
 * Author: Krafty Sprouts Media, LLC
 * Description: Running without a button: the hourly gate for automatic runs, the "run now"
 *              toggle, and the two job handlers Teme calls.
 *
 * @package KraftySprouts\Schedulely
 * @since   0.1.0
 */

declare(strict_types=1);

use KraftySprouts\Schedulely\Run\AutoRunJob;
use KraftySprouts\Schedulely\Run\AutoRunner;
use KraftySprouts\Schedulely\Run\RunJob;
use KraftySprouts\Schedulely\Run\RunNowTrigger;
use KraftySprouts\Schedulely\Storage\StateStore;
use KraftySprouts\Schedulely\Tests\Support\FakeContext;
use KraftySprouts\Schedulely\Tests\Support\FakeJobs;
use KraftySprouts\Schedulely\Tests\Support\Harness;
use Teme\Contracts\Jobs\JobStopped;
use Teme\Contracts\Settings\SettingsChanged;

function autoRunner(Harness $h): AutoRunner
{
    return new AutoRunner($h->settings, $h->db, $h->clock->now(...), $h->service);
}

it('does nothing on the hourly tick while automatic scheduling is off', function (): void {
    $h = Harness::make(['auto_schedule' => false]);
    $outcome = autoRunner($h)->tick();

    expect($outcome->ran)->toBeFalse()->and($outcome->reason)->toContain('off')->and($h->writer->scheduled)->toBe([]);
});

it('runs on the first tick after it is turned on', function (): void {
    $h = Harness::make(['auto_schedule' => true]);
    $outcome = autoRunner($h)->tick();

    expect($outcome->ran)->toBeTrue()->and($h->writer->scheduled)->toHaveCount(5)->and((new StateStore($h->db))->get('last_run'))->toBe(Harness::start());
});

it('waits until the chosen interval has passed', function (): void {
    $h = Harness::make(['auto_schedule' => true, 'auto_interval' => '12']);
    (new StateStore($h->db))->put('last_run', Harness::start() - 3600);
    $outcome = autoRunner($h)->tick();

    expect($outcome->ran)->toBeFalse()->and($outcome->reason)->toContain('not due')->and($h->writer->scheduled)->toBe([]);
});

it('runs once the interval has passed, allowing five minutes for the hourly tick to drift', function (): void {
    $h = Harness::make(['auto_schedule' => true, 'auto_interval' => '12']);
    (new StateStore($h->db))->put('last_run', Harness::start() - (12 * 3600 - 240));

    expect(autoRunner($h)->tick()->ran)->toBeTrue();
});

it('honours an hourly interval', function (): void {
    $h = Harness::make(['auto_schedule' => true, 'auto_interval' => '1']);
    (new StateStore($h->db))->put('last_run', Harness::start() - 3600);

    expect(autoRunner($h)->tick()->ran)->toBeTrue();
});

it('never reorders on an automatic run', function (): void {
    $h = Harness::make(['auto_schedule' => true, 'reorder_queue' => true, 'ordering_method' => 'ai'], key: 'dummy-key-not-real');
    autoRunner($h)->tick();

    expect($h->http->posts)->toBe([]);
});

it('queues a run when the Run now setting is saved on, for the person who saved it', function (): void {
    $h = Harness::make(['run_now' => true]);
    $jobs = new FakeJobs;
    $id = (new RunNowTrigger($h->settings, $jobs))->settingsChanged(new SettingsChanged('plugin.schedulely', ['run_now', 'posts_per_day'], 'person-1'));

    expect($id)->toBe('job-1')
        ->and($jobs->queued)->toHaveCount(1)
        ->and($jobs->queued[0]['key'])->toBe('run')
        ->and($jobs->queued[0]['payload'])->toBe(['actor' => 'person-1']);
});

it('does not queue a run when Run now was saved off', function (): void {
    $h = Harness::make(['run_now' => false]);
    $jobs = new FakeJobs;

    expect((new RunNowTrigger($h->settings, $jobs))->settingsChanged(new SettingsChanged('plugin.schedulely', ['run_now'], 'p')))->toBeNull()
        ->and($jobs->queued)->toBe([]);
});

it('does not queue a run for another setting, or another plugin\'s group', function (): void {
    $h = Harness::make(['run_now' => true]);
    $jobs = new FakeJobs;
    $trigger = new RunNowTrigger($h->settings, $jobs);

    expect($trigger->settingsChanged(new SettingsChanged('plugin.schedulely', ['posts_per_day'], 'p')))->toBeNull()
        ->and($trigger->settingsChanged(new SettingsChanged('plugin.other', ['run_now'], 'p')))->toBeNull()
        ->and($trigger->settingsChanged(new SettingsChanged('general', ['run_now'], 'p')))->toBeNull()
        ->and($jobs->queued)->toBe([]);
});

it('runs as nobody when no person saved the setting', function (): void {
    $h = Harness::make(['run_now' => true]);
    $jobs = new FakeJobs;
    (new RunNowTrigger($h->settings, $jobs))->settingsChanged(new SettingsChanged('plugin.schedulely', ['run_now'], null));

    expect($jobs->queued[0]['payload'])->toBe(['actor' => '']);
});

it('runs the queued job as the person it names and reports progress', function (): void {
    $h = Harness::make(['batch_size' => 10], queued: 12);
    $context = new FakeContext(['actor' => 'person-9']);
    (new RunJob($h->service))->handle($context);

    expect(array_unique(array_column($h->writer->scheduled, 'person')))->toBe(['person-9'])
        ->and($context->progress[0])->toBe([10, 12, 'Scheduled 10 of 12 items.'])
        ->and(end($context->progress)[0])->toBe(12);
});

it('lets a stopped job unwind, keeping what it had scheduled', function (): void {
    $h = Harness::make(['batch_size' => 10], queued: 30);
    $context = new FakeContext(['actor' => 'p'], stopAfter: 1);

    expect(fn () => (new RunJob($h->service))->handle($context))->toThrow(JobStopped::class)
        ->and($h->writer->scheduled)->toHaveCount(20);
});

it('tells the person when a job cannot start, rather than failing', function (): void {
    $h = Harness::make(['acting_account' => '']);
    $context = new FakeContext(['actor' => '']);
    (new RunJob($h->service))->handle($context);

    expect($context->progress)->toHaveCount(1)->and($context->progress[0][2])->toContain('account')->and($h->writer->scheduled)->toBe([]);
});

it('runs the automatic job through the gate', function (): void {
    $off = Harness::make(['auto_schedule' => false]);
    (new AutoRunJob(autoRunner($off)))->handle(new FakeContext);

    $on = Harness::make(['auto_schedule' => true]);
    (new AutoRunJob(autoRunner($on)))->handle(new FakeContext);

    expect($off->writer->scheduled)->toBe([])->and($on->writer->scheduled)->toHaveCount(5);
});
