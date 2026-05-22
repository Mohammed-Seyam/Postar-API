<?php

namespace App\Services;

use App\Models\PostTarget;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class YouTubePublishService
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

        $accessToken = $this->youtubeService->getValidToken($account);

        $videoPath = $post->video_path;
        $disk = Storage::disk('public');
        
        if (!$disk->exists($videoPath)) {
            throw new \Exception('Video file not found.');
        }

        $fileSize = $disk->size($videoPath);
        $mimeType = $disk->mimeType($videoPath);

        $description = $post->caption;
        if (strpos($description, '#Shorts') === false && strpos($post->title, '#Shorts') === false) {
            $description .= "\n\n#Shorts";
        }

        $metadata = [
            'snippet' => [
                'title' => $post->title,
                'description' => $description,
                'tags' => ['#Shorts'],
            ],
            'status' => [
                'privacyStatus' => 'public',
            ]
        ];

        // Step 1: POST to /upload/youtube/v3/videos?uploadType=resumable
        $initResponse = Http::withToken($accessToken)
            ->withHeaders([
                'X-Upload-Content-Length' => $fileSize,
                'X-Upload-Content-Type' => $mimeType,
            ])
            ->post('https://www.googleapis.com/upload/youtube/v3/videos?uploadType=resumable&part=snippet,status', $metadata);

        if ($initResponse->failed()) {
            throw new \Exception('Failed to initialize resumable upload: ' . $initResponse->body());
        }

        $uploadUri = $initResponse->header('Location');
        if (!$uploadUri) {
            throw new \Exception('No upload URI returned from YouTube.');
        }

        // Step 2: PUT chunks to the upload URI
        $stream = $disk->readStream($videoPath);

        $uploadResponse = Http::withHeaders([
            'Content-Length' => $fileSize,
            'Content-Type' => $mimeType,
        ])->timeout(300)->send('PUT', $uploadUri, [
            'body' => $stream
        ]);

        if ($uploadResponse->failed()) {
            throw new \Exception('Failed to upload video data: ' . $uploadResponse->body());
        }

        // Step 3: Parse response
        $uploadData = $uploadResponse->json();
        $platformPostId = $uploadData['id'] ?? null;

        if (!$platformPostId) {
            throw new \Exception('Upload successful but no video ID returned.');
        }

        return [
            'id' => $platformPostId,
            'status' => 'published',
        ];
    }
}
