<?php
/**
 * Filename: CapacityTest.php
 * Author: Krafty Sprouts Media, LLC
 * Description: The capacity check: how many items a window and interval can hold
 *              in each scheduling mode, and what it suggests when the quota does
 *              not fit. The numbers are those the WordPress plugin produces.
 *
 * @package KraftySprouts\Schedulely
 *
 * @since   0.1.0
 */

declare(strict_types=1);

use KraftySprouts\Schedulely\Engine\Capacity;
use KraftySprouts\Schedulely\Engine\SchedulingMode;
use KraftySprouts\Schedulely\Time\SiteCalendar;
use KraftySprouts\Schedulely\Time\TimeOfDay;

function capacityFor(string $start, string $end, int $interval, int $quota, SchedulingMode $mode = SchedulingMode::Random): Capacity
{
    return new Capacity(SiteCalendar::fromName('America/New_York'), $mode);
}

function capacityReport(string $start, string $end, int $interval, int $quota, SchedulingMode $mode = SchedulingMode::Random)
{
    return capacityFor($start, $end, $interval, $quota, $mode)->calculate(
        TimeOfDay::parse($start),
        TimeOfDay::parse($end),
        $interval,
        $quota,
        '2026-11-16',
    );
}

it('uses 65 percent of the theoretical slots in Random mode for a 40 minute interval', function (): void {
    $report = capacityReport('5:00 PM', '11:00 PM', 40, 8);

    expect($report->valid)->toBeTrue()
        ->and($report->totalMinutes)->toBe(360.0)
        ->and($report->capacity)->toBe(5)
        ->and($report->meetsQuota)->toBeFalse();
});

it('fits every slot in Sequential mode', function (): void {
    $report = capacityReport('5:00 PM', '11:00 PM', 40, 8, SchedulingMode::Sequential);

    expect($report->capacity)->toBe(9)->and($report->meetsQuota)->toBeTrue();
});

it('fits 95 percent of slots in Hybrid mode', function (): void {
    $report = capacityReport('5:00 PM', '11:00 PM', 40, 8, SchedulingMode::Hybrid);

    expect($report->capacity)->toBe(8)->and($report->meetsQuota)->toBeTrue();
});

it('gives a long Random window ten more points of efficiency, capped at 70 percent', function (): void {
    $report = capacityReport('8:00 AM', '11:00 PM', 30, 20);

    // 900 minutes, 30 per slot = 30 slots; 0.65 + 0.10 capped at 0.70 = 21.
    expect($report->capacity)->toBe(21)->and($report->meetsQuota)->toBeTrue();
});

it('is more careful when only a few slots fit', function (): void {
    $report = capacityReport('5:00 PM', '6:30 PM', 30, 3);

    // 90 minutes / 30 = 3 theoretical slots, so capacity is 3 - 1 = 2.
    expect($report->capacity)->toBe(2)->and($report->meetsQuota)->toBeFalse();
});

it('never reports less than one post of capacity', function (): void {
    $report = capacityReport('5:00 PM', '5:10 PM', 60, 1, SchedulingMode::Sequential);

    expect($report->capacity)->toBe(1);
});

it('measures an overnight window across midnight', function (): void {
    $report = capacityReport('10:00 PM', '3:00 AM', 60, 4);

    expect($report->valid)->toBeTrue()->and($report->totalMinutes)->toBe(300.0);
});

it('says so when a time cannot be read', function (): void {
    $calendar = SiteCalendar::fromName('UTC');
    $report = (new Capacity($calendar, SchedulingMode::Random))->calculate(null, TimeOfDay::parse('5 PM'), 40, 8, '2026-11-16');

    expect($report->valid)->toBeFalse()
        ->and($report->capacity)->toBe(0)
        ->and($report->error)->not->toBeNull();
});

it('suggests three ways out when the quota does not fit', function (): void {
    $report = capacityReport('5:00 PM', '11:00 PM', 40, 8);
    $byType = [];
    foreach ($report->suggestions as $suggestion) {
        $byType[$suggestion->type] = $suggestion;
    }

    expect(array_keys($byType))->toBe(['reduce_interval', 'reduce_quota', 'expand_window'])
        ->and($byType['reduce_interval']->suggested)->toBe(27)
        ->and($byType['reduce_interval']->newCapacity)->toBe(7)
        ->and($byType['reduce_quota']->suggested)->toBe(5)
        ->and($byType['expand_window']->suggestedStart)->toBe('3:19 PM')
        ->and($byType['expand_window']->suggestedEnd)->toBe('11:59 PM');
});

it('suggests only extending the end when there is room before midnight', function (): void {
    $report = capacityReport('5:00 PM', '9:00 PM', 40, 5);
    $expand = null;
    foreach ($report->suggestions as $suggestion) {
        if ($suggestion->type === 'expand_window') {
            $expand = $suggestion;
        }
    }

    // 240 minutes; 0.65 -> 6*0.65 = 3 slots. Needs ceil(5/0.65)=8 slots * 40 = 320 minutes: 80 more.
    expect($expand)->not->toBeNull()
        ->and($expand->suggestedStart)->toBe('5:00 PM')
        ->and($expand->suggestedEnd)->toBe('10:20 PM');
});

it('gives only a generic widening hint for an overnight window', function (): void {
    $report = capacityReport('10:00 PM', '1:00 AM', 60, 6);
    $expand = null;
    foreach ($report->suggestions as $suggestion) {
        if ($suggestion->type === 'expand_window') {
            $expand = $suggestion;
        }
    }

    expect($expand)->not->toBeNull()
        ->and($expand->suggestedStart)->toBe('10:00 PM')
        ->and($expand->suggestedEnd)->toBe('1:00 AM');
});

it('offers no suggestions when the quota fits', function (): void {
    expect(capacityReport('5:00 PM', '11:00 PM', 40, 4)->suggestions)->toBe([]);
});
