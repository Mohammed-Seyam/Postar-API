<?php

namespace App\Services\Platforms;

use App\Models\PostAnalytics;
use App\Models\PostTarget;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TikTokAnalyticsService
{
    /**
     * Fetch analytics for a batch of TikTok post targets (max 20)
     *
     * @param  Collection  $targets  Collection of PostTarget models
     * @param  string  $accessToken  Valid access token for the user
     */
    public function fetchAnalyticsBatch(Collection $targets, string $accessToken): void
    {
        if ($targets->isEmpty()) {
            return;
        }

        $validTargets = new Collection;
        $invalidTargets = new Collection;

        foreach ($targets as $target) {
            $id = $target->platform_post_id;
            if (! empty($id) && ctype_digit(strval($id))) {
                $validTargets->push($target);
            } else {
                $invalidTargets->push($target);
            }
        }

        foreach ($invalidTargets as $target) {
            $target->update([
                'analytics_error' => 'Invalid TikTok Video ID format (must be numeric)',
            ]);
        }

        if ($validTargets->isEmpty()) {
            return;
        }

        $videoIds = $validTargets->pluck('platform_post_id')->toArray();

        try {
            $response = Http::withToken($accessToken)
                ->withHeaders(['Content-Type' => 'application/json'])
                ->post('https://open.tiktokapis.com/v2/video/query/?fields=id,view_count,like_count,comment_count,share_count', [
                    'filters' => [
                        'video_ids' => array_values($videoIds),
                    ],
                ]);

            if ($response->failed() || $response->json('error.code') !== 'ok') {
                $errorCode = $response->json('error.code');
                $errorMessage = $response->json('error.message') ?? $response->body();

                Log::error('TikTok analytics batch query failed', [
                    'code' => $errorCode,
                    'message' => $errorMessage,
                ]);

                $this->markBatchWithError($validTargets, "TikTok API error: {$errorMessage}");

                return;
            }

            $videosData = $response->json('data.videos', []);
            $videosMap = [];
            foreach ($videosData as $v) {
                if (isset($v['id'])) {
                    $videosMap[$v['id']] = $v;
                }
            }

            foreach ($validTargets as $target) {
                $platformPostId = $target->platform_post_id;

                if (isset($videosMap[$platformPostId])) {
                    $v = $videosMap[$platformPostId];

                    PostAnalytics::updateOrCreate(
                        [
                            'post_target_id' => $target->id,
                            'platform' => 'tiktok',
                        ],
                        [
                            'views' => $v['view_count'] ?? 0,
                            'likes' => $v['like_count'] ?? 0,
                            'comments' => $v['comment_count'] ?? 0,
                            'shares' => $v['share_count'] ?? 0,
                            'fetched_at' => now(),
                        ]
                    );

                    $target->update([
                        'analytics_fetched_at' => now(),
                        'analytics_error' => null,
                    ]);
                } else {
                    // Video wasn't returned by TikTok API (e.g. deleted or private)
                    $target->update([
                        'analytics_error' => 'Video not found or returned by TikTok API',
                    ]);
                }
            }

        } catch (\Throwable $e) {
            Log::error('TikTok analytics batch query exception', [
                'error' => $e->getMessage(),
            ]);
            $this->markBatchWithError($validTargets, 'Exception: '.$e->getMessage());
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
