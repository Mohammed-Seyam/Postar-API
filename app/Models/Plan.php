<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Plan extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'display_name',
        'price_monthly',
        'price_yearly',
        'max_posts_per_month',
        'max_uploads_per_month',
        'max_file_size_mb',
        'bulk_upload_limit',
        'max_social_accounts',
        'allowed_platforms',
        'analytics_days',
        'storage_gb',
        'retention_days',
        'custom_caption',
        'auto_retry',
    ];

    protected $casts = [
        'price_monthly' => 'decimal:2',
        'price_yearly' => 'decimal:2',
        'allowed_platforms' => 'array',
        'custom_caption' => 'boolean',
        'auto_retry' => 'boolean',
        'max_posts_per_month' => 'integer',
        'max_uploads_per_month' => 'integer',
    ];
}
