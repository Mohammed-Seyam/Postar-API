<?php

namespace App\Services\Platforms;

use App\Models\PostMedia;
use App\Models\PostTarget;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

class TikTokPublishService implements PublishServiceContract
{
    /**
     * Maximum size for a single-chunk upload (64 MB).
     * Files larger than this must be uploaded in multiple chunks.
     */
    private const MAX_SINGLE_CHUNK = 64 * 1024 * 1024;

    /**
     * Size of each chunk for multi-part uploads (10 MB).
     */
    private const CHUNK_SIZE = 10 * 1024 * 1024;

    /**
     * Publish a video to TikTok using the Content Posting API (FILE_UPLOAD).
     *
     * Uses FILE_UPLOAD instead of PULL_FROM_URL to avoid the domain-verification
     * requirement. The server reads the video from local disk and PUTs it
     * directly to TikTok's upload endpoint.
     *
     * Flow:
     *  1. POST /v2/post/publish/video/init/  → get publish_id + upload_url
     *  2. PUT  the video binary to upload_url
     *  3. Poll /v2/post/publish/status/fetch/ until PUBLISH_COMPLETE or failure
     */
    /**
     * Route to the correct posting mode based on the post's media:
     *  - image slides → PHOTO mode carousel (Content Posting API)
     *  - single video → FILE_UPLOAD (unchanged)
     *
     * Capability validation (no mixing, max 1 video, ≤35 images) has already run
     * in SchedulePostJob before we get here.
     */
    public function publish(PostTarget $target): array
    {
        $media = $target->post->media()->orderBy('position')->get();

        $hasImages = $media->contains(fn ($m) => $m->type === 'image');

        if ($hasImages) {
            return $this->publishPhotos($target, $media);
        }

        return $this->publishVideo($target);
    }

    /**
     * Publish an ordered set of images as a TikTok photo carousel.
     *
     * TikTok's PHOTO mode only supports PULL_FROM_URL, so we hand TikTok a
     * time-limited signed URL for each slide (served by MediaProxyController on
     * our own API domain) and let it pull them. The host must be a domain you
     * have verified for URL ownership in the TikTok Developer Portal — which is
     * why we route through our own domain rather than R2's S3 endpoint.
     */
    private function publishPhotos(PostTarget $target, $media): array
    {
        $post = $target->post;
        $account = $target->socialAccount;
        $accessToken = $account->access_token;
        $options = $target->platform_options ?? [];

        $caption = $this->resolveCaption($post);

        $imageUrls = $media
            ->where('type', 'image')
            ->sortBy('position')
            ->map(fn ($m) => $this->resolvePullUrl($m))
            ->filter()
            ->values()
            ->all();

        if (empty($imageUrls)) {
            throw new \RuntimeException('No image URLs could be resolved for the TikTok carousel.');
        }

        $initPayload = [
            'post_info' => [
                'title' => $post->title ?? '',
                'description' => $caption ?? '',
                'privacy_level' => $options['privacy_level'] ?? 'SELF_ONLY',
                'disable_comment' => (bool) ($options['disable_comment'] ?? false),
                'auto_add_music' => (bool) ($options['auto_add_music'] ?? true),
            ],
            'source_info' => [
                'source' => 'PULL_FROM_URL',
                'photo_cover_index' => (int) ($options['photo_cover_index'] ?? 0),
                'photo_images' => $imageUrls,
            ],
            'post_mode' => 'DIRECT_POST',
            'media_type' => 'PHOTO',
        ];

        $response = Http::withToken($accessToken)
            ->withHeaders(['Content-Type' => 'application/json; charset=UTF-8'])
            ->post('https://open.tiktokapis.com/v2/post/publish/content/init/', $initPayload);

        if ($response->failed() || $response->json('error.code') !== 'ok') {
            $errorCode = $response->json('error.code');
            $errorMessage = $response->json('error.message') ?? $response->body();

            if ($errorCode === 'unaudited_client_can_only_post_to_private_accounts') {
                $errorMessage = "Your TikTok Developer App is in 'Development/Unaudited' status and can only post to private accounts. Please set your TikTok account to Private, use a sandbox account, or submit your App for verification.";
            }

            $this->logError('TikTok photo publish init failed', [
                'target_id' => $target->id,
                'error' => $errorMessage,
                'raw_code' => $errorCode,
                'status' => $response->status(),
                'body' => $response->json() ?? $response->body(),
                'image_count' => count($imageUrls),
            ]);
            throw new \RuntimeException("TikTok photo publish init failed: {$errorMessage}");
        }

        $publishId = $response->json('data.publish_id');

        if (! $publishId) {
            throw new \RuntimeException(
                'TikTok did not return a publish_id for the photo post. Response: '.$response->body()
            );
        }

        Log::info('TikTok photo carousel initiated (PULL_FROM_URL)', [
            'target_id' => $target->id,
            'publish_id' => $publishId,
            'image_count' => count($imageUrls),
        ]);

        // TikTok pulls the images asynchronously; reuse the shared status poller.
        $pollResult = $this->pollStatus($accessToken, $publishId, $target->id);

        return [
            'id' => $publishId,
            'status' => $pollResult['status'],
            'real_id' => $pollResult['real_id'],
        ];
    }

