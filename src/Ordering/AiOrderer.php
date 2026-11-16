<?php
/**
 * Filename: AiOrderer.php
 * Author: Krafty Sprouts Media, LLC
 * Description: Asks an OpenAI-compatible model to order the queue so posts of one
 *              series are spread apart, and repairs or rejects its answer.
 *
 * @package KraftySprouts\Schedulely
 *
 * @since   0.1.0
 */

declare(strict_types=1);

namespace KraftySprouts\Schedulely\Ordering;

use JsonException;
use KraftySprouts\Schedulely\Queue\QueueItem;
use KraftySprouts\Schedulely\Support\Words;
use Teme\Contracts\Http\AnswerTooLarge;
use Teme\Contracts\Http\HostRefused;
use Teme\Contracts\Http\HttpAnswer;
use Teme\Contracts\Http\OutboundHttp;
use Teme\Contracts\Http\RequestFailed;

/**
 * Sends one numbered slug per queued item, never the item's ID or content, and reads
 * back a permutation. Teme limits a plugin's request to ten seconds and 1 MB, so a
 * queue beyond the configured size is refused here and left to PHP ordering.
 *
 * @since 0.1.0
 */
final readonly class AiOrderer
{
    /** Longest a request may take; Teme's own ceiling for a plugin. */
    private const int TIMEOUT_SECONDS = 10;

    /** Low on purpose: this is a permutation task, and high temperatures invite repetition loops. */
    private const float TEMPERATURE = 0.3;

    /** A runaway-loop fuse, far above the few thousand tokens a real answer takes. */
    private const int MAX_TOKENS = 65536;

    /**
     * @param  string|null  $apiKey  the provider key, read from the plugin's secret setting
     * @param  int  $maxItems  the most items sent in one request
     *
     * @since 0.1.0
     */
    public function __construct(
        private OutboundHttp $http,
        private AiProvider $provider,
        private string $model,
        private ?string $apiKey,
        private int $maxItems,
    ) {}

    /**
     * The model asked, for the reorder log.
     *
     * @since 0.1.0
     */
    public function modelName(): string
    {
        return $this->model;
    }

    /**
     * @param  list<QueueItem>  $items
     *
     * @since 0.1.0
     */
    public function reorder(array $items): AiOutcome
    {
        $key = trim((string) $this->apiKey);

        if ($key === '') {
            return $this->failure('ai_no_key', Words::say('No API key is set for AI queue ordering.'));
        }

        if (count($items) > $this->maxItems) {
            return $this->failure('ai_too_many', Words::say(':count items is more than one AI request can order (:max); PHP ordering is used instead.', [
                'count' => count($items), 'max' => $this->maxItems,
            ]));
        }

        $items = array_values($items);

        try {
            $answer = $this->http->postJson($this->provider->endpoint(), $this->body($items), [
                'Authorization' => 'Bearer '.$key,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ], self::TIMEOUT_SECONDS);
        } catch (HostRefused $e) {
            return $this->failure('ai_host_refused', $this->clean($e->getMessage(), $key));
        } catch (AnswerTooLarge $e) {
            return $this->failure('ai_too_large', $this->clean($e->getMessage(), $key));
        } catch (RequestFailed $e) {
            return $this->failure('ai_request_failed', $this->clean($e->getMessage(), $key));
        }

        return $this->read($answer, $items, $key);
    }

    /**
     * @param  list<QueueItem>  $items
     *
     * @return array<string, mixed>
     */
    private function body(array $items): array
    {
        $lines = [];

        foreach ($items as $index => $item) {
            $lines[] = ($index + 1)."\t".SeriesStem::slugify($item->title);
        }

        $count = count($items);

        return [
            'model' => $this->model,
            'messages' => [
                ['role' => 'system', 'content' => self::SYSTEM],
                ['role' => 'user', 'content' => sprintf(
                    "Reorder these %1\$d posts. Output a JSON object {\"ordered_ids\":[...]} whose array contains all %1\$d post numbers, each appearing exactly once, in your new order. Do not repeat any number. Do not invent numbers. The array length must be exactly %1\$d. Stop immediately after the %1\$d-th number.\n\n",
                    $count,
                ).implode("\n", $lines)],
            ],
            'temperature' => self::TEMPERATURE,
            'top_p' => 1.0,
            'max_tokens' => self::MAX_TOKENS,
            'response_format' => ['type' => 'json_object'],
        ];
    }

    /** The system prompt: English only, not translated, for the model's sake. */
    private const string SYSTEM = 'You reorder posts for publication. Each line is: number TAB slug. '
        .'The slug is the URL keyword; posts in the same series share an almost identical slug with only a word or two differing. '
        .'Detect posts that belong to the same series or template. Your goal is to maximize variety in the sequence. '
        .'Do not place similar or same-series posts close together. Maintain a minimum spacing of at least 3 to 5 other posts '
        .'between similar slugs whenever the mix allows. If perfect spacing is not possible, distribute similar posts as evenly as you can across the entire list. '
        .'Prioritize diversity of topics over original order. Return a JSON object with a single key "ordered_ids" whose value is an array of integers. '
        .'It MUST list every input number exactly once: same length as the input list, no duplicates, no made-up numbers, no omissions. '
        .'Never repeat a number you have already emitted. As soon as every number has appeared once, close the array and stop. '
        .'Example: for the 4 input lines "1 cats-in-texas", "2 dogs-in-ohio", "3 cats-in-ohio", "4 dogs-in-texas", a complete valid response is exactly {"ordered_ids":[1,2,3,4]}. '
        .'Output only valid JSON, no markdown fences, no commentary.';

    /**
     * @param  list<QueueItem>  $items
     */
    private function read(HttpAnswer $answer, array $items, string $key): AiOutcome
    {
        $tokens = $this->tokens($answer->body);

        if ($answer->status !== 200) {
            return $this->failure('ai_http', Words::say('The AI request failed (HTTP :status): :body', [
                'status' => $answer->status, 'body' => $this->excerpt($answer->body, 200, $key),
            ]), $answer->status, $tokens, raw: $this->excerpt($answer->body, 2000, $key));
        }

        try {
            $decoded = $answer->json();
        } catch (JsonException) {
            $decoded = null;
        }

        if (! is_array($decoded)) {
            return $this->failure('ai_json', Words::say('The AI answer was not valid JSON.'), $answer->status, $tokens, raw: $this->excerpt($answer->body, 2000, $key));
        }

        $text = $this->assistantText($decoded);

        if ($text === '') {
            return $this->failure('ai_empty', Words::say('The AI returned an empty message.'), $answer->status, $tokens, raw: $this->excerpt($answer->body, 2000, $key));
        }

        $parsed = $this->parseIds($text);

        if ($parsed === null) {
            return $this->failure('ai_shape', Words::say('The AI JSON must contain an "ordered_ids" array.'), $answer->status, $tokens, $this->excerpt($text, 1200, $key));
        }

        [$ordered, $strict] = $this->reconcile(count($items), $parsed);
        $ids = array_map(static fn (int $number): string => $items[$number - 1]->id, $ordered);

        return new AiOutcome(
            ok: true,
            orderedIds: $ids,
            httpCode: $answer->status,
            usageTokens: $tokens,
            assistantExcerpt: $this->excerpt($text, 1200, $key),
            note: $strict
                ? Words::say('Queue order was applied.')
                : Words::say('Queue order applied after reconciliation (the model returned :got numbers for :want items).', ['got' => count($parsed), 'want' => count($items)]),
        );
    }

    /**
     * @return list<int>|null
     */
    private function parseIds(string $text): ?array
    {
        $text = trim($text);

        if (preg_match('/^```(?:json)?\s*(\{.*\})\s*```$/s', $text, $m) === 1) {
            $text = $m[1];
        }

        $object = json_decode($text, true);

        if (! is_array($object) || ! isset($object['ordered_ids']) || ! is_array($object['ordered_ids'])) {
            return null;
        }

        return array_map(static fn (mixed $value): int => is_numeric($value) ? abs((int) $value) : 0, array_values($object['ordered_ids']));
    }

    /**
     * Keeps valid numbers in the model's order, drops unknown and repeated ones, and
     * appends any left out in input order.
     *
     * @param  list<int>  $parsed
     *
     * @return array{0: list<int>, 1: bool} the repaired order, and whether the answer needed no repair
     */
    private function reconcile(int $count, array $parsed): array
    {
        $sorted = $parsed;
        sort($sorted);
        $strict = $sorted === range(1, $count);

        $seen = [];
        $ordered = [];

        foreach ($parsed as $number) {
            if ($number >= 1 && $number <= $count && ! isset($seen[$number])) {
                $seen[$number] = true;
                $ordered[] = $number;
            }
        }

        for ($number = 1; $number <= $count; $number++) {
            if (! isset($seen[$number])) {
                $ordered[] = $number;
            }
        }

        return [$ordered, $strict];
    }

    /**
     * @param  array<mixed>  $decoded
     */
    private function assistantText(array $decoded): string
    {
        $choice = $decoded['choices'][0] ?? null;

        if (! is_array($choice)) {
            return '';
        }

        $message = $choice['message'] ?? null;
        $content = is_array($message) ? ($message['content'] ?? null) : null;

        if (is_string($content) && trim($content) !== '') {
            return trim($content);
        }

        if (is_array($content)) {
            $parts = [];

            foreach ($content as $part) {
                if (is_array($part) && isset($part['text'])) {
                    $parts[] = (string) $part['text'];
                }
            }

            return trim(implode('', $parts));
        }

        return isset($choice['text']) && is_string($choice['text']) ? trim($choice['text']) : '';
    }

    private function tokens(string $body): ?int
    {
        $decoded = json_decode($body, true);

        return is_array($decoded) && isset($decoded['usage']['total_tokens']) ? (int) $decoded['usage']['total_tokens'] : null;
    }

    private function failure(string $code, string $message, ?int $http = null, ?int $tokens = null, string $assistant = '', string $raw = ''): AiOutcome
    {
        return new AiOutcome(false, [], $code, $message, $http, $tokens, $assistant, $raw);
    }

    private function excerpt(string $text, int $max, string $key): string
    {
        $text = $this->clean(trim((string) preg_replace('/\s+/u', ' ', strip_tags($text))), $key);

        return mb_strlen($text) > $max ? mb_substr($text, 0, $max).'…' : $text;
    }

    private function clean(string $text, string $key): string
    {
        return $key === '' ? $text : str_replace($key, '***', $text);
    }
}
