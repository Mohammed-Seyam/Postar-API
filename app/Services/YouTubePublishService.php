<?php

namespace App\Services;

use App\Models\PostTarget;
use App\Services\Platforms\PublishServiceContract;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class YouTubePublishService implements PublishServiceContract
{
    private YouTubeService $youtubeService;

    public function __construct(YouTubeService $youtubeService)
    {
        $this->youtubeService = $youtubeService;
    }

    /**
     * Publishes a video to YouTube using resumable upload protocol
     */
    public function publish(PostTarget $postTarget): array
    {
        $account = $postTarget->socialAccount;
        $post = $postTarget->post;

        // YouTube has no carousel concept: exactly one video, no images.
        $media = $post->media()->orderBy('position')->get();
        if ($media->isNotEmpty()) {
            $videoCount = $media->where('type', 'video')->count();
            $imageCount = $media->where('type', 'image')->count();

            if ($imageCount > 0 || $videoCount !== 1) {
                throw new \Exception('YouTube supports exactly one video per post and does not support image carousels.');
            }
        }

        $accessToken = $this->youtubeService->getValidToken($account);

        $videoPath = $post->video_path ?: optional($media->firstWhere('type', 'video'))->path;
        $disk = null;

        if (Storage::disk('public')->exists($videoPath)) {
            $disk = Storage::disk('public');
        } elseif (Storage::disk('r2')->exists($videoPath)) {
            $disk = Storage::disk('r2');
        }

        if (! $disk) {
            throw new \Exception('Video file not found.');
        }

        $fileSize = $disk->size($videoPath);
        $mimeType = $disk->mimeType($videoPath);

        $description = $post->caption;

        // Decode JSON if description is a platform-specific JSON payload
        $decoded = json_decode($description, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            $description = $decoded['youtube'] ?? '';
        }

        // Check if the user/system intends this to be a Short (case-insensitive check for #shorts or #short in description)
        $isShort = false;
        if (preg_match('/#(shorts|short)/i', $description)) {
            $isShort = true;
        }

        $tags = [];
        if ($isShort) {
            $tags = ['#shorts'];
        }

        $options = $postTarget->platform_options ?? [];
        $privacy = $options['privacy'] ?? 'public';
        $categoryId = $options['category'] ?? '22'; // 22 is People & Blogs default
        $madeForKids = $options['madeForKids'] ?? false;
        
        $keywords = $options['keywords'] ?? '';
        if ($keywords) {
            $parsedKeywords = array_map('trim', explode(',', $keywords));
            $tags = array_merge($tags, $parsedKeywords);
        }

        $metadata = [
            'snippet' => [
                'title' => $post->title,
                'description' => $description,
                'tags' => array_values(array_unique($tags)),
                'categoryId' => $categoryId,
            ],
            'status' => [
                'privacyStatus' => $privacy,
                'selfDeclaredMadeForKids' => $madeForKids,
            ],
        ];

        // Step 1: POST to /upload/youtube/v3/videos?uploadType=resumable
        $initResponse = Http::withToken($accessToken)
            ->withHeaders([
                'X-Upload-Content-Length' => $fileSize,
                'X-Upload-Content-Type' => $mimeType,
            ])
            ->post('https://www.googleapis.com/upload/youtube/v3/videos?uploadType=resumable&part=snippet,status', $metadata);

        if ($initResponse->failed()) {
            $this->logError('YouTube video publish init failed', [
                'target_id' => $postTarget->id,
                'status' => $initResponse->status(),
                'body' => $initResponse->json() ?? $initResponse->body(),
                'metadata' => $metadata,
            ]);
            throw new \Exception('Failed to initialize resumable upload: '.$initResponse->body());
        }

        $uploadUri = $initResponse->header('Location');
        if (! $uploadUri) {
            $this->logError('YouTube video publish init did not return upload Location header', [
                'target_id' => $postTarget->id,
                'status' => $initResponse->status(),
                'headers' => $initResponse->headers(),
                'body' => $initResponse->json() ?? $initResponse->body(),
            ]);
            throw new \Exception('No upload URI returned from YouTube.');
        }

        // Step 2: PUT chunks to the upload URI
        $stream = $disk->readStream($videoPath);

        $uploadResponse = Http::withHeaders([
            'Content-Length' => $fileSize,
            'Content-Type' => $mimeType,
        ])->timeout(300)->send('PUT', $uploadUri, [
            'body' => $stream,
        ]);

        if ($uploadResponse->failed()) {
            $this->logError('YouTube video upload data failed', [
                'target_id' => $postTarget->id,
                'status' => $uploadResponse->status(),
                'body' => $uploadResponse->json() ?? $uploadResponse->body(),
                'upload_uri' => $uploadUri,
            ]);
            throw new \Exception('Failed to upload video data: '.$uploadResponse->body());
        }

        // Step 3: Parse response
        $uploadData = $uploadResponse->json();
        $platformPostId = $uploadData['id'] ?? null;

        if (! $platformPostId) {
            throw new \Exception('Upload successful but no video ID returned.');
        }

        // Custom Thumbnail Upload to YouTube
        if ($post->thumbnail_path) {
            $thumbDisk = null;
            if (Storage::disk('public')->exists($post->thumbnail_path)) {
                $thumbDisk = Storage::disk('public');
            } elseif (Storage::disk('r2')->exists($post->thumbnail_path)) {
                $thumbDisk = Storage::disk('r2');
            }

            if ($thumbDisk) {
                try {
                    $thumbSize = $thumbDisk->size($post->thumbnail_path);
                    $thumbMimeType = $thumbDisk->mimeType($post->thumbnail_path);
                    $thumbData = $thumbDisk->get($post->thumbnail_path);

                    $thumbResponse = Http::withToken($accessToken)
                        ->withHeaders([
                            'Content-Type' => $thumbMimeType,
                        ])
                        ->timeout(120)
                        ->withBody($thumbData, $thumbMimeType)
                        ->post('https://www.googleapis.com/upload/youtube/v3/thumbnails/set?videoId=' . $platformPostId);

                    if ($thumbResponse->failed()) {
                        \Illuminate\Support\Facades\Log::warning('YouTube custom thumbnail upload failed: ' . $thumbResponse->body());
                    }
                } catch (\Exception $e) {
                    \Illuminate\Support\Facades\Log::warning('YouTube custom thumbnail exception: ' . $e->getMessage());
                }
            }
        }

        return [
            'id' => $platformPostId,
            'status' => 'published',
        ];
    }

    /**
     * Log detailed error information to standard Log and dedicated youtube.log file.
     */
    private function logError(string $message, array $context = []): void
    {
        \Illuminate\Support\Facades\Log::error($message, $context);

        try {
            $logPath = storage_path('logs/youtube.log');
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
