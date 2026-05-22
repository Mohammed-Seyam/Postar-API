<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessVideoJob implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(public \App\Models\Post $video)
    {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            \Illuminate\Support\Facades\Log::info("Starting processing for video {$this->video->id}. Path: {$this->video->video_path}");

            // Use absolute path for local file analysis
            $absolutePath = \Illuminate\Support\Facades\Storage::disk('public')->path($this->video->video_path);
            \Illuminate\Support\Facades\Log::info("Resolved absolute path: {$absolutePath}");

            if (!file_exists($absolutePath)) {
                 \Illuminate\Support\Facades\Log::error("File does not exist at absolute path: {$absolutePath}");
                 throw new \Exception("File not found at path: {$absolutePath}");
            }

            $getID3 = new \getID3;
            \Illuminate\Support\Facades\Log::info("Analyzing file with getID3...");
            $fileInfo = $getID3->analyze($absolutePath);
            \Illuminate\Support\Facades\Log::info("Analysis complete.");

            if (isset($fileInfo['error'])) {
                 \Illuminate\Support\Facades\Log::error("getID3 Error: " . json_encode($fileInfo['error']));
            }

            $duration = isset($fileInfo['playtime_seconds']) ? round($fileInfo['playtime_seconds']) : 0;

            $this->video->update([
                'duration' => $duration,
            ]);
            
            \Illuminate\Support\Facades\Log::info("Video processed: {$this->video->id} with duration {$duration}s");
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error("Failed to process video {$this->video->id}: " . $e->getMessage());
            \Illuminate\Support\Facades\Log::error("Stack trace: " . $e->getTraceAsString());
            $this->video->update(['status' => 'failed']);
        }
    }
}
