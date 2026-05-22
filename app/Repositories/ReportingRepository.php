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
            if ($last == 0) {
                return $current > 0 ? '+100%' : '0%';
            }
            $pct = round((($current - $last) / $last) * 100, 1);
            return ($pct >= 0 ? '+' : '') . $pct . '%';
        };

        $stats = [];
        $platforms = ['youtube', 'tiktok'];

        foreach ($platforms as $platform) {
            $current = DB::table('post_targets')
                ->join('posts', 'post_targets.post_id', '=', 'posts.id')
                ->join('social_accounts', 'post_targets.social_account_id', '=', 'social_accounts.id')
                ->where('posts.user_id', $userId)
                ->where('social_accounts.platform', $platform)
                ->whereBetween('post_targets.created_at', [$startCurrent, $endCurrent])
                ->count();

            $last = DB::table('post_targets')
                ->join('posts', 'post_targets.post_id', '=', 'posts.id')
                ->join('social_accounts', 'post_targets.social_account_id', '=', 'social_accounts.id')
                ->where('posts.user_id', $userId)
                ->where('social_accounts.platform', $platform)
                ->whereBetween('post_targets.created_at', [$startLast, $endLast])
                ->count();
            
            $total = DB::table('post_targets')
                ->join('posts', 'post_targets.post_id', '=', 'posts.id')
                ->join('social_accounts', 'post_targets.social_account_id', '=', 'social_accounts.id')
                ->where('posts.user_id', $userId)
                ->where('social_accounts.platform', $platform)
                ->where('post_targets.status', 'pending')
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
        $stats['total_views_growth'] = '0%'; // Mock
        $stats['total_engagement_growth'] = '0%'; // Mock

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
}
