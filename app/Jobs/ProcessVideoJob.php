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

            $tempFile = null;

            try {
                if (file_exists($absolutePath)) {
                    $fileToAnalyze = $absolutePath;
                } elseif (\Illuminate\Support\Facades\Storage::disk('r2')->exists($this->video->video_path)) {
                    \Illuminate\Support\Facades\Log::info('File is on R2. Downloading to a temporary file for getID3 extraction.');

                    $tempFile = tempnam(sys_get_temp_dir(), 'video_');
                    if ($tempFile === false) {
                        throw new \Exception('Failed to generate a temporary file name.');
                    }

                    $tempStream = fopen($tempFile, 'wb');
                    if ($tempStream === false) {
                        throw new \Exception("Failed to open temporary file for writing: {$tempFile}");
                    }

                    $r2Stream = \Illuminate\Support\Facades\Storage::disk('r2')->readStream($this->video->video_path);
                    if ($r2Stream === false || ! is_resource($r2Stream)) {
                        fclose($tempStream);
                        throw new \Exception("Failed to read stream from R2 for path: {$this->video->video_path}");
                    }

                    stream_copy_to_stream($r2Stream, $tempStream);
                    fclose($r2Stream);
                    fclose($tempStream);

                    $fileToAnalyze = $tempFile;
                } else {
                    \Illuminate\Support\Facades\Log::error("File does not exist at absolute path or on R2: {$absolutePath}");
                    throw new \Exception("File not found at path: {$absolutePath}");
                }

                $getID3 = new \getID3;
                \Illuminate\Support\Facades\Log::info('Analyzing file with getID3...');
                $fileInfo = $getID3->analyze($fileToAnalyze);
                \Illuminate\Support\Facades\Log::info('Analysis complete.');

                if (isset($fileInfo['error'])) {
                    \Illuminate\Support\Facades\Log::error('getID3 Error: '.json_encode($fileInfo['error']));
                }

                $duration = isset($fileInfo['playtime_seconds']) ? round($fileInfo['playtime_seconds']) : 0;

                $this->video->update([
                    'duration' => $duration,
                ]);

                \Illuminate\Support\Facades\Log::info("Video processed: {$this->video->id} with duration {$duration}s");
            } finally {
                if ($tempFile && file_exists($tempFile)) {
                    \Illuminate\Support\Facades\Log::info("Cleaning up temporary file: {$tempFile}");
                    unlink($tempFile);
                }
            }
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error("Failed to process video {$this->video->id}: ".$e->getMessage());
            \Illuminate\Support\Facades\Log::error('Stack trace: '.$e->getTraceAsString());
            $this->video->update(['status' => 'failed']);
        }
    }
}
