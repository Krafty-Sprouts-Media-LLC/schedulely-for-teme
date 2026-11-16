<?php
/**
 * Filename: AiOrdererTest.php
 * Author: Krafty Sprouts Media, LLC
 * Description: AI queue ordering through OutboundHttp: the request it sends, how it
 *              reads and repairs the answer, and every way it can fail. A fake
 *              OutboundHttp stands in for the network, and the key is a dummy.
 *
 * @package KraftySprouts\Schedulely
 * @since   0.1.0
 */

declare(strict_types=1);

use KraftySprouts\Schedulely\Ordering\AiOrderer;
use KraftySprouts\Schedulely\Ordering\AiProvider;
use KraftySprouts\Schedulely\Queue\QueueItem;
use Teme\Contracts\Http\AnswerTooLarge;
use Teme\Contracts\Http\HostRefused;
use Teme\Contracts\Http\HttpAnswer;
use Teme\Contracts\Http\OutboundHttp;
use Teme\Contracts\Http\RequestFailed;

/**
 * A scripted OutboundHttp: answers with what it was given and remembers each call.
 */
final class FakeHttp implements OutboundHttp
{
    /** @var list<array{url: string, json: array<string, mixed>, headers: array<string, string>, timeout: int}> */
    public array $posts = [];

    public function __construct(private HttpAnswer|Throwable $reply) {}

    public function get(string $url, array $headers = [], int $timeoutSeconds = 5): HttpAnswer
    {
        throw new LogicException('Schedulely never GETs.');
    }

    public function postJson(string $url, array $json, array $headers = [], int $timeoutSeconds = 5): HttpAnswer
    {
        $this->posts[] = ['url' => $url, 'json' => $json, 'headers' => $headers, 'timeout' => $timeoutSeconds];

        if ($this->reply instanceof Throwable) {
            throw $this->reply;
        }

        return $this->reply;
    }
}

function chatAnswer(string $content, int $status = 200, int $tokens = 120): HttpAnswer
{
    return new HttpAnswer($status, (string) json_encode([
        'choices' => [['message' => ['content' => $content]]],
        'usage' => ['total_tokens' => $tokens],
    ]), []);
}

function aiItems(): array
{
    return [
        new QueueItem('01a', 'post', 'Best Pizza in Texas', 'en', 'draft', 1),
        new QueueItem('01b', 'post', 'Best Pizza in Ohio', 'en', 'draft', 2),
        new QueueItem('01c', 'post', 'Best Tacos in Texas', 'en', 'draft', 3),
        new QueueItem('01d', 'post', 'A Standalone Piece', 'en', 'draft', 4),
    ];
}

function orderer(FakeHttp $http, ?string $key = 'dummy-key-not-real', int $max = 150): AiOrderer
{
    return new AiOrderer($http, AiProvider::Deepseek, 'deepseek-v4-flash', $key, $max);
}

it('posts a JSON-mode chat completion to the provider with the key as a bearer token', function (): void {
    $http = new FakeHttp(chatAnswer('{"ordered_ids":[1,3,2,4]}'));
    orderer($http)->reorder(aiItems());

    expect($http->posts)->toHaveCount(1);
    $post = $http->posts[0];

    expect($post['url'])->toBe('https://api.deepseek.com/v1/chat/completions')
        ->and($post['headers']['Authorization'])->toBe('Bearer dummy-key-not-real')
        ->and($post['json']['model'])->toBe('deepseek-v4-flash')
        ->and($post['json']['temperature'])->toBe(0.3)
        ->and($post['json']['response_format'])->toBe(['type' => 'json_object'])
        ->and($post['json']['max_tokens'])->toBe(65536)
        ->and($post['json']['messages'][0]['role'])->toBe('system')
        ->and($post['json']['messages'][1]['role'])->toBe('user')
        ->and($post['timeout'])->toBeLessThanOrEqual(10);
});

it('sends a numbered slug per line and never the item IDs or any content', function (): void {
    $http = new FakeHttp(chatAnswer('{"ordered_ids":[1,2,3,4]}'));
    orderer($http)->reorder(aiItems());
    $prompt = $http->posts[0]['json']['messages'][1]['content'];

    expect($prompt)->toContain("1\tbest-pizza-in-texas")
        ->and($prompt)->toContain("4\ta-standalone-piece")
        ->and($prompt)->toContain('exactly 4')
        ->and($prompt)->not->toContain('01a')
        ->and(json_encode($http->posts[0]['json']))->not->toContain('dummy-key-not-real');
});

