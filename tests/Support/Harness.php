<?php
/**
 * Filename: Harness.php
 * Author: Krafty Sprouts Media, LLC
 * Description: Builds a RunService on SQLite and fakes, with a queue already filled.
 *
 * @package KraftySprouts\Schedulely
 *
 * @since   0.1.0
 */

declare(strict_types=1);

namespace KraftySprouts\Schedulely\Tests\Support;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\ConnectionInterface;
use KraftySprouts\Schedulely\Queue\QueueItem;
use KraftySprouts\Schedulely\Run\RunService;
use KraftySprouts\Schedulely\Storage\QueueStore;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Teme\Contracts\Http\HttpAnswer;

/**
 * Everything a run test needs, wired the way the plugin wires it: a daily window of 9 to 5
 * UTC, two a day, Sequential, oldest first, and the clock at 2026-11-15 08:00 UTC.
 *
 * @since 0.1.0
 */
final readonly class Harness
{
    public function __construct(
        public RunService $service,
        public FakeWriter $writer,
        public ConnectionInterface $db,
        public FakeHttp $http,
        public ArraySettings $settings,
        public TestClock $clock,
    ) {}

    /** 2026-11-15 08:00 UTC, a Sunday morning before the window opens. */
    public static function start(): int
    {
        return (new DateTimeImmutable('2026-11-15 08:00', new DateTimeZone('UTC')))->getTimestamp();
    }

    /**
     * @param  array<string, mixed>  $settings  plugin settings on top of the defaults described above
     *
     * @since 0.1.0
     */
    public static function make(array $settings = [], int $queued = 5, ?string $key = null, ?HttpAnswer $reply = null): self
    {
        $db = TestDatabase::fresh();
        $queue = new QueueStore($db);

        for ($i = 1; $i <= $queued; $i++) {
            $queue->put(new QueueItem(sprintf('item%02d', $i), 'post', "Draft number {$i}", 'en', 'draft', $i));
        }

        $writer = new FakeWriter;
        $http = new FakeHttp($reply ?? FakeHttp::chat('{"ordered_ids":[5,4,3,2,1]}'));
        $clock = new TestClock(self::start());
        $reader = new ArraySettings(['plugin.schedulely' => $settings + [
            'posts_per_day' => 2, 'scheduling_mode' => 'sequential', 'start_time' => '9:00 AM', 'end_time' => '5:00 PM',
            'acting_account' => 'acting-1', 'shuffle_queue' => false,
        ], 'general' => ['timezone' => 'UTC']]);

        $service = new RunService($reader, new FixedSecret($key), $writer, $http, $db, $clock->now(...), new Randomizer(new Mt19937(8)));

        return new self($service, $writer, $db, $http, $reader, $clock);
    }
}