    /**
     * Build the URL TikTok will pull a carousel slide from.
     *
     * Returns a short-lived signed link on our own API domain (see the
     * `media.raw` route / MediaProxyController). We deliberately do NOT use the
     * media's presigned R2 URL: that lives on `*.r2.cloudflarestorage.com`, a
     * domain we cannot verify for URL ownership, which TikTok rejects.
     *
     * @throws \RuntimeException if the app is configured with a host TikTok can
     *                           never reach (e.g. localhost), so the failure is
     *                           actionable instead of a cryptic TikTok error.
     */
    private function resolvePullUrl(PostMedia $media): ?string
    {
        if (! $media->path) {
            return null;
        }

        $url = URL::temporarySignedRoute('media.raw', now()->addHours(6), ['media' => $media->id]);

        $host = parse_url($url, PHP_URL_HOST);

        if (in_array($host, ['localhost', '127.0.0.1', '::1', '0.0.0.0'], true)) {
            throw new \RuntimeException(
                "TikTok cannot pull carousel images from \"{$host}\". Set APP_URL to a public domain ".
                'that is verified for URL ownership in your TikTok Developer Portal.'
            );
        }

        return $url;
    }

    /**
     * Publish a single video to TikTok via FILE_UPLOAD (the original flow).
     */
    private function publishVideo(PostTarget $target): array
    {
        $post = $target->post;
        $account = $target->socialAccount;
        $accessToken = $account->access_token;

        // ── Pre-flight: Resolve the local video file ─────────────────────────
        $localPath = $this->resolveVideoPath($post);
        $fileSize = filesize($localPath);

        Log::info('TikTok publish: resolved video file', [
            'target_id' => $target->id,
            'path' => $localPath,
            'size_mb' => round($fileSize / 1024 / 1024, 2),
        ]);

        // ── Step 1: Initiate the publish (FILE_UPLOAD) ───────────────────────
        $chunkSize = min($fileSize, self::MAX_SINGLE_CHUNK);
        $totalChunks = (int) ceil($fileSize / $chunkSize);

        $caption = $this->resolveCaption($post);

        $options = $target->platform_options ?? [];

        $postInfo = [
            'title' => $caption ?? $post->title ?? '',
            'privacy_level' => $options['privacy_level'] ?? 'SELF_ONLY',
            'disable_duet' => (bool) ($options['disable_duet'] ?? false),
            'disable_comment' => (bool) ($options['disable_comment'] ?? false),
            'disable_stitch' => (bool) ($options['disable_stitch'] ?? false),
        ];

        if (isset($options['video_cover_timestamp_ms'])) {
            $postInfo['video_cover_timestamp_ms'] = (int) $options['video_cover_timestamp_ms'];
        }
        
        if (isset($options['brand_organic_toggle'])) {
            $postInfo['brand_organic_toggle'] = (bool) $options['brand_organic_toggle'];
        }
        
        if (isset($options['brand_content_toggle'])) {
            $postInfo['brand_content_toggle'] = (bool) $options['brand_content_toggle'];
        }

        $initPayload = [
            'post_info' => $postInfo,
            'source_info' => [
                'source' => 'FILE_UPLOAD',
                'video_size' => $fileSize,
                'chunk_size' => $chunkSize,
                'total_chunk_count' => $totalChunks,
            ],
        ];

        $response = Http::withToken($accessToken)
            ->withHeaders(['Content-Type' => 'application/json; charset=UTF-8'])
            ->post('https://open.tiktokapis.com/v2/post/publish/video/init/', $initPayload);

        if ($response->failed() || $response->json('error.code') !== 'ok') {
            $errorCode = $response->json('error.code');
            $errorMessage = $response->json('error.message') ?? $response->body();

            if ($errorCode === 'unaudited_client_can_only_post_to_private_accounts') {
                $errorMessage = "Your TikTok Developer App is in 'Development/Unaudited' status and can only post to private accounts. Please set your TikTok account to Private in settings, use a sandbox/testing account, or submit your App for verification on the TikTok Developer Portal.";
            }

            $this->logError('TikTok publish init failed', [
                'target_id' => $target->id,
                'error' => $errorMessage,
                'raw_code' => $errorCode,
                'status' => $response->status(),
                'body' => $response->json() ?? $response->body(),
                'payload' => $initPayload,
            ]);
            throw new \RuntimeException("TikTok publish init failed: {$errorMessage}");
        }

        $publishId = $response->json('data.publish_id');
        $uploadUrl = $response->json('data.upload_url');

        if (! $publishId || ! $uploadUrl) {
            throw new \RuntimeException(
                'TikTok did not return publish_id or upload_url. Response: '.$response->body()
            );
        }

        Log::info('TikTok publish initiated (FILE_UPLOAD)', [
            'target_id' => $target->id,
            'publish_id' => $publishId,
        ]);

        try {
            // ── Step 2: Upload the video binary ──────────────────────────────────
            $this->uploadVideoFile($uploadUrl, $localPath, $fileSize, $chunkSize, $totalChunks, $target->id);

            // ── Step 3: Poll for final status ────────────────────────────────────
            $pollResult = $this->pollStatus($accessToken, $publishId, $target->id);

            return [
                'id' => $publishId,
                'status' => $pollResult['status'],
                'real_id' => $pollResult['real_id'],
            ];
        } finally {
            // Clean up temporary file downloaded from R2
            if (str_starts_with($localPath, sys_get_temp_dir()) && file_exists($localPath)) {
                @unlink($localPath);
            }
        }
    }

