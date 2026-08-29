<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VideoResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // Prefer the ordered media collection (carousel). Fall back to the legacy
        // scalar columns for posts that predate post_media.
        $media = $this->resource->relationLoaded('media')
            ? $this->resource->media
            : $this->resource->media()->get();

        $primary = $media->first();

        return [
            'id' => $this->id,
            'title' => $this->title,
            'file_path' => $primary ? $primary->playableUrl() : $this->resource->playableVideoUrl(),
            'thumbnail_path' => $primary ? $primary->playableThumbnailUrl() : $this->resource->playableThumbnailUrl(),
            'duration' => $this->duration,
            'status' => $this->status,
            'video_deleted_at' => $this->video_deleted_at,
            'created_at' => $this->created_at,
            'media' => $media->map(fn ($item) => [
                'id' => $item->id,
                'type' => $item->type,
                'url' => $item->playableUrl(),
                'thumbnail_path' => $item->playableThumbnailUrl(),
                'position' => $item->position,
                'width' => $item->width,
                'height' => $item->height,
                'duration' => $item->duration,
            ])->values(),
        ];
    }
}
