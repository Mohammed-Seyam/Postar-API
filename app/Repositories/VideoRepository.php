<?php

namespace App\Repositories;

use App\Models\Post;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class VideoRepository
{
    public function create(array $data): Post
    {
        return Post::create($data);
    }

    public function find(string $id): ?Post
    {
        return Post::find($id);
    }

    public function update(Post $video, array $data): bool
    {
        return $video->update($data);
    }

    public function delete(Post $video): bool
    {
        return $video->delete();
    }

    public function listForUser(string $userId, int $perPage = 10, ?string $platform = null): LengthAwarePaginator
    {
        $query = Post::where('user_id', $userId);

        if ($platform) {
            $query->whereHas('targets.socialAccount', function ($q) use ($platform) {
                $q->where('platform', $platform);
            });
        }

        return $query->latest()->paginate($perPage);
    }
}
