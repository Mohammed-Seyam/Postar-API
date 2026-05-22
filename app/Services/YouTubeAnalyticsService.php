<?php

namespace App\Services;

use App\Models\PostTarget;
use App\Models\PostAnalytics;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class YouTubeAnalyticsService
{
    private YouTubeService $youtubeService;

    public function __construct(YouTubeService $youtubeService)
    {
        $this->youtubeService = $youtubeService;
    }

    /**
     * Fetches analytics for a specific YouTube post
     */
    public function fetchAnalytics(PostTarget $postTarget): void
    {
        try {
            if (!$postTarget->platform_post_id || $postTarget->status !== 'published') {
                return;
            }

            $account = $postTarget->socialAccount;

            // Get valid token
            $accessToken = $this->youtubeService->getValidToken($account);

            // Call videos.list with part=statistics
            $response = Http::withToken($accessToken)
                ->get('https://www.googleapis.com/youtube/v3/videos', [
                    'part' => 'statistics',
                    'id' => $postTarget->platform_post_id,
                ]);

            if ($response->failed()) {
                throw new \Exception('Failed to fetch YouTube analytics: ' . $response->body());
            }

            $items = $response->json('items');
            if (empty($items)) {
                return;
            }

            $statistics = $items[0]['statistics'] ?? [];

            // Upsert into post_analytics
            PostAnalytics::updateOrCreate(
                [
                    'post_target_id' => $postTarget->id,
                    'platform' => 'youtube',
                ],
                [
                    'views' => $statistics['viewCount'] ?? 0,
                    'likes' => $statistics['likeCount'] ?? 0,
                    'comments' => $statistics['commentCount'] ?? 0,
                    'fetched_at' => now(),
                ]
            );

        } catch (\Throwable $e) {
            Log::error('YouTube analytics fetch failed', [
                'post_target_id' => $postTarget->id,
                'error' => $e->getMessage()
            ]);
        }
    }
}
