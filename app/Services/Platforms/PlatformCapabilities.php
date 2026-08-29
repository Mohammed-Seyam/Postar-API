<?php

namespace App\Services\Platforms;

use Illuminate\Support\Collection;

/**
 * Reads the config/platforms.php capability registry and validates a post's
 * media collection against a target platform. Used by request validation, the
 * post controller, the publish job, and the GET /platforms endpoint.
 */
class PlatformCapabilities
{
    /**
     * All platforms and their capabilities, keyed by platform slug.
     */
    public static function all(): array
    {
        return config('platforms', []);
    }

    /**
     * Capabilities for a single platform, or null if unknown.
     */
    public static function for(string $platform): ?array
    {
        return config("platforms.{$platform}");
    }

    public static function supports(string $platform): bool
    {
        return static::for($platform) !== null;
    }

    /**
     * Validate a media collection against a platform's capabilities.
     * Returns a list of human-readable error strings; empty means valid.
     *
     * @param  iterable  $media  Items exposing a ->type of 'image'|'video'.
     */
    public static function validate(string $platform, iterable $media): array
    {
        $caps = static::for($platform);

        if ($caps === null) {
            return ["Unsupported platform: {$platform}."];
        }

        $items = Collection::make($media);
        $count = $items->count();
        $label = $caps['label'] ?? ucfirst($platform);
        $errors = [];

        if ($count < ($caps['min_media'] ?? 1)) {
            $errors[] = "{$label} requires at least ".($caps['min_media'] ?? 1).' media item(s).';

            return $errors; // nothing else worth checking with too few items
        }

        if ($count > ($caps['max_media'] ?? 1)) {
            $errors[] = "{$label} allows at most ".($caps['max_media'] ?? 1)." media item(s); {$count} given.";
        }

        $types = $items->map(fn ($m) => is_array($m) ? ($m['type'] ?? null) : $m->type);
        $allowed = $caps['media_types'] ?? [];

        foreach ($types->unique()->filter() as $type) {
            if (! in_array($type, $allowed, true)) {
                $errors[] = "{$label} does not support {$type} media.";
            }
        }

        if (! ($caps['supports_carousel'] ?? false) && $count > 1) {
            $errors[] = "{$label} does not support carousels (multiple media).";
        }

        $hasImage = $types->contains('image');
        $hasVideo = $types->contains('video');

        if (! ($caps['allow_mixed'] ?? false) && $hasImage && $hasVideo) {
            $errors[] = "{$label} does not support mixing images and video in one post.";
        }

        $videoCount = $types->filter(fn ($t) => $t === 'video')->count();
        if (isset($caps['max_video']) && $videoCount > $caps['max_video']) {
            $errors[] = "{$label} allows at most {$caps['max_video']} video(s) per post.";
        }

        return $errors;
    }
}
