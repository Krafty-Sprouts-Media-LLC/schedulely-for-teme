<?php
/**
 * Filename: UsTimezonesTest.php
 * Author: Krafty Sprouts Media, LLC
 * Description: Finding a US state's timezone group in a title, and the part of the
 *              publishing window that falls in each group's waking hours.
 *
 * @package KraftySprouts\Schedulely
 * @since   0.1.0
 */

declare(strict_types=1);

use KraftySprouts\Schedulely\Ordering\UsTimezones;
use KraftySprouts\Schedulely\Time\PublishingWindow;
use KraftySprouts\Schedulely\Time\SiteCalendar;
use KraftySprouts\Schedulely\Time\TimeOfDay;

it('reads the timezone group from the state named in a title', function (string $title, string $group): void {
    expect(UsTimezones::classify($title))->toBe($group);
})->with([
    ['Best Pizza in Texas', 'central'],
    ['Best Pizza in New York', 'eastern'],
    ['Best Pizza in Florida', 'eastern'],
    ['Hiking in Colorado', 'mountain'],
    ['Hiking in California', 'pacific'],
    ['Hunting Seasons in Alaska', 'pacific'],
    ['Where to eat in Arkansas', 'central'],
    ['A weekend in Indianapolis', 'general'],
    ['Visiting Washington DC', 'eastern'],
    ['Visiting the District of Columbia', 'eastern'],
    ['Skiing in Washington', 'pacific'],
    ['Best Pizza in West Virginia', 'eastern'],
    ['How to bake bread', 'general'],
]);

it('reads a state from the slug as well as the title', function (): void {
    expect(UsTimezones::classify('Speed limits', 'speed-limits-in-new-mexico'))->toBe('mountain');
});

function usWindow(string $start, string $end): PublishingWindow
{
    return new PublishingWindow(SiteCalendar::fromName('America/New_York'), TimeOfDay::parse($start), TimeOfDay::parse($end));
}

function etAt(string $datetime): int
{
    return (new DateTimeImmutable($datetime, new DateTimeZone('America/New_York')))->getTimestamp();
}

it('keeps the whole window for the general group', function (): void {
    $window = usWindow('5:00 PM', '11:00 PM');

    expect(UsTimezones::overlap($window, '2026-11-16', 'general'))->toBe($window->bounds('2026-11-16'));
});

it('keeps the whole evening window for every US zone, since 5 to 11 PM Eastern is awake everywhere', function (string $group): void {
    $window = usWindow('5:00 PM', '11:00 PM');

    expect(UsTimezones::overlap($window, '2026-11-16', $group))->toBe($window->bounds('2026-11-16'));
})->with(['eastern', 'central', 'mountain', 'pacific']);

it('trims an early window to the zone waking hours', function (): void {
    $window = usWindow('5:00 AM', '11:00 AM');

    // Pacific is awake from 7 AM PST = 10 AM EST.
    expect(UsTimezones::overlap($window, '2026-11-16', 'pacific'))->toBe([etAt('2026-11-16 10:00'), etAt('2026-11-16 11:00')])
        ->and(UsTimezones::overlap($window, '2026-11-16', 'eastern'))->toBe([etAt('2026-11-16 07:00'), etAt('2026-11-16 11:00')]);
});

it('falls back to the whole window when a zone is asleep for all of it', function (): void {
    $window = usWindow('1:00 AM', '3:00 AM');

    expect(UsTimezones::overlap($window, '2026-11-16', 'eastern'))->toBe($window->bounds('2026-11-16'));
});

it('follows daylight saving in the zone it is asked about', function (): void {
    // On 2026-07-01 Eastern is UTC-4 and Pacific is UTC-7; the 3 hour gap is the same, but the dates differ in DST on the edges.
    $window = usWindow('5:00 AM', '11:00 AM');

    expect(UsTimezones::overlap($window, '2026-07-01', 'pacific')[0])->toBe(etAt('2026-07-01 10:00'));
});

it('handles an overnight window by checking the next calendar day too', function (): void {
    $window = usWindow('10:00 PM', '3:00 AM');

    // Eastern is awake until 11 PM, then from 7 AM; only 10-11 PM of the window overlaps.
    expect(UsTimezones::overlap($window, '2026-11-16', 'eastern'))->toBe([etAt('2026-11-16 22:00'), etAt('2026-11-16 23:00')]);
});

it('counts how many posts of a run fall in each group', function (): void {
    $distribution = UsTimezones::distribution(['a' => 'eastern', 'b' => 'eastern', 'c' => 'pacific', 'd' => 'general'], ['a', 'b', 'c', 'd', 'unknown']);

    expect($distribution)->toBe(['eastern' => 2, 'central' => 0, 'mountain' => 0, 'pacific' => 1, 'general' => 2]);
});
