<?php

namespace App\Repositories;

use Illuminate\Support\Facades\DB;

class ReportingRepository
{
    public function getUserStats(string $userId): ?object
    {
        $posts = DB::table('posts')
            ->where('user_id', $userId)
            ->whereNull('deleted_at');

        $targets = DB::table('post_targets')
            ->join('posts', 'post_targets.post_id', '=', 'posts.id')
            ->where('posts.user_id', $userId)
            ->whereNull('posts.deleted_at');

        $analytics = DB::table('post_analytics')
            ->join('post_targets', 'post_analytics.post_target_id', '=', 'post_targets.id')
            ->join('posts', 'post_targets.post_id', '=', 'posts.id')
            ->where('posts.user_id', $userId)
            ->whereNull('posts.deleted_at');

        return (object) [
            'total_views' => $analytics->sum('views') ?? 0,
            'total_engagement' => ($analytics->sum('likes') + $analytics->sum('comments') + $analytics->sum('shares')) ?? 0,
            'total_videos' => $posts->count(),
            'scheduled_videos' => (clone $posts)->where('status', 'scheduled')->count(),
            'published_videos' => (clone $posts)->where('status', 'published')->count(),
            'failed_videos' => (clone $posts)->where('status', 'failed')->count(),
            'upcoming_posts' => (clone $targets)->whereIn('post_targets.status', ['pending', 'uploading'])->count(),
            'total_likes' => $analytics->sum('likes') ?? 0,
            'total_comments' => $analytics->sum('comments') ?? 0,
            'total_shares' => $analytics->sum('shares') ?? 0,
        ];
    }

    public function getCalendar(string $userId): \Illuminate\Support\Collection
    {
        return DB::table('post_targets')
            ->join('posts', 'post_targets.post_id', '=', 'posts.id')
            ->join('social_accounts', 'post_targets.social_account_id', '=', 'social_accounts.id')
            ->where('posts.user_id', $userId)
            ->whereNull('posts.deleted_at')
            ->whereNotNull('posts.scheduled_at')
            ->select(
                'posts.id as video_id',
                'posts.title as caption',
                'posts.scheduled_at as publish_at',
                'post_targets.status',
                'posts.video_url',
                'social_accounts.platform'
            )
            ->get()
            ->map(function ($item) {
                $item->thumbnail_path = null;

                return $item;
            });
    }

    public function getStorageUsage(string $userId): ?object
    {
        $usedBytes = DB::table('posts')
            ->where('user_id', $userId)
            ->whereNull('deleted_at')
            ->sum('file_size_mb') * 1024 * 1024;

        $totalBytes = 1073741824; // 1GB default limit or dynamic from Plan

        return (object) [
            'used_bytes' => $usedBytes,
            'total_bytes' => $totalBytes,
            'usage_percentage' => $totalBytes > 0 ? round(($usedBytes / $totalBytes) * 100, 2) : 0,
        ];
    }

