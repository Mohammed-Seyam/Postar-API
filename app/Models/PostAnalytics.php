<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PostAnalytics extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'post_target_id',
        'platform',
        'views',
        'likes',
        'comments',
        'shares',
        'saves',
        'reach',
        'avg_watch_time_ms',
        'fetched_at',
    ];

    protected $casts = [
        'fetched_at' => 'datetime',
    ];

    public function postTarget(): BelongsTo
    {
        return $this->belongsTo(PostTarget::class);
    }
}