it('maps the numbers the model answers with back to item IDs', function (): void {
    $outcome = orderer(new FakeHttp(chatAnswer('{"ordered_ids":[1,3,2,4]}', tokens: 321)))->reorder(aiItems());

    expect($outcome->ok)->toBeTrue()
        ->and($outcome->orderedIds)->toBe(['01a', '01c', '01b', '01d'])
        ->and($outcome->usageTokens)->toBe(321)
        ->and($outcome->httpCode)->toBe(200);
});

it('reads JSON wrapped in a code fence', function (): void {
    $outcome = orderer(new FakeHttp(chatAnswer("```json\n{\"ordered_ids\":[4,3,2,1]}\n```")))->reorder(aiItems());

    expect($outcome->orderedIds)->toBe(['01d', '01c', '01b', '01a']);
});

it('repairs an answer that drops, repeats or invents numbers', function (): void {
    $outcome = orderer(new FakeHttp(chatAnswer('{"ordered_ids":[3,3,9,1]}')))->reorder(aiItems());

    // 3 and 1 kept in the model's order, the repeat and 9 dropped, 2 and 4 appended in input order.
    expect($outcome->ok)->toBeTrue()
        ->and($outcome->orderedIds)->toBe(['01c', '01a', '01b', '01d'])
        ->and($outcome->note)->toContain('reconciliation');
});

it('reads an answer whose content is split into parts', function (): void {
    $answer = new HttpAnswer(200, (string) json_encode(['choices' => [['message' => ['content' => [['text' => '{"ordered_ids":'], ['text' => '[2,1,3,4]}']]]]]]), []);

    expect(orderer(new FakeHttp($answer))->reorder(aiItems())->orderedIds)->toBe(['01b', '01a', '01c', '01d']);
});

it('fails clearly without a key and sends nothing', function (): void {
    $http = new FakeHttp(chatAnswer('{}'));
    $outcome = orderer($http, key: null)->reorder(aiItems());

    expect($outcome->ok)->toBeFalse()->and($outcome->errorCode)->toBe('ai_no_key')->and($http->posts)->toBe([]);

    $blank = orderer($http, key: '   ')->reorder(aiItems());

    expect($blank->errorCode)->toBe('ai_no_key');
});

it('refuses a queue too big for one ten second request, without sending it', function (): void {
    $http = new FakeHttp(chatAnswer('{}'));
    $outcome = orderer($http, max: 3)->reorder(aiItems());

    expect($outcome->ok)->toBeFalse()->and($outcome->errorCode)->toBe('ai_too_many')->and($http->posts)->toBe([]);
});

it('reports every way the call can go wrong', function (HttpAnswer|Throwable $reply, string $code): void {
    $outcome = orderer(new FakeHttp($reply))->reorder(aiItems());

    expect($outcome->ok)->toBeFalse()->and($outcome->errorCode)->toBe($code)->and($outcome->errorMessage)->not->toBe('');
})->with([
    'host refused' => [new HostRefused('no'), 'ai_host_refused'],
    'too large' => [new AnswerTooLarge('big'), 'ai_too_large'],
    'no answer' => [new RequestFailed('timeout'), 'ai_request_failed'],
    'http error' => [new HttpAnswer(401, '{"error":{"message":"bad key"}}', []), 'ai_http'],
    'not json' => [new HttpAnswer(200, 'oops', []), 'ai_json'],
    'no message' => [new HttpAnswer(200, '{"choices":[]}', []), 'ai_empty'],
    'wrong shape' => [chatAnswer('{"order":[1,2]}'), 'ai_shape'],
    'content not json' => [chatAnswer('sorry I cannot'), 'ai_shape'],
]);

it('keeps the key out of error text and excerpts', function (): void {
    $outcome = orderer(new FakeHttp(new HttpAnswer(401, 'Invalid key dummy-key-not-real', [])))->reorder(aiItems());

    expect($outcome->errorMessage)->not->toContain('dummy-key-not-real')
        ->and($outcome->rawExcerpt)->not->toContain('dummy-key-not-real');
});

it('names each provider host so the manifest can declare it', function (): void {
    expect(array_map(static fn (AiProvider $p): string => $p->host(), AiProvider::cases()))
        ->toBe(['api.deepseek.com', 'api.openai.com', 'openrouter.ai'])
        ->and(AiProvider::fromStored('openai'))->toBe(AiProvider::OpenAi)
        ->and(AiProvider::fromStored('nope'))->toBe(AiProvider::Deepseek);
});
