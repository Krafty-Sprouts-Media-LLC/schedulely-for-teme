<?php
/**
 * Filename: AiProvider.php
 * Author: Krafty Sprouts Media, LLC
 * Description: The OpenAI-compatible services AI ordering can call.
 *
 * @package KraftySprouts\Schedulely
 *
 * @since   0.1.0
 */

declare(strict_types=1);

namespace KraftySprouts\Schedulely\Ordering;

/**
 * A provider the manifest declares under `hosts`. Teme's outbound door reaches only
 * declared hosts, so WordPress's "any base URL" becomes this short list.
 *
 * @since 0.1.0
 */
enum AiProvider: string
{
    case Deepseek = 'deepseek';
    case OpenAi = 'openai';
    case OpenRouter = 'openrouter';

    /**
     * The host to declare in teme-plugin.json.
     *
     * @since 0.1.0
     */
    public function host(): string
    {
        return match ($this) {
            self::Deepseek => 'api.deepseek.com',
            self::OpenAi => 'api.openai.com',
            self::OpenRouter => 'openrouter.ai',
        };
    }

    /**
     * The chat completions address.
     *
     * @since 0.1.0
     */
    public function endpoint(): string
    {
        return match ($this) {
            self::Deepseek => 'https://api.deepseek.com/v1/chat/completions',
            self::OpenAi => 'https://api.openai.com/v1/chat/completions',
            self::OpenRouter => 'https://openrouter.ai/api/v1/chat/completions',
        };
    }

    /**
     * The provider named by a stored value, DeepSeek when it is unknown.
     *
     * @since 0.1.0
     */
    public static function fromStored(mixed $value): self
    {
        return is_string($value) ? (self::tryFrom($value) ?? self::Deepseek) : self::Deepseek;
    }
}
