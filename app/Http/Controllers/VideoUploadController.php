<?php

namespace App\Http\Controllers;

use App\Models\Post;
use App\Models\Upload;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class VideoUploadController extends Controller
{
    protected RateLimiter $limiter;

    /** Content types accepted for upload, grouped by media kind. */
    private const VIDEO_TYPES = ['video/mp4', 'video/quicktime', 'video/webm'];

    private const IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    /** Per-kind maximum upload size in bytes. */
    private const MAX_VIDEO_SIZE = 157286400; // 150 MB

    private const MAX_IMAGE_SIZE = 10485760;  // 10 MB

    public function __construct(RateLimiter $limiter)
    {
        $this->limiter = $limiter;
    }

    /** Map a content type to its media kind, or null if unsupported. */
    private function mediaKindFor(string $contentType): ?string
    {
        $type = strtolower(explode(';', $contentType)[0]);

        if (in_array($type, self::VIDEO_TYPES, true)) {
            return 'video';
        }
        if (in_array($type, self::IMAGE_TYPES, true)) {
            return 'image';
        }

        return null;
    }

    private function maxSizeFor(string $kind): int
    {
        return $kind === 'image' ? self::MAX_IMAGE_SIZE : self::MAX_VIDEO_SIZE;
    }

    /**
     * Generate presigned URL for video upload
     * - 15-minute expiration
     * - Unique per user
     * - Rate limited
     */
    public function generatePresignedUrl(Request $request)
    {
        // Validate request — videos and carousel images are both accepted.
        $allowedTypes = array_merge(self::VIDEO_TYPES, self::IMAGE_TYPES);
        $validated = $request->validate([
            'filename' => 'required|string|max:255',
            'content_type' => 'required|in:'.implode(',', $allowedTypes),
            'file_size' => 'required|integer|min:1|max:'.self::MAX_VIDEO_SIZE,
        ]);

        // Enforce the per-kind size ceiling (images are capped lower than video).
        $kind = $this->mediaKindFor($validated['content_type']);
        if ($kind && (int) $validated['file_size'] > $this->maxSizeFor($kind)) {
            return response()->json([
                'error' => ucfirst($kind).' exceeds the maximum allowed size.',
                'max_file_size' => $this->maxSizeFor($kind),
                'code' => 413,
            ], 413);
        }

        // Additional rate limiting per user (not just per IP)
        $key = 'presigned-url:'.auth()->id();

        if ($this->limiter->tooManyAttempts($key, 50)) {
            return response()->json([
                'error' => 'Too many upload requests. Please try again later.',
                'code' => 429,
                'retry_after' => $this->limiter->availableIn($key),
            ], 429);
        }

        try {
            // Record the attempt only when we actually start processing
            $this->limiter->hit($key, 3600); // 1 hour window
            $s3Client = new \Aws\S3\S3Client([
                'version' => 'latest',
                'region' => config('filesystems.disks.r2.region', 'auto'),
                'endpoint' => config('filesystems.disks.r2.endpoint'),
                'credentials' => [
                    'key' => config('filesystems.disks.r2.key'),
                    'secret' => config('filesystems.disks.r2.secret'),
                ],
            ]);

            // Generate unique filename — prevents enumeration
            $uniqueId = uniqid(auth()->id().'_', true);
            $extension = pathinfo($validated['filename'], PATHINFO_EXTENSION) ?: ($kind === 'image' ? 'jpg' : 'mp4');
            $filename = $uniqueId.'.'.$extension;
            $objectKey = 'media/'.auth()->id().'/'.$filename;

            // Pin Content-Type and Content-Length into the signature so the client
            // cannot upload a different type/size than it declared. R2/S3 will reject
            // any PUT whose headers don't match these signed values.
            $cmd = $s3Client->getCommand('PutObject', [
                'Bucket' => config('filesystems.disks.r2.bucket'),
                'Key' => $objectKey,
                'ContentType' => $validated['content_type'],
                'ContentLength' => (int) $validated['file_size'],
            ]);

            // Generate presigned URL with 15-minute expiration
            $presignedRequest = $s3Client->createPresignedRequest($cmd, '+15 minutes');
            $presignedUrl = (string) $presignedRequest->getUri();

            // Store upload metadata in database for verification on confirm
            $upload = Upload::create([
                'user_id' => auth()->id(),
                'filename' => $filename,
                'key' => $objectKey,
                'content_type' => $validated['content_type'],
                'expected_size' => (int) $validated['file_size'],
                'expires_at' => now()->addMinutes(15),
                'status' => 'pending',
            ]);

            return response()->json([
                'message' => 'Presigned URL generated',
                'upload_id' => $upload->id,
                'url' => $presignedUrl,
                // The client MUST send exactly these headers on the PUT or R2 rejects it
                'required_headers' => [
                    'Content-Type' => $validated['content_type'],
                    'Content-Length' => (int) $validated['file_size'],
                ],
                'expires_in' => 900,  // 15 minutes in seconds
                'max_file_size' => $kind ? $this->maxSizeFor($kind) : self::MAX_VIDEO_SIZE,
                'allowed_content_types' => $allowedTypes,
            ], 200);

        } catch (\Exception $e) {
            Log::error('R2 Error in generatePresignedUrl: '.$e->getMessage());

            return response()->json([
                'error' => 'Failed to generate upload URL. Please check your R2 configuration.',
                'details' => config('app.debug') ? $e->getMessage() : null,
                'code' => 500,
            ], 500);
        }
    }

    /**
     * Confirm video upload
     * - Verify upload was completed
     * - Verify file ownership
     */
    public function confirmUpload(Request $request)
    {
        $validated = $request->validate([
            'upload_id' => 'required|exists:uploads,id',
            'title' => 'nullable|string|max:255',
            'description' => 'nullable|string|max:5000',
            'thumbnail' => 'nullable|image|max:10240', // 10MB max
            'duration' => 'nullable|integer|min:0|max:86400',
            // When present, append this media as another slide of an existing draft
            // post (carousel). When absent, a new draft post is created.
            'post_id' => 'nullable|string|exists:posts,id',
            'width' => 'nullable|integer|min:0',
            'height' => 'nullable|integer|min:0',
        ]);

        // Get upload record — scoped to the authenticated user
        $upload = Upload::where('id', $validated['upload_id'])
            ->where('user_id', auth()->id())
            ->firstOrFail();

        // Verify URL hasn't expired
        if ($upload->expires_at < now()) {
            return response()->json([
                'error' => 'Upload URL expired',
                'code' => 410,
            ], 410);
        }

        // Check if file actually exists in R2
        try {
            $s3Client = new \Aws\S3\S3Client([
                'version' => 'latest',
                'region' => config('filesystems.disks.r2.region', 'auto'),
                'endpoint' => config('filesystems.disks.r2.endpoint'),
                'credentials' => [
                    'key' => config('filesystems.disks.r2.key'),
                    'secret' => config('filesystems.disks.r2.secret'),
                ],
            ]);

            $head = $s3Client->headObject([
                'Bucket' => config('filesystems.disks.r2.bucket'),
                'Key' => $upload->key,
            ]);
        } catch (\Exception $e) {
            Log::error('R2 Error in confirmUpload: '.$e->getMessage());

            return response()->json([
                'error' => 'File not found in storage or R2 configuration is missing.',
                'code' => 404,
            ], 404);
        }

        // Server-side verification: the object actually stored in R2 must match what
        // was authorised at presign time (defence against a client uploading a
        // different content-type or an oversized file to the signed URL).
        $actualType = strtolower(explode(';', (string) $head['ContentType'])[0]);
        $actualSize = (int) $head['ContentLength'];
        $mediaKind = $this->mediaKindFor($actualType);

        if ($mediaKind === null) {
            // Remove the offending object so it can't be served or scheduled.
            try {
                $s3Client->deleteObject([
                    'Bucket' => config('filesystems.disks.r2.bucket'),
                    'Key' => $upload->key,
                ]);
            } catch (\Exception $e) {
                Log::warning('Failed to delete rejected upload: '.$e->getMessage());
            }
            $upload->update(['status' => 'rejected']);

            return response()->json([
                'error' => 'Uploaded file type is not allowed.',
                'code' => 415,
            ], 415);
        }

        if ($actualSize <= 0 || $actualSize > $this->maxSizeFor($mediaKind)) {
            try {
                $s3Client->deleteObject([
                    'Bucket' => config('filesystems.disks.r2.bucket'),
                    'Key' => $upload->key,
                ]);
            } catch (\Exception $e) {
                Log::warning('Failed to delete oversized upload: '.$e->getMessage());
            }
            $upload->update(['status' => 'rejected']);

            return response()->json([
                'error' => 'Uploaded file exceeds the maximum allowed size.',
                'code' => 413,
            ], 413);
        }

        // Update upload status
        $upload->update([
            'status' => 'completed',
            'uploaded_at' => now(),
        ]);

        // Upload thumbnail to R2 if provided
        $thumbnailPath = null;
        if ($request->hasFile('thumbnail')) {
            try {
                $thumbnail = $request->file('thumbnail');
                $thumbOriginalName = pathinfo($thumbnail->getClientOriginalName(), PATHINFO_FILENAME);
                $thumbExtension = $thumbnail->getClientOriginalExtension();
                $thumbSanitizedName = preg_replace('/[^\w\-\.]/', '_', $thumbOriginalName);
                $thumbnailName = time().'_thumb_'.$thumbSanitizedName.'.'.$thumbExtension;

                $thumbnailPath = $thumbnail->storeAs('thumbnails/'.$upload->user_id, $thumbnailName, 'r2');
            } catch (\Exception $e) {
                Log::error('R2 Thumbnail Upload Error: '.$e->getMessage());
            }
        }

        // Duration is extracted client-side from the file's metadata; the queued
        // ProcessVideoJob is only a fallback for clients that couldn't provide it.
        $duration = (int) ($validated['duration'] ?? 0);

        // Resolve the target post: append to an existing draft (carousel) or create
        // a new draft. Only the owner's draft posts may be appended to.
        $post = null;
        if (! empty($validated['post_id'])) {
            $post = Post::where('id', $validated['post_id'])
                ->where('user_id', $upload->user_id)
                ->where('status', 'draft')
                ->first();
        }

        if (! $post) {
            $post = Post::create([
                'user_id' => $upload->user_id,
                'title' => $validated['title'] ?? 'Untitled',
                'status' => 'draft',
            ]);
        }

        // Next slide position within the post.
        $maxPosition = $post->media()->max('position');
        $position = is_null($maxPosition) ? 0 : $maxPosition + 1;

        $mediaUrl = Storage::disk('r2')->url($upload->key);

        $media = $post->media()->create([
            'type' => $mediaKind,
            'path' => $upload->key,
            'url' => $mediaUrl,
            'thumbnail_path' => $mediaKind === 'video' ? $thumbnailPath : null,
            'position' => $position,
            'duration' => $mediaKind === 'video' ? $duration : null,
            'width' => $validated['width'] ?? null,
            'height' => $validated['height'] ?? null,
            'file_size_mb' => round($actualSize / 1048576, 2),
        ]);

        // Keep the legacy scalar columns in sync from the primary (position-0) slide
        // so existing publish services and display code keep working unchanged.
        if ($position === 0) {
            $post->update([
                'video_path' => $mediaKind === 'video' ? $upload->key : null,
                'video_url' => $mediaKind === 'video' ? $mediaUrl : null,
                'thumbnail_path' => $mediaKind === 'video' ? $thumbnailPath : $upload->key,
                'duration' => $mediaKind === 'video' ? $duration : 0,
            ]);

            if ($mediaKind === 'video' && $duration <= 0) {
                \App\Jobs\ProcessVideoJob::dispatch($post);
            }
        }

        return response()->json([
            'message' => 'Media upload confirmed',
            'upload' => $upload,
            'post_id' => $post->id,
            'media' => $media,
            // 'video' retained for backward compatibility with the single-video client.
            'video' => new \App\Http\Resources\VideoResource($post->fresh('media')),
        ], 200);
    }
}
