<?php
/**
 * Filename: FixedSecret.php
 * Author: Krafty Sprouts Media, LLC
 * Description: A SecretReader that answers one dummy value, for tests.
 *
 * @package KraftySprouts\Schedulely
 * @since   0.1.0
 */

declare(strict_types=1);

namespace KraftySprouts\Schedulely\Tests\Support;

use Teme\Contracts\Settings\SecretReader;

/**
 * Answers the AI key of the plugin's own group, as Teme's reader would, and null for any
 * other group or key.
 *
 * @since 0.1.0
 */
final class FixedSecret implements SecretReader
{
    public function __construct(private ?string $value) {}

    public function secret(string $group, string $key): ?string
    {
        return $group === 'plugin.schedulely' && $key === 'ai_api_key' ? $this->value : null;
    }
}
