<?php

namespace App\Services\Platforms;

use App\Models\PostMedia;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Publishes a single video to Instagram as a Reel (Instagram API with
 * Instagram Login, content publishing):
 *
 *  1. POST /{ig-user-id}/media  media_type=REELS, video_url, caption → container id
 *  2. GET  /{container-id}?fields=status_code  (polled by CheckInstagramContainerJob)
 *  3. POST /{ig-user-id}/media_publish  creation_id=<container id> → media id
 *
 * publish() only performs step 1 and returns status "processing"; SchedulePostJob
 * stores the container id in platform_post_id and queues the poll job, so no
 * worker ever blocks while Meta processes the video.
 */
class InstagramPublishService implements PublishServiceContract
{
    private const CAPTION_MAX = 2200;

    /** Video URLs handed to Meta stay valid for this long (container creation + fetch). */
    private const VIDEO_URL_TTL_HOURS = 6;

    public function __construct(private InstagramService $instagram) {}

    public function publish(PostTarget $target): array
    {
        $post = $target->post;
        $account = $target->socialAccount;

        // Capabilities (exactly one video, no images) were enforced by SchedulePostJob.
        $video = $post->media()->orderBy('position')->get()->firstWhere('type', 'video');
        if (! $video) {
            throw new \RuntimeException('Media incompatible with instagram: Instagram Reels require exactly one video.');
        }

        $accessToken = $this->instagram->getValidToken($account);

        $this->assertWithinPublishingLimit($account, $accessToken);

        $options = $target->platform_options ?? [];

        $payload = [
            'media_type' => 'REELS',
            'video_url' => $this->resolveVideoUrl($video),
            'caption' => $this->resolveCaption($post),
            'share_to_feed' => ($options['share_to_feed'] ?? true) ? 'true' : 'false',
        ];

        $response = Http::withToken($accessToken)
            ->asForm()
            ->timeout(60)
            ->post($this->instagram->graphEndpoint("{$account->platform_user_id}/media"), $payload);

        $this->instagram->throwIfError($response, 'media container creation');

        $containerId = $response->json('id');
        if (! $containerId) {
            throw new \RuntimeException('Instagram media container creation failed: no container id returned.');
        }

        Log::info('Instagram Reels container created', [
            'target_id' => $target->id,
            'container_id' => $containerId,
        ]);

        return [
            'id' => (string) $containerId,
            'status' => 'processing',
        ];
    }

    /**
     * Current processing status of a media container.
     *
     * @return array{status_code: string|null, status: string|null}
     */
    public function getContainerStatus(SocialAccount $account, string $containerId): array
    {
        $response = Http::withToken($this->instagram->getValidToken($account))
            ->get($this->instagram->graphEndpoint($containerId), [
                'fields' => 'status_code,status',
            ]);

        $this->instagram->throwIfError($response, 'container status');

        return [
            'status_code' => $response->json('status_code'),
            'status' => $response->json('status'),
        ];
    }

    /**
     * Publish a FINISHED container. Returns the Instagram media id.
     */
    public function publishContainer(SocialAccount $account, string $containerId): string
    {
        $response = Http::withToken($this->instagram->getValidToken($account))
            ->asForm()
            ->post($this->instagram->graphEndpoint("{$account->platform_user_id}/media_publish"), [
                'creation_id' => $containerId,
            ]);

        $this->instagram->throwIfError($response, 'media publish');

        $mediaId = $response->json('id');
        if (! $mediaId) {
            throw new \RuntimeException('Instagram media publish failed: no media id returned.');
        }

        return (string) $mediaId;
    }

    /**
     * Instagram allows 100 API-published posts per rolling 24 hours. A failed
     * lookup must not block publishing; an exhausted quota is retryable.
     */
    private function assertWithinPublishingLimit(SocialAccount $account, string $accessToken): void
    {
        try {
            $response = Http::withToken($accessToken)
                ->get($this->instagram->graphEndpoint("{$account->platform_user_id}/content_publishing_limit"), [
                    'fields' => 'quota_usage,config',
                ]);
        } catch (\Throwable $e) {
            Log::warning('Instagram publishing limit check failed', ['account_id' => $account->id, 'error' => $e->getMessage()]);

            return;
        }

        if ($response->failed()) {
            Log::warning('Instagram publishing limit check failed', ['account_id' => $account->id, 'status' => $response->status()]);

            return;
        }

        $usage = $response->json('data.0.quota_usage');
        $total = $response->json('data.0.config.quota_total') ?? 100;

        if ($usage !== null && (int) $usage >= (int) $total) {
            throw new \RuntimeException("Instagram publishing limit reached ({$usage}/{$total} posts in the last 24 hours). Will retry later.");
        }
    }

    /**
     * Meta fetches the video itself, so the URL must be publicly reachable.
     * R2 objects are private → short-lived presigned URL; files on the public
     * disk use the public storage URL.
     */
    private function resolveVideoUrl(PostMedia $media): string
    {
        $path = $media->path;

        if (! $path) {
            $url = $media->url;
        } elseif (str_starts_with($path, 'http')) {
            $url = $path;
        } elseif (Storage::disk('public')->exists($path)) {
            $url = Storage::disk('public')->url($path);
        } elseif (Storage::disk('r2')->exists($path)) {
            $url = Storage::disk('r2')->temporaryUrl($path, now()->addHours(self::VIDEO_URL_TTL_HOURS));
        } else {
            throw new \RuntimeException("Media file not found on disk or R2: {$path}.");
        }

        $host = $url ? parse_url($url, PHP_URL_HOST) : null;
        $scheme = $url ? parse_url($url, PHP_URL_SCHEME) : null;

        if (! $host || ! in_array($scheme, ['http', 'https'], true)
            || in_array($host, ['localhost', '127.0.0.1', '::1', '0.0.0.0'], true)
            || str_ends_with($host, '.local')) {
            throw new \RuntimeException(
                "Instagram cannot fetch media from \"{$host}\". Store videos on R2 or set APP_URL to a public domain."
            );
        }

        return $url;
    }

    /**
     * Captions may be a JSON map of per-platform text; use the Instagram entry.
     */
    private function resolveCaption($post): string
    {
        $caption = (string) ($post->caption ?? '');
        $decoded = json_decode($caption, true);

        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            $caption = (string) ($decoded['instagram'] ?? '');
        } elseif ($caption === '') {
            $caption = (string) ($post->title ?? '');
        }

        return mb_substr($caption, 0, self::CAPTION_MAX);
    }
}
