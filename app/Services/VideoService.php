<?php

namespace App\Services;

use App\Jobs\ProcessVideoJob;
use App\Models\User;
use App\Models\Post;
use App\Repositories\VideoRepository;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class VideoService
{
    public function __construct(protected VideoRepository $videoRepository)
    {
    }

    public function upload(User $user, UploadedFile $file, ?string $title = null, ?UploadedFile $thumbnail = null): Post
    {
        // Generate unique filename: timestamp_sanitizedOriginalName
        $originalName = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $extension = $file->getClientOriginalExtension();
        // Remove control characters and replace spaces/illegal chars with underscore
        $sanitizedName = preg_replace('/[^\w\-\.]/', '_', $originalName);
        $videoName = time() . '_' . $sanitizedName . '.' . $extension;
        
        $path = $file->storeAs('videos/' . $user->id, $videoName, 'public');
        
        $thumbnailPath = null;
        if ($thumbnail) {
            $thumbOriginalName = pathinfo($thumbnail->getClientOriginalName(), PATHINFO_FILENAME);
            $thumbExtension = $thumbnail->getClientOriginalExtension();
            $thumbSanitizedName = preg_replace('/[^\w\-\.]/', '_', $thumbOriginalName);
            $thumbnailName = time() . '_thumb_' . $thumbSanitizedName . '.' . $thumbExtension;
            
            $thumbnailPath = $thumbnail->storeAs('thumbnails/' . $user->id, $thumbnailName, 'public');
        }

        // Extract duration synchronously so frontend doesn't show 0:00
        $duration = 0;
        try {
            $absolutePath = Storage::disk('public')->path($path);
            if (file_exists($absolutePath)) {
                $getID3 = new \getID3;
                $fileInfo = $getID3->analyze($absolutePath);
                $duration = isset($fileInfo['playtime_seconds']) ? round($fileInfo['playtime_seconds']) : 0;
            }
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning("Could not extract duration in VideoService: " . $e->getMessage());
        }

        try {
            $video = $this->videoRepository->create([
                'user_id' => $user->id,
                'title' => $title ?: $originalName,
                'video_path' => $path,
                'video_url' => Storage::disk('public')->url($path),
                'thumbnail_path' => $thumbnailPath,
                'duration' => $duration, 
                'status' => 'draft',
            ]);

            ProcessVideoJob::dispatch($video);

            return $video;
        } catch (\Exception $e) {
            Storage::disk('public')->delete($path);
            if ($thumbnailPath) {
                Storage::disk('public')->delete($thumbnailPath);
            }
            throw $e;
        }
    }

    public function update(Post $video, ?string $title = null, ?UploadedFile $thumbnail = null): Post
    {
        $data = [];

        if ($title !== null) {
            $data['title'] = $title;
        }

        if ($thumbnail) {
            // Delete old thumbnail if it exists
            if ($video->thumbnail_path) {
                Storage::disk('public')->delete($video->thumbnail_path);
            }

            // Upload new thumbnail
            $thumbOriginalName = pathinfo($thumbnail->getClientOriginalName(), PATHINFO_FILENAME);
            $thumbExtension = $thumbnail->getClientOriginalExtension();
            $thumbSanitizedName = preg_replace('/[^\w\-\.]/', '_', $thumbOriginalName);
            $thumbnailName = time() . '_thumb_' . $thumbSanitizedName . '.' . $thumbExtension;
            
            $data['thumbnail_path'] = $thumbnail->storeAs('thumbnails/' . $video->user_id, $thumbnailName, 'public');
        }

        if (!empty($data)) {
            $video->update($data);
        }

        return $video;
    }

    public function list(User $user, ?string $platform = null, int $perPage = 10): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        return $this->videoRepository->listForUser($user->id, $perPage, $platform);
    }

    public function delete(Post $video): bool
    {
        if ($video->targets()->whereIn('status', ['pending', 'uploading', 'processing'])->exists()) {
             throw ValidationException::withMessages([
                'video' => ['Cannot delete video that has active scheduled posts.'],
            ]);
        }

        // Optionally delete from S3
        // Storage::disk('s3')->delete($video->file_path);
        
        return $this->videoRepository->delete($video);
    }
}
