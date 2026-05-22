<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Post;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class CleanupExpiredVideos extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'videos:cleanup-expired';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Deletes videos past their plan retention period to free up R2 storage';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting expired video cleanup...');
        
        // Chunk through videos to prevent memory exhaustion
        Post::whereIn('status', ['published', 'failed'])
             ->with('user.plan')
             ->chunkById(100, function ($videos) {
                 foreach ($videos as $video) {
                     $retentionDays = $video->user->plan->retention_days ?? 30;
                     
                     if (now()->diffInDays($video->created_at) > $retentionDays) {
                         $this->deleteVideo($video);
                     }
                 }
             });

        $this->info('Cleanup complete.');
    }

    private function deleteVideo(Post $video)
    {
        try {
            if ($video->video_path && Storage::disk('r2')->exists($video->video_path)) {
                Storage::disk('r2')->delete($video->video_path);
            }

            if ($video->thumbnail_path && Storage::disk('r2')->exists($video->thumbnail_path)) {
                Storage::disk('r2')->delete($video->thumbnail_path);
            }

            // Update status and soft delete
            $video->update(['status' => 'deleted']);
            $video->delete();

            Log::info("Deleted expired video: {$video->id} for user: {$video->user_id}");
        } catch (\Exception $e) {
            Log::error("Failed to delete expired video {$video->id}: {$e->getMessage()}");
        }
    }
}
