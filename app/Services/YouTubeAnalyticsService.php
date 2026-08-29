<?php

namespace App\Services;

use App\Models\PostAnalytics;
use App\Models\PostTarget;
use Illuminate\Support\Collection;
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
     * Fetch analytics for a batch of YouTube post targets (max 50)
     *
     * @param  Collection  $targets  Collection of PostTarget models
     * @param  string  $accessToken  Decrypted access token for the user
     */
    public function fetchAnalyticsBatch(Collection $targets, string $accessToken): void
    {
        if ($targets->isEmpty()) {
            return;
        }

        $videoIds = $targets->pluck('platform_post_id')->filter()->toArray();

        if (empty($videoIds)) {
            return;
        }

        try {
            $response = Http::withToken($accessToken)
                ->get('https://www.googleapis.com/youtube/v3/videos', [
                    'part' => 'statistics',
                    'id' => implode(',', $videoIds),
                ]);

            if ($response->failed()) {
                $errorMessage = $response->body();
                Log::error('YouTube analytics batch query failed', [
                    'message' => $errorMessage,
                ]);

                $this->markBatchWithError($targets, "YouTube API error: {$errorMessage}");

                return;
            }

            $items = $response->json('items', []);
            $itemsMap = [];
            foreach ($items as $item) {
                if (isset($item['id'])) {
                    $itemsMap[$item['id']] = $item['statistics'] ?? [];
                }
            }

            foreach ($targets as $target) {
                $platformPostId = $target->platform_post_id;

                if (isset($itemsMap[$platformPostId])) {
                    $statistics = $itemsMap[$platformPostId];

                    PostAnalytics::updateOrCreate(
                        [
                            'post_target_id' => $target->id,
                            'platform' => 'youtube',
                        ],
                        [
                            'views' => $statistics['viewCount'] ?? 0,
                            'likes' => $statistics['likeCount'] ?? 0,
                            'comments' => $statistics['commentCount'] ?? 0,
                            'shares' => 0,
                            'fetched_at' => now(),
                        ]
                    );

                    $target->update([
                        'analytics_fetched_at' => now(),
                        'analytics_error' => null,
                    ]);
                } else {
                    $target->update([
                        'analytics_error' => 'Video not found on YouTube',
                    ]);
                }
            }

        } catch (\Throwable $e) {
            Log::error('YouTube analytics batch query exception', [
                'error' => $e->getMessage(),
            ]);
            $this->markBatchWithError($targets, 'Exception: '.$e->getMessage());
        }
    }

    /**
     * Mark all targets in this batch with a specific error
     */
    private function markBatchWithError(Collection $targets, string $error): void
    {
        foreach ($targets as $target) {
            $target->update([
                'analytics_error' => substr($error, 0, 500),
            ]);
        }
    }
}
