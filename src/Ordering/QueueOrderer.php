<?php
/**
 * Filename: QueueOrderer.php
 * Author: Krafty Sprouts Media, LLC
 * Description: Decides how the queue is ordered before times are given out.
 *
 * @package KraftySprouts\Schedulely
 *
 * @since   0.1.0
 */

declare(strict_types=1);

namespace KraftySprouts\Schedulely\Ordering;

use KraftySprouts\Schedulely\Queue\QueueItem;
use KraftySprouts\Schedulely\Support\Words;
use Random\Randomizer;

/**
 * Reorders with PHP or AI when asked to and allowed to (a person's manual run, never an
 * automatic one), falls back to PHP when AI fails, and otherwise shuffles or leaves the
 * oldest-first order alone.
 *
 * @since 0.1.0
 */
final readonly class QueueOrderer
{
    /**
     * @param  bool  $enabled  the "reorder the queue" setting
     * @param  bool  $shuffle  the "shuffle" setting, used when no reordering happens
     * @param  AiOrderer|null  $ai  null when no AI service is set up
     *
     * @since 0.1.0
     */
    public function __construct(
        private bool $enabled,
        private OrderingMethod $method,
        private bool $shuffle,
        private PhpOrderer $php,
        private ?AiOrderer $ai,
        private Randomizer $random,
        private ReorderLog $log,
    ) {}

    /**
     * @param  list<QueueItem>  $items  oldest first
     * @param  bool  $allowReorder  true for a manual run
     *
     * @since 0.1.0
     */
    public function order(array $items, bool $allowReorder): OrderResult
    {
        $items = array_values($items);

        if (count($items) < 2) {
            return new OrderResult($items, false);
        }

        if ($allowReorder && $this->enabled) {
            return $this->method === OrderingMethod::Ai ? $this->viaAi($items) : $this->viaPhp($items, Words::say('PHP ordering applied; the ordering method is PHP, so the AI was not called.'));
        }

        return new OrderResult($this->shuffle ? $this->random->shuffleArray($items) : $items, false);
    }

    /**
     * @param  list<QueueItem>  $items
     */
    private function viaAi(array $items): OrderResult
    {
        if ($this->ai === null) {
            return $this->viaPhp($items, Words::say('PHP ordering applied as the automatic fallback; no AI service is set up.'));
        }

        $outcome = $this->ai->reorder($items);

        if (! $outcome->ok) {
            $this->log->append(new ReorderLogEntry(
                'error', $this->modelName(), count($items), $outcome->httpCode, $outcome->usageTokens,
                $outcome->errorCode, $outcome->errorMessage, $outcome->assistantExcerpt, $outcome->rawExcerpt,
                Words::say('The AI request failed; the queue is ordered in PHP instead.'),
            ));

            return $this->viaPhp($items, Words::say('PHP ordering applied as the automatic fallback after the AI request failed (:code).', ['code' => $outcome->errorCode]), $outcome->errorCode);
        }

        $byId = [];
        foreach ($items as $item) {
            $byId[$item->id] = $item;
        }

        $this->log->append(new ReorderLogEntry(
            'success', $this->modelName(), count($items), $outcome->httpCode, $outcome->usageTokens,
            assistantExcerpt: $outcome->assistantExcerpt, note: $outcome->note,
        ));

        return new OrderResult(array_map(static fn (string $id): QueueItem => $byId[$id], $outcome->orderedIds), true, 'ai');
    }

    /**
     * @param  list<QueueItem>  $items
     */
    private function viaPhp(array $items, string $why, string $errorCode = ''): OrderResult
    {
        $ordered = $this->php->order($items);

        $this->log->append(new ReorderLogEntry(
            'success', 'php', count($items), errorCode: $errorCode,
            assistantExcerpt: '{"ordered_ids":['.implode(',', array_map(static fn (QueueItem $item): string => $item->id, $ordered)).']}',
            note: $why.' '.$this->php->summary($items),
        ));

        return new OrderResult($ordered, true, 'php');
    }

    private function modelName(): string
    {
        return $this->ai?->modelName() ?? 'ai';
    }
}
