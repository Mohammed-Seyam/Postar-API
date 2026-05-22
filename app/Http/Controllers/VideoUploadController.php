<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Cache\RateLimiter;
use App\Models\Upload;
use Illuminate\Support\Facades\Log;

class VideoUploadController extends Controller
{
    protected RateLimiter $limiter;

    public function __construct(RateLimiter $limiter)
    {
        $this->limiter = $limiter;
    }

    /**
     * Generate presigned URL for video upload
     * - 15-minute expiration
     * - Unique per user
     * - Rate limited
     */
    public function generatePresignedUrl(Request $request)
    {
        // Validate request
        $validated = $request->validate([
            'filename' => 'required|string|max:255',
            'content_type' => 'required|in:video/mp4,video/quicktime,video/webm',
            'file_size' => 'required|integer|max:157286400', // 150MB
        ]);

        // Additional rate limiting per user (not just per IP)
        $key = 'presigned-url:' . auth()->id();

        if ($this->limiter->tooManyAttempts($key, 5)) {
            return response()->json([
                'error' => 'Too many upload requests. Please try again later.',
                'code' => 429,
                'retry_after' => $this->limiter->availableIn($key),
            ], 429);
        }

        // Record the attempt
        $this->limiter->hit($key, 3600); // 1 hour window

        try {
            $s3Client = new \Aws\S3\S3Client([
                'version' => 'latest',
                'region'  => config('filesystems.disks.r2.region', 'auto'),
                'endpoint' => config('filesystems.disks.r2.endpoint'),
                'credentials' => [
                    'key'    => config('filesystems.disks.r2.key'),
                    'secret' => config('filesystems.disks.r2.secret'),
                ],
            ]);

            // Generate unique filename — prevents enumeration
            $uniqueId = uniqid(auth()->id() . '_', true);
            $extension = pathinfo($validated['filename'], PATHINFO_EXTENSION) ?: 'mp4';
            $filename = $uniqueId . '.' . $extension;
            $objectKey = 'videos/' . auth()->id() . '/' . $filename;

            // Create PUT object command
            $cmd = $s3Client->getCommand('PutObject', [
                'Bucket'       => config('filesystems.disks.r2.bucket'),
                'Key'          => $objectKey,
                'ContentType'  => $validated['content_type'],
                'Metadata'     => [
                    'user-id' => (string) auth()->id(),
                    'uploaded-at' => now()->toIso8601String(),
                ],
            ]);

            // Generate presigned URL with 15-minute expiration
            $presignedRequest = $s3Client->createPresignedRequest($cmd, '+15 minutes');
            $presignedUrl = (string) $presignedRequest->getUri();

            // Store upload metadata in database for verification
            $upload = Upload::create([
                'user_id'    => auth()->id(),
                'filename'   => $filename,
                'key'        => $objectKey,
                'expires_at' => now()->addMinutes(15),
                'status'     => 'pending',
            ]);

            return response()->json([
                'message'               => 'Presigned URL generated',
                'upload_id'             => $upload->id,
                'url'                   => $presignedUrl,
                'expires_in'            => 900,  // 15 minutes in seconds
                'max_file_size'         => 157286400,  // 150MB
                'allowed_content_types' => ['video/mp4', 'video/quicktime', 'video/webm'],
            ], 200);

        } catch (\Aws\Exception\AwsException $e) {
            Log::error('R2 Error: ' . $e->getMessage());

            return response()->json([
                'error' => 'Failed to generate upload URL',
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
            'upload_id'   => 'required|exists:uploads,id',
            'title'       => 'required|string|max:255',
            'description' => 'nullable|string|max:5000',
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
                'region'  => config('filesystems.disks.r2.region', 'auto'),
                'endpoint' => config('filesystems.disks.r2.endpoint'),
                'credentials' => [
                    'key'    => config('filesystems.disks.r2.key'),
                    'secret' => config('filesystems.disks.r2.secret'),
                ],
            ]);

            $s3Client->headObject([
                'Bucket' => config('filesystems.disks.r2.bucket'),
                'Key'    => $upload->key,
            ]);
        } catch (\Aws\Exception\AwsException $e) {
            return response()->json([
                'error' => 'File not found in storage',
                'code' => 404,
            ], 404);
        }

        // Update upload status
        $upload->update([
            'status'      => 'completed',
            'uploaded_at' => now(),
        ]);

        return response()->json([
            'message' => 'Video upload confirmed',
            'upload'  => $upload,
        ], 200);
    }
}
