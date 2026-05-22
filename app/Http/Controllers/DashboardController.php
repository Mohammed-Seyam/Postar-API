<?php

namespace App\Http\Controllers;

use App\Repositories\ReportingRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __construct(protected ReportingRepository $reportingRepository)
    {
    }

    public function stats(Request $request): JsonResponse
    {
        $stats = $this->reportingRepository->getUserStats($request->user()->id);
        
        if (!$stats) {
            $stats = (object) [
                'total_views' => 0,
                'total_likes' => 0,
                'total_comments' => 0,
                'total_shares' => 0,
                'total_videos' => 0,
                'scheduled_videos' => 0,
                'published_videos' => 0,
                'failed_videos' => 0,
                'upcoming_posts' => 0,
            ];
        }

        $platformStats = $this->reportingRepository->getPlatformStats($request->user()->id);
        
        // Merge stats (convert object to array if needed, or cast)
        $statsArray = (array) $stats;
        $mergedStats = array_merge($statsArray, $platformStats);

        return response()->json($mergedStats);
    }

    public function calendar(Request $request): JsonResponse
    {
        $calendar = $this->reportingRepository->getCalendar($request->user()->id);
        return response()->json($calendar);
    }

    public function storage(Request $request): JsonResponse
    {
        $storage = $this->reportingRepository->getStorageUsage($request->user()->id);
        
        if (!$storage) {
            $storage = [
                'used_bytes' => 0,
                'total_bytes' => 1073741824, // 1GB default?
                'usage_percentage' => 0,
            ];
        }

        return response()->json($storage);
    }
}
