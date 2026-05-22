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
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'file_size_mb' => 'decimal:2',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function targets(): HasMany
    {
        return $this->hasMany(PostTarget::class);
    }

    public function toArray()
    {
        $array = parent::toArray();
        
        // Transform local thumbnail paths to absolute URLs
        if (!empty($array['thumbnail_path']) && !str_starts_with($array['thumbnail_path'], 'http')) {
            $array['thumbnail_path'] = \Illuminate\Support\Facades\Storage::disk('public')->url($array['thumbnail_path']);
        }
        
        // Map file_path for frontend backward compatibility
        $array['file_path'] = $this->video_url ?? ($this->video_path ? \Illuminate\Support\Facades\Storage::disk('public')->url($this->video_path) : null);
        
        return $array;
    }
}
