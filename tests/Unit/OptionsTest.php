<?php
/**
 * Filename: OptionsTest.php
 * Author: Krafty Sprouts Media, LLC
 * Description: Reading Schedulely's settings group into one checked value object:
 *              defaults, clamping, and the text lists that stand in for pickers.
 *
 * @package KraftySprouts\Schedulely
 * @since   0.1.0
 */

declare(strict_types=1);

use KraftySprouts\Schedulely\Engine\SchedulingMode;
use KraftySprouts\Schedulely\Ordering\AiProvider;
use KraftySprouts\Schedulely\Ordering\OrderingMethod;
use KraftySprouts\Schedulely\Ordering\Spread;
use KraftySprouts\Schedulely\Settings\Defaults;
use KraftySprouts\Schedulely\Settings\Options;
use KraftySprouts\Schedulely\Tests\Support\ArraySettings;

function optionsFrom(array $plugin = [], ?string $timezone = null): Options
{
    $values = ['plugin.schedulely' => $plugin];

    if ($timezone !== null) {
        $values['general'] = ['timezone' => $timezone];
    }

    return Options::read(new ArraySettings($values));
}

it('falls back to WordPress Schedulely\'s defaults when nothing is saved', function (): void {
    $o = optionsFrom();

    expect($o->status)->toBe('draft')
        ->and($o->types)->toBe(['post'])
        ->and($o->quota)->toBe(8)
        ->and($o->poolSize)->toBe(1500)
        ->and($o->batchSize)->toBe(75)
        ->and($o->start->format12())->toBe('5:00 PM')
        ->and($o->end->format12())->toBe('11:00 PM')
        ->and($o->minInterval)->toBe(40)
        ->and($o->activeDays)->toBe([0, 1, 2, 3, 4, 5, 6])
        ->and($o->mode)->toBe(SchedulingMode::Random)
        ->and($o->safetyBufferSeconds)->toBe(1800)
        ->and($o->reorder)->toBeFalse()
        ->and($o->method)->toBe(OrderingMethod::Php)
        ->and($o->spread)->toBe(Spread::Even)
        ->and($o->timezoneOrdering)->toBeFalse()
        ->and($o->aiProvider)->toBe(AiProvider::Deepseek)
        ->and($o->aiModel)->toBe('deepseek-v4-flash')
        ->and($o->aiMaxItems)->toBe(150)
        ->and($o->randomizeAuthors)->toBeFalse()
        ->and($o->autoSchedule)->toBeFalse()
        ->and($o->autoIntervalHours)->toBe(12)
        ->and($o->runNow)->toBeFalse()
        ->and($o->timezone)->toBe('UTC');
});

it('reads saved values', function (): void {
    $o = optionsFrom([
        'post_status' => 'review', 'posts_per_day' => 12, 'start_time' => '9:00 AM', 'end_time' => '6:30 PM',
        'scheduling_mode' => 'hybrid', 'ordering_method' => 'ai', 'php_spread' => 'round_robin',
        'reorder_queue' => true, 'shuffle_queue' => false, 'ai_provider' => 'openai', 'auto_schedule' => true,
        'auto_interval' => '6', 'run_now' => true, 'min_interval' => 25, 'safety_buffer' => 10,
    ], 'America/Chicago');

    expect($o->status)->toBe('review')
        ->and($o->quota)->toBe(12)
        ->and($o->start->format12())->toBe('9:00 AM')
        ->and($o->end->format12())->toBe('6:30 PM')
        ->and($o->mode)->toBe(SchedulingMode::Hybrid)
        ->and($o->method)->toBe(OrderingMethod::Ai)
        ->and($o->spread)->toBe(Spread::RoundRobin)
        ->and($o->reorder)->toBeTrue()
        ->and($o->shuffle)->toBeFalse()
        ->and($o->aiProvider)->toBe(AiProvider::OpenAi)
        ->and($o->autoSchedule)->toBeTrue()
        ->and($o->autoIntervalHours)->toBe(6)
        ->and($o->runNow)->toBeTrue()
        ->and($o->minInterval)->toBe(25)
        ->and($o->safetyBufferSeconds)->toBe(600)
        ->and($o->timezone)->toBe('America/Chicago');
});

