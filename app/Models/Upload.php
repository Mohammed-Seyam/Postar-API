<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Upload extends Model
{
    protected $fillable = [
        'user_id',
        'filename',
        'key',
        'content_type',
        'expected_size',
        'expires_at',
        'status',
        'uploaded_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'uploaded_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function video()
    {
        return $this->hasOne(Post::class, 'video_path', 'key');
    }
}
