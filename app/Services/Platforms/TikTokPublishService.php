<?php

namespace App\Services\Platforms;

use App\Models\PostTarget;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class TikTokPublishService
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
    public function publish(PostTarget $target): array
    {
        $post        = $target->post;
        $account     = $target->socialAccount;
        $accessToken = $account->access_token;

        // ── Pre-flight: Resolve the local video file ─────────────────────────
        $localPath = $this->resolveVideoPath($post);
        $fileSize  = filesize($localPath);

        Log::info('TikTok publish: resolved video file', [
            'target_id' => $target->id,
            'path'      => $localPath,
            'size_mb'   => round($fileSize / 1024 / 1024, 2),
        ]);

        // ── Step 1: Initiate the publish (FILE_UPLOAD) ───────────────────────
        $chunkSize  = min($fileSize, self::MAX_SINGLE_CHUNK);
        $totalChunks = (int) ceil($fileSize / $chunkSize);

        $initPayload = [
            'post_info' => [
                'title'           => $post->caption ?? $post->title ?? '',
                'privacy_level'   => 'SELF_ONLY',
                'disable_duet'    => false,
                'disable_comment' => false,
                'disable_stitch'  => false,
            ],
            'source_info' => [
                'source'       => 'FILE_UPLOAD',
                'video_size'   => $fileSize,
                'chunk_size'   => $chunkSize,
                'total_chunk_count' => $totalChunks,
            ],
        ];

        $response = Http::withToken($accessToken)
            ->withHeaders(['Content-Type' => 'application/json; charset=UTF-8'])
            ->post('https://open.tiktokapis.com/v2/post/publish/video/init/', $initPayload);

        if ($response->failed() || $response->json('error.code') !== 'ok') {
            $errorMessage = $response->json('error.message') ?? $response->body();
            Log::error('TikTok publish init failed', [
                'target_id' => $target->id,
                'error'     => $errorMessage,
                'status'    => $response->status(),
                'body'      => $response->json(),
            ]);
            throw new \RuntimeException("TikTok publish init failed: {$errorMessage}");
        }

        $publishId = $response->json('data.publish_id');
        $uploadUrl = $response->json('data.upload_url');

        if (!$publishId || !$uploadUrl) {
            throw new \RuntimeException(
                'TikTok did not return publish_id or upload_url. Response: ' . $response->body()
            );
        }

        Log::info('TikTok publish initiated (FILE_UPLOAD)', [
            'target_id'  => $target->id,
            'publish_id' => $publishId,
        ]);

        // ── Step 2: Upload the video binary ──────────────────────────────────
        $this->uploadVideoFile($uploadUrl, $localPath, $fileSize, $chunkSize, $totalChunks, $target->id);

        // ── Step 3: Poll for final status ────────────────────────────────────
        $finalStatus = $this->pollStatus($accessToken, $publishId, $target->id);

        return [
            'id'     => $publishId,
            'status' => $finalStatus,  // 'published' | 'processing'
        ];
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
        }

        throw new \RuntimeException(
            "Video file not found on disk for post {$post->id}. " .
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
        if (!$handle) {
            throw new \RuntimeException("Cannot open video file: {$filePath}");
        }

        try {
            if ($totalChunks === 1) {
                // Single-chunk upload
                $data = fread($handle, $fileSize);
                $response = Http::timeout(300)->withHeaders([
                    'Content-Type'  => 'video/mp4',
                    'Content-Range' => "bytes 0-" . ($fileSize - 1) . "/{$fileSize}",
                ])->withBody($data, 'video/mp4')
                  ->put($uploadUrl);

                if ($response->failed()) {
                    Log::error('TikTok video upload failed (single chunk)', [
                        'target_id' => $targetId,
                        'status'    => $response->status(),
                        'body'      => $response->body(),
                    ]);
                    throw new \RuntimeException(
                        "TikTok video upload failed with HTTP {$response->status()}"
                    );
                }

                Log::info('TikTok video uploaded (single chunk)', [
                    'target_id' => $targetId,
                    'size_mb'   => round($fileSize / 1024 / 1024, 2),
                ]);
            } else {
                // Multi-chunk upload
                for ($chunk = 0; $chunk < $totalChunks; $chunk++) {
                    $offset    = $chunk * $chunkSize;
                    $length    = min($chunkSize, $fileSize - $offset);
                    $endByte   = $offset + $length - 1;

                    fseek($handle, $offset);
                    $data = fread($handle, $length);

                    $response = Http::timeout(300)->withHeaders([
                        'Content-Type'  => 'video/mp4',
                        'Content-Range' => "bytes {$offset}-{$endByte}/{$fileSize}",
                    ])->withBody($data, 'video/mp4')
                      ->put($uploadUrl);

                    if ($response->failed()) {
                        Log::error('TikTok video chunk upload failed', [
                            'target_id' => $targetId,
                            'chunk'     => $chunk + 1,
                            'of'        => $totalChunks,
                            'status'    => $response->status(),
                            'body'      => $response->body(),
                        ]);
                        throw new \RuntimeException(
                            "TikTok chunk upload failed (chunk " . ($chunk + 1) .
                            "/{$totalChunks}) with HTTP {$response->status()}"
                        );
                    }

                    Log::info('TikTok chunk uploaded', [
                        'target_id' => $targetId,
                        'chunk'     => $chunk + 1,
                        'of'        => $totalChunks,
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
    ): string {
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
                Log::warning('TikTok status poll failed', [
                    'target_id'  => $targetId,
                    'publish_id' => $publishId,
                    'attempt'    => $attempt,
                    'http_status'=> $response->status(),
                ]);
                continue;
            }

            $status = $response->json('data.status');

            Log::info('TikTok publish status polled', [
                'target_id'  => $targetId,
                'publish_id' => $publishId,
                'status'     => $status,
                'attempt'    => $attempt,
            ]);

            if ($status === 'PUBLISH_COMPLETE') {
                return 'published';
            }

            if (in_array($status, $failureStatuses)) {
                $reason = $response->json('data.fail_reason') ?? $status;
                throw new \RuntimeException("TikTok publish failed: {$reason}");
            }

            // PROCESSING_DOWNLOAD / PROCESSING_UPLOAD — still in progress
        }

        // Exhausted all attempts — video is still being processed by TikTok
        Log::warning('TikTok publish still processing after max poll attempts', [
            'target_id'  => $targetId,
            'publish_id' => $publishId,
        ]);

        return 'processing';
    }
}