    /**
     * Resolve the TikTok caption. Captions may be stored as a JSON map of
     * per-platform text; pull the TikTok entry when present.
     */
    private function resolveCaption($post): string
    {
        $caption = $post->caption;
        $decoded = json_decode((string) $caption, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $decoded['tiktok'] ?? '';
        }

        return (string) ($caption ?? $post->title ?? '');
    }

    /**
     * Resolve the absolute local path to the video file.
     * Falls back from video_path → video_url parsing.
     *
     * @throws \RuntimeException if the file cannot be found on disk
     */
    private function resolveVideoPath($post): string
    {
        // Preferred: use the relative storage path
        if ($post->video_path) {
            $absolutePath = Storage::disk('public')->path($post->video_path);
            if (file_exists($absolutePath)) {
                return $absolutePath;
            }

            if (Storage::disk('r2')->exists($post->video_path)) {
                $tempPath = tempnam(sys_get_temp_dir(), 'postar_video_');
                file_put_contents($tempPath, Storage::disk('r2')->get($post->video_path));

                return $tempPath;
            }
        }

        throw new \RuntimeException(
            "Video file not found on disk or R2 for post {$post->id}. ".
            "video_path: {$post->video_path}"
        );
    }

    /**
     * Upload the video binary to TikTok's upload URL.
     *
     * For files ≤ 64MB: single PUT with the entire file.
     * For files > 64MB: chunked PUT requests with Content-Range headers.
     */
    private function uploadVideoFile(
        string $uploadUrl,
        string $filePath,
        int $fileSize,
        int $chunkSize,
        int $totalChunks,
        string $targetId
    ): void {
        $handle = fopen($filePath, 'rb');
        if (! $handle) {
            throw new \RuntimeException("Cannot open video file: {$filePath}");
        }

        try {
            if ($totalChunks === 1) {
                // Single-chunk upload
                $data = fread($handle, $fileSize);
                $response = Http::timeout(300)->withHeaders([
                    'Content-Type' => 'video/mp4',
                    'Content-Range' => 'bytes 0-'.($fileSize - 1)."/{$fileSize}",
                ])->withBody($data, 'video/mp4')
                    ->put($uploadUrl);

                if ($response->failed()) {
                    $this->logError('TikTok video upload failed (single chunk)', [
                        'target_id' => $targetId,
                        'status' => $response->status(),
                        'body' => $response->body(),
                    ]);
                    throw new \RuntimeException(
                        "TikTok video upload failed with HTTP {$response->status()}"
                    );
                }

                Log::info('TikTok video uploaded (single chunk)', [
                    'target_id' => $targetId,
                    'size_mb' => round($fileSize / 1024 / 1024, 2),
                ]);
            } else {
                // Multi-chunk upload
                for ($chunk = 0; $chunk < $totalChunks; $chunk++) {
                    $offset = $chunk * $chunkSize;
                    $length = min($chunkSize, $fileSize - $offset);
                    $endByte = $offset + $length - 1;

                    fseek($handle, $offset);
                    $data = fread($handle, $length);

                    $response = Http::timeout(300)->withHeaders([
                        'Content-Type' => 'video/mp4',
                        'Content-Range' => "bytes {$offset}-{$endByte}/{$fileSize}",
                    ])->withBody($data, 'video/mp4')
                        ->put($uploadUrl);

                    if ($response->failed()) {
                        $this->logError('TikTok video chunk upload failed', [
                            'target_id' => $targetId,
                            'chunk' => $chunk + 1,
                            'of' => $totalChunks,
                            'status' => $response->status(),
                            'body' => $response->body(),
                        ]);
                        throw new \RuntimeException(
                            'TikTok chunk upload failed (chunk '.($chunk + 1).
                            "/{$totalChunks}) with HTTP {$response->status()}"
                        );
                    }

                    Log::info('TikTok chunk uploaded', [
                        'target_id' => $targetId,
                        'chunk' => $chunk + 1,
                        'of' => $totalChunks,
                    ]);
                }
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * Poll TikTok's status endpoint until the video is PUBLISH_COMPLETE or failed.
     * Max ~60 seconds of polling (6 attempts × 10s sleep).
     * Returns 'published' or 'processing' (if we hit the attempt limit).
     */
    private function pollStatus(
        string $accessToken,
        string $publishId,
        string $targetId,
        int $maxAttempts = 6
    ): array {
        // Terminal failure statuses defined by TikTok's API
        $failureStatuses = [
            'FAILED',
            'PUBLISH_FAILED',
            'VIDEO_PULL_FAILED',
            'SPAM_RISK_TOO_MANY_POSTS',
            'SPAM_RISK_USER_BANNED_FROM_POSTING',
            'BLOCKED_BY_CREATOR',
            'AUDITED_FAILED_MUSIC',
            'AUDITED_FAILED_GENERAL',
        ];

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            if ($attempt > 1) {
                sleep(10);  // Longer sleep: upload processing takes time
            }

            $response = Http::withToken($accessToken)
                ->post('https://open.tiktokapis.com/v2/post/publish/status/fetch/', [
                    'publish_id' => $publishId,
                ]);

            if ($response->failed()) {
                $this->logError('TikTok status poll request failed', [
                    'target_id' => $targetId,
                    'publish_id' => $publishId,
                    'attempt' => $attempt,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                continue;
            }

            $status = $response->json('data.status');

            Log::info('TikTok publish status polled', [
                'target_id' => $targetId,
                'publish_id' => $publishId,
                'status' => $status,
                'attempt' => $attempt,
            ]);

            if ($status === 'PUBLISH_COMPLETE') {
                $postIds = $response->json('data.publicaly_available_post_id', []);
                $realPostId = ! empty($postIds) ? $postIds[0] : null;

                return [
                    'status' => 'published',
                    'real_id' => $realPostId,
                ];
            }

            if (in_array($status, $failureStatuses)) {
                $reason = $response->json('data.fail_reason') ?? $status;
                
                $this->logError('TikTok publish failed during status polling', [
                    'target_id' => $targetId,
                    'publish_id' => $publishId,
                    'status' => $status,
                    'fail_reason' => $reason,
                    'response_body' => $response->json(),
                ]);

                throw new \RuntimeException("TikTok publish failed: {$reason}");
            }

            // PROCESSING_DOWNLOAD / PROCESSING_UPLOAD — still in progress
        }

        // Exhausted all attempts — video is still being processed by TikTok
        Log::warning('TikTok publish still processing after max poll attempts', [
            'target_id' => $targetId,
            'publish_id' => $publishId,
        ]);

        return [
            'status' => 'processing',
            'real_id' => null,
        ];
    }

    /**
     * Log detailed error information to standard Log and dedicated tiktok.log file.
     */
    private function logError(string $message, array $context = []): void
    {
        Log::error($message, $context);

        try {
            $logPath = storage_path('logs/tiktok.log');
            $logData = json_encode([
                'timestamp' => now()->toIso8601String(),
                'message' => $message,
                'context' => $context,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

            file_put_contents($logPath, $logData . PHP_EOL . str_repeat('=', 80) . PHP_EOL, FILE_APPEND);
        } catch (\Exception $e) {
            // Silently ignore log write failures to avoid failing the application flow
        }
    }
}
