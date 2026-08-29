<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Post;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PostMediaController extends Controller
{
    /**
     * Persist the slide order for a post's carousel. Accepts an ordered array of
     * post_media ids; positions are rewritten to match the given order.
     */
    public function reorder(Request $request, string $postId)
    {
        $post = Post::where('id', $postId)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $validated = $request->validate([
            'media' => 'required|array|min:1',
            'media.*' => 'required|string',
        ]);

        $ownedIds = $post->media()->pluck('id')->all();
        $requested = $validated['media'];

        // The request must reference exactly the post's own media, no more no less.
        if (count(array_diff($requested, $ownedIds)) > 0
            || count(array_diff($ownedIds, $requested)) > 0) {
            return response()->json([
                'error' => 'Media order must reference exactly this post\'s media items.',
            ], 422);
        }

        DB::transaction(function () use ($post, $requested) {
            // Two-phase update avoids colliding with the unique(post_id, position)
            // index while positions are being swapped.
            foreach ($requested as $index => $mediaId) {
                $post->media()->where('id', $mediaId)->update(['position' => 1000 + $index]);
            }
            foreach ($requested as $index => $mediaId) {
                $post->media()->where('id', $mediaId)->update(['position' => $index]);
            }
        });

        $post->refresh();
        $this->syncPrimaryColumns($post);

        return response()->json([
            'message' => 'Order updated.',
            'post' => $post->load('media'),
        ]);
    }

    /**
     * Remove a single slide from a post and re-pack positions.
     */
    public function destroy(Request $request, string $postId, string $mediaId)
    {
        $post = Post::where('id', $postId)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $media = $post->media()->where('id', $mediaId)->firstOrFail();

        DB::transaction(function () use ($post, $media) {
            $media->delete();

            // Re-pack remaining positions to stay contiguous from 0.
            $remaining = $post->media()->orderBy('position')->get();
            foreach ($remaining as $index => $item) {
                if ($item->position !== $index) {
                    $item->update(['position' => $index]);
                }
            }
        });

        $post->refresh();
        $this->syncPrimaryColumns($post);

        return response()->json([
            'message' => 'Media removed.',
            'post' => $post->load('media'),
        ]);
    }

    /**
     * Keep the legacy scalar columns aligned with the current position-0 slide.
     */
    private function syncPrimaryColumns(Post $post): void
    {
        $primary = $post->media()->orderBy('position')->first();

        if (! $primary) {
            $post->update([
                'video_path' => null,
                'video_url' => null,
                'thumbnail_path' => null,
                'duration' => 0,
            ]);

            return;
        }

        $post->update([
            'video_path' => $primary->type === 'video' ? $primary->path : null,
            'video_url' => $primary->type === 'video' ? $primary->url : null,
            'thumbnail_path' => $primary->type === 'video' ? $primary->thumbnail_path : $primary->path,
            'duration' => $primary->type === 'video' ? ($primary->duration ?? 0) : 0,
        ]);
    }
}
