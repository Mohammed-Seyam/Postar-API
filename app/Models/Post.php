<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Post extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'user_id',
        'title',
        'caption',
        'video_path',
        'video_url',
        'thumbnail_path',
        'file_size_mb',
        'duration',
        'scheduled_at',
        'status',
        'video_deleted_at',
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'file_size_mb' => 'decimal:2',
        'video_deleted_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function targets(): HasMany
    {
        return $this->hasMany(PostTarget::class);
    }

    /**
     * Ordered media slides. This is the source of truth for a post's media; the
     * scalar video_* / thumbnail_path columns are kept only for BC.
     */
    public function media(): HasMany
    {
        return $this->hasMany(PostMedia::class)->orderBy('position');
    }

    /**
     * Browser-playable URL for the video. Files on the R2 bucket are private,
     * so they must be served through presigned temporary URLs.
     */
    public function playableVideoUrl(): ?string
    {
        if (! $this->video_path) {
            return $this->video_url;
        }

        if (\Illuminate\Support\Facades\Storage::disk('public')->exists($this->video_path)) {
            return \Illuminate\Support\Facades\Storage::disk('public')->url($this->video_path);
        }

        return \Illuminate\Support\Facades\Storage::disk('r2')->temporaryUrl($this->video_path, now()->addHours(6));
    }

    /**
     * What kind of media this post carries: 'video', 'image', 'mixed', or 'none'.
     * Drives type-aware wording in notifications/emails (a photo post must never
     * say "video"). Falls back to the legacy video_path/video_url columns for
     * posts created before the media-collection migration.
     */
    public function mediaKind(): string
    {
        $types = $this->relationLoaded('media')
            ? $this->media->pluck('type')->unique()
            : $this->media()->pluck('type')->unique();

        if ($types->isEmpty()) {
            return ($this->video_path || $this->video_url) ? 'video' : 'none';
        }

        return $types->count() > 1 ? 'mixed' : $types->first();
    }

    /**
     * Arabic noun phrase (with matching possessive adjective) describing this
     * post's media, for use in notification/email copy.
     */
    public function mediaLabel(): array
    {
        return match ($this->mediaKind()) {
            'image' => $this->imageCount() > 1
                ? ['noun' => 'الصور', 'possessive' => 'الخاصة']
                : ['noun' => 'الصورة', 'possessive' => 'الخاصة'],
            'mixed' => ['noun' => 'المنشور', 'possessive' => 'الخاص'],
            'none' => ['noun' => 'المنشور', 'possessive' => 'الخاص'],
            default => ['noun' => 'الفيديو', 'possessive' => 'الخاص'],
        };
    }

    private function imageCount(): int
    {
        return $this->relationLoaded('media')
            ? $this->media->where('type', 'image')->count()
            : $this->media()->where('type', 'image')->count();
    }

    public function playableThumbnailUrl(): ?string
    {
        if (! $this->thumbnail_path) {
            return null;
        }

        if (str_starts_with($this->thumbnail_path, 'http')) {
            return $this->thumbnail_path;
        }

        if (\Illuminate\Support\Facades\Storage::disk('public')->exists($this->thumbnail_path)) {
            return \Illuminate\Support\Facades\Storage::disk('public')->url($this->thumbnail_path);
        }

        return \Illuminate\Support\Facades\Storage::disk('r2')->temporaryUrl($this->thumbnail_path, now()->addHours(6));
    }

    public function toArray()
    {
        $array = parent::toArray();

        // Ordered media collection — source of truth for the carousel UI.
        // Only emit when the relation is loaded to avoid N+1 lazy loads.
        if ($this->relationLoaded('media')) {
            $array['media'] = $this->media->map(function (PostMedia $item) {
                return [
                    'id' => $item->id,
                    'type' => $item->type,
                    'url' => $item->playableUrl(),
                    'thumbnail_path' => $item->playableThumbnailUrl(),
                    'position' => $item->position,
                    'width' => $item->width,
                    'height' => $item->height,
                    'duration' => $item->duration,
                ];
            })->values()->all();
        }

        // Backward-compatible scalar fields: prefer the position-0 slide, else the
        // legacy columns on the post itself.
        $primary = $this->relationLoaded('media') ? $this->media->first() : null;

        $array['thumbnail_path'] = $primary
            ? $primary->playableThumbnailUrl()
            : $this->playableThumbnailUrl();

        // Map file_path for frontend backward compatibility
        $array['file_path'] = $primary
            ? $primary->playableUrl()
            : $this->playableVideoUrl();

        return $array;
    }
}
