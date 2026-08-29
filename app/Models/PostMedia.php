<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class PostMedia extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'post_media';

    protected $fillable = [
        'post_id',
        'type',
        'path',
        'url',
        'thumbnail_path',
        'position',
        'width',
        'height',
        'duration',
        'file_size_mb',
        'meta',
    ];

    protected $casts = [
        'position' => 'integer',
        'width' => 'integer',
        'height' => 'integer',
        'duration' => 'integer',
        'file_size_mb' => 'decimal:2',
        'meta' => 'array',
    ];

    protected $appends = ['playable_url', 'playable_thumbnail_url'];

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    /**
     * Browser-playable URL for this slide. R2 objects are private, so they are
     * served through presigned temporary URLs (mirrors Post::playableVideoUrl).
     */
    public function playableUrl(): ?string
    {
        if (! $this->path) {
            return $this->url;
        }

        if (str_starts_with($this->path, 'http')) {
            return $this->path;
        }

        if (Storage::disk('public')->exists($this->path)) {
            return Storage::disk('public')->url($this->path);
        }

        return Storage::disk('r2')->temporaryUrl($this->path, now()->addHours(6));
    }

    public function playableThumbnailUrl(): ?string
    {
        if (! $this->thumbnail_path) {
            // Images are their own thumbnail.
            return $this->type === 'image' ? $this->playableUrl() : null;
        }

        if (str_starts_with($this->thumbnail_path, 'http')) {
            return $this->thumbnail_path;
        }

        if (Storage::disk('public')->exists($this->thumbnail_path)) {
            return Storage::disk('public')->url($this->thumbnail_path);
        }

        return Storage::disk('r2')->temporaryUrl($this->thumbnail_path, now()->addHours(6));
    }

    public function getPlayableUrlAttribute(): ?string
    {
        return $this->playableUrl();
    }

    public function getPlayableThumbnailUrlAttribute(): ?string
    {
        return $this->playableThumbnailUrl();
    }
}
