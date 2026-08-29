<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PostTarget extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'post_id',
        'social_account_id',
        'status',
        'platform_post_id',
        'error_message',
        'published_at',
        'analytics_fetched_at',
        'analytics_error',
        'platform_options',
    ];

    protected $casts = [
        'published_at' => 'datetime',
        'analytics_fetched_at' => 'datetime',
        'platform_options' => 'array',
    ];

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    public function socialAccount(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class)->withTrashed();
    }

    public function analytics(): HasOne
    {
        return $this->hasOne(PostAnalytics::class);
    }
}