    public function getPlatformStats(string $userId): array
    {
        $now = \Carbon\Carbon::now();
        $startCurrent = $now->copy()->startOfMonth();
        $endCurrent = $now->copy()->endOfMonth();
        $startLast = $now->copy()->subMonth()->startOfMonth();
        $endLast = $now->copy()->subMonth()->endOfMonth();

        $calculateGrowth = function ($current, $last) {
            $diff = $current - $last;
            if ($diff > 0) {
                return '+'.$diff;
            } elseif ($diff < 0) {
                return (string) $diff;
            }

            return '0';
        };

        $stats = [];
        $platforms = ['youtube', 'tiktok'];

        foreach ($platforms as $platform) {
            $current = DB::table('post_targets')
                ->join('posts', 'post_targets.post_id', '=', 'posts.id')
                ->join('social_accounts', 'post_targets.social_account_id', '=', 'social_accounts.id')
                ->where('posts.user_id', $userId)
                ->where('social_accounts.platform', $platform)
                ->where('post_targets.status', 'published')
                ->whereBetween('post_targets.published_at', [$startCurrent, $endCurrent])
                ->count();

            $last = DB::table('post_targets')
                ->join('posts', 'post_targets.post_id', '=', 'posts.id')
                ->join('social_accounts', 'post_targets.social_account_id', '=', 'social_accounts.id')
                ->where('posts.user_id', $userId)
                ->where('social_accounts.platform', $platform)
                ->where('post_targets.status', 'published')
                ->whereBetween('post_targets.published_at', [$startLast, $endLast])
                ->count();

            $total = DB::table('post_targets')
                ->join('posts', 'post_targets.post_id', '=', 'posts.id')
                ->join('social_accounts', 'post_targets.social_account_id', '=', 'social_accounts.id')
                ->where('posts.user_id', $userId)
                ->where('social_accounts.platform', $platform)
                ->where('post_targets.status', 'published')
                ->count();

            $stats["{$platform}_count"] = $total;
            $stats["{$platform}_growth"] = $calculateGrowth($current, $last);
        }

        // Total Videos Growth
        $currentVideos = DB::table('posts')
            ->where('user_id', $userId)
            ->whereBetween('created_at', [$startCurrent, $endCurrent])
            ->count();
        $lastVideos = DB::table('posts')
            ->where('user_id', $userId)
            ->whereBetween('created_at', [$startLast, $endLast])
            ->count();

        $stats['total_videos_growth'] = $calculateGrowth($currentVideos, $lastVideos);

        // Total Views Growth (posts published this month vs last month)
        $currentViews = DB::table('post_analytics')
            ->join('post_targets', 'post_analytics.post_target_id', '=', 'post_targets.id')
            ->join('posts', 'post_targets.post_id', '=', 'posts.id')
            ->where('posts.user_id', $userId)
            ->whereNull('posts.deleted_at')
            ->whereBetween('post_targets.published_at', [$startCurrent, $endCurrent])
            ->sum('views') ?? 0;

        $lastViews = DB::table('post_analytics')
            ->join('post_targets', 'post_analytics.post_target_id', '=', 'post_targets.id')
            ->join('posts', 'post_targets.post_id', '=', 'posts.id')
            ->where('posts.user_id', $userId)
            ->whereNull('posts.deleted_at')
            ->whereBetween('post_targets.published_at', [$startLast, $endLast])
            ->sum('views') ?? 0;

        $stats['total_views_growth'] = $calculateGrowth($currentViews, $lastViews);

        // Total Engagement Growth (posts published this month vs last month)
        $currentEngagementData = DB::table('post_analytics')
            ->join('post_targets', 'post_analytics.post_target_id', '=', 'post_targets.id')
            ->join('posts', 'post_targets.post_id', '=', 'posts.id')
            ->where('posts.user_id', $userId)
            ->whereNull('posts.deleted_at')
            ->whereBetween('post_targets.published_at', [$startCurrent, $endCurrent])
            ->selectRaw('SUM(likes) as total_likes, SUM(comments) as total_comments, SUM(shares) as total_shares')
            ->first();

        $currentEngagement = ($currentEngagementData->total_likes ?? 0) + ($currentEngagementData->total_comments ?? 0) + ($currentEngagementData->total_shares ?? 0);

        $lastEngagementData = DB::table('post_analytics')
            ->join('post_targets', 'post_analytics.post_target_id', '=', 'post_targets.id')
            ->join('posts', 'post_targets.post_id', '=', 'posts.id')
            ->where('posts.user_id', $userId)
            ->whereNull('posts.deleted_at')
            ->whereBetween('post_targets.published_at', [$startLast, $endLast])
            ->selectRaw('SUM(likes) as total_likes, SUM(comments) as total_comments, SUM(shares) as total_shares')
            ->first();

        $lastEngagement = ($lastEngagementData->total_likes ?? 0) + ($lastEngagementData->total_comments ?? 0) + ($lastEngagementData->total_shares ?? 0);

        $stats['total_engagement_growth'] = $calculateGrowth($currentEngagement, $lastEngagement);

        // Posted This Month
        $postedThisMonth = DB::table('post_targets')
            ->join('posts', 'post_targets.post_id', '=', 'posts.id')
            ->where('posts.user_id', $userId)
            ->where('post_targets.status', 'published')
            ->whereBetween('post_targets.published_at', [$startCurrent, $endCurrent])
            ->count();

        $stats['posted_this_month'] = $postedThisMonth;

        return $stats;
    }

    public function getTopPerformingVideos(string $userId): array
    {
        $posts = \App\Models\Post::with(['media', 'targets.socialAccount', 'targets.analytics'])
            ->where('user_id', $userId)
            ->where('status', 'published')
            ->whereNull('deleted_at')
            ->get();

        return $posts->map(function ($post) {
            $totalViews = $post->targets->sum(function ($target) {
                return $target->analytics ? $target->analytics->views : 0;
            });
            $post->total_views = (int) $totalViews;

            return $post;
        })
            ->sortByDesc('total_views')
            ->take(5)
            ->values()
            ->toArray();
    }
}
