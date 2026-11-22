<?php
/**
 * Filename: FakeHttp.php
 * Author: Krafty Sprouts Media, LLC
 * Description: A scripted OutboundHttp for tests that must never touch a network.
 *
 * @package KraftySprouts\Schedulely
 *
 * @since   0.1.0
 */

declare(strict_types=1);

namespace KraftySprouts\Schedulely\Tests\Support;

use LogicException;
use Teme\Contracts\Http\HttpAnswer;
use Teme\Contracts\Http\OutboundHttp;
use Throwable;

/**
 * Answers every POST with what it was given, or throws it, and remembers each call.
 *
 * @since 0.1.0
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

    public function postForm(string $url, array $fields, array $headers = [], int $timeoutSeconds = 5): HttpAnswer
    {
        throw new LogicException('Schedulely never posts forms.');
    }

    public function postJson(string $url, array $json, array $headers = [], int $timeoutSeconds = 5): HttpAnswer
    {
        $this->posts[] = ['url' => $url, 'json' => $json, 'headers' => $headers, 'timeout' => $timeoutSeconds];

        if ($this->reply instanceof Throwable) {
            throw $this->reply;
        }

        return $this->reply;
    }

    /**
     * A chat-completions answer carrying this assistant text.
     *
     * @since 0.1.0
     */
    public static function chat(string $content, int $status = 200, int $tokens = 120): HttpAnswer
    {
        return new HttpAnswer($status, (string) json_encode([
            'choices' => [['message' => ['content' => $content]]],
            'usage' => ['total_tokens' => $tokens],
        ]), []);
    }
}
