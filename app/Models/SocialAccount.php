<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SocialAccount extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'platform',
        'platform_user_id',
        'platform_scoped_user_id',
        'username',
        'account_name',
        'email',
        'avatar_url',
        'access_token',
        'refresh_token',
        'page_token',
        'token_expires_at',
        'refresh_expires_at',
        'scopes',
        'is_active',
    ];

    /**
     * Never serialize OAuth tokens (e.g. GET /social-accounts, post targets).
     */
    protected $hidden = [
        'access_token',
        'refresh_token',
        'page_token',
    ];

    protected $casts = [
        'access_token' => 'encrypted',
        'refresh_token' => 'encrypted',
        'page_token' => 'encrypted',
        'token_expires_at' => 'datetime',
        'refresh_expires_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function postTargets(): HasMany
    {
        return $this->hasMany(PostTarget::class);
    }
}
