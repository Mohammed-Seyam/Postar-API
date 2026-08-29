<?php

namespace App\Services\Platforms;

use App\Models\PostTarget;

/**
 * Every platform publisher implements this. It standardizes the publish entry
 * point so SchedulePostJob can treat all platforms uniformly. Per-platform
 * capabilities (media types, carousel support, limits) are declared in
 * config/platforms.php and enforced via PlatformCapabilities.
 */
interface PublishServiceContract
{
    /**
     * Publish the given target's post to the platform.
     *
     * @return array{id?: string|null, status?: string, real_id?: string|null}
     */
    public function publish(PostTarget $target): array;
}
