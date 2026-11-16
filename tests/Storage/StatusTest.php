<?php
/**
 * Filename: StatusTest.php
 * Author: Krafty Sprouts Media, LLC
 * Description: The notices Schedulely raises on every admin page, standing in for the
 *              capacity pill, the stat cards and the notification email Teme gives a
 *              plugin no screen or mail door for.
 *
 * @package KraftySprouts\Schedulely
 *
 * @since   0.1.0
 */

declare(strict_types=1);

use Illuminate\Database\ConnectionInterface;
use KraftySprouts\Schedulely\Queue\QueueItem;
use KraftySprouts\Schedulely\Status\StatusChecks;
use KraftySprouts\Schedulely\Storage\QueueStore;
use KraftySprouts\Schedulely\Storage\StateStore;
use KraftySprouts\Schedulely\Tests\Support\ArraySettings;
use KraftySprouts\Schedulely\Tests\Support\FixedSecret;
use KraftySprouts\Schedulely\Tests\Support\TestDatabase;
use Teme\Contracts\Status\StatusNotice;

const STATUS_NOW = 1_794_988_800;

/**
 * @param  array<string, mixed>  $settings
 *
 * @return array{0: list<StatusNotice>, 1: ConnectionInterface}
 */
function notices(array $settings = [], ?string $key = null, bool $prepare = true): array
{
    $db = TestDatabase::fresh();

    if ($prepare) {
        // A queued draft and a past run, so the "just installed" note stays quiet unless a test wants it.
        (new QueueStore($db))->put(new QueueItem('a', 'post', 'A', 'en', 'draft', 1));
        (new StateStore($db))->put('last_run', STATUS_NOW - 10 * 86400);
    }

    $good = ['posts_per_day' => 3, 'scheduling_mode' => 'sequential', 'start_time' => '9:00 AM', 'end_time' => '5:00 PM', 'acting_account' => 'acct'];
    $checks = new StatusChecks(new ArraySettings(['plugin.schedulely' => $settings + $good, 'general' => ['timezone' => 'UTC']]), new FixedSecret($key), $db, static fn (): int => STATUS_NOW);

    $found = [];
    foreach ($checks->statusChecks() as $check) {
        $notice = $check->notice();

        if ($notice !== null) {
            $found[] = $notice;
        }
    }

    return [$found, $db];
}

function messages(array $notices): array
{
    return array_map(static fn (StatusNotice $n): string => $n->message, $notices);
}

it('is quiet when everything is set up and nothing needs saying', function (): void {
    [$found] = notices();

    expect($found)->toBe([]);
});

it('warns when the window cannot hold the daily quota, and says what would fix it', function (): void {
    [$found] = notices(['posts_per_day' => 8, 'scheduling_mode' => 'random', 'start_time' => '5:00 PM', 'end_time' => '11:00 PM', 'min_interval' => 40]);

    expect($found)->toHaveCount(1)
        ->and($found[0]->level)->toBe('warning')
        ->and($found[0]->opensSettings)->toBeTrue()
        ->and($found[0]->message)->toContain('about 5')->and($found[0]->message)->toContain('8');
});

it('errors when automatic scheduling is on with no account to act as', function (): void {
    [$found] = notices(['auto_schedule' => true, 'acting_account' => '']);

    expect($found)->toHaveCount(1)->and($found[0]->level)->toBe('error')->and($found[0]->opensSettings)->toBeTrue()->and($found[0]->message)->toContain('account');
});

it('does not ask for an account when a rotation is set', function (): void {
    [$found] = notices(['auto_schedule' => true, 'acting_account' => '', 'randomize_authors' => true, 'author_accounts' => 'u1,u2']);

    expect($found)->toBe([]);
});

it('says timezone ordering needs Random mode', function (): void {
    [$found] = notices(['timezone_ordering' => true, 'scheduling_mode' => 'hybrid']);

    expect($found)->toHaveCount(1)->and($found[0]->level)->toBe('info')->and($found[0]->message)->toContain('Random');
});

