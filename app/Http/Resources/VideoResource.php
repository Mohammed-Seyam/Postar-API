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
        return [
            'id' => $this->id,
            'title' => $this->title,
            'file_path' => $this->video_url ?? \Illuminate\Support\Facades\Storage::disk('public')->url($this->video_path),
            'thumbnail_path' => $this->thumbnail_path ? \Illuminate\Support\Facades\Storage::disk('public')->url($this->thumbnail_path) : null,
            'duration' => $this->duration,
            'status' => $this->status,
            'created_at' => $this->created_at,
        ];
    }
}
