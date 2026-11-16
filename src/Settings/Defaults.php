<?php
/**
 * Filename: Defaults.php
 * Author: Krafty Sprouts Media, LLC
 * Description: Every setting's default, in one place.
 *
 * @package KraftySprouts\Schedulely
 * @since   0.1.0
 */

declare(strict_types=1);

namespace KraftySprouts\Schedulely\Settings;

/**
 * The values WordPress's Schedulely_Defaults held, as Teme settings keys. The manifest
 * declares the same defaults for Teme to show; a test keeps the two from drifting.
 *
 * @since 0.1.0
 */
final class Defaults
{
    /** The settings group Teme gives the plugin: `plugin.` and the plugin's code. */
    public const string GROUP = 'plugin.schedulely';

    /**
     * @var array<string, mixed>
     *
     * @since 0.1.0
     */
    public const array ALL = [
        'post_status' => 'draft',
        'post_types' => 'post',
        'posts_per_day' => 8,
        'pool_size' => 1500,
        'batch_size' => 75,
        'start_time' => '5:00 PM',
        'end_time' => '11:00 PM',
        'min_interval' => 40,
        'active_days' => ['0', '1', '2', '3', '4', '5', '6'],
        'scheduling_mode' => 'random',
        'safety_buffer' => 30,
        'reorder_queue' => false,
        'ordering_method' => 'php',
        'php_spread' => 'even',
        'timezone_ordering' => false,
        'shuffle_queue' => true,
        'ai_provider' => 'deepseek',
        'ai_model' => 'deepseek-v4-flash',
        'ai_max_items' => 150,
        'randomize_authors' => false,
        'author_accounts' => '',
        'excluded_accounts' => '',
        'acting_account' => '',
        'auto_schedule' => false,
        'auto_interval' => '12',
        'run_now' => false,
    ];
}
