<?php
/**
 * Filename: SlotPlannerTest.php
 * Author: Krafty Sprouts Media, LLC
 * Description: Choosing times inside a day's window: even slots for Sequential,
 *              randomised slots for Hybrid, and trial-and-error for Random.
 *
 * @package KraftySprouts\Schedulely
 * @since   0.1.0
 */

declare(strict_types=1);

use KraftySprouts\Schedulely\Engine\SchedulingMode;
use KraftySprouts\Schedulely\Engine\SlotPlanner;
use KraftySprouts\Schedulely\Time\PublishingWindow;
use KraftySprouts\Schedulely\Time\SiteCalendar;
use KraftySprouts\Schedulely\Time\TimeOfDay;
use Random\Engine\Mt19937;
use Random\Randomizer;

function planner(string $start = '9:00 AM', string $end = '5:00 PM', int $buffer = 1800, int $intervalMinutes = 40, int $seed = 7): SlotPlanner
{
    $calendar = SiteCalendar::fromName('UTC');

    return new SlotPlanner(
        new PublishingWindow($calendar, TimeOfDay::parse($start), TimeOfDay::parse($end)),
        new Randomizer(new Mt19937($seed)),
        $buffer,
        $intervalMinutes * 60,
    );
}

function at(string $datetime): int
{
    return (new DateTimeImmutable($datetime, new DateTimeZone('UTC')))->getTimestamp();
}

it('splits the window into equal slots in Sequential mode', function (): void {
    $slots = planner()->daySlots('2026-11-16', 4, SchedulingMode::Sequential, at('2026-11-15 00:00'));

    expect($slots)->toBe([
        at('2026-11-16 09:00'), at('2026-11-16 11:00'), at('2026-11-16 13:00'), at('2026-11-16 15:00'),
    ]);
});

it('starts the slots after the safety buffer when the window is already open', function (): void {
    $slots = planner()->daySlots('2026-11-16', 3, SchedulingMode::Sequential, at('2026-11-16 12:00'));

    // Usable span 12:30 to 17:00 = 16200 s; 3 slots of 5400 s.
    expect($slots)->toBe([at('2026-11-16 12:30'), at('2026-11-16 14:00'), at('2026-11-16 15:30')]);
});

it('gives no slots when the window has closed or the quota is zero', function (): void {
    expect(planner()->daySlots('2026-11-16', 4, SchedulingMode::Sequential, at('2026-11-16 16:45')))->toBe([])
        ->and(planner()->daySlots('2026-11-16', 0, SchedulingMode::Sequential, at('2026-11-15 00:00')))->toBe([]);
});

it('puts each Hybrid time inside its own slot', function (): void {
    $slots = planner()->daySlots('2026-11-16', 4, SchedulingMode::Hybrid, at('2026-11-15 00:00'));
    $starts = [at('2026-11-16 09:00'), at('2026-11-16 11:00'), at('2026-11-16 13:00'), at('2026-11-16 15:00')];

    expect($slots)->toHaveCount(4);
    foreach ($slots as $i => $slot) {
        expect($slot)->toBeGreaterThanOrEqual($starts[$i])->and($slot)->toBeLessThanOrEqual($starts[$i] + 7199);
    }
});

it('plans the same Hybrid slots for the same seed', function (): void {
    $a = planner(seed: 99)->daySlots('2026-11-16', 6, SchedulingMode::Hybrid, at('2026-11-15 00:00'));
    $b = planner(seed: 99)->daySlots('2026-11-16', 6, SchedulingMode::Hybrid, at('2026-11-15 00:00'));
    $c = planner(seed: 100)->daySlots('2026-11-16', 6, SchedulingMode::Hybrid, at('2026-11-15 00:00'));

    expect($a)->toBe($b)->and($a)->not->toBe($c);
});

it('picks Random times that keep the minimum interval from times already used', function (): void {
    $planner = planner();
    $used = [at('2026-11-16 12:00')];
    $now = at('2026-11-15 00:00');

    for ($i = 0; $i < 40; $i++) {
        $time = $planner->randomTime('2026-11-16', $used, $now);

        expect($time)->not->toBeNull()
            ->and($time)->toBeGreaterThanOrEqual(at('2026-11-16 09:00'))
            ->and($time)->toBeLessThanOrEqual(at('2026-11-16 17:00'))
            ->and(abs($time - $used[0]))->toBeGreaterThanOrEqual(40 * 60);
    }
});

it('never picks a Random time before the safety buffer has passed', function (): void {
    $planner = planner();
    $now = at('2026-11-16 12:00');

    for ($i = 0; $i < 40; $i++) {
        expect($planner->randomTime('2026-11-16', [], $now))->toBeGreaterThanOrEqual($now + 1800);
    }
});

it('gives up on Random when no time can honour the interval', function (): void {
    $planner = planner('9:30 AM', '10:30 AM', intervalMinutes: 120);

    expect($planner->randomTime('2026-11-16', [at('2026-11-16 10:00')], at('2026-11-15 00:00')))->toBeNull();
});

it('gives up on Random once the window has closed', function (): void {
    expect(planner()->randomTime('2026-11-16', [], at('2026-11-16 17:30')))->toBeNull();
});

it('keeps Random times inside a band, falling back to the whole window when the band misses it', function (): void {
    $planner = planner();
    $now = at('2026-11-15 00:00');
    $band = [at('2026-11-16 13:00'), at('2026-11-16 14:00')];

    for ($i = 0; $i < 25; $i++) {
        expect($planner->randomTime('2026-11-16', [], $now, $band))
            ->toBeGreaterThanOrEqual($band[0])->and($planner->randomTime('2026-11-16', [], $now, $band))->toBeLessThanOrEqual($band[1]);
    }

    $outside = [at('2026-11-16 20:00'), at('2026-11-16 21:00')];
    $time = $planner->randomTime('2026-11-16', [], $now, $outside);

    expect($time)->toBeGreaterThanOrEqual(at('2026-11-16 09:00'))->and($time)->toBeLessThanOrEqual(at('2026-11-16 17:00'));
});

it('is repeatable for a seed', function (): void {
    $a = planner(seed: 5)->randomTime('2026-11-16', [], at('2026-11-15 00:00'));
    $b = planner(seed: 5)->randomTime('2026-11-16', [], at('2026-11-15 00:00'));

    expect($a)->toBe($b);
});
