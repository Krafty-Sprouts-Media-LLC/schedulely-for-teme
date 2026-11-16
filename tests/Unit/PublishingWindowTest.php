<?php
/**
 * Filename: PublishingWindowTest.php
 * Author: Krafty Sprouts Media, LLC
 * Created: 16/11/2026
 * Last Modified: 16/11/2026
 * Description: Time-of-day parsing, the site calendar and the daily publishing
 *              window, including overnight windows and daylight-saving changes.
 *
 * @package KraftySprouts\Schedulely
 * @since   0.1.0
 */

declare(strict_types=1);

use KraftySprouts\Schedulely\Time\PublishingWindow;
use KraftySprouts\Schedulely\Time\SiteCalendar;
use KraftySprouts\Schedulely\Time\TimeOfDay;

it('parses 12 hour and 24 hour times', function (string $text, int $seconds): void {
    expect(TimeOfDay::parse($text)?->seconds)->toBe($seconds);
})->with([
    ['5:00 PM', 17 * 3600],
    ['5:00pm', 17 * 3600],
    ['5 PM', 17 * 3600],
    ['12:00 AM', 0],
    ['12:30 PM', 12 * 3600 + 1800],
    ['11:59 PM', 23 * 3600 + 59 * 60],
    ['17:45', 17 * 3600 + 45 * 60],
    ['00:00', 0],
    ['  9:05 AM  ', 9 * 3600 + 300],
]);

it('refuses text that is not a time', function (string $text): void {
    expect(TimeOfDay::parse($text))->toBeNull();
})->with(['', 'noon', '25:00', '13:00 PM', '7:61', '0:00 PM', 'abc 5:00']);

it('formats back to a 12 hour clock', function (): void {
    expect(TimeOfDay::parse('17:05')?->format12())->toBe('5:05 PM')
        ->and(TimeOfDay::parse('00:00')?->format12())->toBe('12:00 AM');
});

it('turns a site-local date and time into a timestamp', function (): void {
    $calendar = SiteCalendar::fromName('America/New_York');
    $expected = (new DateTimeImmutable('2026-11-16 17:00:00', new DateTimeZone('America/New_York')))->getTimestamp();

    expect($calendar->timestamp('2026-11-16', TimeOfDay::parse('5:00 PM')))->toBe($expected);
});

it('falls back to UTC for an unknown timezone', function (): void {
    expect(SiteCalendar::fromName('Not/AZone')->timezone()->getName())->toBe('UTC');
});

it('adds calendar days across a daylight-saving change', function (): void {
    $calendar = SiteCalendar::fromName('America/New_York');

    expect($calendar->addDays('2026-11-01', 1))->toBe('2026-11-02')
        ->and($calendar->addDays('2026-03-08', 1))->toBe('2026-03-09')
        ->and($calendar->addDays('2026-11-16', -1))->toBe('2026-11-15');
});

it('knows the weekday of a date, Sunday being 0', function (): void {
    $calendar = SiteCalendar::fromName('UTC');

    expect($calendar->weekday('2026-11-16'))->toBe(1)
        ->and($calendar->weekday('2026-11-15'))->toBe(0)
        ->and($calendar->weekday('2026-11-21'))->toBe(6);
});

it('draws a same-day window between its start and end', function (): void {
    $calendar = SiteCalendar::fromName('America/New_York');
    $window = new PublishingWindow($calendar, TimeOfDay::parse('5:00 PM'), TimeOfDay::parse('11:00 PM'));
    [$start, $end] = $window->bounds('2026-11-16');

    expect($window->isOvernight())->toBeFalse()
        ->and($end - $start)->toBe(6 * 3600)
        ->and($calendar->format('Y-m-d H:i', $start))->toBe('2026-11-16 17:00')
        ->and($calendar->format('Y-m-d H:i', $end))->toBe('2026-11-16 23:00');
});

it('runs an overnight window into the next morning', function (): void {
    $calendar = SiteCalendar::fromName('America/New_York');
    $window = new PublishingWindow($calendar, TimeOfDay::parse('10:00 PM'), TimeOfDay::parse('3:00 AM'));
    [$start, $end] = $window->bounds('2026-11-16');

    expect($window->isOvernight())->toBeTrue()
        ->and($calendar->format('Y-m-d H:i', $start))->toBe('2026-11-16 22:00')
        ->and($calendar->format('Y-m-d H:i', $end))->toBe('2026-11-17 03:00');
});

it('treats an end equal to the start as a full-day overnight window', function (): void {
    $window = new PublishingWindow(SiteCalendar::fromName('UTC'), TimeOfDay::parse('9:00 AM'), TimeOfDay::parse('9:00 AM'));
    [$start, $end] = $window->bounds('2026-11-16');

    expect($window->isOvernight())->toBeTrue()
        ->and($end - $start)->toBe(86400);
});

it('finds the window an instant belongs to, naming the evening it opened', function (): void {
    $calendar = SiteCalendar::fromName('America/New_York');
    $window = new PublishingWindow($calendar, TimeOfDay::parse('10:00 PM'), TimeOfDay::parse('3:00 AM'));

    $earlyMorning = $calendar->timestamp('2026-11-17', TimeOfDay::parse('1:00 AM'));
    $evening = $calendar->timestamp('2026-11-17', TimeOfDay::parse('11:00 PM'));

    expect($window->anchorFor($earlyMorning))->toBe('2026-11-16')
        ->and($window->anchorFor($evening))->toBe('2026-11-17');
});

it('names the calendar date for a same-day window', function (): void {
    $calendar = SiteCalendar::fromName('UTC');
    $window = new PublishingWindow($calendar, TimeOfDay::parse('9:00 AM'), TimeOfDay::parse('5:00 PM'));

    expect($window->anchorFor($calendar->timestamp('2026-11-16', TimeOfDay::parse('1:00 PM'))))->toBe('2026-11-16');
});

it('says whether an instant lies inside an anchor day window, ends included', function (): void {
    $calendar = SiteCalendar::fromName('UTC');
    $window = new PublishingWindow($calendar, TimeOfDay::parse('9:00 AM'), TimeOfDay::parse('5:00 PM'));
    [$start, $end] = $window->bounds('2026-11-16');

    expect($window->contains($start, '2026-11-16'))->toBeTrue()
        ->and($window->contains($end, '2026-11-16'))->toBeTrue()
        ->and($window->contains($start - 1, '2026-11-16'))->toBeFalse()
        ->and($window->contains($end + 1, '2026-11-16'))->toBeFalse();
});