it('keeps numbers inside the range WordPress allowed', function (string $key, int $given, int $expected, string $property): void {
    expect(optionsFrom([$key => $given])->{$property})->toBe($expected);
})->with([
    ['posts_per_day', 0, 1, 'quota'],
    ['posts_per_day', 500, 100, 'quota'],
    ['pool_size', 0, 1, 'poolSize'],
    ['pool_size', 99999, 10000, 'poolSize'],
    ['batch_size', 5, 10, 'batchSize'],
    ['batch_size', 9999, 500, 'batchSize'],
    ['min_interval', 0, 1, 'minInterval'],
    ['min_interval', 5000, 1440, 'minInterval'],
    ['ai_max_items', 0, 1, 'aiMaxItems'],
    ['ai_max_items', 9000, 1000, 'aiMaxItems'],
]);

it('replaces a window time it cannot read with the default', function (): void {
    $o = optionsFrom(['start_time' => 'dinner', 'end_time' => '25:99']);

    expect($o->start->format12())->toBe('5:00 PM')->and($o->end->format12())->toBe('11:00 PM');
});

it('turns the weekday checkboxes into numbers, and never allows none', function (): void {
    expect(optionsFrom(['active_days' => ['1', '3', '5']])->activeDays)->toBe([1, 3, 5])
        ->and(optionsFrom(['active_days' => ['0', '6', '9', 'x', '-1']])->activeDays)->toBe([0, 6])
        ->and(optionsFrom(['active_days' => []])->activeDays)->toBe([0, 1, 2, 3, 4, 5, 6])
        ->and(optionsFrom(['active_days' => ['1', '1', '2']])->activeDays)->toBe([1, 2]);
});

it('reads content types from a comma separated list', function (): void {
    expect(optionsFrom(['post_types' => 'post, page ,  '])->types)->toBe(['post', 'page'])
        ->and(optionsFrom(['post_types' => ' '])->types)->toBe(['post'])
        ->and(optionsFrom(['post_types' => 'Post,POST,news-item'])->types)->toBe(['post', 'news-item']);
});

it('reads account lists and removes the excluded ones from the author pool', function (): void {
    $o = optionsFrom([
        'randomize_authors' => true,
        'author_accounts' => "01JA, 01JB\n01JC 01JD",
        'excluded_accounts' => '01JB,01JD',
        'acting_account' => ' 01JX ',
    ]);

    expect($o->authorPool())->toBe(['01JA', '01JC'])
        ->and($o->actingAccount)->toBe('01JX');
});

it('has no author pool unless random authors are on', function (): void {
    expect(optionsFrom(['randomize_authors' => false, 'author_accounts' => '01JA'])->authorPool())->toBe([]);
});

it('reads the timezone from the site settings, UTC when unset or unknown', function (): void {
    expect(optionsFrom([], 'Europe/Paris')->timezone)->toBe('Europe/Paris')
        ->and(optionsFrom([], 'Nope/Nowhere')->timezone)->toBe('UTC')
        ->and(optionsFrom([], null)->timezone)->toBe('UTC');
});

it('chooses a safe interval for automatic runs', function (): void {
    expect(optionsFrom(['auto_interval' => '1'])->autoIntervalHours)->toBe(1)
        ->and(optionsFrom(['auto_interval' => '24'])->autoIntervalHours)->toBe(24)
        ->and(optionsFrom(['auto_interval' => '7'])->autoIntervalHours)->toBe(12);
});

it('declares in the manifest the same defaults the code falls back to', function (): void {
    $manifest = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/teme-plugin.json'), true);
    $declared = [];

    foreach ($manifest['contributes']['settings'] as $setting) {
        $declared[$setting['key']] = $setting['default'] ?? null;
    }

    foreach (Defaults::ALL as $key => $value) {
        expect($declared)->toHaveKey($key);
        expect($declared[$key])->toEqual($value, "default of {$key}");
    }
});
