<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Backfill one post_media row (position 0) for every existing post that has a
     * video, so the new media-collection code sees existing posts uniformly.
     * Idempotent: skips posts that already have media rows.
     */
    public function up(): void
    {
        DB::table('posts')
            ->whereNotNull('video_path')
            ->orderBy('id')
            ->chunkById(200, function ($posts) {
                $now = now();
                $rows = [];

                $existing = DB::table('post_media')
                    ->whereIn('post_id', collect($posts)->pluck('id'))
                    ->pluck('post_id')
                    ->flip();

                foreach ($posts as $post) {
                    if ($existing->has($post->id)) {
                        continue;
                    }

                    $rows[] = [
                        'id' => (string) Str::uuid(),
                        'post_id' => $post->id,
                        'type' => 'video',
                        'path' => $post->video_path,
                        'url' => $post->video_url,
                        'thumbnail_path' => $post->thumbnail_path,
                        'position' => 0,
                        'duration' => $post->duration ?? null,
                        'file_size_mb' => $post->file_size_mb ?? null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                if (! empty($rows)) {
                    DB::table('post_media')->insert($rows);
                }
            });
    }

    public function down(): void
    {
        // Only remove the video rows we would have backfilled at position 0.
        DB::table('post_media')->where('type', 'video')->where('position', 0)->delete();
    }
};
