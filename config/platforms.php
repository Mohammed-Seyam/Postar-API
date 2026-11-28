<?php

/**
 * Platform capability registry.
 *
 * Single source of truth for what each publish target can accept. Consumed by:
 *  - App\Services\Platforms\PlatformCapabilities (validation)
 *  - GET /platforms (so the frontend never hardcodes rules)
 *  - StorePostRequest / PostController (reject invalid platform + media combos)
 *
 * Adding a new platform = add an entry here + a PublishServiceContract
 * implementation + one line in SchedulePostJob::getPublishService().
 *
 * Keys:
 *  - label            Human-readable name for the UI.
 *  - media_types      Which slide types the platform accepts.
 *  - supports_carousel Whether more than one media item is allowed in one post.
 *  - allow_mixed      Whether images and video may be combined in one post.
 *  - min_media        Minimum number of media items required.
 *  - max_media        Maximum number of media items allowed.
 *  - max_video        Maximum number of video items allowed.
 */

return [
    'tiktok' => [
        'label' => 'TikTok',
        'media_types' => ['image', 'video'],
        'supports_carousel' => true,
        'allow_mixed' => false,
        'min_media' => 1,
        'max_media' => 35,   // TikTok photo-mode carousel limit
        'max_video' => 1,
    ],

    'youtube' => [
        'label' => 'YouTube',
        'media_types' => ['video'],
        'supports_carousel' => false,
        'allow_mixed' => false,
        'min_media' => 1,
        'max_media' => 1,
        'max_video' => 1,
    ],

    // Instagram Reels via the Instagram API with Instagram Login.
    'instagram' => [
        'label' => 'Instagram',
        'media_types' => ['video'],
        'supports_carousel' => false,
        'allow_mixed' => false,
        'min_media' => 1,
        'max_media' => 1,
        'max_video' => 1,
    ],
];
