<?php

declare(strict_types=1);

namespace KraftySprouts\Schedulely;

use Teme\Contracts\Content\ContentSaved;
use Teme\Contracts\Content\ContentWriter;
use Teme\Contracts\Hooks\Hooks;
use Teme\Contracts\Jobs\Jobs;
use Teme\Contracts\Notices\NoticeRegistrar;
use Teme\Contracts\Notices\Notices;
use Teme\Contracts\Plugins\Plugin;
use Teme\Contracts\Settings\SettingsReader;

/**
 * Probe.
 */
final class SchedulelyPlugin implements Plugin
{
    public function __construct(
        private readonly SettingsReader $settings,
        private readonly ContentWriter $writer,
        private readonly Jobs $jobs,
        private readonly Notices $notices,
        private readonly NoticeRegistrar $registrar,
    ) {}

    public function register(Hooks $hooks): void
    {
        $hooks->on(ContentSaved::class, function (ContentSaved $saved): void {});
    }
}