it('warns when AI ordering is chosen without a key', function (): void {
    [$found] = notices(['reorder_queue' => true, 'ordering_method' => 'ai'], key: null);

    expect($found)->toHaveCount(1)->and($found[0]->level)->toBe('warning')->and($found[0]->message)->toContain('key');
    expect(notices(['reorder_queue' => true, 'ordering_method' => 'ai'], key: 'dummy-key-not-real')[0])->toBe([]);
});

it('does not worry about a missing key when AI ordering is off', function (): void {
    expect(notices(['reorder_queue' => false, 'ordering_method' => 'ai'])[0])->toBe([])
        ->and(notices(['reorder_queue' => true, 'ordering_method' => 'php'])[0])->toBe([]);
});

it('explains an empty queue on a site that has never run', function (): void {
    [$found] = notices(prepare: false);

    expect($found)->toHaveCount(1)->and($found[0]->level)->toBe('info')->and($found[0]->message)->toContain('saved');
});

it('tells how the last run went for a day after it, counting failures', function (): void {
    [$found, $db] = notices();
    $state = new StateStore($db);
    $state->put('last_run', STATUS_NOW - 3600);
    $state->put('last_report', ['at' => STATUS_NOW - 3600, 'manual' => true, 'scheduled' => 7, 'queued' => 9, 'failed' => 2, 'windows' => ['2026-11-16', '2026-11-18'], 'errors' => ['Item x could not be scheduled: locked'], 'last_post_at' => null, 'ordered_by' => 'php']);

    $checks = new StatusChecks(new ArraySettings(['plugin.schedulely' => ['posts_per_day' => 3, 'scheduling_mode' => 'sequential', 'acting_account' => 'a'], 'general' => ['timezone' => 'UTC']]), new FixedSecret(null), $db, static fn (): int => STATUS_NOW);
    $notes = array_values(array_filter(array_map(static fn ($c) => $c->notice(), $checks->statusChecks())));

    expect($notes)->toHaveCount(2)
        ->and($notes[0]->level)->toBe('info')
        ->and($notes[0]->message)->toContain('7 of 9')->and($notes[0]->message)->toContain('2026-11-16')
        ->and($notes[1]->level)->toBe('warning')->and($notes[1]->message)->toContain('2')->and($notes[1]->message)->toContain('locked');
});

it('stops mentioning a run after a day', function (): void {
    [, $db] = notices();
    $state = new StateStore($db);
    $state->put('last_run', STATUS_NOW - 2 * 86400);
    $state->put('last_report', ['at' => STATUS_NOW - 2 * 86400, 'manual' => true, 'scheduled' => 7, 'queued' => 7, 'failed' => 0, 'windows' => [], 'errors' => [], 'last_post_at' => null, 'ordered_by' => '']);

    $checks = new StatusChecks(new ArraySettings(['plugin.schedulely' => ['posts_per_day' => 3, 'scheduling_mode' => 'sequential', 'acting_account' => 'a'], 'general' => ['timezone' => 'UTC']]), new FixedSecret(null), $db, static fn (): int => STATUS_NOW);

    expect(array_values(array_filter(array_map(static fn ($c) => $c->notice(), $checks->statusChecks()))))->toBe([]);
});

it('mentions items the pool size is holding back', function (): void {
    $db = TestDatabase::fresh();
    $queue = new QueueStore($db);
    foreach (range(1, 5) as $i) {
        $queue->put(new QueueItem("i{$i}", 'post', "T{$i}", 'en', 'draft', $i));
    }
    (new StateStore($db))->put('last_run', STATUS_NOW - 10 * 86400);

    $checks = new StatusChecks(new ArraySettings(['plugin.schedulely' => ['pool_size' => 2, 'posts_per_day' => 3, 'scheduling_mode' => 'sequential', 'acting_account' => 'a'], 'general' => ['timezone' => 'UTC']]), new FixedSecret(null), $db, static fn (): int => STATUS_NOW);
    $notes = array_values(array_filter(array_map(static fn ($c) => $c->notice(), $checks->statusChecks())));

    expect($notes)->toHaveCount(1)->and($notes[0]->message)->toContain('5')->and($notes[0]->message)->toContain('2');
});
