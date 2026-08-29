<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PostMedia;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MediaProxyController extends Controller
{
    /**
     * Publicly stream a post-media object over our own (verifiable) domain.
     *
     * TikTok's photo/carousel publishing only supports PULL_FROM_URL and requires
     * the image host to be a domain verified for URL ownership in the TikTok
     * Developer Portal. R2's S3 endpoint — and the presigned URLs built from it —
     * live on Cloudflare's shared `*.r2.cloudflarestorage.com` domain, which we
     * cannot verify. So we expose media through this time-limited *signed* route
     * on our own API domain instead. The signature makes the link tamper-proof
     * and short-lived even though it carries no session auth (TikTok pulls it
     * server-side with no cookies).
     */
    public function raw(Request $request, PostMedia $media): StreamedResponse
    {
        abort_unless($request->hasValidSignature(), 403, 'Invalid or expired media link.');

        $path = $media->path;

        abort_if(! $path || str_starts_with($path, 'http'), 404, 'Media is not stored on R2.');

        $disk = Storage::disk('r2');

        abort_unless($disk->exists($path), 404, 'Media object not found.');

        return new StreamedResponse(function () use ($disk, $path) {
            $stream = $disk->readStream($path);

            if (! is_resource($stream)) {
                return;
            }

            fpassthru($stream);
            fclose($stream);
        }, 200, [
            'Content-Type' => $this->mimeForPath($path),
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    /**
     * Best-effort content type from the stored object's extension. TikTok's photo
     * pull only accepts JPEG/WEBP/PNG, so those are the meaningful cases here.
     */
    private function mimeForPath(string $path): string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            'mp4' => 'video/mp4',
            'mov' => 'video/quicktime',
            default => 'application/octet-stream',
        };
    }
}
